using System.Net;
using System.Net.Http.Json;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Threading.Channels;

namespace ReceiptEmulator;

public interface IUsageTelemetry
{
    void RecordPrintJob();
    void RecordActivation();
}

public sealed record InstallationCredentials(Guid InstallationId, string Token);

public interface IInstallationCredentialsProvider
{
    Task<InstallationCredentials> GetCredentialsAsync(CancellationToken cancellationToken);
}

public sealed class UsageTelemetryService : BackgroundService, IUsageTelemetry, IInstallationCredentialsProvider
{
    private readonly HttpClient _httpClient;
    private readonly LicenseService _license;
    private readonly ILogger<UsageTelemetryService> _logger;
    private readonly Channel<TelemetryEvent> _events;
    private readonly string _statePath;
    private readonly Uri? _endpoint;
    private readonly bool _enabled;
    private readonly TimeSpan _retryDelay;
    private readonly SemaphoreSlim _registrationGate = new(1, 1);
    private readonly object _stateSync = new();
    private TelemetryState _state;

    public UsageTelemetryService(
        HttpClient httpClient,
        LicenseService license,
        IConfiguration configuration,
        IHostEnvironment environment,
        ILogger<UsageTelemetryService> logger)
    {
        _httpClient = httpClient;
        _license = license;
        _logger = logger;
        _statePath = Path.Combine(license.RootPath, "telemetry-state.json");
        _state = LoadState() ?? new TelemetryState(Guid.NewGuid(), null);
        _license.BindInstallationId(_state.InstallationId);
        _events = Channel.CreateBounded<TelemetryEvent>(new BoundedChannelOptions(512)
        {
            FullMode = BoundedChannelFullMode.DropOldest,
            SingleReader = true,
            SingleWriter = false
        });

        _enabled = !environment.IsEnvironment("Testing") &&
                   configuration.GetValue("Telemetry:Enabled", true) &&
                   Uri.TryCreate(configuration["Telemetry:Endpoint"], UriKind.Absolute, out _endpoint) &&
                   _endpoint.Scheme == Uri.UriSchemeHttps;
        _retryDelay = TimeSpan.FromSeconds(Math.Max(0.01, configuration.GetValue("Telemetry:RetryDelaySeconds", 60d)));
        _httpClient.Timeout = TimeSpan.FromSeconds(15);
        _httpClient.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    }

    public void RecordPrintJob()
    {
        if (_enabled)
        {
            _events.Writer.TryWrite(TelemetryEvent.PrintJob);
        }
    }

    public void RecordActivation()
    {
        if (_enabled)
        {
            _events.Writer.TryWrite(TelemetryEvent.Activation);
        }
    }

    public async Task<InstallationCredentials> GetCredentialsAsync(CancellationToken cancellationToken)
    {
        if (!_enabled || _endpoint is null)
        {
            throw new InvalidOperationException("The secure installation service is unavailable.");
        }
        if (!await EnsureRegisteredAsync(cancellationToken))
        {
            throw new InvalidOperationException(
                "The application could not verify this installation. Check the internet connection and try again.");
        }
        lock (_stateSync)
        {
            return new InstallationCredentials(
                _state.InstallationId,
                _state.Token ?? throw new InvalidOperationException("The installation registration is incomplete."));
        }
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        if (!_enabled || _endpoint is null)
        {
            return;
        }

        var launchPending = true;
        var printJobsPending = 0;
        var activationPending = false;
        var nextHeartbeat = DateTimeOffset.UtcNow.AddHours(12);

        while (!stoppingToken.IsCancellationRequested)
        {
            if (string.IsNullOrWhiteSpace(_state.Token))
            {
                if (!await EnsureRegisteredAsync(stoppingToken))
                {
                    await Task.Delay(TimeSpan.FromMinutes(15), stoppingToken);
                    continue;
                }
            }

            if (launchPending)
            {
                launchPending = !await SendEventAsync("launch", 1, stoppingToken);
                if (launchPending)
                {
                    await Task.Delay(_retryDelay, stoppingToken);
                }
                continue;
            }

            if (printJobsPending > 0)
            {
                if (await SendEventAsync("print_job", printJobsPending, stoppingToken))
                {
                    printJobsPending = 0;
                }
                else
                {
                    await Task.Delay(_retryDelay, stoppingToken);
                }
                continue;
            }

            if (activationPending)
            {
                activationPending = !await SendEventAsync("activation", 1, stoppingToken);
                if (activationPending)
                {
                    await Task.Delay(_retryDelay, stoppingToken);
                }
                continue;
            }

            var waitForEvent = _events.Reader.WaitToReadAsync(stoppingToken).AsTask();
            var waitForHeartbeat = Task.Delay(nextHeartbeat - DateTimeOffset.UtcNow > TimeSpan.Zero
                ? nextHeartbeat - DateTimeOffset.UtcNow
                : TimeSpan.Zero, stoppingToken);
            var completed = await Task.WhenAny(waitForEvent, waitForHeartbeat);

            if (completed == waitForHeartbeat)
            {
                await SendEventAsync("heartbeat", 1, stoppingToken);
                nextHeartbeat = DateTimeOffset.UtcNow.AddHours(12);
                continue;
            }

            if (!await waitForEvent)
            {
                break;
            }

            while (_events.Reader.TryRead(out var telemetryEvent))
            {
                printJobsPending += telemetryEvent == TelemetryEvent.PrintJob ? 1 : 0;
                activationPending |= telemetryEvent == TelemetryEvent.Activation;
            }
        }
    }

