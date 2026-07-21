using System.Diagnostics;
using System.Collections.Concurrent;
using System.IO.Compression;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.Versioning;
using System.ServiceProcess;
using System.Text;
using System.Text.Json;

namespace ReceiptEmulator;

public static class DiagnosticStates
{
    public const string Passed = "Passed";
    public const string AttentionNeeded = "AttentionNeeded";
    public const string Failed = "Failed";
    public const string Skipped = "Skipped";
}

public sealed record ConnectionDiagnosticCheck(
    string Id,
    string Area,
    string Title,
    string Status,
    string Summary,
    string? TechnicalDetails = null,
    string? Action = null,
    string? ListenerId = null);

public sealed record PosConnectionDetail(string ListenerId, string PrinterName, string IpAddress, int Port, bool LocalOnly);

public sealed record ConnectionDiagnosticReport(
    string PackageId,
    DateTimeOffset GeneratedAt,
    string ApplicationVersion,
    string WindowsVersion,
    IReadOnlyList<ConnectionDiagnosticCheck> Checks,
    IReadOnlyList<PosConnectionDetail> ConnectionDetails,
    int Passed,
    int AttentionNeeded,
    int Failed,
    int Skipped);

public sealed record SupportPackageFile(string FileName, string Category, string Description);

public sealed record SupportPackagePreview(
    string PackageId,
    IReadOnlyList<SupportPackageFile> Files,
    IReadOnlyList<string> IncludedCategories,
    IReadOnlyList<string> ExcludedCategories);

