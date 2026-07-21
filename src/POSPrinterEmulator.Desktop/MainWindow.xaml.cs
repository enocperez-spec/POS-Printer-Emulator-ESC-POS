using System.Diagnostics;
using System.ComponentModel;
using System.IO;
using System.Net.Http;
using System.Text.Json;
using System.Windows;
using Microsoft.Web.WebView2.Core;
using Microsoft.Win32;

namespace POSPrinterEmulator.Desktop;

public partial class MainWindow : Window
{
    private static readonly Uri ViewerUri = new("http://127.0.0.1:5187");
    private static readonly Uri HealthUri = new("http://127.0.0.1:5187/api/status");
    private readonly HttpClient _httpClient = new() { Timeout = TimeSpan.FromSeconds(2) };
    private bool _webViewInitialized;
    private bool _viewerReady;

    public MainWindow()
    {
        InitializeComponent();
        Loaded += async (_, _) => await StartAsync();
        Closed += (_, _) => _httpClient.Dispose();
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
                if (request?.Type == "install-update" && Uri.TryCreate(request.Url, UriKind.Absolute, out var updateUri))
                {
                    await DownloadAndLaunchUpdateAsync(updateUri);
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
            var dialog = new SaveFileDialog
            {
                Title = "Save receipt file",
                FileName = string.IsNullOrWhiteSpace(suggestedName) ? "receipt-download" : suggestedName,
                InitialDirectory = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
                    "Downloads"),
                AddExtension = true,
                OverwritePrompt = true,
                Filter = "Support and receipt files|*.zip;*.txt;*.bin;*.ppecapture|All files|*.*"
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

    private async Task DownloadAndLaunchUpdateAsync(Uri updateUri)
    {
        if (!string.Equals(updateUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)
            || !string.Equals(updateUri.Host, "github.com", StringComparison.OrdinalIgnoreCase)
            || !updateUri.AbsolutePath.EndsWith(".exe", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException("The update link was not a trusted GitHub installer.");
        }

        var downloadDirectory = Path.Combine(
            Path.GetTempPath(),
            "POSPrinterEmulator",
            "Updates",
            $"{DateTime.UtcNow:yyyyMMddHHmmss}-{Guid.NewGuid():N}");
        Directory.CreateDirectory(downloadDirectory);
        var installerPath = Path.Combine(downloadDirectory, Path.GetFileName(updateUri.LocalPath));
        var partialPath = installerPath + ".download";
        using var updateClient = new HttpClient { Timeout = TimeSpan.FromMinutes(5) };
        using var response = await updateClient.GetAsync(updateUri, HttpCompletionOption.ResponseHeadersRead);
        response.EnsureSuccessStatusCode();
        await using (var output = new FileStream(
            partialPath,
            FileMode.CreateNew,
            FileAccess.Write,
            FileShare.None,
            bufferSize: 81920,
            FileOptions.Asynchronous | FileOptions.SequentialScan))
        {
            await response.Content.CopyToAsync(output);
            await output.FlushAsync();
        }

        File.Move(partialPath, installerPath);
        Process.Start(new ProcessStartInfo(installerPath) { UseShellExecute = true });
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
    private sealed record DesktopMessage(string Type, string? Url, PrinterInstallRequest? Printer, string? PrinterName);
    private sealed record PrinterInstallRequest(string PrinterName, string IpAddress, int Port, bool SameComputer);
}
