using System.ComponentModel;
using System.Diagnostics;
using System.Management;
using System.Net;
using System.Net.Http.Json;
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

public sealed record PrinterPortSelection(
    int Port,
    bool AutomaticallyAdjusted,
    string Message);

public static class PrinterSetupManager
{
    public const string DriverName = "EPSON TM-T88V Receipt5";
    public const string RecommendedApdVersion = "5.13.0.0";
    public const string RecommendedDriverVersion = "5.12.0.0";
    public const string RecommendedStatusApiVersion = "6.7.0.0";
    private const string QueueComment = "Managed by POS Printer Emulator";
    private const string LocalListenerApi = "http://127.0.0.1:5187/api/listeners";
    private const string LocalAvailablePortApi = "http://127.0.0.1:5187/api/printer-setup/available-port";
    private const string WindowsPrinterRegistryPath = @"SYSTEM\CurrentControlSet\Control\Print\Printers";
    private const string WindowsTcpPortRegistryPath = @"SYSTEM\CurrentControlSet\Control\Print\Monitors\Standard TCP/IP Port\Ports";
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

    public static PrinterPortSelection GetAvailablePort(
        string printerName,
        string ipAddress,
        int startingPort = PrinterListenerDefaults.DefaultPort,
        IEnumerable<PrinterListenerConfiguration>? configuredListeners = null)
    {
        Validate(new PrinterInstallRequest(printerName, ipAddress, startingPort, ipAddress == "127.0.0.1"));
        HashSet<int> assignedPorts = OperatingSystem.IsWindows()
            ? GetAssignedPrinterPorts(printerName)
            : [];
        var listeners = configuredListeners?.ToArray() ?? [];

        var port = FindFirstAvailablePort(startingPort, assignedPorts, ipAddress, listeners);
        if (port == startingPort)
        {
            return OperatingSystem.IsWindows()
                ? new(port, false, $"Port {port} is available.")
                : new(port, false, $"Port {port} is the default connection port.");
        }

        var reason = assignedPorts.Contains(startingPort)
            ? $"Port {startingPort} is already assigned to another Windows printer."
            : $"Port {startingPort} is assigned to an emulator listener that cannot be reused for {ipAddress} with the Epson TM-T88V driver.";
        return new(port, true, $"{reason} Port {port} was selected automatically.");
    }

    internal static int FindFirstAvailablePort(int startingPort, IEnumerable<int> assignedPorts)
    {
        if (startingPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(startingPort), "The starting port must be between 1 and 65535.");

        var assigned = assignedPorts.ToHashSet();
        for (var candidate = startingPort; candidate <= 65535; candidate++)
        {
            if (!assigned.Contains(candidate)) return candidate;
        }

        throw new InvalidOperationException($"No available printer port was found at or above {startingPort}.");
    }

    internal static int FindFirstAvailablePort(
        int startingPort,
        IEnumerable<int> assignedPorts,
        string requestedAddress,
        IEnumerable<PrinterListenerConfiguration> configuredListeners)
    {
        var reservedPorts = assignedPorts.ToHashSet();
        reservedPorts.UnionWith(configuredListeners
            .Where(listener => !IsListenerCompatibleForPrinterSetup(listener, requestedAddress))
            .Select(listener => listener.Port));
        return FindFirstAvailablePort(startingPort, reservedPorts);
    }

    internal static bool IsListenerCompatibleForPrinterSetup(
        PrinterListenerConfiguration listener,
        string requestedAddress) =>
        IsListenerBindCompatible(listener.BindAddress, requestedAddress) &&
        string.Equals(listener.ProfileId, PrinterProfileService.EpsonTmT88VId, StringComparison.OrdinalIgnoreCase);

    internal static bool IsListenerBindCompatible(string bindAddress, string requestedAddress)
    {
        if (!IPAddress.TryParse(bindAddress, out var bind) || bind.AddressFamily != AddressFamily.InterNetwork ||
            !IPAddress.TryParse(requestedAddress, out var requested) || requested.AddressFamily != AddressFamily.InterNetwork)
        {
            return false;
        }

        return bind.Equals(IPAddress.Any) || bind.Equals(requested);
    }

