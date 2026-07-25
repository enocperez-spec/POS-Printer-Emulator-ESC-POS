namespace ReceiptEmulator;

public sealed class DeviceEntitlementSyncService(
    AccountLinkService accountLink,
    ILogger<DeviceEntitlementSyncService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await Task.Delay(TimeSpan.FromMinutes(1), stoppingToken);
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
            await Task.Delay(TimeSpan.FromMinutes(15), stoppingToken);
        }
    }
}
