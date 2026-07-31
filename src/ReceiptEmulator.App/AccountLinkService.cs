using System.Net.Http.Json;
using System.Text.Json;

namespace ReceiptEmulator;

public sealed record AccountLinkStartResult(
    string State,
    Guid LinkId,
    string RequestToken,
    string UserCode,
    int ExpiresInSeconds,
    string VerificationUrl,
    string Message)
{
    public Guid CorrelationId { get; init; }
}

public sealed record AccountLinkStatusResult(
    string State,
    string Message,
    LicenseStatus? License = null)
{
    public Guid? CorrelationId { get; init; }
}

public sealed record AccountUnlinkResult(
    LicenseStatus License,
    string Message);

public sealed class AccountLinkService
{
    private readonly HttpClient _httpClient;
    private readonly IInstallationCredentialsProvider _credentials;
    private readonly LicenseService _license;
    private readonly IUsageTelemetry _telemetry;
    private readonly Uri _endpoint;
    private readonly Uri _entitlementEndpoint;
    private readonly Uri _unlinkEndpoint;
    private readonly ExternalServicesOptions _externalServices;
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public AccountLinkService(
        HttpClient httpClient,
        IInstallationCredentialsProvider credentials,
        LicenseService license,
        IUsageTelemetry telemetry,
        IConfiguration configuration)
    {
        _httpClient = httpClient;
        _credentials = credentials;
        _license = license;
        _telemetry = telemetry;
        _externalServices = ExternalServicesOptions.FromConfiguration(configuration);
        var configured = configuration["ExternalServices:AccountLinkEndpoint"] ??
                         configuration["AccountLink:Endpoint"] ??
                         "https://www.posprinteremulator.com/api/v1/account-link.php";
        if (!Uri.TryCreate(configured, UriKind.Absolute, out var endpoint) ||
            endpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException("The secure Customer Portal link service is not configured.");
        }
        _endpoint = endpoint;
        var entitlementConfigured = configuration["ExternalServices:DeviceEntitlementEndpoint"] ??
                                    configuration["AccountLink:EntitlementEndpoint"] ??
                                    "https://admin.posprinteremulator.com/api/v1/device-entitlement.php";
        if (!Uri.TryCreate(entitlementConfigured, UriKind.Absolute, out var entitlementEndpoint) ||
            entitlementEndpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException("The secure device licensing service is not configured.");
        }
        _entitlementEndpoint = entitlementEndpoint;
        var unlinkConfigured = configuration["ExternalServices:DeviceUnlinkEndpoint"] ??
                               configuration["AccountLink:UnlinkEndpoint"] ??
                               "https://admin.posprinteremulator.com/api/v1/device-unlink.php";
        if (!Uri.TryCreate(unlinkConfigured, UriKind.Absolute, out var unlinkEndpoint) ||
            unlinkEndpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException("The secure device unlink service is not configured.");
        }
        _unlinkEndpoint = unlinkEndpoint;
    }

    public async Task<AccountLinkStartResult> StartAsync(CancellationToken cancellationToken)
    {
        var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
        var response = await SendAsync(
            credentials,
            new AccountLinkServerRequest("start", credentials.InstallationId, null, null),
            cancellationToken);
        if (!Guid.TryParse(response.LinkId, out var linkId) ||
            string.IsNullOrWhiteSpace(response.RequestToken) ||
            string.IsNullOrWhiteSpace(response.UserCode) ||
            string.IsNullOrWhiteSpace(response.VerificationUrl))
        {
            throw new InvalidOperationException("The secure link service returned an incomplete request. Try again.");
        }
        var correlationId = Guid.TryParse(response.CorrelationId, out var parsedCorrelationId)
            ? parsedCorrelationId
            : linkId;
        _externalServices.ValidateReturnedUrl("account-link verification URL", response.VerificationUrl);
        return new AccountLinkStartResult(
            response.State ?? "Pending",
            linkId,
            response.RequestToken,
            response.UserCode,
            Math.Max(1, response.ExpiresInSeconds ?? 600),
            response.VerificationUrl,
            response.Message ?? "Approve this computer in the Customer Portal.")
        {
            CorrelationId = correlationId,
        };
    }

