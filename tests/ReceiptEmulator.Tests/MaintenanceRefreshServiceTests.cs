using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using POSPrinterEmulator.Licensing;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class MaintenanceRefreshServiceTests
{
    [Fact]
    public async Task RefreshSendsOnlyLicenseIdAndRegistrationDigestThenAppliesSignedToken()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var license = CreateActivatedLicense(vendorKey, issuedAt, now);
        var licenseId = license.GetStatus().LicenseId!.Value;
        var maintenanceToken = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            licenseId,
            LicenseTier.Pro,
            now,
            now.AddYears(1));
        var handler = new RecordingHandler(JsonSerializer.Serialize(new
        {
            status = "active",
            serverTime = now,
            licenseId = licenseId.ToString("D").ToLowerInvariant(),
            tier = "Pro",
            maintenanceExpiresAt = now.AddYears(1),
            renewalUrl = "https://buy.posprinteremulator.com/?product=maintenance&tier=Pro",
            maintenanceToken
        }));
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") };
        var service = new MaintenanceRefreshService(client, license, NullLogger<MaintenanceRefreshService>.Instance);

        var result = await service.RefreshAsync();

        Assert.True(result.Updated);
        Assert.True(result.License.Maintenance.IsActive);
        Assert.Equal(now.AddYears(1), result.License.Maintenance.ExpiresAt);
        Assert.Equal("/api/maintenance-entitlement.php", handler.RequestUri?.AbsolutePath);
        Assert.NotNull(handler.RequestBody);
        using var request = JsonDocument.Parse(handler.RequestBody!);
        Assert.Equal(licenseId.ToString("D").ToLowerInvariant(), request.RootElement.GetProperty("licenseId").GetString());
        Assert.Equal(
            "58bc64ba32a49be9f133b7a3c8e4ae7f45663760542c5c15747525fb66944c21",
            request.RootElement.GetProperty("registrationDigest").GetString());
        Assert.Equal(2, request.RootElement.EnumerateObject().Count());
        Assert.DoesNotContain("PPE1-", handler.RequestBody, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ExpiredRemoteStatusDoesNotReplaceThePermanentLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var license = CreateActivatedLicense(vendorKey, issuedAt, now);
        var licenseId = license.GetStatus().LicenseId!.Value;
        var handler = new RecordingHandler(JsonSerializer.Serialize(new
        {
            status = "expired",
            serverTime = now,
            licenseId = licenseId.ToString("D").ToLowerInvariant(),
            tier = "Pro",
            maintenanceExpiresAt = issuedAt.AddYears(1),
            renewalUrl = "https://buy.posprinteremulator.com/?product=maintenance&tier=Pro"
        }));
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") };
        var service = new MaintenanceRefreshService(client, license, NullLogger<MaintenanceRefreshService>.Instance);

        var result = await service.RefreshAsync();

        Assert.True(result.Updated);
        Assert.Equal("expired", result.RemoteStatus);
        Assert.True(result.License.IsPaid);
        Assert.Equal("Pro", result.License.Mode);
        Assert.True(result.License.Features.History);
        Assert.False(result.License.Features.Updates);
    }

    [Theory]
    [InlineData("expired")]
    [InlineData("revoked")]
    public async Task AuthoritativeUnavailableStatusDisablesActiveSignedCoverageAcrossRestart(
        string remoteStatus)
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var license = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Remote Status Customer",
            "remote@example.com",
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1));
        var activated = license.Activate("Remote Status Customer", "remote@example.com", activationKey);
        var locallyActiveToken = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            activated.LicenseId!.Value,
            LicenseTier.Pro,
            now.AddMinutes(-1),
            now.AddYears(1));
        Assert.True(license.InstallMaintenanceEntitlement(locallyActiveToken).Maintenance.IsActive);
        var handler = new RecordingHandler(JsonSerializer.Serialize(new
        {
            status = remoteStatus,
            serverTime = now,
            licenseId = activated.LicenseId.Value.ToString("D").ToLowerInvariant(),
            tier = "Pro",
            maintenanceExpiresAt = remoteStatus == "expired" ? now.AddDays(-1) : (DateTimeOffset?)null,
            renewalUrl = "https://buy.posprinteremulator.com/?product=maintenance&tier=Pro"
        }));
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") };
        var service = new MaintenanceRefreshService(client, license, NullLogger<MaintenanceRefreshService>.Instance);

        var result = await service.RefreshAsync();
        var reloaded = new LicenseService(new TestEnvironment(), configuration, () => now).GetStatus();

        Assert.False(result.License.Maintenance.IsActive);
        Assert.False(result.License.Features.Updates);
        Assert.False(result.License.Features.Support);
        Assert.True(result.License.Features.History);
        Assert.True(result.License.Features.PremiumFeatures);
        Assert.False(reloaded.Maintenance.IsActive);
        Assert.Equal(result.License.Maintenance.State, reloaded.Maintenance.State);

        var newerToken = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            activated.LicenseId.Value,
            LicenseTier.Pro,
            now.AddSeconds(1),
            now.AddYears(1).AddDays(1));
        var restored = license.InstallMaintenanceEntitlement(newerToken);
        Assert.True(restored.Maintenance.IsActive);
        Assert.True(restored.Features.Updates);
    }

    [Fact]
    public async Task NetworkFailureLeavesPermanentFeaturesAndExistingMaintenanceUnchanged()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddMonths(6);
        var license = CreateActivatedLicense(vendorKey, issuedAt, now);
        var before = license.GetStatus();
        using var client = new HttpClient(new ThrowingHandler())
        {
            BaseAddress = new Uri("https://admin.posprinteremulator.com/")
        };
        var service = new MaintenanceRefreshService(client, license, NullLogger<MaintenanceRefreshService>.Instance);

        await Assert.ThrowsAsync<InvalidOperationException>(() => service.RefreshAsync());
        var after = license.GetStatus();

        Assert.True(after.IsPaid);
        Assert.True(after.Features.History);
        Assert.True(after.Features.PremiumFeatures);
        Assert.Equal(before.Maintenance, after.Maintenance);
    }

    [Fact]
    public async Task RefreshRejectsAResponseForAnotherLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var license = CreateActivatedLicense(vendorKey, issuedAt, now);
        var handler = new RecordingHandler(JsonSerializer.Serialize(new
        {
            status = "not_found",
            serverTime = now,
            licenseId = Guid.NewGuid().ToString("D"),
            tier = (string?)null,
            maintenanceExpiresAt = (DateTimeOffset?)null,
            renewalUrl = "https://buy.posprinteremulator.com/?product=maintenance&tier=Pro"
        }));
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") };
        var service = new MaintenanceRefreshService(client, license, NullLogger<MaintenanceRefreshService>.Instance);

        var exception = await Assert.ThrowsAsync<InvalidOperationException>(() => service.RefreshAsync());

        Assert.Contains("different license", exception.Message);
    }

    private static LicenseService CreateActivatedLicense(
        ECDsa vendorKey,
        DateTimeOffset issuedAt,
        DateTimeOffset now)
    {
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N")),
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var license = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Northwind Market",
            "owner@northwind.example",
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1));
        license.Activate("Northwind Market", "owner@northwind.example", activationKey);
        return license;
    }

    private sealed class RecordingHandler(string responseJson) : HttpMessageHandler
    {
        public Uri? RequestUri { get; private set; }
        public string? RequestBody { get; private set; }

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            RequestUri = request.RequestUri;
            RequestBody = await request.Content!.ReadAsStringAsync(cancellationToken);
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(responseJson, Encoding.UTF8, "application/json")
            };
        }
    }

    private sealed class ThrowingHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) =>
            throw new HttpRequestException("Network unavailable");
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
