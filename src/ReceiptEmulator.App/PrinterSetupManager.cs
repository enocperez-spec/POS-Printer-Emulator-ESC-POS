using System.Diagnostics;
using System.Management;
using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace ReceiptEmulator;

public sealed record PrinterSetupStatus(
    bool IsWindows,
    bool DriverInstalled,
    string? ApdVersion,
    string? DriverVersion,
    string? StatusApiVersion,
    string DriverName,
    string RecommendedApdVersion,
    string RecommendedDriverVersion,
    string RecommendedStatusApiVersion,
    bool DriverPackageAvailable,
    string Message);

public sealed record PrinterInstallRequest(
    string PrinterName,
    string IpAddress,
    int Port,
    bool SameComputer);

public sealed record PrinterInstallResult(
    bool Success,
    string Message,
    string PrinterName,
    string IpAddress,
    int Port,
    string DriverName,
    string? TechnicalDetails = null);

public static class PrinterSetupManager
{
    public const string DriverName = "EPSON TM-T88V Receipt5";
    public const string RecommendedApdVersion = "5.13.0.0";
    public const string RecommendedDriverVersion = "5.12.0.0";
    public const string RecommendedStatusApiVersion = "6.7.0.0";
    private const string QueueComment = "Managed by POS Printer Emulator";
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web) { WriteIndented = true };

    public static PrinterSetupStatus GetStatus()
    {
        if (!OperatingSystem.IsWindows())
        {
            return new(false, false, null, null, null, DriverName, RecommendedApdVersion,
                RecommendedDriverVersion, RecommendedStatusApiVersion, false,
                "Printer setup is available only in the Windows desktop application.");
        }

        DriverInfo? driver = null;
        string? apdVersion = null;
        try
        {
            driver = FindPrinterDriver();
            apdVersion = FindUninstallVersion("EPSON Advanced Printer Driver for TM-T88V Ver.5");
        }
        catch (Exception exception)
        {
            Log($"Driver status warning: {exception.Message}");
        }
        var statusApiPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86),
            "EPSON", "Advanced Printer Tool", "StatusAPI", "EpsonStatusAPI.dll");
        var statusApiVersion = File.Exists(statusApiPath)
            ? FileVersionInfo.GetVersionInfo(statusApiPath).FileVersion
            : null;
        var installer = FindDriverInf();
        var driverInstalled = driver is not null;
        var message = driverInstalled
            ? "The required Epson TM-T88V Windows printer driver is installed."
            : installer is not null
                ? "The Epson driver is missing and will be installed automatically."
                : "The Epson driver is missing and its installation package is not available.";

        return new(true, driverInstalled, apdVersion, driver?.Version, statusApiVersion, DriverName,
            RecommendedApdVersion, RecommendedDriverVersion, RecommendedStatusApiVersion,
            installer is not null, message);
    }

    public static async Task<int> InstallFromFilesAsync(string requestPath, string resultPath, CancellationToken cancellationToken)
    {
        PrinterInstallResult result;
        try
        {
            if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Printer setup requires Windows.");
            var request = JsonSerializer.Deserialize<PrinterInstallRequest>(
                await File.ReadAllTextAsync(requestPath, cancellationToken), JsonOptions)
                ?? throw new InvalidOperationException("The printer setup request was empty.");
            Validate(request);
            result = await InstallAsync(request, cancellationToken);
        }
        catch (Exception exception)
        {
            Log($"Printer installation failed: {exception}");
            result = new(false, FriendlyMessage(exception), "POS Printer Emulator", "127.0.0.1", 9100,
                DriverName, exception.ToString());
        }

        Directory.CreateDirectory(Path.GetDirectoryName(resultPath)!);
        await File.WriteAllTextAsync(resultPath, JsonSerializer.Serialize(result, JsonOptions), cancellationToken);
        return result.Success ? 0 : 1;
    }

    [SupportedOSPlatform("windows")]
    private static async Task<PrinterInstallResult> InstallAsync(PrinterInstallRequest request, CancellationToken cancellationToken)
    {
        var createdPort = false;
        var createdPrinter = false;
        var portName = MakePortName(request.IpAddress, request.Port);
        Log($"Starting printer installation: name='{request.PrinterName}', endpoint={request.IpAddress}:{request.Port}.");

        try
        {
            if (FindPrinterDriver() is null)
            {
                await InstallEpsonDriverAsync(cancellationToken);
                if (FindPrinterDriver() is null)
                {
                    throw new InvalidOperationException("Epson setup finished, but Windows still cannot find the EPSON TM-T88V Receipt5 driver.");
                }
            }

            createdPort = EnsureTcpPort(portName, request.IpAddress, request.Port);
            createdPrinter = EnsurePrinter(request.PrinterName, portName);

            if (!await CanConnectAsync(request.IpAddress, request.Port, cancellationToken))
            {
                throw new SocketException((int)SocketError.ConnectionRefused);
            }

            if (!PrinterExists(request.PrinterName, portName))
            {
                throw new InvalidOperationException("Windows did not confirm the new printer queue.");
            }

            Log("Printer installation completed successfully.");
            return new(true, "Printer Installed Successfully", request.PrinterName, request.IpAddress,
                request.Port, DriverName);
        }
        catch
        {
            if (createdPrinter) TryDeletePrinter(request.PrinterName);
            if (createdPort) TryDeletePort(portName);
            throw;
        }
    }

    [SupportedOSPlatform("windows")]
    private static async Task InstallEpsonDriverAsync(CancellationToken cancellationToken)
    {
        var driverInf = FindDriverInf()
            ?? throw new FileNotFoundException("The signed Epson TM-T88V driver package is not included with this installation.");

        Log("Staging the signed Epson TM-T88V Receipt5 driver package.");
        await RunRequiredProcessAsync(
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "pnputil.exe"),
            ["/add-driver", driverInf, "/install"],
            cancellationToken,
            [0, 3010]);

        var packageRoot = Directory.GetParent(Path.GetDirectoryName(driverInf)!)!.FullName;
        foreach (var component in new[] { "PCS64.msi", "PrinterReg64.msi", "UtilityPac64.msi" })
        {
            var componentPath = Path.Combine(packageRoot, component);
            if (!File.Exists(componentPath)) continue;
            Log($"Installing Epson supporting component '{component}'.");
            await RunRequiredProcessAsync(
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "msiexec.exe"),
                ["/i", componentPath, "/qn", "/norestart"],
                cancellationToken,
                [0, 1641, 3010]);
        }
    }

    [SupportedOSPlatform("windows")]
    private static bool EnsureTcpPort(string portName, string address, int port)
    {
        using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_TCPIPPrinterPort");
        foreach (ManagementObject existing in searcher.Get())
        {
            using (existing)
            {
                if (!string.Equals(existing["Name"]?.ToString(), portName, StringComparison.OrdinalIgnoreCase)) continue;
                if (!string.Equals(existing["HostAddress"]?.ToString(), address, StringComparison.OrdinalIgnoreCase)
                    || Convert.ToInt32(existing["PortNumber"]) != port)
                    throw new InvalidOperationException($"The Windows printer port '{portName}' already targets a different address.");
                return false;
            }
        }

        using var portClass = new ManagementClass("Win32_TCPIPPrinterPort");
        using var instance = portClass.CreateInstance() ?? throw new InvalidOperationException("Windows could not create a TCP/IP printer port.");
        instance["Name"] = portName;
        instance["HostAddress"] = address;
        instance["PortNumber"] = port;
        instance["Protocol"] = 1;
        instance["SNMPEnabled"] = false;
        instance.Put();
        Log($"Created Standard TCP/IP port '{portName}'.");
        return true;
    }

    [SupportedOSPlatform("windows")]
    private static bool EnsurePrinter(string printerName, string portName)
    {
        using var searcher = new ManagementObjectSearcher($"SELECT * FROM Win32_Printer WHERE Name='{EscapeWql(printerName)}'");
        foreach (ManagementObject existing in searcher.Get())
        {
            using (existing)
            {
                if (!string.Equals(existing["DriverName"]?.ToString(), DriverName, StringComparison.OrdinalIgnoreCase)
                    || !string.Equals(existing["PortName"]?.ToString(), portName, StringComparison.OrdinalIgnoreCase))
                    throw new InvalidOperationException($"A Windows printer named '{printerName}' already exists with different settings.");
                return false;
            }
        }

        using var printerClass = new ManagementClass("Win32_Printer");
        using var printer = printerClass.CreateInstance() ?? throw new InvalidOperationException("Windows could not create the printer queue.");
        printer["Name"] = printerName;
        printer["DriverName"] = DriverName;
        printer["PortName"] = portName;
        printer["Comment"] = QueueComment;
        printer["PrintProcessor"] = "WinPrint";
        printer.Put();
        Log($"Created Windows printer '{printerName}'.");
        return true;
    }

    [SupportedOSPlatform("windows")]
    private static bool PrinterExists(string printerName, string portName)
    {
        using var searcher = new ManagementObjectSearcher($"SELECT * FROM Win32_Printer WHERE Name='{EscapeWql(printerName)}'");
        foreach (ManagementObject printer in searcher.Get())
        {
            using (printer)
                return string.Equals(printer["DriverName"]?.ToString(), DriverName, StringComparison.OrdinalIgnoreCase)
                    && string.Equals(printer["PortName"]?.ToString(), portName, StringComparison.OrdinalIgnoreCase);
        }
        return false;
    }

    [SupportedOSPlatform("windows")]
    private static DriverInfo? FindPrinterDriver()
    {
        using var searcher = new ManagementObjectSearcher("SELECT Name FROM Win32_PrinterDriver");
        foreach (ManagementObject driver in searcher.Get())
        {
            using (driver)
            {
                var name = driver["Name"]?.ToString();
                if (name is null || !name.StartsWith(DriverName, StringComparison.OrdinalIgnoreCase)) continue;
                return new(name, ReadInstalledDriverVersion());
            }
        }
        return null;
    }

    private static string? FindDriverInf()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "drivers", "epson", "Driver", "EA5INSTMT88V.INF");
        return File.Exists(path) ? path : null;
    }

    private static async Task RunRequiredProcessAsync(
        string executable,
        IReadOnlyList<string> arguments,
        CancellationToken cancellationToken,
        IReadOnlyCollection<int> successCodes)
    {
        var startInfo = new ProcessStartInfo(executable)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException($"Windows could not start {Path.GetFileName(executable)}.");
        var output = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var error = process.StandardError.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);
        if (successCodes.Contains(process.ExitCode)) return;
        var details = string.Join(" ", new[] { await output, await error }.Where(value => !string.IsNullOrWhiteSpace(value))).Trim();
        throw new InvalidOperationException($"{Path.GetFileName(executable)} returned error code {process.ExitCode}{(details.Length > 0 ? $": {details}" : ".")}");
    }

    [SupportedOSPlatform("windows")]
    private static string? FindUninstallVersion(string displayName)
    {
        foreach (var root in new[]
        {
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"
        })
        {
            using var key = Registry.LocalMachine.OpenSubKey(root);
            if (key is null) continue;
            foreach (var childName in key.GetSubKeyNames())
            {
                using var child = key.OpenSubKey(childName);
                if (string.Equals(child?.GetValue("DisplayName")?.ToString(), displayName, StringComparison.OrdinalIgnoreCase))
                    return child?.GetValue("DisplayVersion")?.ToString();
            }
        }
        return null;
    }

    private static async Task<bool> CanConnectAsync(string address, int port, CancellationToken cancellationToken)
    {
        using var client = new TcpClient();
        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(TimeSpan.FromSeconds(4));
            await client.ConnectAsync(address, port, timeout.Token);
            return true;
        }
        catch { return false; }
    }

    private static void Validate(PrinterInstallRequest request)
    {
        if (string.IsNullOrWhiteSpace(request.PrinterName) || request.PrinterName.Length > 120
            || request.PrinterName.IndexOfAny(['\\', '/', ':', '*', '?', '"', '<', '>', '|']) >= 0)
            throw new ArgumentException("Enter a valid Windows printer name.");
        if (!IPAddress.TryParse(request.IpAddress, out var address) || address.AddressFamily != AddressFamily.InterNetwork)
            throw new ArgumentException("Enter a valid IPv4 address for the emulator computer.");
        if (request.Port is < 1 or > 65535) throw new ArgumentException("Enter a port between 1 and 65535.");
        if (request.SameComputer && request.IpAddress != "127.0.0.1")
            throw new ArgumentException("A same-computer setup must use 127.0.0.1.");
    }

    private static string MakePortName(string address, int port) => $"PPE_{address.Replace('.', '_')}_{port}";
    private static string EscapeWql(string value) => value.Replace("\\", "\\\\").Replace("'", "\\'");
    [SupportedOSPlatform("windows")]
    private static string ReadInstalledDriverVersion()
    {
        const string path = @"SYSTEM\CurrentControlSet\Control\Print\Environments\Windows x64\Drivers\Version-3\EPSON TM-T88V Receipt5";
        using var key = Registry.LocalMachine.OpenSubKey(path);
        return key?.GetValue("DriverVersion")?.ToString() ?? "Installed";
    }

    [SupportedOSPlatform("windows")]
    private static void TryDeletePrinter(string name)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher($"SELECT * FROM Win32_Printer WHERE Name='{EscapeWql(name)}'");
            foreach (ManagementObject item in searcher.Get()) using (item) item.Delete();
            Log($"Rolled back printer '{name}'.");
        }
        catch (Exception exception) { Log($"Printer rollback warning: {exception.Message}"); }
    }

    [SupportedOSPlatform("windows")]
    private static void TryDeletePort(string name)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_TCPIPPrinterPort");
            foreach (ManagementObject item in searcher.Get())
                using (item) if (string.Equals(item["Name"]?.ToString(), name, StringComparison.OrdinalIgnoreCase)) item.Delete();
            Log($"Rolled back printer port '{name}'.");
        }
        catch (Exception exception) { Log($"Port rollback warning: {exception.Message}"); }
    }

    public static int PrintTestReceipt(string printerName)
    {
        Validate(new(printerName, "127.0.0.1", 9100, true));
        var bytes = Encoding.ASCII.GetBytes("\x1b@\x1ba\x01POS PRINTER EMULATOR\nPrinter setup test\n\nIf you can see this receipt,\nWindows printer setup is working.\n\n\x1dV\x00");
        if (!RawPrinter.Send(printerName, bytes)) throw new InvalidOperationException("Windows could not send the test receipt to the printer.");
        return 0;
    }

    private static string FriendlyMessage(Exception exception) => exception switch
    {
        UnauthorizedAccessException => "Windows administrator approval is required to install the printer.",
        SocketException => "The printer was added, but the POS Printer Emulator is not reachable at the selected IP address and port. The incomplete setup was removed.",
        FileNotFoundException => "The Epson driver package required for automatic installation is missing. Reinstall POS Printer Emulator and try again.",
        ArgumentException => exception.Message,
        _ => $"Windows could not finish installing the printer: {exception.Message}"
    };

    private static void Log(string message)
    {
        try
        {
            var directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "POSPrinterEmulator");
            Directory.CreateDirectory(directory);
            File.AppendAllText(Path.Combine(directory, "printer-setup.log"), $"{DateTimeOffset.Now:O} {message}{Environment.NewLine}");
        }
        catch { }
    }

    public static string ReadLog()
    {
        try
        {
            var path = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "POSPrinterEmulator", "printer-setup.log");
            return File.Exists(path) ? File.ReadAllText(path) : "No printer setup attempts have been recorded.";
        }
        catch (Exception exception)
        {
            return $"Printer setup log could not be read: {exception.Message}";
        }
    }

    private sealed record DriverInfo(string Name, string Version);
}

internal static class RawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private sealed class DocInfo { public string DocumentName = "POS Printer Emulator test receipt"; public string? OutputFile; public string DataType = "RAW"; }
    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)] private static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError = true)] private static extern bool ClosePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)] private static extern int StartDocPrinter(IntPtr handle, int level, [In] DocInfo info);
    [DllImport("winspool.drv", SetLastError = true)] private static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] private static extern bool StartPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] private static extern bool EndPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] private static extern bool WritePrinter(IntPtr handle, byte[] bytes, int count, out int written);

    public static bool Send(string printerName, byte[] bytes)
    {
        if (!OpenPrinter(printerName, out var printer, IntPtr.Zero)) return false;
        try
        {
            if (StartDocPrinter(printer, 1, new DocInfo()) == 0) return false;
            try
            {
                return StartPagePrinter(printer) && WritePrinter(printer, bytes, bytes.Length, out var written)
                    && written == bytes.Length && EndPagePrinter(printer);
            }
            finally { EndDocPrinter(printer); }
        }
        finally { ClosePrinter(printer); }
    }
}