    public async Task<AccountLinkStatusResult> CheckAsync(
        Guid linkId,
        string requestToken,
        CancellationToken cancellationToken)
    {
        if (linkId == Guid.Empty || string.IsNullOrWhiteSpace(requestToken))
        {
            throw new InvalidOperationException("The computer-link request is invalid. Start again.");
        }
        var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
        var response = await SendAsync(
            credentials,
            new AccountLinkServerRequest("status", credentials.InstallationId, linkId, requestToken),
            cancellationToken);
        var state = response.State ?? "Unavailable";
        if (!state.Equals("Approved", StringComparison.OrdinalIgnoreCase))
        {
            return new AccountLinkStatusResult(
                state,
                response.Message ?? "Waiting for Customer Portal approval.")
            {
                CorrelationId = Guid.TryParse(response.CorrelationId, out var pendingCorrelationId)
                    ? pendingCorrelationId
                    : linkId,
            };
        }
        if (string.IsNullOrWhiteSpace(response.CustomerName) ||
            string.IsNullOrWhiteSpace(response.EmailAddress))
        {
            throw new InvalidOperationException("The approved license response was incomplete. Start a new link request.");
        }

        LicenseStatus activated;
        try
        {
            _license.BeginSynchronization();
            var deviceEntitlement = await FetchDeviceEntitlementAsync(credentials, cancellationToken);
            activated = ApplyServerEntitlements(
                deviceEntitlement,
                deviceEntitlement.CustomerName ?? response.CustomerName,
                deviceEntitlement.EmailAddress ?? response.EmailAddress);
            activated = _license.RecordSynchronizationSuccess();
        }
        catch (InvalidOperationException exception)
        {
            _license.RecordSynchronizationFailure(
                "The approved account license could not be installed. Start a new link request or contact support.");
            throw new InvalidOperationException(
                "The Customer Portal approved this computer, but the selected license could not be installed. " +
                exception.Message,
                exception);
        }

        await SendAsync(
            credentials,
            new AccountLinkServerRequest("complete", credentials.InstallationId, linkId, requestToken),
            cancellationToken);
        _telemetry.RecordActivation();
        return new AccountLinkStatusResult(
            "Activated",
            $"License activated successfully. This computer is now linked to your {activated.Mode} License.",
            activated)
        {
            CorrelationId = Guid.TryParse(response.CorrelationId, out var correlationId)
                ? correlationId
                : linkId,
        };
    }

    public async Task<LicenseStatus> SynchronizeAsync(CancellationToken cancellationToken)
    {
        _license.BeginSynchronization();
        try
        {
            var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
            var result = await FetchDeviceEntitlementAsync(credentials, cancellationToken);
            if (string.IsNullOrWhiteSpace(result.CustomerName) ||
                string.IsNullOrWhiteSpace(result.EmailAddress) ||
                (string.IsNullOrWhiteSpace(result.DeviceEntitlement) &&
                 string.IsNullOrWhiteSpace(result.PromotionEntitlement)))
            {
                throw new InvalidOperationException("The device licensing service returned an incomplete entitlement.");
            }
            ApplyServerEntitlements(result, result.CustomerName, result.EmailAddress);
            return _license.RecordSynchronizationSuccess();
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            if (_license.GetStatus().Synchronization.State is not "Revoked" and not "Unlinked")
            {
                _license.RecordSynchronizationFailure(
                    "The licensing service could not be reached. Licensed features remain available during the offline grace period.");
            }
            throw new InvalidOperationException(
                exception is HttpRequestException or TaskCanceledException
                    ? "The licensing service could not be reached. Licensed features remain available during the offline grace period."
                    : exception.Message,
                exception);
        }
    }

    public async Task<AccountUnlinkResult> UnlinkAsync(CancellationToken cancellationToken)
    {
        var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
        using var request = new HttpRequestMessage(HttpMethod.Post, _unlinkEndpoint)
        {
            Content = JsonContent.Create(new
            {
                installationId = credentials.InstallationId,
                appVersion = ProductInfo.Version,
                reason = "Customer unlinked this computer from the installed application.",
            }),
        };
        request.Headers.Add("X-Installation-Token", credentials.Token);
        HttpResponseMessage response;
        try
        {
            response = await _httpClient.SendAsync(request, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException)
        {
            throw new InvalidOperationException(
                "This computer could not be unlinked because the licensing service is unavailable. No local license state was changed.",
                exception);
        }
        using (response)
        {
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        DeviceUnlinkServerResponse? result = null;
        try
        {
            result = JsonSerializer.Deserialize<DeviceUnlinkServerResponse>(body, JsonOptions);
        }
        catch (JsonException) { }
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(
                result?.Error ?? "This computer could not be unlinked. Check the internet connection and try again.");
        }
        _license.RemoveDeviceEntitlement();
        var status = _license.RecordAuthoritativeState(
            "Unlinked",
            "This computer is unlinked. Local receipts and settings were preserved.");
        return new AccountUnlinkResult(
            status,
            result?.Message ?? "This computer was unlinked and its license is available for another computer.");
        }
    }

