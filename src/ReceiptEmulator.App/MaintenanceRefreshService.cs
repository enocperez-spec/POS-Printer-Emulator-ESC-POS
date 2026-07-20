using System.Net.Http.Json;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator;

public sealed class MaintenanceRefreshService(
    HttpClient client,
    LicenseService license,
    ILogger<MaintenanceRefreshService> logger)
{
    public async Task<MaintenanceRefreshResult> RefreshAsync(CancellationToken cancellationToken = default)
    {
        var current = license.GetStatus();
        if (!current.IsPaid || current.LicenseId is null)
        {
            throw new InvalidOperationException("Activate a Lite, Pro, or Enterprise License before refreshing maintenance.");
        }

        var request = new RemoteMaintenanceRequest(
            current.LicenseId.Value.ToString("D").ToLowerInvariant(),
            ActivationKeyCodec.CreateRegistrationDigest(current.CustomerName, current.EmailAddress));

        try
        {
            using var response = await client.PostAsJsonAsync(
                "api/maintenance-entitlement.php",
                request,
                cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                logger.LogWarning(
                    "Maintenance refresh returned HTTP {StatusCode}",
                    (int)response.StatusCode);
                throw new InvalidOperationException(
                    (int)response.StatusCode == 429
                        ? "Maintenance status was checked too frequently. Wait a moment and try again."
                        : "The maintenance service could not refresh this license. Try again later.");
            }

            var remote = await response.Content.ReadFromJsonAsync<RemoteMaintenanceResponse>(
                cancellationToken: cancellationToken)
                ?? throw new InvalidOperationException("The maintenance service returned an empty response.");
            if (!string.Equals(remote.LicenseId, request.LicenseId, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("The maintenance service returned a response for a different license.");
            }

            var remoteStatus = remote.Status?.Trim().ToLowerInvariant()
                ?? throw new InvalidOperationException("The maintenance service returned an invalid status.");
            return remoteStatus switch
            {
                "active" => ApplyActiveEntitlement(remote),
                "expired" => UnavailableResult(
                    remote,
                    remoteStatus,
                    "The maintenance service confirms that coverage is expired. Renew to restore updates and assisted support."),
                "revoked" => UnavailableResult(
                    remote,
                    remoteStatus,
                    "The maintenance service reports that this coverage was revoked. Contact licensing support if this is unexpected."),
                "not_found" => CurrentResult(
                    current,
                    remoteStatus,
                    "No online maintenance record was found. Existing signed or grandfathered local coverage remains unchanged."),
                _ => throw new InvalidOperationException("The maintenance service returned an unknown status.")
            };
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (InvalidOperationException)
        {
            throw;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException)
        {
            logger.LogWarning(exception, "Maintenance status could not be refreshed");
            throw new InvalidOperationException(
                "The maintenance service could not be reached. Check your internet connection and try again.",
                exception);
        }
    }

    private MaintenanceRefreshResult ApplyActiveEntitlement(RemoteMaintenanceResponse remote)
    {
        if (string.IsNullOrWhiteSpace(remote.MaintenanceToken))
        {
            throw new InvalidOperationException("The maintenance service did not return a signed entitlement.");
        }

        var before = license.GetStatus().Maintenance;
        var updatedLicense = license.InstallMaintenanceEntitlement(remote.MaintenanceToken);
        var updated = updatedLicense.Maintenance.ExpiresAt != before.ExpiresAt ||
                      updatedLicense.Maintenance.IsActive != before.IsActive ||
                      !string.Equals(updatedLicense.Maintenance.State, before.State, StringComparison.Ordinal);
        return new MaintenanceRefreshResult(
            updatedLicense,
            updated,
            remote.Status,
            updated
                ? "Maintenance coverage was refreshed successfully."
                : "This computer already has the latest maintenance entitlement.");
    }

    private static MaintenanceRefreshResult CurrentResult(
        LicenseStatus current,
        string remoteStatus,
        string message) =>
        new(current, false, remoteStatus, message);

    private MaintenanceRefreshResult UnavailableResult(
        RemoteMaintenanceResponse remote,
        string remoteStatus,
        string message) =>
        new(
            license.RecordMaintenanceUnavailable(remoteStatus, remote.MaintenanceExpiresAt),
            true,
            remoteStatus,
            message);

    private sealed record RemoteMaintenanceRequest(string LicenseId, string RegistrationDigest);

    private sealed record RemoteMaintenanceResponse(
        string Status,
        DateTimeOffset ServerTime,
        string LicenseId,
        string? Tier,
        DateTimeOffset? MaintenanceExpiresAt,
        string? RenewalUrl,
        string? MaintenanceToken);
}
