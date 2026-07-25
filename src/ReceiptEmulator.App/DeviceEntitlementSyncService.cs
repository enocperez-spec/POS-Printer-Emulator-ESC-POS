namespace ReceiptEmulator;

public sealed class DeviceEntitlementSyncService(
    AccountLinkService accountLink,
    LicenseService license,
    ReceiptStore store,
    PrinterListenerManager listeners,
    ILogger<DeviceEntitlementSyncService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // Allow secure installation registration to finish, then synchronize during startup.
        await Task.Delay(TimeSpan.FromSeconds(10), stoppingToken);
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await accountLink.SynchronizeAsync(stoppingToken);
            }
            catch (InvalidOperationException exception)
            {
                logger.LogDebug(
                    "Device entitlement synchronization did not complete: {Message}",
                    exception.Message);
            }
            catch (HttpRequestException exception)
            {
                logger.LogInformation(
                    exception,
                    "Device entitlement synchronization is waiting for network access");
            }
            catch (Exception exception)
            {
                logger.LogWarning(exception, "Device entitlement synchronization failed");
            }
            finally
            {
                store.ReconcileLicenseAccess();
                try
                {
                    await listeners.ReconcileAsync(stoppingToken);
                }
                catch (Exception exception)
                {
                    logger.LogWarning(exception, "Printer listener limits could not be reconciled after license synchronization");
                }
                logger.LogDebug(
                    "License synchronization state: {State}",
                    license.GetStatus().Synchronization.State);
            }
            await Task.Delay(TimeSpan.FromMinutes(15), stoppingToken);
        }
    }
}
