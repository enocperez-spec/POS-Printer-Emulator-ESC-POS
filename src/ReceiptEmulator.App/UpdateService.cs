using System.Net;
using System.Net.Http.Json;
using System.Text.Json.Serialization;

namespace ReceiptEmulator;

public sealed record UpdateStatus(
    string CurrentVersion,
    string? LatestVersion,
    bool UpdateAvailable,
    bool CheckSucceeded,
    string? ReleaseUrl,
    string? DownloadUrl,
    DateTimeOffset CheckedAt,
    string Message);

public sealed class UpdateService(HttpClient client, ILogger<UpdateService> logger)
{
    private readonly SemaphoreSlim _checkLock = new(1, 1);
    private UpdateStatus? _cached;

    public async Task<UpdateStatus> CheckAsync(bool force, CancellationToken cancellationToken = default)
    {
        if (!force && _cached is { } cached && DateTimeOffset.UtcNow - cached.CheckedAt < TimeSpan.FromHours(1))
        {
            return cached;
        }

        await _checkLock.WaitAsync(cancellationToken);
        try
        {
            if (!force && _cached is { } lockedCached && DateTimeOffset.UtcNow - lockedCached.CheckedAt < TimeSpan.FromHours(1))
            {
                return lockedCached;
            }

            var checkedAt = DateTimeOffset.UtcNow;
            try
            {
                using var response = await client.GetAsync("releases/latest", cancellationToken);
                if (response.StatusCode == HttpStatusCode.NotFound)
                {
                    return _cached = new UpdateStatus(
                        ProductInfo.Version,
                        ProductInfo.Version,
                        false,
                        true,
                        "https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases",
                        null,
                        checkedAt,
                        "You already have the latest version installed. No newer public release is available.");
                }

                response.EnsureSuccessStatusCode();
                var release = await response.Content.ReadFromJsonAsync<GitHubRelease>(cancellationToken: cancellationToken)
                    ?? throw new InvalidOperationException("The update service returned an empty response.");
                var latest = release.TagName.Trim().TrimStart('v', 'V');
                var asset = release.Assets.FirstOrDefault(item =>
                    item.Name.EndsWith("-win-x64.exe", StringComparison.OrdinalIgnoreCase))
                    ?? release.Assets.FirstOrDefault(item => item.Name.EndsWith(".exe", StringComparison.OrdinalIgnoreCase));
                var newerRelease = CompareVersions(latest, ProductInfo.Version) > 0;
                // A release without a Windows installer (for example a documentation
                // or security-process release) must not be offered as an installable
                // desktop update. Never fall back to the release HTML page as an
                // installer URL.
                var updateAvailable = newerRelease && asset is not null;
                var message = updateAvailable
                    ? $"POS Printer Emulator {latest} is available."
                    : newerRelease
                        ? $"POS Printer Emulator {latest} is published, but no Windows installer is available yet."
                        : "You already have the latest version installed.";

                return _cached = new UpdateStatus(
                    ProductInfo.Version,
                    latest,
                    updateAvailable,
                    true,
                    release.HtmlUrl,
                    asset?.BrowserDownloadUrl,
                    checkedAt,
                    message);
            }
            catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or InvalidOperationException)
            {
                logger.LogWarning(exception, "The update check could not be completed");
                return _cached = Unavailable(checkedAt, "The update service could not be reached. Check your internet connection and try again.");
            }
        }
        finally
        {
            _checkLock.Release();
        }
    }

    public UpdateStatus? GetCached() => _cached;

    internal static int CompareVersions(string left, string right)
    {
        static int[] Parts(string value) => value.Trim().TrimStart('v', 'V').Split('.')
            .Select(part => int.TryParse(part, out var number) ? number : 0)
            .Concat(Enumerable.Repeat(0, 4))
            .Take(4)
            .ToArray();

        var leftParts = Parts(left);
        var rightParts = Parts(right);
        for (var index = 0; index < leftParts.Length; index++)
        {
            var comparison = leftParts[index].CompareTo(rightParts[index]);
            if (comparison != 0) return comparison;
        }

        return 0;
    }

    private static UpdateStatus Unavailable(DateTimeOffset checkedAt, string message) =>
        new(ProductInfo.Version, null, false, false, null, null, checkedAt, message);

    private sealed record GitHubRelease(
        [property: JsonPropertyName("tag_name")] string TagName,
        [property: JsonPropertyName("html_url")] string HtmlUrl,
        [property: JsonPropertyName("assets")] GitHubAsset[] Assets);

    private sealed record GitHubAsset(
        [property: JsonPropertyName("name")] string Name,
        [property: JsonPropertyName("browser_download_url")] string BrowserDownloadUrl);
}

public sealed class PeriodicUpdateChecker(UpdateService updates, LicenseService license, ILogger<PeriodicUpdateChecker> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        try
        {
            await Task.Delay(TimeSpan.FromSeconds(30), stoppingToken);
            while (!stoppingToken.IsCancellationRequested)
            {
                if (license.HasMaintenanceAccess)
                {
                    await updates.CheckAsync(false, stoppingToken);
                }
                await Task.Delay(TimeSpan.FromHours(6), stoppingToken);
            }
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
        {
            logger.LogDebug("Periodic update checks stopped");
        }
    }
}
