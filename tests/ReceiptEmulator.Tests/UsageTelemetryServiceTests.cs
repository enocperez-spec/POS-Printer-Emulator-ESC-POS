using System.Net;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class UsageTelemetryServiceTests
{
    [Fact]
    public async Task RetriesFailedPrintJobBatchWithoutDroppingTheCount()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Telemetry:Enabled"] = "true",
            ["Telemetry:Endpoint"] = "https://www.posprinteremulator.com/api/v1/telemetry.php",
            ["Telemetry:RetryDelaySeconds"] = "0.01"
        }).Build();
        var handler = new TelemetryHandler();
        var service = new UsageTelemetryService(
            new HttpClient(handler),
            new LicenseService(new ProductionEnvironment(), configuration),
            configuration,
            new ProductionEnvironment(),
            NullLogger<UsageTelemetryService>.Instance);

        try
        {
            await service.StartAsync(CancellationToken.None);
            service.RecordPrintJob();
            service.RecordPrintJob();

            var reportedCount = await handler.PrintJobsReported.Task.WaitAsync(TimeSpan.FromSeconds(5));

            Assert.Equal(2, reportedCount);
            Assert.Equal(2, handler.PrintJobAttempts);
            Assert.All(handler.Requests, request =>
            {
                Assert.Equal(HttpMethod.Post, request.Method);
                Assert.Equal("www.posprinteremulator.com", request.RequestUri!.Host);
            });
            Assert.All(handler.Payloads, payload =>
            {
                Assert.Equal("NotApplicable", payload.GetProperty("maintenanceStatus").GetString());
                Assert.Equal(JsonValueKind.Null, payload.GetProperty("maintenanceExpiresAt").ValueKind);
                Assert.False(payload.TryGetProperty("activationKey", out _));
                Assert.False(payload.TryGetProperty("entitlementToken", out _));
            });
        }
        finally
        {
            await service.StopAsync(CancellationToken.None);
            if (Directory.Exists(root)) Directory.Delete(root, true);
        }
    }

    private sealed class TelemetryHandler : HttpMessageHandler
    {
        public List<HttpRequestMessage> Requests { get; } = [];
        public List<JsonElement> Payloads { get; } = [];
        public TaskCompletionSource<int> PrintJobsReported { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public int PrintJobAttempts { get; private set; }

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests.Add(new HttpRequestMessage(request.Method, request.RequestUri));
            var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(cancellationToken));
            Payloads.Add(body.RootElement.Clone());
            var action = body.RootElement.GetProperty("action").GetString();
            if (action == "register")
            {
                return Json(HttpStatusCode.Created, "{\"token\":\"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN1234\"}");
            }

            var eventName = body.RootElement.GetProperty("event").GetString();
            if (eventName == "print_job")
            {
                PrintJobAttempts++;
                if (PrintJobAttempts == 1) return Json(HttpStatusCode.InternalServerError, "{}");
                var count = body.RootElement.GetProperty("count").GetInt32();
                PrintJobsReported.TrySetResult(count);
            }

            return Json(HttpStatusCode.OK, "{\"ok\":true}");
        }

        private static HttpResponseMessage Json(HttpStatusCode status, string content) => new(status)
        {
            Content = new StringContent(content, Encoding.UTF8, "application/json")
        };
    }

    private sealed class ProductionEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = Environments.Production;
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
