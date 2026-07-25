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
    string Message);

public sealed record AccountLinkStatusResult(
    string State,
    string Message,
    LicenseStatus? License = null);

public sealed class AccountLinkService
{
    private readonly HttpClient _httpClient;
    private readonly IInstallationCredentialsProvider _credentials;
    private readonly LicenseService _license;
    private readonly IUsageTelemetry _telemetry;
    private readonly Uri _endpoint;
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
        var configured = configuration["AccountLink:Endpoint"] ??
                         "https://www.posprinteremulator.com/api/v1/account-link.php";
        if (!Uri.TryCreate(configured, UriKind.Absolute, out var endpoint) ||
            endpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException("The secure Customer Portal link service is not configured.");
        }
        _endpoint = endpoint;
    }

    public async Task<AccountLinkStartResult> StartAsync(
        string? activationKey,
        CancellationToken cancellationToken)
    {
        var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
        var response = await SendAsync(
            credentials,
            new AccountLinkServerRequest("start", credentials.InstallationId, null, null, activationKey),
            cancellationToken);
        if (!Guid.TryParse(response.LinkId, out var linkId) ||
            string.IsNullOrWhiteSpace(response.RequestToken) ||
            string.IsNullOrWhiteSpace(response.UserCode) ||
            string.IsNullOrWhiteSpace(response.VerificationUrl))
        {
            throw new InvalidOperationException("The secure link service returned an incomplete request. Try again.");
        }
        return new AccountLinkStartResult(
            response.State ?? "Pending",
            linkId,
            response.RequestToken,
            response.UserCode,
            Math.Max(1, response.ExpiresInSeconds ?? 600),
            response.VerificationUrl,
            response.Message ?? "Approve this computer in the Customer Portal.");
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
            new AccountLinkServerRequest("status", credentials.InstallationId, linkId, requestToken, null),
            cancellationToken);
        var state = response.State ?? "Unavailable";
        if (!state.Equals("Approved", StringComparison.OrdinalIgnoreCase))
        {
            return new AccountLinkStatusResult(
                state,
                response.Message ?? "Waiting for Customer Portal approval.");
        }
        if (string.IsNullOrWhiteSpace(response.CustomerName) ||
            string.IsNullOrWhiteSpace(response.EmailAddress) ||
            string.IsNullOrWhiteSpace(response.ActivationKey))
        {
            throw new InvalidOperationException("The approved license response was incomplete. Start a new link request.");
        }

        LicenseStatus activated;
        try
        {
            activated = _license.Activate(
                response.CustomerName,
                response.EmailAddress,
                response.ActivationKey);
        }
        catch (InvalidOperationException exception)
        {
            throw new InvalidOperationException(
                "The Customer Portal approved this computer, but the selected license could not be installed. " +
                exception.Message,
                exception);
        }

        await SendAsync(
            credentials,
            new AccountLinkServerRequest("complete", credentials.InstallationId, linkId, requestToken, null),
            cancellationToken);
        _telemetry.RecordActivation();
        return new AccountLinkStatusResult(
            "Activated",
            "This computer is linked to your verified Customer Portal account and the selected license is active.",
            activated);
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
                activationKey = payload.ActivationKey,
                appVersion = ProductInfo.Version,
            }),
        };
        request.Headers.Add("X-Installation-Token", credentials.Token);
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
        string? RequestToken,
        string? ActivationKey);

    private sealed record AccountLinkServerResponse(
        string? State,
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
        string? ActivationKey,
        string? Error);
}
