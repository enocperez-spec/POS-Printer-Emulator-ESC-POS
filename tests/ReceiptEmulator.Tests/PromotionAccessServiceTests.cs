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

public sealed class PromotionAccessServiceTests
{
    [Fact]
    public async Task ConnectionFailureReturnsCustomerFriendlyMessage()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var installationId = Guid.NewGuid();
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem(),
        }).Build();
        var license = new LicenseService(new TestEnvironment(), configuration);
        var service = new PromotionAccessService(
            new HttpClient(new ConnectionFailureHandler()) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") },
            new CredentialsProvider(installationId),
            license);

        try
        {
            var exception = await Assert.ThrowsAsync<InvalidOperationException>(() =>
                service.GetOfferAsync(CancellationToken.None));

            Assert.Contains("could not connect to the licensing server", exception.Message);
            Assert.DoesNotContain("simulated transport detail", exception.Message);
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    [Fact]
    public async Task RetryReusesRequestIdAndActivatesWithoutManualKeyEntry()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var installationId = Guid.NewGuid();
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem(),
        }).Build();
        var license = new LicenseService(new TestEnvironment(), configuration);
        license.BindInstallationId(installationId);
        var handler = new RetryHandler(vendorKey, installationId);
        var service = new PromotionAccessService(
            new HttpClient(handler) { BaseAddress = new Uri("https://admin.posprinteremulator.com/") },
            new CredentialsProvider(installationId),
            license);

        try
        {
            await Assert.ThrowsAsync<InvalidOperationException>(() =>
                service.StartAsync("Enterprise", CancellationToken.None));

            var result = await service.StartAsync("Enterprise", CancellationToken.None);

            Assert.Equal("Enterprise", result.License.Mode);
            Assert.True(result.License.Promotion.IsActive);
            Assert.Equal(2, handler.RequestIds.Count);
            Assert.Equal(handler.RequestIds[0], handler.RequestIds[1]);
            Assert.All(handler.Tokens, token => Assert.Equal("installation-token-abcdefghijklmnopqrstuvwxyz123456", token));
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    private sealed class RetryHandler(ECDsa vendorKey, Guid installationId) : HttpMessageHandler
    {
        public List<Guid> RequestIds { get; } = [];
        public List<string> Tokens { get; } = [];

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            Tokens.Add(request.Headers.GetValues("X-Installation-Token").Single());
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(cancellationToken));
            var requestId = body.RootElement.GetProperty("requestId").GetGuid();
            RequestIds.Add(requestId);
            if (RequestIds.Count == 1)
            {
                return Json(HttpStatusCode.ServiceUnavailable, "{\"error\":\"Temporary interruption.\"}");
            }

            var now = DateTimeOffset.UtcNow;
            var token = PromotionEntitlementCodec.Issue(
                vendorKey.ExportECPrivateKeyPem(),
                requestId,
                PromotionSubjectType.Installation,
                installationId,
                now,
                now.AddDays(5),
                LicenseTier.Trial,
                LicenseTier.Enterprise);
            return Json(HttpStatusCode.OK, JsonSerializer.Serialize(new
            {
                ok = true,
                promotionId = requestId,
                previousTier = "Trial",
                grantedTier = "Enterprise",
                startsAt = now,
                expiresAt = now.AddDays(5),
                entitlementToken = token,
            }));
        }

        private static HttpResponseMessage Json(HttpStatusCode status, string content) => new(status)
        {
            Content = new StringContent(content, Encoding.UTF8, "application/json"),
        };
    }

    private sealed class CredentialsProvider(Guid installationId) : IInstallationCredentialsProvider
    {
        public Task<InstallationCredentials> GetCredentialsAsync(CancellationToken cancellationToken) =>
            Task.FromResult(new InstallationCredentials(
                installationId,
                "installation-token-abcdefghijklmnopqrstuvwxyz123456"));
    }

    private sealed class ConnectionFailureHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) =>
            throw new HttpRequestException("simulated transport detail");
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
