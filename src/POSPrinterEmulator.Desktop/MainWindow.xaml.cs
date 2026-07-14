using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Windows;
using Microsoft.Web.WebView2.Core;

namespace POSPrinterEmulator.Desktop;

public partial class MainWindow : Window
{
    private static readonly Uri ViewerUri = new("http://127.0.0.1:5187");
    private static readonly Uri HealthUri = new("http://127.0.0.1:5187/api/status");
    private readonly HttpClient _httpClient = new() { Timeout = TimeSpan.FromSeconds(2) };
    private bool _webViewInitialized;

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
        Browser.CoreWebView2.NewWindowRequested += (_, eventArgs) =>
        {
            eventArgs.Handled = true;
            Process.Start(new ProcessStartInfo(eventArgs.Uri) { UseShellExecute = true });
        };
        Browser.NavigationCompleted += (_, eventArgs) =>
        {
            if (eventArgs.IsSuccess)
            {
                LoadingPanel.Visibility = Visibility.Collapsed;
                ErrorPanel.Visibility = Visibility.Collapsed;
                Browser.Visibility = Visibility.Visible;
            }
            else
            {
                ShowError($"The local viewer could not be opened ({eventArgs.WebErrorStatus}).");
            }
        };

        _webViewInitialized = true;
    }

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
}
