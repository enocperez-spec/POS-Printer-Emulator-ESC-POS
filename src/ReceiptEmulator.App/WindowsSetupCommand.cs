using System.Diagnostics;
using System.Runtime.Versioning;
using System.Security.Principal;
using System.ServiceProcess;
using System.Text.Json;

namespace ReceiptEmulator;

public enum WindowsSetupAction
{
    None,
    Install,
    Uninstall,
    HealthCheck,
    InstallPrinter,
    PrintPrinterTest
}

public static class WindowsSetupCommand
{
    private const string ServiceName = "ReceiptLab";
    private const string DisplayName = "POS Printer Emulator";
    private const string FirewallRuleName = "POS Printer Emulator RAW TCP Listeners";
    private static readonly string[] LegacyFirewallRuleNames =
    [
        "POS Printer Emulator ESC-POS (TCP 9100)",
        "Receipt Lab ESC-POS (TCP 9100)"
    ];
    private const string ViewerHealthUrl = "http://127.0.0.1:5187/api/status";

    public static WindowsSetupAction ParseAction(IReadOnlyList<string> arguments)
    {
        if (arguments.Count == 0)
        {
            return WindowsSetupAction.None;
        }

        return arguments[0].ToLowerInvariant() switch
        {
            "--install-windows" => WindowsSetupAction.Install,
            "--uninstall-windows" => WindowsSetupAction.Uninstall,
            "--health-check" => WindowsSetupAction.HealthCheck,
            "--install-printer" => WindowsSetupAction.InstallPrinter,
            "--print-printer-test" => WindowsSetupAction.PrintPrinterTest,
            _ => WindowsSetupAction.None
        };
    }

    public static async Task<int?> TryRunAsync(string[] arguments, CancellationToken cancellationToken = default)
    {
        var action = ParseAction(arguments);
        if (action == WindowsSetupAction.None)
        {
            return null;
        }

        if (!OperatingSystem.IsWindows())
        {
            Console.Error.WriteLine("POS Printer Emulator Windows setup commands can only run on Windows.");
            return 1;
        }

        ClearSetupError();

        try
        {
            return await RunWindowsActionAsync(action, arguments, cancellationToken);
        }
        catch (Exception exception)
        {
            WriteSetupError(exception);
            Console.Error.WriteLine($"POS Printer Emulator setup failed: {exception.Message}");
            return 1;
        }
    }

    [SupportedOSPlatform("windows")]
    private static async Task<int> RunWindowsActionAsync(
        WindowsSetupAction action,
        IReadOnlyList<string> arguments,
        CancellationToken cancellationToken)
    {
        if (action == WindowsSetupAction.HealthCheck)
        {
            await WaitForViewerAsync(TimeSpan.FromSeconds(5), cancellationToken);
            Console.WriteLine("POS Printer Emulator is healthy.");
            return 0;
        }

        EnsureAdministrator();

        if (action == WindowsSetupAction.InstallPrinter)
        {
            return await PrinterSetupManager.InstallFromFilesAsync(
                GetRequiredOption(arguments, "--request"),
                GetRequiredOption(arguments, "--result"),
                cancellationToken);
        }

        if (action == WindowsSetupAction.PrintPrinterTest)
        {
            return PrinterSetupManager.PrintTestReceipt(GetRequiredOption(arguments, "--printer-name"));
        }

        if (action == WindowsSetupAction.Install)
        {
            var customerName = GetRequiredOption(arguments, "--customer-name");
            var emailAddress = GetRequiredOption(arguments, "--email");
            var upgradeStateRestored = arguments.Any(argument =>
                string.Equals(argument, "--upgrade-state-restored", StringComparison.OrdinalIgnoreCase));
            await InstallAsync(customerName, emailAddress, upgradeStateRestored, cancellationToken);
        }
        else
        {
            await UninstallAsync(cancellationToken);
        }

        return 0;
    }

