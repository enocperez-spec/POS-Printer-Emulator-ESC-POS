using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using POSPrinterEmulator.Licensing;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class MaintenanceRefreshServiceTests
{
    [Fact]
    public async Task RefreshSynchronizesTheCurrentAccountDeviceEntitlement()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(
            Path.GetTempPath(),
            "POSPrinterEmulator.Tests",
            Guid.NewGuid().ToString("N"));
        var installationId = Guid.NewGuid();
        var licenseId = Guid.NewGuid();
        var customerId = Guid.NewGuid();
        var now = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var initialToken = DeviceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            licenseId,
            customerId,
            installationId,
            LicenseTier.Pro,
            now,
            now.AddMonths(1),
            1);
        var updatedToken = DeviceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            licenseId,
            customerId,
            installationId,
            LicenseTier.Enterprise,
            now,
            now.AddYears(1),
            2);
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem(),
            ["AccountLink:EntitlementEndpoint"] =
                "https://admin.posprinteremulator.com/api/v1/device-entitlement.php",
        }).Build();
        var license = new LicenseService(new TestEnvironment(), configuration, () => now);
        license.BindInstallationId(installationId);
        license.InstallDeviceEntitlement(
            "Verified Customer",
            "verified@example.com",
            initialToken);
        var accountLink = new AccountLinkService(
            new HttpClient(new EntitlementHandler(updatedToken)),
            new CredentialsProvider(installationId),
            license,
            new NoOpTelemetry(),
            configuration);
        var service = new MaintenanceRefreshService(accountLink, license);

        try
        {
            var result = await service.RefreshAsync();

            Assert.True(result.Updated);
            Assert.Equal("Enterprise", result.License.Mode);
            Assert.Equal(now.AddYears(1), result.License.Maintenance.ExpiresAt);
            Assert.Equal("synchronized", result.RemoteStatus);
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    private sealed class EntitlementHandler(string token) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(JsonSerializer.Serialize(new
                {
                    state = "Active",
                    deviceEntitlement = token,
                    customerName = "Verified Customer",
                    emailAddress = "verified@example.com",
                }), Encoding.UTF8, "application/json"),
            });
    }

    private sealed class CredentialsProvider(Guid installationId) : IInstallationCredentialsProvider
    {
        public Task<InstallationCredentials> GetCredentialsAsync(CancellationToken cancellationToken) =>
            Task.FromResult(new InstallationCredentials(
                installationId,
                "installation-token-abcdefghijklmnopqrstuvwxyz123456"));
    }

    private sealed class NoOpTelemetry : IUsageTelemetry
    {
        public void RecordPrintJob() { }
        public void RecordActivation() { }
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = AppContext.BaseDirectory;
        public IFileProvider ContentRootFileProvider { get; set; } =
            new NullFileProvider();
    }
}
