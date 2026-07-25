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

public sealed class AccountLinkServiceTests
{
    [Fact]
    public async Task ApprovedPortalLinkActivatesLicenseAndConsumesSingleUseRequest()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var installationId = Guid.NewGuid();
        var linkId = Guid.NewGuid();
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Verified Customer",
            "verified@example.com",
            LicenseTier.Pro);
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem(),
            ["AccountLink:Endpoint"] = "https://www.posprinteremulator.com/api/v1/account-link.php",
        }).Build();
        var license = new LicenseService(new TestEnvironment(), configuration);
        license.BindInstallationId(installationId);
        var telemetry = new RecordingTelemetry();
        var handler = new LinkHandler(linkId, activationKey);
        var service = new AccountLinkService(
            new HttpClient(handler),
            new CredentialsProvider(installationId),
            license,
            telemetry,
            configuration);

        try
        {
            var started = await service.StartAsync(activationKey, CancellationToken.None);
            var completed = await service.CheckAsync(started.LinkId, started.RequestToken, CancellationToken.None);

            Assert.Equal("ABCD-2345", started.UserCode);
            Assert.Equal("Activated", completed.State);
            Assert.NotNull(completed.License);
            Assert.Equal("Pro", completed.License!.Mode);
            Assert.Equal(1, telemetry.Activations);
            Assert.Equal(["start", "status", "complete"], handler.Actions);
            Assert.All(handler.Tokens, token => Assert.Equal(
                "installation-token-abcdefghijklmnopqrstuvwxyz123456",
                token));
            Assert.Equal(activationKey, handler.StartActivationKey);
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    [Fact]
    public async Task ServerFailureDoesNotExposeUpstreamBody()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem(),
            ["AccountLink:Endpoint"] = "https://www.posprinteremulator.com/api/v1/account-link.php",
        }).Build();
        var service = new AccountLinkService(
            new HttpClient(new HtmlFailureHandler()),
            new CredentialsProvider(Guid.NewGuid()),
            new LicenseService(new TestEnvironment(), configuration),
            new RecordingTelemetry(),
            configuration);

        try
        {
            var exception = await Assert.ThrowsAsync<InvalidOperationException>(() =>
                service.StartAsync(null, CancellationToken.None));
            Assert.Contains("could not complete", exception.Message);
            Assert.DoesNotContain("private server detail", exception.Message);
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    private sealed class LinkHandler(Guid linkId, string activationKey) : HttpMessageHandler
    {
        public List<string> Actions { get; } = [];
        public List<string> Tokens { get; } = [];
        public string? StartActivationKey { get; private set; }

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            Tokens.Add(request.Headers.GetValues("X-Installation-Token").Single());
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(cancellationToken));
            var action = body.RootElement.GetProperty("action").GetString()!;
            Actions.Add(action);
            if (action == "start")
            {
                StartActivationKey = body.RootElement.GetProperty("activationKey").GetString();
                return Json(HttpStatusCode.Created, new
                {
                    state = "Pending",
                    linkId,
                    requestToken = "request-token-abcdefghijklmnopqrstuvwxyz12345",
                    userCode = "ABCD-2345",
                    expiresInSeconds = 600,
                    verificationUrl = "https://userportal.posprinteremulator.com/?link=ABCD-2345",
                    message = "Approve this computer.",
                });
            }
            if (action == "status")
            {
                return Json(HttpStatusCode.OK, new
                {
                    state = "Approved",
                    customerName = "Verified Customer",
                    emailAddress = "verified@example.com",
                    licenseId = Guid.NewGuid(),
                    licenseTier = "Pro",
                    activationKey,
                    message = "Approved.",
                });
            }
            return Json(HttpStatusCode.OK, new { state = "Consumed", message = "Complete." });
        }

        private static HttpResponseMessage Json(HttpStatusCode status, object body) => new(status)
        {
            Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json"),
        };
    }

    private sealed class CredentialsProvider(Guid installationId) : IInstallationCredentialsProvider
    {
        public Task<InstallationCredentials> GetCredentialsAsync(CancellationToken cancellationToken) =>
            Task.FromResult(new InstallationCredentials(
                installationId,
                "installation-token-abcdefghijklmnopqrstuvwxyz123456"));
    }

    private sealed class RecordingTelemetry : IUsageTelemetry
    {
        public int Activations { get; private set; }
        public void RecordPrintJob() { }
        public void RecordActivation() => Activations++;
    }

    private sealed class HtmlFailureHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(HttpStatusCode.InternalServerError)
            {
                Content = new StringContent("<html>private server detail</html>"),
            });
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