    [SupportedOSPlatform("windows")]
    private static async Task InstallAsync(
        string customerName,
        string emailAddress,
        bool upgradeStateRestored,
        CancellationToken cancellationToken)
    {
        var executablePath = Environment.ProcessPath;
        if (string.IsNullOrWhiteSpace(executablePath) || !File.Exists(executablePath))
        {
            throw new InvalidOperationException("The POS Printer Emulator service executable path could not be determined.");
        }

        Console.WriteLine("Registering the POS Printer Emulator installation...");
        Directory.CreateDirectory(LicenseService.DefaultRootPath);
        await RunRequiredProcessAsync(
            GetSystemExecutable("takeown.exe"),
            BuildTakeOwnershipArguments(LicenseService.DefaultRootPath),
            cancellationToken);
        await RunRequiredProcessAsync(
            GetSystemExecutable("icacls.exe"),
            BuildDataDirectoryAclArguments(LicenseService.DefaultRootPath),
            cancellationToken);
        if (Directory.EnumerateFileSystemEntries(LicenseService.DefaultRootPath).Any())
        {
            await RunRequiredProcessAsync(
                GetSystemExecutable("icacls.exe"),
                BuildDataDirectoryChildAclResetArguments(LicenseService.DefaultRootPath),
                cancellationToken);
            await RunRequiredProcessAsync(
                GetSystemExecutable("icacls.exe"),
                BuildDataDirectoryChildInheritanceArguments(LicenseService.DefaultRootPath),
                cancellationToken);
        }
        if (!upgradeStateRestored)
        {
            LicenseService.RestoreUpgradeStateAtDefaultPath();
        }
        LicenseService.RegisterInstallationAtDefaultPath(customerName, emailAddress);
        var expectedLicenseMode = upgradeStateRestored
            ? LicenseService.GetRequiredPersistedLicenseModeAtDefaultPath()
            : null;

        Console.WriteLine("Configuring the POS Printer Emulator Windows service...");
        await RemoveServiceAsync(cancellationToken);

        WindowsServiceManager.Create(
            ServiceName,
            DisplayName,
            "Receives ESC/POS jobs on configured RAW TCP ports and serves the local POS Printer Emulator viewer.",
            executablePath);

        Console.WriteLine("Configuring the private/domain RAW TCP listener firewall rule...");
        await RemoveFirewallRulesAsync(cancellationToken);
        await RunRequiredProcessAsync(
            GetSystemExecutable("netsh.exe"),
            BuildFirewallRuleArguments(executablePath),
            cancellationToken);

        using var service = new ServiceController(ServiceName);
        service.Start();
        service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(30));

