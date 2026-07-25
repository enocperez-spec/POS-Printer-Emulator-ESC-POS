namespace ReceiptEmulator;

public sealed class MaintenanceRefreshService(
    AccountLinkService accountLink,
    LicenseService license)
{
    public async Task<MaintenanceRefreshResult> RefreshAsync(
        CancellationToken cancellationToken = default)
    {
        var before = license.GetStatus();
        if (!before.IsPaid || before.LicenseId is null)
        {
            throw new InvalidOperationException(
                "Link this computer to a verified Customer Portal account before synchronizing license coverage.");
        }

        var synchronized = await accountLink.SynchronizeAsync(cancellationToken);
        var updated = synchronized.Mode != before.Mode ||
                      synchronized.Maintenance.ExpiresAt != before.Maintenance.ExpiresAt ||
                      synchronized.Maintenance.IsActive != before.Maintenance.IsActive ||
                      !string.Equals(
                          synchronized.Maintenance.State,
                          before.Maintenance.State,
                          StringComparison.Ordinal);

        return new MaintenanceRefreshResult(
            synchronized,
            updated,
            "synchronized",
            updated
                ? "License and Maintenance and Support coverage were synchronized successfully."
                : "This computer already has the latest account entitlement.");
    }
}
