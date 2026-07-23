using System.Net;
using System.Text;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class UpdateServiceTests
{
    [Fact]
    public async Task ReportsANewerInstallerRelease()
    {
        var newerVersion = GetNewerVersion();
        using var client = CreateClient($$"""
            {
              "tag_name": "v{{newerVersion}}",
              "html_url": "https://github.com/example/releases/tag/v{{newerVersion}}",
              "assets": [
                {
                  "name": "POSPrinterEmulatorSetup-{{newerVersion}}-win-x64.exe",
                  "browser_download_url": "https://github.com/example/releases/download/v{{newerVersion}}/setup.exe"
                },
                {
                  "name": "POSPrinterEmulatorSetup-{{newerVersion}}-win-x64.exe.sha256",
                  "browser_download_url": "https://github.com/example/releases/download/v{{newerVersion}}/setup.exe.sha256"
                }
              ]
            }
            """);
        var service = new UpdateService(client, NullLogger<UpdateService>.Instance);

        var status = await service.CheckAsync(true);

        Assert.True(status.CheckSucceeded);
        Assert.True(status.UpdateAvailable);
        Assert.Equal(newerVersion, status.LatestVersion);
        Assert.EndsWith("setup.exe", status.DownloadUrl);
        Assert.EndsWith("setup.exe.sha256", status.ChecksumUrl);
    }

    [Fact]
    public async Task DoesNotOfferAnInstallerWithoutItsSecurityChecksum()
    {
        var newerVersion = GetNewerVersion();
        using var client = CreateClient($$"""
            {
              "tag_name": "v{{newerVersion}}",
              "html_url": "https://github.com/example/releases/tag/v{{newerVersion}}",
              "assets": [{
                "name": "POSPrinterEmulatorSetup-{{newerVersion}}-win-x64.exe",
                "browser_download_url": "https://github.com/example/releases/download/v{{newerVersion}}/setup.exe"
              }]
            }
            """);
        var service = new UpdateService(client, NullLogger<UpdateService>.Instance);

        var status = await service.CheckAsync(true);

        Assert.False(status.UpdateAvailable);
        Assert.Contains("security checksum", status.Message);
    }

    [Fact]
    public async Task ConfirmsWhenTheInstalledVersionIsCurrent()
    {
        using var client = CreateClient("""
            {
              "tag_name": "v0.3.04",
              "html_url": "https://github.com/example/releases/tag/v0.3.04",
              "assets": []
            }
            """);
        var service = new UpdateService(client, NullLogger<UpdateService>.Instance);

        var status = await service.CheckAsync(true);

        Assert.True(status.CheckSucceeded);
        Assert.False(status.UpdateAvailable);
        Assert.Contains("latest version", status.Message);
    }

    [Fact]
    public async Task DoesNotOfferAReleaseWithoutAWindowsInstaller()
    {
        var newerVersion = GetNewerVersion();
        using var client = CreateClient($$"""
            {
              "tag_name": "v{{newerVersion}}",
              "html_url": "https://github.com/example/releases/tag/v{{newerVersion}}",
              "assets": []
            }
            """);
        var service = new UpdateService(client, NullLogger<UpdateService>.Instance);

        var status = await service.CheckAsync(true);

        Assert.True(status.CheckSucceeded);
        Assert.False(status.UpdateAvailable);
        Assert.Null(status.DownloadUrl);
        Assert.Contains("no Windows installer", status.Message);
    }

    [Fact]
    public async Task TreatsAnEmptyPublicReleaseFeedAsCurrent()
    {
        using var client = new HttpClient(new JsonHandler("", HttpStatusCode.NotFound))
        {
            BaseAddress = new Uri("https://api.github.com/repos/example/project/")
        };
        var service = new UpdateService(client, NullLogger<UpdateService>.Instance);

        var status = await service.CheckAsync(true);

        Assert.True(status.CheckSucceeded);
        Assert.False(status.UpdateAvailable);
        Assert.Equal(ProductInfo.Version, status.LatestVersion);
        Assert.Contains("latest version installed", status.Message);
    }

    private static HttpClient CreateClient(string json) => new(new JsonHandler(json))
    {
        BaseAddress = new Uri("https://api.github.com/repos/example/project/")
    };

    private static string GetNewerVersion()
    {
        var installed = Version.Parse(ProductInfo.Version);
        return $"{installed.Major}.{installed.Minor}.{installed.Build + 1}";
    }

    private sealed class JsonHandler(string json, HttpStatusCode statusCode = HttpStatusCode.OK) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(statusCode)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            });
    }
}
