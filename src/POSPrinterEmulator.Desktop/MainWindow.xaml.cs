using System.Diagnostics;
using System.ComponentModel;
using System.IO;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Windows;
using Microsoft.Web.WebView2.Core;
using Microsoft.Win32;
using POSPrinterEmulator.Update;

namespace POSPrinterEmulator.Desktop;

public partial class MainWindow : Window
{
    private static readonly Uri ViewerUri = new("http://127.0.0.1:5187");
    private static readonly Uri HealthUri = new("http://127.0.0.1:5187/api/status");
    private readonly HttpClient _httpClient = new() { Timeout = TimeSpan.FromSeconds(30) };
    private readonly SemaphoreSlim _updateGate = new(1, 1);
    private bool _webViewInitialized;
    private bool _viewerReady;
    private WindowState _lastNonMinimizedWindowState = WindowState.Maximized;

    public MainWindow()
    {
        InitializeComponent();
        SourceInitialized += (_, _) =>
        {
            _lastNonMinimizedWindowState = WindowPlacementManager.Apply(this);
        };
        StateChanged += (_, _) =>
        {
            if (WindowState is WindowState.Normal or WindowState.Maximized)
            {
                _lastNonMinimizedWindowState = WindowState;
            }
        };
        Closing += (_, _) => WindowPlacementManager.Save(this, _lastNonMinimizedWindowState);
        Loaded += async (_, _) => await StartAsync();
        Closed += (_, _) => { _httpClient.Dispose(); _updateGate.Dispose(); };
    }

    private async Task StartAsync()
    {
        ShowLoading("Starting the local printer service…");

        try
        {
            await WaitForServiceAsync(TimeSpan.FromSeconds(30));
            ShowLoading("Opening POS Printer Emulator…");
            await InitializeWebViewAsync();
            Browser.Source = ViewerUri;
            ShowPreviousUpdateResult();
        }
        catch (Exception exception)
        {
            ShowError(GetFriendlyError(exception));
        }
    }

    private async Task WaitForServiceAsync(TimeSpan timeout)
    {
        var deadline = DateTimeOffset.UtcNow.Add(timeout);
        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var response = await _httpClient.GetAsync(HealthUri);
                if (response.IsSuccessStatusCode)
                {
                    return;
                }
            }
            catch (HttpRequestException)
            {
                // The Windows service may still be starting.
            }
            catch (TaskCanceledException)
            {
                // Retry until the overall startup timeout expires.
            }