    public static async Task<int> InstallFromFilesAsync(string requestPath, string resultPath, CancellationToken cancellationToken)
    {
        PrinterInstallResult result;
        PrinterInstallRequest? request = null;
        try
        {
            if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Printer setup requires Windows.");
            request = JsonSerializer.Deserialize<PrinterInstallRequest>(
                await File.ReadAllTextAsync(requestPath, cancellationToken), JsonOptions)
                ?? throw new InvalidOperationException("The printer setup request was empty.");
            Validate(request);
            result = await InstallAsync(request, cancellationToken);
        }
        catch (Exception exception)
        {
            Log($"Printer installation failed: {exception}");
            result = new(false, FriendlyMessage(exception), request?.PrinterName ?? "POS Printer Emulator",
                request?.IpAddress ?? "127.0.0.1", request?.Port ?? PrinterListenerDefaults.DefaultPort,
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
        string? createdListenerId = null;
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

            EnsurePortIsAvailable(request.PrinterName, request.Port);
            createdListenerId = await EnsureEmulatorListenerAsync(request, cancellationToken);
            createdPort = EnsureTcpPort(portName, request.IpAddress, request.Port);
            EnsurePortIsAvailable(request.PrinterName, request.Port);
            createdPrinter = EnsurePrinter(request.PrinterName, portName);
            EnsurePortIsAvailable(request.PrinterName, request.Port);

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
            if (createdListenerId is not null)
                await TryDeleteSetupListenerAsync(createdListenerId, cancellationToken);
            throw;
        }
    }

    private static async Task<string?> EnsureEmulatorListenerAsync(
        PrinterInstallRequest request,
        CancellationToken cancellationToken)
    {
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
        using var listResponse = await client.GetAsync(LocalListenerApi, cancellationToken);
        if (listResponse.StatusCode == HttpStatusCode.Forbidden)
        {
            if (request.Port == PrinterListenerDefaults.DefaultPort)
            {
                await RevalidateDefaultListenerAsync(client, request, cancellationToken);
                return null;
            }

            throw new InvalidOperationException(
                $"Installing an additional printer on port {request.Port} requires an Enterprise License so the emulator can listen on that port.");
        }

        listResponse.EnsureSuccessStatusCode();
        var collection = await listResponse.Content.ReadFromJsonAsync<PrinterListenerCollectionResponse>(
            JsonOptions,
            cancellationToken) ?? throw new InvalidOperationException("The emulator did not return its printer listener configuration.");
        var existing = collection.Listeners.FirstOrDefault(listener => listener.Port == request.Port);
        if (existing is not null)
        {
            if (!IsListenerBindCompatible(existing.BindAddress, request.IpAddress))
            {
                throw new InvalidOperationException(
                    $"Port {request.Port} is assigned to emulator listener '{existing.Name}', but its bind address " +
                    $"{existing.BindAddress} cannot accept connections for {request.IpAddress}. Return to the printer setup summary so another port can be selected.");
            }

            if (!string.Equals(existing.ProfileId, PrinterProfileService.EpsonTmT88VId, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    $"Port {request.Port} is assigned to emulator listener '{existing.Name}', but it uses a printer profile that is not compatible " +
                    $"with the {DriverName} driver. Return to the printer setup summary so another port can be selected.");
            }

            if (!existing.Listening)
            {
                using var startResponse = await client.PostAsync(
                    $"{LocalListenerApi}/{Uri.EscapeDataString(existing.Id)}/start",
                    content: null,
                    cancellationToken);
                startResponse.EnsureSuccessStatusCode();
            }
            return null;
        }

        if (request.Port == PrinterListenerDefaults.DefaultPort)
        {
            throw new InvalidOperationException(
                $"The emulator's default printer listener on port {request.Port} is unavailable. Return to the printer setup summary so another port can be selected.");
        }

        var input = BuildSetupListenerInput(request.PrinterName, request.Port);
        using var createResponse = await client.PostAsJsonAsync(LocalListenerApi, input, JsonOptions, cancellationToken);
        createResponse.EnsureSuccessStatusCode();
        var created = await createResponse.Content.ReadFromJsonAsync<PrinterListenerResponse>(
            JsonOptions,
            cancellationToken) ?? throw new InvalidOperationException("The emulator did not confirm the new printer listener.");
        if (!created.Listening)
        {
            await TryDeleteSetupListenerAsync(created.Id, cancellationToken);
            throw new InvalidOperationException($"The emulator created port {request.Port}, but it did not start listening.");
        }

        Log($"Created Enterprise printer listener '{created.Name}' on port {created.Port} for Windows printer setup.");
        return created.Id;
    }

    private static async Task RevalidateDefaultListenerAsync(
        HttpClient client,
        PrinterInstallRequest request,
        CancellationToken cancellationToken)
    {
        var requestUri = $"{LocalAvailablePortApi}?printerName={Uri.EscapeDataString(request.PrinterName)}" +
            $"&ipAddress={Uri.EscapeDataString(request.IpAddress)}&startingPort={request.Port}";
        using var response = await client.GetAsync(requestUri, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(
                $"The emulator's default printer listener on port {request.Port} can no longer be reused for this setup. Return to the printer setup summary so another port can be selected.");
        }

        var selection = await response.Content.ReadFromJsonAsync<PrinterPortSelection>(JsonOptions, cancellationToken)
            ?? throw new InvalidOperationException("The emulator did not confirm the selected printer port.");
        if (selection.Port != request.Port)
        {
            throw new InvalidOperationException($"{selection.Message} Return to the printer setup summary before continuing.");
        }
    }

    internal static PrinterListenerInput BuildSetupListenerInput(string printerName, int port)
    {
        var suffix = $" - {port}";
        var baseName = printerName.Trim();
        if (baseName.Length > 80 - suffix.Length) baseName = baseName[..(80 - suffix.Length)];
        var name = baseName + suffix;
        return new PrinterListenerInput(
            name,
            PrinterListenerDefaults.DefaultBindAddress,
            port,
            PrinterProfileService.EpsonTmT88VId,
            true,
            PrinterListenerDefaults.DefaultIdleJobTimeoutMilliseconds,
            PrinterListenerDefaults.DefaultMaximumJobBytes,
            new PrinterListenerBufferConfiguration());
    }

    private static async Task TryDeleteSetupListenerAsync(string listenerId, CancellationToken cancellationToken)
    {
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
            using var response = await client.DeleteAsync(
                $"{LocalListenerApi}/{Uri.EscapeDataString(listenerId)}",
                cancellationToken);
            if (!response.IsSuccessStatusCode)
                Log($"Printer listener rollback warning: the local service returned HTTP {(int)response.StatusCode}.");
        }
        catch (Exception exception)
        {
            Log($"Printer listener rollback warning: {exception.Message}");
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
    private static void EnsurePortIsAvailable(string printerName, int port)
    {
        if (GetAssignedPrinterPorts(printerName).Contains(port))
        {
            throw new PrinterPortConflictException(port);
        }
    }

    [SupportedOSPlatform("windows")]
    private static HashSet<int> GetAssignedPrinterPorts(string excludedPrinterName)
    {
        var assignedPortNames = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        AddAssignedPrinterPortNamesFromRegistry(assignedPortNames, excludedPrinterName);
        try
        {
            using var printerSearcher = new ManagementObjectSearcher("SELECT Name, PortName FROM Win32_Printer");
            foreach (ManagementObject printer in printerSearcher.Get())
            {
                using (printer)
                {
                    if (string.Equals(printer["Name"]?.ToString(), excludedPrinterName, StringComparison.OrdinalIgnoreCase))
                        continue;
                    foreach (var name in (printer["PortName"]?.ToString() ?? string.Empty)
                                 .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
                        assignedPortNames.Add(name);
                }
            }
        }
        catch (ManagementException exception)
        {
            Log($"Windows printer WMI lookup warning: {exception.Message}");
        }

        var assignedPorts = new HashSet<int>();
        AddAssignedTcpPortsFromRegistry(assignedPortNames, assignedPorts);
        try
        {
            using var portSearcher = new ManagementObjectSearcher("SELECT Name, PortNumber FROM Win32_TCPIPPrinterPort");
            foreach (ManagementObject port in portSearcher.Get())
            {
                using (port)
                {
                    if (!assignedPortNames.Contains(port["Name"]?.ToString() ?? string.Empty)) continue;
                    if (int.TryParse(port["PortNumber"]?.ToString(), out var portNumber)) assignedPorts.Add(portNumber);
                }
            }
        }
        catch (ManagementException exception)
        {
            Log($"Windows TCP/IP port WMI lookup warning: {exception.Message}");
        }
        return assignedPorts;
    }

    [SupportedOSPlatform("windows")]
    private static void AddAssignedPrinterPortNamesFromRegistry(
        HashSet<string> assignedPortNames,
        string excludedPrinterName)
    {
        try
        {
            using var printers = Registry.LocalMachine.OpenSubKey(WindowsPrinterRegistryPath);
            if (printers is null) return;
            foreach (var printerName in printers.GetSubKeyNames())
            {
                if (string.Equals(printerName, excludedPrinterName, StringComparison.OrdinalIgnoreCase)) continue;
                using var printer = printers.OpenSubKey(printerName);
                foreach (var portName in (printer?.GetValue("Port")?.ToString() ?? string.Empty)
                             .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
                    assignedPortNames.Add(portName);
            }
        }
        catch (UnauthorizedAccessException exception)
        {
            Log($"Windows printer registry lookup warning: {exception.Message}");
        }
    }

    [SupportedOSPlatform("windows")]
    private static void AddAssignedTcpPortsFromRegistry(
        HashSet<string> assignedPortNames,
        HashSet<int> assignedPorts)
    {
        try
        {
            using var ports = Registry.LocalMachine.OpenSubKey(WindowsTcpPortRegistryPath);
            if (ports is null) return;
            foreach (var portName in ports.GetSubKeyNames())
            {
                if (!assignedPortNames.Contains(portName)) continue;
                using var port = ports.OpenSubKey(portName);
                if (int.TryParse(port?.GetValue("PortNumber")?.ToString(), out var portNumber))
                    assignedPorts.Add(portNumber);
            }
        }
        catch (UnauthorizedAccessException exception)
        {
            Log($"Windows TCP/IP port registry lookup warning: {exception.Message}");
        }
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

        WindowsPrinterQueue.Create(printerName, portName, DriverName, QueueComment);
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
            using (var printerSearcher = new ManagementObjectSearcher("SELECT PortName FROM Win32_Printer"))
            {
                foreach (ManagementObject printer in printerSearcher.Get())
                {
                    using (printer)
                    {
                        var assignedNames = (printer["PortName"]?.ToString() ?? string.Empty)
                            .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
                        if (!assignedNames.Contains(name, StringComparer.OrdinalIgnoreCase)) continue;
                        Log($"Port rollback skipped because Windows printer port '{name}' is now assigned to another printer.");
                        return;
                    }
                }
            }

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
        RawPrinter.Send(printerName, bytes);
        return 0;
    }

    private static string FriendlyMessage(Exception exception) => exception switch
    {
        UnauthorizedAccessException => "Windows administrator approval is required to install the printer.",
        SocketException => "The printer was added, but the POS Printer Emulator is not reachable at the selected IP address and port. The incomplete setup was removed.",
        Win32Exception { NativeErrorCode: 5 } => "Windows administrator approval is required to install the printer.",
        Win32Exception nativeException => $"Windows could not add the printer queue (Windows error {nativeException.NativeErrorCode}).",
        FileNotFoundException => "The Epson driver package required for automatic installation is missing. Reinstall POS Printer Emulator and try again.",
        PrinterPortConflictException conflict => $"Port {conflict.Port} was assigned to another Windows printer before installation could finish. Return to the summary so the wizard can select the next available port, then try again.",
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

    private sealed class PrinterPortConflictException(int port) : InvalidOperationException
    {
        public int Port { get; } = port;
    }
}

internal sealed record PrinterQueueDefinition(
    string PrinterName,
    string PortName,
    string DriverName,
    string PrintProcessor,
    string DataType,
    string Comment);

internal static class WindowsPrinterQueue
{
    internal const string DefaultPrintProcessor = "winprint";
    internal const string RawDataType = "RAW";

    internal static PrinterQueueDefinition CreateDefinition(
        string printerName,
        string portName,
        string driverName,
        string comment) => new(
            printerName,
            portName,
            driverName,
            DefaultPrintProcessor,
            RawDataType,
            comment);

    [SupportedOSPlatform("windows")]
    public static void Create(string printerName, string portName, string driverName, string comment)
    {
        var definition = CreateDefinition(printerName, portName, driverName, comment);
        var printer = new PrinterInfo2
        {
            PrinterName = definition.PrinterName,
            PortName = definition.PortName,
            DriverName = definition.DriverName,
            Comment = definition.Comment,
            PrintProcessor = definition.PrintProcessor,
            DataType = definition.DataType,
            Priority = 1,
            DefaultPriority = 1
        };

        var handle = AddPrinter(null, 2, ref printer);
        if (handle == IntPtr.Zero)
        {
            var error = Marshal.GetLastWin32Error();
            throw new Win32Exception(error, $"Windows could not create the printer queue (Windows error {error}).");
        }

        ClosePrinter(handle);
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct PrinterInfo2
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string? ServerName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? PrinterName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? ShareName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? PortName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? DriverName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? Comment;
        [MarshalAs(UnmanagedType.LPWStr)] public string? Location;
        public IntPtr DeviceMode;
        [MarshalAs(UnmanagedType.LPWStr)] public string? SeparatorFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string? PrintProcessor;
        [MarshalAs(UnmanagedType.LPWStr)] public string? DataType;
        [MarshalAs(UnmanagedType.LPWStr)] public string? Parameters;
        public IntPtr SecurityDescriptor;
        public uint Attributes;
        public uint Priority;
        public uint DefaultPriority;
        public uint StartTime;
        public uint UntilTime;
        public uint Status;
        public uint JobCount;
        public uint AveragePagesPerMinute;
    }

    [DllImport("winspool.drv", EntryPoint = "AddPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern IntPtr AddPrinter(string? serverName, uint level, ref PrinterInfo2 printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr handle);
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

    public static void Send(string printerName, byte[] bytes)
    {
        if (!OpenPrinter(printerName, out var printer, IntPtr.Zero))
        {
            ThrowSpoolerError("open the Windows printer");
        }

        try
        {
            if (StartDocPrinter(printer, 1, new DocInfo()) == 0)
            {
                ThrowSpoolerError("start the test receipt print job");
            }

            try
            {
                if (!StartPagePrinter(printer))
                {
                    ThrowSpoolerError("start the test receipt page");
                }

                if (!WritePrinter(printer, bytes, bytes.Length, out var written))
                {
                    ThrowSpoolerError("send the test receipt bytes to Windows");
                }

                if (written != bytes.Length)
                {
                    throw new IOException($"Windows accepted only {written} of {bytes.Length} test receipt bytes.");
                }

                if (!EndPagePrinter(printer))
                {
                    ThrowSpoolerError("finish the test receipt page");
                }
            }
            finally { EndDocPrinter(printer); }
        }
        finally { ClosePrinter(printer); }
    }

    private static void ThrowSpoolerError(string operation)
    {
        var error = Marshal.GetLastWin32Error();
        throw new Win32Exception(error, $"Windows could not {operation} (Windows error {error}).");
    }
}