public sealed class ConnectionDiagnosticsService(
    PrinterListenerManager listeners,
    LicenseService license,
    PrinterProfileService profiles,
    SupportLogProvider logs,
    ILogger<ConnectionDiagnosticsService> logger)
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web) { WriteIndented = true };
    private readonly ConcurrentDictionary<string, ConnectionDiagnosticReport> _reports = new(StringComparer.Ordinal);

    public async Task<ConnectionDiagnosticReport> RunAsync(CancellationToken cancellationToken = default)
    {
        var checks = new List<ConnectionDiagnosticCheck>();
        var listenerStatus = listeners.GetStatus();
        var availableProfiles = profiles.GetStatus().Profiles.Select(profile => profile.Id).ToHashSet(StringComparer.OrdinalIgnoreCase);
        var packageId = $"PPE-DIAG-{DateTime.UtcNow:yyyyMMdd}-{Convert.ToHexString(System.Security.Cryptography.RandomNumberGenerator.GetBytes(4))}";

        checks.Add(new("service", "Application", "POS Printer Emulator service", DiagnosticStates.Passed,
            "The emulator service is running and answered this diagnostic request.", $"Process: {Environment.ProcessId}; runtime: {Environment.Version}"));
        checks.Add(new("viewer", "Application", "Local receipt viewer", DiagnosticStates.Passed,
            "The local viewer is available on this computer.", "The diagnostic request reached the local HTTP API on 127.0.0.1."));
        checks.Add(CheckStorage());

        var activePorts = GetActiveTcpPorts();
        foreach (var listener in listenerStatus.Listeners)
        {
            cancellationToken.ThrowIfCancellationRequested();
            checks.Add(CheckListener(listener, activePorts));
            checks.Add(availableProfiles.Contains(listener.Configuration.ProfileId)
                ? new($"profile-{listener.Configuration.Id}", "Profiles", $"Printer profile — {listener.Configuration.Name}", DiagnosticStates.Passed,
                    "The listener's assigned printer profile is available.", $"Profile ID: {listener.Configuration.ProfileId}")
                : new($"profile-{listener.Configuration.Id}", "Profiles", $"Printer profile — {listener.Configuration.Name}", DiagnosticStates.Failed,
                    "The listener's assigned printer profile could not be found.", $"Profile ID: {listener.Configuration.ProfileId}", "OpenPrinterSetupWizard"));
            checks.Add(await ProbeListenerAsync(listener, cancellationToken));
            checks.Add(CheckNetworkScope(listener));
            checks.Add(CheckRecentActivity(listener));
        }

        if (listenerStatus.Listeners.Count == 0)
            checks.Add(new("listeners-none", "Listeners", "Printer listeners", DiagnosticStates.Failed,
                "No printer listener is configured.", null, "OpenPrinterSetupWizard"));

        if (OperatingSystem.IsWindows())
        {
            checks.Add(CheckWindowsService("Spooler", "Windows Print Spooler", "spooler"));
            checks.Add(CheckWindowsService("ReceiptLab", "POS Printer Emulator Windows service registration", "windows-service"));
            checks.Add(CheckPrinterQueues());
            checks.Add(CheckEpsonDriver());
            checks.Add(await CheckFirewallAsync(cancellationToken));
        }
        else
        {
            checks.Add(new("windows-printer", "Windows printer", "Windows printer checks", DiagnosticStates.Skipped,
                "Windows printer, driver, spooler, and firewall checks are available only on Windows."));
        }

        var details = listenerStatus.Listeners.Select(listener => new PosConnectionDetail(
            listener.Configuration.Id,
            listener.Configuration.Name,
            ConnectionAddress(listener.Configuration.BindAddress),
            listener.Configuration.Port,
            IPAddress.IsLoopback(ParseAddress(listener.Configuration.BindAddress)))).ToArray();

        var report = new ConnectionDiagnosticReport(
            packageId,
            DateTimeOffset.UtcNow,
            ProductInfo.Version,
            Environment.OSVersion.VersionString,
            checks,
            details,
            checks.Count(check => check.Status == DiagnosticStates.Passed),
            checks.Count(check => check.Status == DiagnosticStates.AttentionNeeded),
            checks.Count(check => check.Status == DiagnosticStates.Failed),
            checks.Count(check => check.Status == DiagnosticStates.Skipped));
        _reports[packageId] = report;
        foreach (var expired in _reports.Where(item => item.Value.GeneratedAt < DateTimeOffset.UtcNow.AddMinutes(-30)).Select(item => item.Key).ToArray())
            _reports.TryRemove(expired, out _);
        return report;
    }

    public bool TryGetReport(string packageId, out ConnectionDiagnosticReport report) =>
        _reports.TryGetValue(packageId, out report!);

    public SupportPackagePreview PreviewPackage(ConnectionDiagnosticReport report) => new(
        report.PackageId,
        [
            new("summary.txt", "Diagnostic results", "Plain-language diagnostic results and redacted connection guidance."),
            new("manifest.json", "Machine-readable manifest", "Application, Windows, listener, driver, queue, and firewall check results."),
            new("application.log", "Application log", "Recent application events after deterministic redaction."),
            new("printer-setup.log", "Printer setup log", "Recent Printer Setup Wizard events after deterministic redaction.")
        ],
        ["application and Windows versions", "license tier only", "diagnostic results", "listener/profile summaries", "driver, queue, spooler, and firewall summaries", "recent redacted application errors"],
        ["receipt text and raw receipt bytes", "saved receipt history and imported captures", "activation and maintenance keys", "customer contact and payment information", "IP addresses", "Windows user names and full local paths"]);

    public async Task<byte[]> CreatePackageAsync(ConnectionDiagnosticReport report, CancellationToken cancellationToken = default)
    {
        await using var memory = new MemoryStream();
        using (var archive = new ZipArchive(memory, ZipArchiveMode.Create, leaveOpen: true))
        {
            await WriteEntryAsync(archive, "summary.txt", RedactedSummary(report), cancellationToken);
            await WriteEntryAsync(archive, "manifest.json", JsonSerializer.Serialize(RedactedReport(report), JsonOptions), cancellationToken);
            await WriteEntryAsync(archive, "application.log", SupportRequestService.Redact(logs.ReadLog()), cancellationToken);
            await WriteEntryAsync(archive, "printer-setup.log", SupportRequestService.Redact(PrinterSetupManager.ReadLog()), cancellationToken);
        }
        return memory.ToArray();
    }

    private ConnectionDiagnosticCheck CheckStorage()
    {
        var probe = Path.Combine(license.RootPath, $".diagnostic-{Guid.NewGuid():N}.tmp");
        try
        {
            Directory.CreateDirectory(license.RootPath);
            File.WriteAllText(probe, "POS Printer Emulator storage diagnostic", Encoding.UTF8);
            File.Delete(probe);
            return new("storage", "Application", "Application data storage", DiagnosticStates.Passed,
                "The emulator can read and write its local application-data folder.");
        }
        catch (Exception exception)
        {
            logger.LogWarning(exception, "Application storage diagnostic failed");
            try { if (File.Exists(probe)) File.Delete(probe); } catch { }
            return new("storage", "Application", "Application data storage", DiagnosticStates.Failed,
                "Windows is preventing the emulator from writing its local data.", exception.Message, "RepairInstallation");
        }
    }

    private static ConnectionDiagnosticCheck CheckListener(PrinterListenerRuntimeStatus listener, ISet<int> activePorts)
    {
        var configuration = listener.Configuration;
        if (!configuration.Enabled)
            return new($"listener-{configuration.Id}", "Listeners", configuration.Name, DiagnosticStates.AttentionNeeded,
                $"This printer listener is turned off. POS software cannot connect to port {configuration.Port} until it is started.", listener.LastError);
        if (listener.Listening)
            return new($"listener-{configuration.Id}", "Listeners", configuration.Name, DiagnosticStates.Passed,
                $"The printer listener is accepting RAW TCP connections on port {configuration.Port}.", $"State: {listener.State}; bind: {configuration.BindAddress}");
        var conflict = activePorts.Contains(configuration.Port);
        return new($"listener-{configuration.Id}", "Listeners", configuration.Name, DiagnosticStates.Failed,
            conflict ? $"Port {configuration.Port} is already in use, so this printer listener could not start." : "This configured printer listener is not accepting connections.",
            listener.LastError ?? $"State: {listener.State}", "RestartListener", configuration.Id);
    }

    private static async Task<ConnectionDiagnosticCheck> ProbeListenerAsync(PrinterListenerRuntimeStatus listener, CancellationToken cancellationToken)
    {
        var configuration = listener.Configuration;
        if (!listener.Listening)
            return new($"probe-{configuration.Id}", "Connection", $"Local health probe — {configuration.Name}", DiagnosticStates.Skipped,
                "The health probe was skipped because the listener is not running.", null, "RestartListener", configuration.Id);
        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(TimeSpan.FromSeconds(2));
            using var client = new TcpClient(AddressFamily.InterNetwork);
            var address = configuration.BindAddress == PrinterListenerDefaults.DefaultBindAddress ? IPAddress.Loopback.ToString() : configuration.BindAddress;
            await client.ConnectAsync(address, configuration.Port, timeout.Token);
            return new($"probe-{configuration.Id}", "Connection", $"Local health probe — {configuration.Name}", DiagnosticStates.Passed,
                $"The emulator accepted a local connection on port {configuration.Port}. No print data was sent and no print job was created.");
        }
        catch (Exception exception) when (exception is SocketException or OperationCanceledException)
        {
            return new($"probe-{configuration.Id}", "Connection", $"Local health probe — {configuration.Name}", DiagnosticStates.Failed,
                $"The emulator did not accept a local connection on port {configuration.Port} within two seconds.", exception.Message, "RestartListener", configuration.Id);
        }
    }

    private static ConnectionDiagnosticCheck CheckNetworkScope(PrinterListenerRuntimeStatus listener)
    {
        var configuration = listener.Configuration;
        var address = ParseAddress(configuration.BindAddress);
        if (IPAddress.IsLoopback(address))
            return new($"network-{configuration.Id}", "Connection", $"Network access — {configuration.Name}", DiagnosticStates.AttentionNeeded,
                "This listener accepts connections only from POS software on this computer.", $"Bind address: {configuration.BindAddress}");
        return new($"network-{configuration.Id}", "Connection", $"Network access — {configuration.Name}", DiagnosticStates.Passed,
            "This listener is configured to accept connections from another computer on the private or domain network.", $"Bind address: {configuration.BindAddress}");
    }

    private static ConnectionDiagnosticCheck CheckRecentActivity(PrinterListenerRuntimeStatus listener)
    {
        var configuration = listener.Configuration;
        if (listener.Counters.FailedJobs > 0 || listener.Counters.RejectedJobs > 0)
            return new($"activity-{configuration.Id}", "Jobs", $"Recent processing — {configuration.Name}", DiagnosticStates.AttentionNeeded,
                $"The listener reports {listener.Counters.FailedJobs} failed and {listener.Counters.RejectedJobs} rejected print jobs.",
                $"Received: {listener.Counters.ReceivedJobs}; completed: {listener.Counters.CompletedJobs}; last error: {listener.LastError ?? "None"}");
        if (listener.LastConnection is null)
            return new($"activity-{configuration.Id}", "Jobs", $"Recent processing — {configuration.Name}", DiagnosticStates.AttentionNeeded,
                "No POS connection has been recorded since this listener started. Confirm the displayed IP address and port in the POS software.");
        return new($"activity-{configuration.Id}", "Jobs", $"Recent processing — {configuration.Name}", DiagnosticStates.Passed,
            "The listener has received connections without recording rejected or failed print jobs.", $"Last connection: {listener.LastConnection:O}; completed jobs: {listener.Counters.CompletedJobs}");
    }

    [SupportedOSPlatform("windows")]
    private static ConnectionDiagnosticCheck CheckWindowsService(string serviceName, string title, string id)
    {
        try
        {
            using var service = new ServiceController(serviceName);
            var status = service.Status;
            return status == ServiceControllerStatus.Running
                ? new(id, "Windows", title, DiagnosticStates.Passed, $"{title} is running.", $"Windows service: {serviceName}; status: {status}")
                : new(id, "Windows", title, DiagnosticStates.Failed, $"{title} is {status}.", $"Windows service: {serviceName}; status: {status}", "RepairInstallation");
        }
        catch (InvalidOperationException exception)
        {
            return new(id, "Windows", title, DiagnosticStates.Failed, $"Windows could not find or query {title}.", exception.Message, "RepairInstallation");
        }
    }

    [SupportedOSPlatform("windows")]
    private static ConnectionDiagnosticCheck CheckPrinterQueues()
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT Name, PortName, DriverName, WorkOffline, PrinterStatus FROM Win32_Printer");
            var queues = searcher.Get().Cast<ManagementObject>().Select(queue => new
            {
                Name = Convert.ToString(queue["Name"]) ?? "Unnamed printer",
                Port = Convert.ToString(queue["PortName"]) ?? "Unknown",
                Driver = Convert.ToString(queue["DriverName"]) ?? "Unknown"
            }).Where(queue => queue.Driver.Contains("EPSON TM-T88V", StringComparison.OrdinalIgnoreCase) || queue.Name.Contains("POS Printer Emulator", StringComparison.OrdinalIgnoreCase)).ToArray();
            return queues.Length > 0
                ? new("printer-queue", "Windows printer", "Windows printer queue", DiagnosticStates.Passed,
                    $"Windows recognizes {queues.Length} POS Printer Emulator/Epson receipt-printer queue(s).",
                    string.Join("; ", queues.Select(queue => $"{queue.Name} -> {queue.Port} / {queue.Driver}")))
                : new("printer-queue", "Windows printer", "Windows printer queue", DiagnosticStates.AttentionNeeded,
                    "No POS Printer Emulator or Epson TM-T88V Windows printer queue was found.", null, "OpenPrinterSetupWizard");
        }
        catch (Exception exception)
        {
            return new("printer-queue", "Windows printer", "Windows printer queue", DiagnosticStates.Failed,
                "Windows printer queues could not be inspected.", exception.Message, "OpenPrinterSetupWizard");
        }
    }

    private static ConnectionDiagnosticCheck CheckEpsonDriver()
    {
        var status = PrinterSetupManager.GetStatus();
        if (status.DriverInstalled)
            return new("epson-driver", "Windows printer", "Epson Advanced Printer Driver", DiagnosticStates.Passed,
                "The tested Epson TM-T88V Receipt5 driver is installed.", $"APD: {status.ApdVersion ?? "Unknown"}; driver: {status.DriverVersion ?? "Unknown"}; Status API: {status.StatusApiVersion ?? "Unknown"}");
        return new("epson-driver", "Windows printer", "Epson Advanced Printer Driver", DiagnosticStates.Failed,
            status.DriverPackageAvailable ? "The Epson driver is missing, but the bundled repair package is available." : "The Epson driver and bundled repair package are unavailable.",
            status.Message, "OpenPrinterSetupWizard");
    }

    private static async Task<ConnectionDiagnosticCheck> CheckFirewallAsync(CancellationToken cancellationToken)
    {
        try
        {
            var netsh = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "netsh.exe");
            var start = new ProcessStartInfo(netsh)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true
            };
            foreach (var argument in new[] { "advfirewall", "firewall", "show", "rule", "name=POS Printer Emulator RAW TCP Listeners", "verbose" }) start.ArgumentList.Add(argument);
            using var process = Process.Start(start) ?? throw new InvalidOperationException("Windows could not start the firewall inspection tool.");
            var outputTask = process.StandardOutput.ReadToEndAsync(cancellationToken);
            var errorTask = process.StandardError.ReadToEndAsync(cancellationToken);
            await process.WaitForExitAsync(cancellationToken);
            var output = await outputTask;
            var error = await errorTask;
            if (process.ExitCode == 0 && output.Contains("POS Printer Emulator RAW TCP Listeners", StringComparison.OrdinalIgnoreCase))
                return new("firewall", "Windows", "Private/domain firewall rule", DiagnosticStates.Passed,
                    "The program-scoped Windows firewall rule is present for private and domain networks.", output);
            return new("firewall", "Windows", "Private/domain firewall rule", DiagnosticStates.Failed,
                "The POS Printer Emulator firewall rule is missing or unavailable.", string.IsNullOrWhiteSpace(error) ? output : error, "RepairFirewall");
        }
        catch (Exception exception)
        {
            return new("firewall", "Windows", "Private/domain firewall rule", DiagnosticStates.Failed,
                "Windows firewall configuration could not be inspected.", exception.Message, "RepairFirewall");
        }
    }

    private static HashSet<int> GetActiveTcpPorts()
    {
        try { return IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners().Select(endpoint => endpoint.Port).ToHashSet(); }
        catch { return []; }
    }

    private static IPAddress ParseAddress(string value) => IPAddress.TryParse(value, out var address) ? address : IPAddress.Loopback;

    private static string ConnectionAddress(string bindAddress)
    {
        var address = ParseAddress(bindAddress);
        if (!address.Equals(IPAddress.Any)) return address.ToString();
        try
        {
            return Dns.GetHostAddresses(Dns.GetHostName())
                .FirstOrDefault(candidate => candidate.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(candidate))?.ToString()
                ?? IPAddress.Loopback.ToString();
        }
        catch { return IPAddress.Loopback.ToString(); }
    }

    private string RedactedSummary(ConnectionDiagnosticReport report)
    {
        var builder = new StringBuilder()
            .AppendLine("POS Printer Emulator Support Summary")
            .AppendLine($"Package ID: {report.PackageId}")
            .AppendLine($"Generated: {report.GeneratedAt:O}")
            .AppendLine($"Application: {report.ApplicationVersion}")
            .AppendLine($"Windows: {report.WindowsVersion}")
            .AppendLine($"License tier: {license.GetStatus().Mode}")
            .AppendLine($"Results: {report.Passed} passed, {report.AttentionNeeded} attention needed, {report.Failed} failed, {report.Skipped} skipped")
            .AppendLine();
        foreach (var check in report.Checks)
            builder.AppendLine($"[{check.Status}] {check.Area} / {check.Title}: {check.Summary}");
        builder.AppendLine().AppendLine("Sensitive values are removed from this package. No receipt contents, raw receipt bytes, activation keys, maintenance keys, customer contact information, or payment data are included.");
        return SupportRequestService.Redact(builder.ToString());
    }

    private static ConnectionDiagnosticReport RedactedReport(ConnectionDiagnosticReport report) => report with
    {
        WindowsVersion = SupportRequestService.Redact(report.WindowsVersion),
        Checks = report.Checks.Select(check => check with
        {
            Summary = SupportRequestService.Redact(check.Summary),
            TechnicalDetails = SupportRequestService.Redact(check.TechnicalDetails)
        }).ToArray(),
        ConnectionDetails = report.ConnectionDetails.Select(connection => connection with
        {
            IpAddress = "[IP address removed]"
        }).ToArray()
    };

    private static async Task WriteEntryAsync(ZipArchive archive, string name, string content, CancellationToken cancellationToken)
    {
        var entry = archive.CreateEntry(name, CompressionLevel.Optimal);
        await using var stream = entry.Open();
        await using var writer = new StreamWriter(stream, new UTF8Encoding(false));
        await writer.WriteAsync(content.AsMemory(), cancellationToken);
    }
}
