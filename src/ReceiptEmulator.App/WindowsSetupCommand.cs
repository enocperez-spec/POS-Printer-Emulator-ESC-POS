using System.Diagnostics;
using System.Runtime.Versioning;
using System.Security.Principal;
using System.ServiceProcess;

namespace ReceiptEmulator;

public enum WindowsSetupAction
{
    None,
    Install,
    Uninstall,
    HealthCheck
}

public static class WindowsSetupCommand
{
    private const string ServiceName = "ReceiptLab";
    private const string DisplayName = "Receipt Lab";
    private const string FirewallRuleName = "Receipt Lab ESC-POS (TCP 9100)";
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
            Console.Error.WriteLine("Receipt Lab Windows setup commands can only run on Windows.");
            return 1;
        }

        ClearSetupError();

        try
        {
            return await RunWindowsActionAsync(action, cancellationToken);
        }
        catch (Exception exception)
        {
            WriteSetupError(exception);
            Console.Error.WriteLine($"Receipt Lab setup failed: {exception.Message}");
            return 1;
        }
    }

    [SupportedOSPlatform("windows")]
    private static async Task<int> RunWindowsActionAsync(
        WindowsSetupAction action,
        CancellationToken cancellationToken)
    {
        if (action == WindowsSetupAction.HealthCheck)
        {
            await WaitForViewerAsync(TimeSpan.FromSeconds(5), cancellationToken);
            Console.WriteLine("Receipt Lab is healthy.");
            return 0;
        }

        EnsureAdministrator();

        if (action == WindowsSetupAction.Install)
        {
            await InstallAsync(cancellationToken);
        }
        else
        {
            await UninstallAsync(cancellationToken);
        }

        return 0;
    }

    [SupportedOSPlatform("windows")]
    private static async Task InstallAsync(CancellationToken cancellationToken)
    {
        var executablePath = Environment.ProcessPath;
        if (string.IsNullOrWhiteSpace(executablePath) || !File.Exists(executablePath))
        {
            throw new InvalidOperationException("The Receipt Lab executable path could not be determined.");
        }

        Console.WriteLine("Configuring the Receipt Lab Windows service...");
        await RemoveServiceAsync(cancellationToken);

        WindowsServiceManager.Create(
            ServiceName,
            DisplayName,
            "Receives ESC/POS jobs on TCP port 9100 and serves the local Receipt Lab viewer.",
            executablePath);

        Console.WriteLine("Configuring the private/domain TCP 9100 firewall rule...");
        await RemoveFirewallRuleAsync(cancellationToken);
        await RunRequiredProcessAsync(
            GetSystemExecutable("netsh.exe"),
            [
                "advfirewall",
                "firewall",
                "add",
                "rule",
                $"name={FirewallRuleName}",
                "dir=in",
                "action=allow",
                "protocol=TCP",
                "localport=9100",
                "profile=private,domain",
                $"program={executablePath}",
                "enable=yes"
            ],
            cancellationToken);

        using var service = new ServiceController(ServiceName);
        service.Start();
        service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(30));

        await WaitForViewerAsync(TimeSpan.FromSeconds(30), cancellationToken);
        Console.WriteLine("Receipt Lab installation completed successfully.");
    }

    [SupportedOSPlatform("windows")]
    private static async Task UninstallAsync(CancellationToken cancellationToken)
    {
        Console.WriteLine("Removing the Receipt Lab Windows service and firewall rule...");
        await RemoveServiceAsync(cancellationToken);
        await RemoveFirewallRuleAsync(cancellationToken);
        RemoveServiceData();
        Console.WriteLine("Receipt Lab system components were removed successfully.");
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
                "The previous Receipt Lab service is pending deletion. Restart Windows and run setup again.");
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

    private static async Task RemoveFirewallRuleAsync(CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(
            GetSystemExecutable("netsh.exe"),
            ["advfirewall", "firewall", "delete", "rule", $"name={FirewallRuleName}"],
            cancellationToken);

        if (result.ExitCode != 0)
        {
            Console.WriteLine("No existing Receipt Lab firewall rule needed removal.");
        }
    }

    private static async Task WaitForViewerAsync(TimeSpan timeout, CancellationToken cancellationToken)
    {
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
        var deadline = DateTimeOffset.UtcNow.Add(timeout);

        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var response = await client.GetAsync(ViewerHealthUrl, cancellationToken);
                if (response.IsSuccessStatusCode)
                {
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

        throw new System.TimeoutException("The local Receipt Lab viewer did not become ready.");
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
        return Path.Combine(AppContext.BaseDirectory, "ReceiptLab-setup-error.txt");
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
