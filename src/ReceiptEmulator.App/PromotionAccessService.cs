using System.Net.Http.Json;
using System.Text.Json;

namespace ReceiptEmulator;

public sealed class PromotionAccessService
{
    private readonly HttpClient _httpClient;
    private readonly IInstallationCredentialsProvider _credentials;
    private readonly LicenseService _license;
    private readonly string _pendingPath;

    public PromotionAccessService(
        HttpClient httpClient,
        IInstallationCredentialsProvider credentials,
        LicenseService license)
    {
        _httpClient = httpClient;
        _credentials = credentials;
        _license = license;
        _pendingPath = Path.Combine(license.RootPath, "promotion-request.json");
    }

    public async Task<PromotionOfferStatus> GetOfferAsync(CancellationToken cancellationToken)
    {
        var response = await SendAsync(
            new PromotionServerRequest("status", null, null),
            cancellationToken);
        return response.ToOffer();
    }

    public async Task<PromotionStartResult> StartAsync(
        string grantedTier,
        CancellationToken cancellationToken)
    {
        if (grantedTier is not "Lite" and not "Pro" and not "Enterprise")
        {
            throw new InvalidOperationException("Choose Lite, Pro, or Enterprise.");
        }

        var pending = LoadPending();
        if (pending is null || !pending.GrantedTier.Equals(grantedTier, StringComparison.Ordinal))
        {
            pending = new PendingPromotionRequest(Guid.NewGuid(), grantedTier);
            try
            {
                SavePending(pending);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                throw new InvalidOperationException(
                    "The application could not save the secure trial request. Restart POS Printer Emulator and try again.",
                    exception);
            }
        }

        var response = await SendAsync(
            new PromotionServerRequest("start", grantedTier, pending.RequestId),
            cancellationToken);
        if (string.IsNullOrWhiteSpace(response.EntitlementToken))
        {
            throw new InvalidOperationException("The licensing server did not return a promotional entitlement.");
        }

        var license = _license.InstallPromotionEntitlement(response.EntitlementToken);
        TryDeletePending();
        return new PromotionStartResult(
            license,
            response.PromotionId ?? pending.RequestId,
            response.GrantedTier ?? grantedTier,
            response.StartsAt,
            response.ExpiresAt,
            "Five-Day Trial Active");
    }

    private async Task<PromotionServerResponse> SendAsync(
        PromotionServerRequest payload,
        CancellationToken cancellationToken)
    {
        var credentials = await _credentials.GetCredentialsAsync(cancellationToken);
        using var request = new HttpRequestMessage(HttpMethod.Post, "api/v1/desktop-promotion.php")
        {
            Content = JsonContent.Create(new
            {
                payload.Action,
                installationId = credentials.InstallationId,
                payload.GrantedTier,
                payload.RequestId,
                appVersion = ProductInfo.Version,
            })
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
                "The licensing server took too long to respond. Check the internet connection and try again.",
                exception);
        }
        catch (HttpRequestException exception)
        {
            throw new InvalidOperationException(
                "The application could not connect to the licensing server. Check the internet connection and try again.",
                exception);
        }
        using (response)
        {
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            PromotionServerResponse? result = null;
            try
            {
                result = JsonSerializer.Deserialize<PromotionServerResponse>(
                    body,
                    new JsonSerializerOptions(JsonSerializerDefaults.Web));
            }
            catch (JsonException)
            {
                // The public error below deliberately avoids returning server HTML or diagnostics.
            }
            if (!response.IsSuccessStatusCode)
            {
                throw new InvalidOperationException(
                    string.IsNullOrWhiteSpace(result?.Error)
                        ? "The licensing server could not complete the promotional-trial request."
                        : result.Error);
            }
            return result ?? throw new InvalidOperationException("The licensing server returned an invalid response.");
        }
    }

    private PendingPromotionRequest? LoadPending()
    {
        try
        {
            return File.Exists(_pendingPath)
                ? JsonSerializer.Deserialize<PendingPromotionRequest>(File.ReadAllText(_pendingPath))
                : null;
        }
        catch
        {
            return null;
        }
    }

    private void SavePending(PendingPromotionRequest request)
    {
        var temporary = _pendingPath + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(request));
        File.Move(temporary, _pendingPath, true);
    }

    private void TryDeletePending()
    {
        try
        {
            File.Delete(_pendingPath);
        }
        catch
        {
            // A completed entitlement remains valid; a stale idempotency record is harmless.
        }
    }

    private sealed record PendingPromotionRequest(Guid RequestId, string GrantedTier);
    private sealed record PromotionServerRequest(string Action, string? GrantedTier, Guid? RequestId);
    private sealed record PromotionServerResponse(
        bool Ok,
        string? State,
        string[]? EligibleTiers,
        string? PreviousTier,
        string? GrantedTier,
        DateTimeOffset? StartsAt,
        DateTimeOffset? ExpiresAt,
        string? PurchaseUrl,
        string? VerificationUrl,
        string? Message,
        Guid? PromotionId,
        string? EntitlementToken,
        string? Error)
    {
        public PromotionOfferStatus ToOffer() => new(
            State ?? "Unavailable",
            EligibleTiers ?? [],
            PreviousTier,
            GrantedTier,
            StartsAt,
            ExpiresAt,
            PurchaseUrl,
            VerificationUrl,
            Message ?? "Promotional-trial status is unavailable.");
    }
}