            await Task.Delay(500);
        }

        throw new TimeoutException("The background service did not respond within 30 seconds.");
    }

    private async Task InitializeWebViewAsync()
    {
        if (_webViewInitialized)
        {
            return;
        }

        var userDataFolder = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "POSPrinterEmulator",
            "WebView2");
        var environment = await CoreWebView2Environment.CreateAsync(userDataFolder: userDataFolder);
        await Browser.EnsureCoreWebView2Async(environment);

        Browser.CoreWebView2.Settings.AreDevToolsEnabled = false;
        Browser.CoreWebView2.Settings.IsStatusBarEnabled = false;
        Browser.CoreWebView2.Settings.AreDefaultScriptDialogsEnabled = true;
        Browser.CoreWebView2.WebMessageReceived += async (_, eventArgs) =>
        {
            try
            {
                var request = System.Text.Json.JsonSerializer.Deserialize<DesktopMessage>(eventArgs.WebMessageAsJson,
                    new System.Text.Json.JsonSerializerOptions { PropertyNameCaseInsensitive = true });
                if (request?.Type == "install-update" &&
                    Uri.TryCreate(request.Url, UriKind.Absolute, out var updateUri) &&
                    Uri.TryCreate(request.ChecksumUrl, UriKind.Absolute, out var checksumUri) &&
                    !string.IsNullOrWhiteSpace(request.Version))
                {
                    await DownloadAndPrepareUpdateAsync(updateUri, checksumUri, request.Version);
                }
                else if (request?.Type == "install-printer" && request.Printer is not null)
                {
                    await RunPrinterSetupAsync(request.Printer);
                }
                else if (request?.Type == "print-printer-test" && !string.IsNullOrWhiteSpace(request.PrinterName))
                {
                    await RunPrinterTestAsync(request.PrinterName);
                }
                else if (request?.Type == "repair-firewall")
                {
                    await RunFirewallRepairAsync();
                }
            }
            catch (Exception exception)
            {
                MessageBox.Show(this, $"The update could not be started: {exception.Message}", "POS Printer Emulator",
                    MessageBoxButton.OK, MessageBoxImage.Error);
            }
        };
        Browser.CoreWebView2.NewWindowRequested += (_, eventArgs) =>
        {
            eventArgs.Handled = true;
            Process.Start(new ProcessStartInfo(eventArgs.Uri) { UseShellExecute = true });
        };
        Browser.CoreWebView2.DownloadStarting += (_, eventArgs) =>
        {
            var suggestedName = Path.GetFileName(eventArgs.ResultFilePath);
            var extension = Path.GetExtension(suggestedName).ToLowerInvariant();
            var (title, filter, defaultExtension) = extension switch
            {
                ".ppebackup" => ("Save POS Printer Emulator backup", "POS Printer Emulator backup|*.ppebackup|All files|*.*", "ppebackup"),
                ".ppeprofile" => ("Save printer profile", "POS Printer Emulator profile|*.ppeprofile|All files|*.*", "ppeprofile"),
                ".ppecapture" => ("Save receipt capture", "POS Printer Emulator capture|*.ppecapture|All files|*.*", "ppecapture"),
                ".zip" => ("Save support package", "Compressed support package|*.zip|All files|*.*", "zip"),
                ".txt" => ("Save text file", "Text file|*.txt|All files|*.*", "txt"),
                ".bin" => ("Save raw receipt data", "Binary receipt data|*.bin|All files|*.*", "bin"),
                _ => ("Save POS Printer Emulator file", "POS Printer Emulator files|*.ppebackup;*.ppeprofile;*.ppecapture;*.zip;*.txt;*.bin|All files|*.*", string.Empty)
            };
            var dialog = new SaveFileDialog
            {
                Title = title,
                FileName = string.IsNullOrWhiteSpace(suggestedName) ? "receipt-download" : suggestedName,
                InitialDirectory = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
                    "Downloads"),
                AddExtension = true,
                DefaultExt = defaultExtension,
                OverwritePrompt = true,
                Filter = filter
            };

            eventArgs.Handled = true;
            if (dialog.ShowDialog(this) == true)
            {
                eventArgs.ResultFilePath = dialog.FileName;
            }
            else
            {
                eventArgs.Cancel = true;
            }
        };
        Browser.NavigationCompleted += (_, eventArgs) =>
        {
            if (eventArgs.IsSuccess)
            {
                if (Browser.Source == ViewerUri)
                {
                    _viewerReady = true;
                }
                LoadingPanel.Visibility = Visibility.Collapsed;
                ErrorPanel.Visibility = Visibility.Collapsed;
                Browser.Visibility = Visibility.Visible;
            }
            else if (!_viewerReady)
            {
                ShowError($"The local viewer could not be opened ({eventArgs.WebErrorStatus}).");
            }
            else
            {
                // Attachment downloads can report ConnectionAborted for the attempted
                // document navigation. Keep the already loaded viewer visible.
                LoadingPanel.Visibility = Visibility.Collapsed;
                ErrorPanel.Visibility = Visibility.Collapsed;
                Browser.Visibility = Visibility.Visible;
            }
        };

        _webViewInitialized = true;
    }

    private async Task DownloadAndPrepareUpdateAsync(Uri updateUri, Uri checksumUri, string version)
    {
        if (!UpdatePackageSecurity.IsTrustedGitHubAsset(updateUri, ".exe") ||
            !UpdatePackageSecurity.IsTrustedGitHubAsset(checksumUri, ".sha256"))
        {
            throw new InvalidOperationException("The update files were not trusted GitHub release assets.");
        }

        if (!await _updateGate.WaitAsync(0)) return;
        string? snapshotId = null;
        var prepared = false;
        try
        {
            var downloadDirectory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Updates",
                $"{DateTime.UtcNow:yyyyMMddHHmmss}-{Guid.NewGuid():N}");
            Directory.CreateDirectory(downloadDirectory);
            var installerPath = Path.Combine(downloadDirectory, Path.GetFileName(updateUri.LocalPath));
            var checksumPath = installerPath + ".sha256";

            PostUpdateState("downloading", "Downloading the verified update…", 0);
            using var updateClient = new HttpClient { Timeout = TimeSpan.FromMinutes(10) };
            await DownloadFileAsync(updateClient, updateUri, installerPath, percent =>
                PostUpdateState("downloading", $"Downloading update… {percent}%", percent));
            await DownloadFileAsync(updateClient, checksumUri, checksumPath, null);

            var expected = UpdatePackageSecurity.ParseSha256(
                await File.ReadAllTextAsync(checksumPath), Path.GetFileName(installerPath));
            await UpdatePackageSecurity.VerifySha256Async(installerPath, expected);
            PostUpdateState("verified", "Download complete and security checksum verified.", 100);

            var choice = MessageBox.Show(this,
                $"POS Printer Emulator {version} is downloaded and verified.\n\n" +
                "Install now? The application will finish active receipts, save a safety snapshot, close, install the update, and restart automatically.",
                "Install POS Printer Emulator Update",
                MessageBoxButton.YesNo, MessageBoxImage.Question);
            if (choice != MessageBoxResult.Yes)
            {
                PostUpdateState("deferred", "The verified update was not installed. You can start it again when ready.", 100);
                return;
            }

            PostUpdateState("preparing", "Finishing active receipts and creating a safety snapshot…", 100);
            using var prepareResponse = await _httpClient.PostAsync(new Uri(ViewerUri, "/api/updates/prepare"), null);
            if (!prepareResponse.IsSuccessStatusCode)
            {
                var problem = await prepareResponse.Content.ReadFromJsonAsync<ProblemDetailsResponse>();
                throw new InvalidOperationException(problem?.Detail ?? "The application could not safely prepare for the update.");
            }
            var preparation = await prepareResponse.Content.ReadFromJsonAsync<UpdatePreparation>()
                ?? throw new InvalidOperationException("The update preparation response was empty.");
            snapshotId = preparation.SafetySnapshotId;
            prepared = true;

            var installedUpdater = Path.Combine(AppContext.BaseDirectory, "POSPrinterEmulator.Updater.exe");
            if (!File.Exists(installedUpdater))
                throw new FileNotFoundException("The guided update component is missing. Repair the application with the latest installer.", installedUpdater);
            var updaterPath = Path.Combine(downloadDirectory, "POSPrinterEmulator.Updater.exe");
            File.Copy(installedUpdater, updaterPath, true);
            var desktopPath = Environment.ProcessPath ?? Path.Combine(AppContext.BaseDirectory, "POSPrinterEmulator.Desktop.exe");
            var startInfo = new ProcessStartInfo(updaterPath)
            {
                UseShellExecute = true,
                Verb = "runas",
                WorkingDirectory = downloadDirectory,
                WindowStyle = ProcessWindowStyle.Hidden
            };
            foreach (var argument in new[]
            {
                "--installer", installerPath,
                "--desktop", desktopPath,
                "--version", version,
                "--snapshot", snapshotId,
                "--desktop-pid", Environment.ProcessId.ToString()
            }) startInfo.ArgumentList.Add(argument);
            _ = Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start the guided update component.");
            PostUpdateState("installing", "Closing the application so Windows can install the update…", 100);
            Application.Current.Shutdown();
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            if (prepared) await ResumeListenersAsync();
            PostUpdateState("cancelled", "Windows administrator approval was canceled. The application remains ready to use.", null);
        }
        catch (Exception exception)
        {
            if (prepared) await ResumeListenersAsync();
            PostUpdateState("failed", exception.GetBaseException().Message, null);
            MessageBox.Show(this, $"The update could not be started: {exception.GetBaseException().Message}",
                "POS Printer Emulator", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            _updateGate.Release();
        }
    }

    private static async Task DownloadFileAsync(HttpClient client, Uri uri, string destination, Action<int>? progress)
    {
        var partial = destination + ".download";
        using var response = await client.GetAsync(uri, HttpCompletionOption.ResponseHeadersRead);
        response.EnsureSuccessStatusCode();
        var total = response.Content.Headers.ContentLength;
        await using var input = await response.Content.ReadAsStreamAsync();
        await using var output = new FileStream(partial, FileMode.Create, FileAccess.Write, FileShare.None,
            128 * 1024, FileOptions.Asynchronous | FileOptions.SequentialScan);
        var buffer = new byte[128 * 1024];
        long written = 0;
        var reported = -1;
        int read;
        while ((read = await input.ReadAsync(buffer)) > 0)
        {
            await output.WriteAsync(buffer.AsMemory(0, read));
            written += read;
            if (total is > 0)
            {
                var percent = (int)Math.Min(100, written * 100 / total.Value);
                if (percent != reported) { reported = percent; progress?.Invoke(percent); }
            }
        }
        await output.FlushAsync();
        File.Move(partial, destination, true);
    }

    private async Task ResumeListenersAsync()
    {
        try { await _httpClient.PostAsync(new Uri(ViewerUri, "/api/updates/resume"), null); }
        catch { }
    }

    private void PostUpdateState(string state, string message, int? percent) =>
        Browser.CoreWebView2?.PostWebMessageAsJson(JsonSerializer.Serialize(new
        {
            type = "update-state", state, message, percent
        }, JsonOptions));

    private void ShowPreviousUpdateResult()
    {
        var result = UpdatePackageSecurity.TakeResult();
        if (result is null) return;
        MessageBox.Show(this, result.Message, result.Success ? "Update Complete" : "Update Did Not Finish",
            MessageBoxButton.OK, result.Success ? MessageBoxImage.Information : MessageBoxImage.Warning);
    }

    private async Task RunPrinterSetupAsync(PrinterInstallRequest request)
    {
        var operationDirectory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "PrinterSetup", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(operationDirectory);
        var requestPath = Path.Combine(operationDirectory, "request.json");
        var resultPath = Path.Combine(operationDirectory, "result.json");
        await File.WriteAllTextAsync(requestPath, JsonSerializer.Serialize(request, JsonOptions));

        try
        {
            var process = StartElevatedHelper("--install-printer", "--request", requestPath, "--result", resultPath);
            await process.WaitForExitAsync();
            if (!File.Exists(resultPath))
            {
                throw new InvalidOperationException("The printer installer did not return a result.");
            }

            var resultJson = await File.ReadAllTextAsync(resultPath);
            Browser.CoreWebView2.PostWebMessageAsJson($"{{\"type\":\"printer-install-result\",\"result\":{resultJson}}}");
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            PostPrinterError("Printer installation was canceled before Windows administrator approval was granted.");
        }
        catch (Exception exception)
        {
            PostPrinterError($"The printer installation could not be started: {exception.Message}");
        }
        finally
        {
            try { Directory.Delete(operationDirectory, recursive: true); } catch { }
        }
    }

    private async Task RunPrinterTestAsync(string printerName)
    {
        try
        {
            using var process = StartElevatedHelper("--print-printer-test", "--printer-name", printerName);
            await process.WaitForExitAsync();
            if (process.ExitCode != 0) throw new InvalidOperationException("Windows did not accept the test receipt.");
            Browser.CoreWebView2.PostWebMessageAsJson("{\"type\":\"printer-test-result\",\"success\":true}");
        }
        catch (Exception exception)
        {
            Browser.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new
            {
                type = "printer-test-result",
                success = false,
                message = exception.Message
            }, JsonOptions));
        }
    }

    private async Task RunFirewallRepairAsync()
    {
        try
        {
            using var process = StartElevatedHelper("--repair-firewall");
            await process.WaitForExitAsync();
            if (process.ExitCode != 0) throw new InvalidOperationException("Windows did not complete the firewall repair.");
            MessageBox.Show(this, "The private/domain POS Printer Emulator firewall rule was repaired. Run Connection Diagnostics again to verify it.", "POS Printer Emulator", MessageBoxButton.OK, MessageBoxImage.Information);
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            MessageBox.Show(this, "Firewall repair was canceled before Windows administrator approval was granted.", "POS Printer Emulator", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        catch (Exception exception)
        {
            MessageBox.Show(this, $"The firewall rule could not be repaired: {exception.Message}", "POS Printer Emulator", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private static Process StartElevatedHelper(params string[] arguments)
    {
        var serviceExecutable = Path.Combine(AppContext.BaseDirectory, "ReceiptEmulator.exe");
        if (!File.Exists(serviceExecutable))
            throw new FileNotFoundException("The POS Printer Emulator setup component is missing.", serviceExecutable);

        var startInfo = new ProcessStartInfo(serviceExecutable)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = AppContext.BaseDirectory,
            WindowStyle = ProcessWindowStyle.Hidden
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
        return Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start the printer setup component.");
    }

    private void PostPrinterError(string message) => Browser.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new
    {
        type = "printer-install-result",
        result = new { success = false, message, technicalDetails = message }
    }, JsonOptions));

    private void ShowLoading(string message)
    {
        Browser.Visibility = Visibility.Hidden;
        ErrorPanel.Visibility = Visibility.Collapsed;
        LoadingPanel.Visibility = Visibility.Visible;
        LoadingMessage.Text = message;
    }

    private void ShowError(string message)
    {
        Browser.Visibility = Visibility.Hidden;
        LoadingPanel.Visibility = Visibility.Collapsed;
        ErrorPanel.Visibility = Visibility.Visible;
        ErrorMessage.Text = message;
    }

    private static string GetFriendlyError(Exception exception)
    {
        if (exception is WebView2RuntimeNotFoundException)
        {
            return "The Microsoft WebView2 component is missing. Re-run the POS Printer Emulator installer to repair the application.";
        }

        if (exception is TimeoutException)
        {
            return "The local printer service is not responding. Restart Windows or re-run the installer to repair the service, then try again.";
        }

        return $"The desktop viewer encountered an unexpected problem: {exception.Message}";
    }

    private async void Retry_Click(object sender, RoutedEventArgs e) => await StartAsync();

    private void OpenBrowser_Click(object sender, RoutedEventArgs e) =>
        Process.Start(new ProcessStartInfo(ViewerUri.AbsoluteUri) { UseShellExecute = true });

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private sealed record DesktopMessage(string Type, string? Url, string? ChecksumUrl, string? Version, PrinterInstallRequest? Printer, string? PrinterName);
    private sealed record UpdatePreparation(bool Prepared, string SafetySnapshotId);
    private sealed record ProblemDetailsResponse(string? Detail);
    private sealed record PrinterInstallRequest(string PrinterName, string IpAddress, int Port, bool SameComputer);
}