        await WaitForViewerAsync(TimeSpan.FromSeconds(30), cancellationToken, expectedLicenseMode);
        if (!upgradeStateRestored)
        {
            LicenseService.CompleteUpgradeStateAtDefaultPath();
        }
        Console.WriteLine("POS Printer Emulator installation completed successfully.");
    }

    [SupportedOSPlatform("windows")]
    private static async Task UninstallAsync(CancellationToken cancellationToken)
    {
        Console.WriteLine("Removing the POS Printer Emulator Windows service and firewall rule...");
        await RemoveServiceAsync(cancellationToken);
        await RemoveFirewallRulesAsync(cancellationToken);
        RemoveServiceData();
        Console.WriteLine("POS Printer Emulator system components were removed successfully.");
    }

    [SupportedOSPlatform("windows")]
    private static async Task RemoveServiceAsync(CancellationToken cancellationToken)
    {
        if (!ServiceExists())
        {
            return;
        }

        using (var service = new ServiceController(ServiceName))
        {
            service.Refresh();
            if (service.Status != ServiceControllerStatus.Stopped)
            {
                if (service.CanStop)
                {
                    service.Stop();
                }

                service.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(20));
            }
        }

        WindowsServiceManager.Delete(ServiceName);

        var deadline = DateTimeOffset.UtcNow.AddSeconds(20);
        while (ServiceExists() && DateTimeOffset.UtcNow < deadline)
        {
            await Task.Delay(250, cancellationToken);
        }

        if (ServiceExists())
        {
            throw new InvalidOperationException(
                "The previous POS Printer Emulator service is pending deletion. Restart Windows and run setup again.");
        }
    }

    [SupportedOSPlatform("windows")]
    private static bool ServiceExists()
    {
        var services = ServiceController.GetServices();
        try
        {
            return services.Any(service =>
                string.Equals(service.ServiceName, ServiceName, StringComparison.OrdinalIgnoreCase));
        }
        finally
        {
            foreach (var service in services)
            {
                service.Dispose();
            }
        }
    }

    private static async Task RemoveFirewallRulesAsync(CancellationToken cancellationToken)
    {
        foreach (var ruleName in new[] { FirewallRuleName }.Concat(LegacyFirewallRuleNames))
        {
            var result = await RunProcessAsync(
                GetSystemExecutable("netsh.exe"),
                ["advfirewall", "firewall", "delete", "rule", $"name={ruleName}"],
                cancellationToken);

            if (result.ExitCode != 0)
            {
                Console.WriteLine($"No existing '{ruleName}' firewall rule needed removal.");
            }
        }
    }

    internal static IReadOnlyList<string> BuildFirewallRuleArguments(string executablePath)
    {
        if (string.IsNullOrWhiteSpace(executablePath))
        {
            throw new ArgumentException("The application executable path is required.", nameof(executablePath));
        }

        return
        [
            "advfirewall",
            "firewall",
            "add",
            "rule",
            $"name={FirewallRuleName}",
            "dir=in",
            "action=allow",
            "protocol=TCP",
            "localport=any",
            "profile=private,domain",
            $"program={Path.GetFullPath(executablePath)}",
            "enable=yes"
        ];
    }

    internal static IReadOnlyList<string> BuildTakeOwnershipArguments(string directoryPath)
    {
        if (string.IsNullOrWhiteSpace(directoryPath))
        {
            throw new ArgumentException("The application-data directory is required.", nameof(directoryPath));
        }

        return ["/F", Path.GetFullPath(directoryPath), "/A", "/R", "/D", "Y"];
    }

    internal static IReadOnlyList<string> BuildDataDirectoryAclArguments(string directoryPath)
    {
        if (string.IsNullOrWhiteSpace(directoryPath))
        {
            throw new ArgumentException("The application-data directory is required.", nameof(directoryPath));
        }

        return
        [
            Path.GetFullPath(directoryPath),
            "/inheritance:r",
            "/grant:r",
            "*S-1-5-18:(OI)(CI)F",
            "*S-1-5-32-544:(OI)(CI)F",
            "*S-1-5-19:(OI)(CI)M"
        ];
    }

    internal static IReadOnlyList<string> BuildDataDirectoryChildAclResetArguments(string directoryPath)
    {
        if (string.IsNullOrWhiteSpace(directoryPath))
        {
            throw new ArgumentException("The application-data directory is required.", nameof(directoryPath));
        }

        return [Path.Combine(Path.GetFullPath(directoryPath), "*"), "/reset", "/T", "/C"];
    }

    internal static IReadOnlyList<string> BuildDataDirectoryChildInheritanceArguments(string directoryPath)
    {
        if (string.IsNullOrWhiteSpace(directoryPath))
        {
            throw new ArgumentException("The application-data directory is required.", nameof(directoryPath));
        }

        return [Path.Combine(Path.GetFullPath(directoryPath), "*"), "/inheritance:e", "/T", "/C"];
    }

    private static async Task WaitForViewerAsync(
        TimeSpan timeout,
        CancellationToken cancellationToken,
        string? expectedLicenseMode = null)
    {
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
        var deadline = DateTimeOffset.UtcNow.Add(timeout);
        string? observedLicenseMode = null;

        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var response = await client.GetAsync(ViewerHealthUrl, cancellationToken);
                if (response.IsSuccessStatusCode)
                {
                    if (expectedLicenseMode is null) return;

                    var json = await response.Content.ReadAsStringAsync(cancellationToken);
                    var status = JsonSerializer.Deserialize<ServiceStatus>(
                        json,
                        new JsonSerializerOptions(JsonSerializerDefaults.Web));
                    observedLicenseMode = status?.License.Mode;
                    if (string.Equals(observedLicenseMode, expectedLicenseMode, StringComparison.OrdinalIgnoreCase))
                        return;
                }
            }
            catch (HttpRequestException)
            {
                // The service may still be starting.
            }
            catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                // Retry until the overall startup timeout expires.
            }

            await Task.Delay(500, cancellationToken);
        }

        if (expectedLicenseMode is not null && observedLicenseMode is not null)
        {
            throw new InvalidOperationException(
                $"The updated service reported {observedLicenseMode} instead of the preserved {expectedLicenseMode} License. " +
                "The preserved upgrade files were not removed.");
        }

        throw new System.TimeoutException("The local POS Printer Emulator viewer did not become ready.");
    }

    [SupportedOSPlatform("windows")]
    private static void EnsureAdministrator()
    {
        using var identity = WindowsIdentity.GetCurrent();
        var principal = new WindowsPrincipal(identity);
        if (!principal.IsInRole(WindowsBuiltInRole.Administrator))
        {
            throw new UnauthorizedAccessException("Administrator privileges are required.");
        }
    }

    private static void RemoveServiceData()
    {
        var windowsDirectory = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
        var commonApplicationData = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
        var dataDirectories = new[]
        {
            LicenseService.DefaultRootPath,
            Path.Combine(windowsDirectory, "ServiceProfiles", "LocalService", "AppData", "Local", "ReceiptLab"),
            Path.Combine(commonApplicationData, "ReceiptLab")
        };

        foreach (var directory in dataDirectories)
        {
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, recursive: true);
            }
        }
    }

    private static string GetSystemExecutable(string fileName)
    {
        return Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.System),
            fileName);
    }

    private static string GetRequiredOption(IReadOnlyList<string> arguments, string name)
    {
        for (var index = 0; index < arguments.Count; index++)
        {
            if (!string.Equals(arguments[index], name, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            if (index + 1 < arguments.Count && !string.IsNullOrWhiteSpace(arguments[index + 1]))
            {
                return arguments[index + 1];
            }

            break;
        }

        throw new ArgumentException($"{name} is required during installation.");
    }

    private static void ClearSetupError()
    {
        try
        {
            var path = GetSetupErrorPath();
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // A stale diagnostic file does not prevent setup from running.
        }
    }

    private static void WriteSetupError(Exception exception)
    {
        var details = $"{exception.GetType().Name}: {exception.Message}";
        try
        {
            File.WriteAllText(GetSetupErrorPath(), details);

            var dataDirectory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "ReceiptLab");
            Directory.CreateDirectory(dataDirectory);
            File.AppendAllText(
                Path.Combine(dataDirectory, "setup.log"),
                $"{DateTimeOffset.Now:O} {details}{Environment.NewLine}");
        }
        catch
        {
            // Preserve and report the original setup failure.
        }
    }

    private static string GetSetupErrorPath()
    {
        return Path.Combine(AppContext.BaseDirectory, "POSPrinterEmulator-setup-error.txt");
    }

    private static async Task RunRequiredProcessAsync(
        string executable,
        IReadOnlyList<string> arguments,
        CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(executable, arguments, cancellationToken);
        if (result.ExitCode == 0)
        {
            return;
        }

        var details = string.IsNullOrWhiteSpace(result.StandardError)
            ? result.StandardOutput
            : result.StandardError;
        throw new InvalidOperationException(
            $"{Path.GetFileName(executable)} exited with code {result.ExitCode}: {details.Trim()}");
    }

    private static async Task<ProcessResult> RunProcessAsync(
        string executable,
        IReadOnlyList<string> arguments,
        CancellationToken cancellationToken)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };

        foreach (var argument in arguments)
        {
            startInfo.ArgumentList.Add(argument);
        }

        using var process = new Process { StartInfo = startInfo };
        if (!process.Start())
        {
            throw new InvalidOperationException($"Unable to start {executable}.");
        }

        var standardOutput = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var standardError = process.StandardError.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);

        return new ProcessResult(
            process.ExitCode,
            await standardOutput,
            await standardError);
    }

    private sealed record ProcessResult(int ExitCode, string StandardOutput, string StandardError);
}