    private LicenseStatus ApplyServerEntitlements(
        DeviceEntitlementServerResponse result,
        string customerName,
        string emailAddress)
    {
        LicenseStatus status;
        if (!string.IsNullOrWhiteSpace(result.DeviceEntitlement))
        {
            status = _license.InstallDeviceEntitlement(
                customerName,
                emailAddress,
                result.DeviceEntitlement);
        }
        else
        {
            _license.RegisterInstallation(customerName, emailAddress);
            status = _license.GetStatus();
        }
        return !string.IsNullOrWhiteSpace(result.PromotionEntitlement)
            ? _license.InstallPromotionEntitlement(result.PromotionEntitlement)
            : status;
    }

    private async Task<DeviceEntitlementServerResponse> FetchDeviceEntitlementAsync(
        InstallationCredentials credentials,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, _entitlementEndpoint)
        {
            Content = JsonContent.Create(new { installationId = credentials.InstallationId }),
        };
        request.Headers.Add("X-Installation-Token", credentials.Token);
        using var response = await _httpClient.SendAsync(request, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        DeviceEntitlementServerResponse? result = null;
        try
        {
            result = JsonSerializer.Deserialize<DeviceEntitlementServerResponse>(body, JsonOptions);
        }
        catch (JsonException) { }
        if (!response.IsSuccessStatusCode ||
            (string.IsNullOrWhiteSpace(result?.DeviceEntitlement) &&
             string.IsNullOrWhiteSpace(result?.PromotionEntitlement)))
        {
            if (response.StatusCode == System.Net.HttpStatusCode.Conflict)
            {
                _license.RemoveDeviceEntitlement();
                _license.RecordAuthoritativeState(
                    result?.State?.Equals("Revoked", StringComparison.OrdinalIgnoreCase) == true
                        ? "Revoked"
                        : "Unlinked",
                    result?.Error ?? "This computer no longer has an active account license.");
            }
            throw new InvalidOperationException(
                result?.Error ?? "The approved license could not be synchronized to this computer.");
        }
        return result;
    }

    private async Task<AccountLinkServerResponse> SendAsync(
        InstallationCredentials credentials,
        AccountLinkServerRequest payload,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, _endpoint)
        {
            Content = JsonContent.Create(new
            {
                action = payload.Action,
                installationId = payload.InstallationId,
                linkId = payload.LinkId,
                requestToken = payload.RequestToken,
                appVersion = ProductInfo.Version,
            }),
        };
        request.Headers.Add("X-Installation-Token", credentials.Token);
        if (payload.LinkId is Guid correlationId && correlationId != Guid.Empty)
        {
            request.Headers.Add("X-PPE-Correlation-ID", correlationId.ToString("D"));
        }
        HttpResponseMessage response;
        try
        {
            response = await _httpClient.SendAsync(request, cancellationToken);
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            throw new InvalidOperationException(
                "The Customer Portal link service took too long to respond. Check the internet connection and try again.",
                exception);
        }
        catch (HttpRequestException exception)
        {
            throw new InvalidOperationException(
                "POS Printer Emulator could not reach the Customer Portal link service. Check the internet connection and try again.",
                exception);
        }
        using (response)
        {
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            AccountLinkServerResponse? result = null;
            try
            {
                result = JsonSerializer.Deserialize<AccountLinkServerResponse>(body, JsonOptions);
            }
            catch (JsonException)
            {
                // Do not expose HTML or server diagnostics returned by an upstream host.
            }
            if (!response.IsSuccessStatusCode)
            {
                throw new InvalidOperationException(
                    string.IsNullOrWhiteSpace(result?.Error)
                        ? "The Customer Portal could not complete the secure computer-link request."
                        : result.Error);
            }
            return result ??
                   throw new InvalidOperationException("The Customer Portal returned an invalid link response.");
        }
    }

    private sealed record AccountLinkServerRequest(
        string Action,
        Guid InstallationId,
        Guid? LinkId,
        string? RequestToken);

    private sealed record AccountLinkServerResponse(
        string? State,
        string? CorrelationId,
        string? LinkId,
        string? RequestToken,
        string? UserCode,
        int? ExpiresInSeconds,
        string? VerificationUrl,
        string? Message,
        string? CustomerName,
        string? EmailAddress,
        string? LicenseId,
        string? LicenseTier,
        string? MaintenanceExpiresAt,
        string? Error);

    private sealed record DeviceEntitlementServerResponse(
        string? State,
        string? DeviceEntitlement,
        string? PromotionEntitlement,
        string? CustomerName,
        string? EmailAddress,
        string? Error);

    private sealed record DeviceUnlinkServerResponse(
        string? State,
        string? Message,
        string? Error);
}
