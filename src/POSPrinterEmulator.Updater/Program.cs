using System.Diagnostics;
using POSPrinterEmulator.Update;

return await RunAsync(args);

static async Task<int> RunAsync(string[] args)
{
    var values = ParseArguments(args);
    var installerPath = RequiredPath(values, "installer");
    var desktopPath = RequiredPath(values, "desktop");
    var targetVersion = RequiredValue(values, "version");
    var snapshotId = values.GetValueOrDefault("snapshot");
    var desktopPid = int.TryParse(values.GetValueOrDefault("desktop-pid"), out var parsedPid) ? parsedPid : 0;
    var exitCode = -1;

    try
    {
        await WaitForProcessExitAsync(desktopPid, TimeSpan.FromSeconds(45));
        await StopServiceAsync();
        await WaitForProcessesAsync(["POSPrinterEmulator.Desktop", "ReceiptEmulator"], TimeSpan.FromSeconds(45));

        if (!File.Exists(installerPath)) throw new FileNotFoundException("The verified update installer is missing.", installerPath);
        var startInfo = new ProcessStartInfo(installerPath)
        {
            UseShellExecute = false,
            WorkingDirectory = Path.GetDirectoryName(installerPath)!,
            CreateNoWindow = true
        };
        foreach (var argument in new[] { "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART", "/CLOSEAPPLICATIONS", "/SP-" })
            startInfo.ArgumentList.Add(argument);
        using var installer = Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start the verified installer.");
        await installer.WaitForExitAsync();
        exitCode = installer.ExitCode;
        if (exitCode is not 0 and not 3010)
            throw new InvalidOperationException($"Setup returned exit code {exitCode}.");

        UpdatePackageSecurity.SaveResult(new(true, targetVersion, exitCode,
            $"POS Printer Emulator {targetVersion} was installed successfully.", snapshotId, DateTimeOffset.UtcNow));
        StartDesktop(desktopPath);
        return 0;
    }
    catch (Exception exception)
    {
        try
        {
            UpdatePackageSecurity.SaveResult(new(false, targetVersion, exitCode,
                $"The update did not finish. The existing installation was left available. {exception.GetBaseException().Message}",
                snapshotId, DateTimeOffset.UtcNow));
        }
        catch { }
        try { StartDesktop(desktopPath); } catch { }
        return 1;
    }
}

static Dictionary<string, string> ParseArguments(string[] args)
{
    var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    for (var index = 0; index < args.Length; index++)
    {
        if (!args[index].StartsWith("--", StringComparison.Ordinal) || index + 1 >= args.Length) continue;
        result[args[index][2..]] = args[++index];
    }
    return result;
}

static string RequiredValue(IReadOnlyDictionary<string, string> values, string name) =>
    values.TryGetValue(name, out var value) && !string.IsNullOrWhiteSpace(value)
        ? value
        : throw new ArgumentException($"Missing --{name}.");

static string RequiredPath(IReadOnlyDictionary<string, string> values, string name) =>
    Path.GetFullPath(RequiredValue(values, name));

static async Task WaitForProcessExitAsync(int processId, TimeSpan timeout)
{
    if (processId <= 0) return;
    try
    {
        using var process = Process.GetProcessById(processId);
        using var cancellation = new CancellationTokenSource(timeout);
        await process.WaitForExitAsync(cancellation.Token);
    }
    catch (ArgumentException) { }
}

static async Task StopServiceAsync()
{
    var info = new ProcessStartInfo("sc.exe")
    {
        UseShellExecute = false,
        CreateNoWindow = true,
        RedirectStandardOutput = true,
        RedirectStandardError = true
    };
    info.ArgumentList.Add("stop");
    info.ArgumentList.Add("ReceiptLab");
    using var process = Process.Start(info);
    if (process is null) return;
    await process.WaitForExitAsync();
}

static async Task WaitForProcessesAsync(string[] names, TimeSpan timeout)
{
    var deadline = DateTimeOffset.UtcNow.Add(timeout);
    while (DateTimeOffset.UtcNow < deadline)
    {
        var running = names.SelectMany(name => Process.GetProcessesByName(name)).ToArray();
        if (running.Length == 0) return;
        foreach (var process in running) process.Dispose();
        await Task.Delay(200);
    }
    throw new TimeoutException("The application did not release its installed files in time.");
}

static void StartDesktop(string desktopPath)
{
    if (!File.Exists(desktopPath)) return;
    // Route the restart through the existing Windows shell so the application
    // returns to the customer's normal (non-administrator) desktop session.
    Process.Start(new ProcessStartInfo("explorer.exe")
    {
        UseShellExecute = false,
        CreateNoWindow = true,
        Arguments = $"\"{desktopPath}\""
    });
}