    private async Task<bool> EnsureRegisteredAsync(CancellationToken cancellationToken)
    {
        await _registrationGate.WaitAsync(cancellationToken);
        try
        {
            lock (_stateSync)
            {
                if (!string.IsNullOrWhiteSpace(_state.Token))
                {
                    return true;
                }
            }

            for (var attempt = 0; attempt < 2; attempt++)
            {
                var status = _license.GetStatus();
                var payload = CreatePayload("register", null, 1, status);
                try
                {
                    using var response = await _httpClient.PostAsJsonAsync(_endpoint, payload, cancellationToken);
                    if (response.StatusCode == HttpStatusCode.Conflict)
                    {
                        lock (_stateSync)
                        {
                            _state = new TelemetryState(Guid.NewGuid(), null);
                            _license.BindInstallationId(_state.InstallationId);
                            SaveState();
                        }
                        continue;
                    }

                    response.EnsureSuccessStatusCode();
                    var registration = await response.Content.ReadFromJsonAsync<RegistrationResponse>(cancellationToken: cancellationToken);
                    if (string.IsNullOrWhiteSpace(registration?.Token))
                    {
                        throw new InvalidDataException("Telemetry registration did not return an installation token.");
                    }

                    lock (_stateSync)
                    {
                        _state = _state with { Token = registration.Token };
                        SaveState();
                    }
                    return true;
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception exception)
                {
                    _logger.LogWarning(exception, "Usage reporting is unavailable; receipt emulation will continue normally");
                    return false;
                }
            }

            return false;
        }
        finally
        {
            _registrationGate.Release();
        }
    }

    private async Task<bool> SendEventAsync(string eventName, int count, CancellationToken cancellationToken)
    {
        TelemetryState state;
        lock (_stateSync)
        {
            state = _state;
        }
        if (_endpoint is null || string.IsNullOrWhiteSpace(state.Token))
        {
            return false;
        }

        try
        {
            var status = _license.GetStatus();
            using var request = new HttpRequestMessage(HttpMethod.Post, _endpoint)
            {
                Content = JsonContent.Create(CreatePayload("event", eventName, Math.Clamp(count, 1, 1000), status))
            };
            request.Headers.Add("X-Installation-Token", state.Token);
            using var response = await _httpClient.SendAsync(request, cancellationToken);
            if (response.StatusCode == HttpStatusCode.Unauthorized)
            {
                lock (_stateSync)
                {
                    _state = new TelemetryState(Guid.NewGuid(), null);
                    _license.BindInstallationId(_state.InstallationId);
                    SaveState();
                }
                return false;
            }
            response.EnsureSuccessStatusCode();
            return true;
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Could not report {TelemetryEvent}; it will be retried", eventName);
            return false;
        }
    }

    private object CreatePayload(string action, string? eventName, int count, LicenseStatus status)
    {
        Guid installationId;
        lock (_stateSync)
        {
            installationId = _state.InstallationId;
        }
        return new
        {
        action,
        installationId,
        @event = eventName,
        count,
        customerName = status.CustomerName,
        emailAddress = status.EmailAddress,
        appVersion = ProductInfo.Version,
        deviceLabel = Environment.MachineName,
        windowsVersion = RuntimeInformation.OSDescription,
        licenseMode = status.Mode,
        licenseId = status.LicenseId,
        maintenanceStatus = status.Maintenance.State,
        maintenanceExpiresAt = status.Maintenance.ExpiresAt
        };
    }

    private TelemetryState? LoadState()
    {
        try
        {
            return File.Exists(_statePath)
                ? JsonSerializer.Deserialize<TelemetryState>(File.ReadAllText(_statePath))
                : null;
        }
        catch
        {
            return null;
        }
    }

    private void SaveState()
    {
        var temporaryPath = _statePath + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(_state));
        File.Move(temporaryPath, _statePath, true);
    }

    private enum TelemetryEvent { PrintJob, Activation }
    private sealed record TelemetryState(Guid InstallationId, string? Token);
    private sealed record RegistrationResponse(string Token);
}
