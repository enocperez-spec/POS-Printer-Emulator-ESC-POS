using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class ReceiptProcessorTests
{
    [Fact]
    public void IgnoresControlOnlyConnectionWithoutConsumingTrialJob()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var processor = new ReceiptProcessor(new EscPosParser(), store, license, NullLogger<ReceiptProcessor>.Instance);

        var job = processor.Process(
            [0x1B, 0x40, 0x1B, 0x70, 0x00, 0x1B, 0x40],
            "127.0.0.1",
            out var rejection);

        Assert.Null(job);
        Assert.Equal("The connection contained printer control commands only.", rejection);
        Assert.Empty(store.GetSummaries());
        Assert.Equal(LicenseService.TrialDailyLimit, license.GetStatus().Remaining);
    }

    [Fact]
    public void ImportAndReplayDoNotConsumeTrialAllowance()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var processor = new ReceiptProcessor(new EscPosParser(), store, license, NullLogger<ReceiptProcessor>.Instance);
        var originalReceivedAt = new DateTimeOffset(2026, 7, 1, 10, 0, 0, TimeSpan.Zero);

        var imported = processor.Import(
            System.Text.Encoding.ASCII.GetBytes("IMPORTED RECEIPT\n"),
            "customer.bin",
            originalReceivedAt,
            "192.0.2.44",
            Guid.NewGuid(),
            out var importRejection);
        var replayed = processor.Replay(imported!, out var replayRejection);

        Assert.Null(importRejection);
        Assert.Null(replayRejection);
        Assert.NotNull(imported);
        Assert.NotNull(replayed);
        Assert.Equal(JobOrigins.Imported, imported.Origin);
        Assert.Equal("customer.bin", imported.ImportedFileName);
        Assert.Equal(originalReceivedAt, imported.OriginalReceivedAt);
        Assert.Equal(JobOrigins.Replayed, replayed.Origin);
        Assert.Equal(imported.Id, replayed.ParentJobId);
        Assert.Equal(2, store.GetSummaries().Count);
        Assert.Equal(LicenseService.TrialDailyLimit, license.GetStatus().Remaining);
    }

    [Fact]
    public void BuiltInTestReceiptsAreUnlimitedAndDoNotConsumeTrialAllowance()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var telemetry = new RecordingTelemetry();
        var processor = new ReceiptProcessor(new EscPosParser(), store, license, NullLogger<ReceiptProcessor>.Instance, telemetry);
        var profile = new PrinterProfileService(license).GetSelected();
        var listener = new PrinterListenerJobContext(PrinterListenerDefaults.DefaultId, PrinterListenerDefaults.DefaultName, 9100);

        foreach (var _ in Enumerable.Range(0, 12))
        {
            var sample = processor.ProcessTestReceipt(SampleReceipt.Create(), profile, listener, out var rejection);
            Assert.NotNull(sample);
            Assert.Null(rejection);
            Assert.Equal(JobOrigins.TestReceipt, sample.Origin);
            Assert.Equal("Completed", sample.Status);
        }

        Assert.Equal(LicenseService.TrialDailyLimit, license.GetStatus().Remaining);
        Assert.All(store.GetSummaries(), summary => Assert.Equal(JobOrigins.TestReceipt, summary.Origin));
        Assert.Equal(0, telemetry.PrintJobs);
    }

    [Fact]
    public void JobsAfterTrialLimitAreAcceptedWithOnlyTenLinesAndNoOriginalBytes()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var telemetry = new RecordingTelemetry();
        var processor = new ReceiptProcessor(new EscPosParser(), store, license, NullLogger<ReceiptProcessor>.Instance, telemetry);
        var payload = System.Text.Encoding.ASCII.GetBytes(string.Join('\n', Enumerable.Range(1, 15).Select(index => $"SECRET LINE {index:D2}")) + "\n");

        foreach (var count in Enumerable.Range(0, LicenseService.TrialDailyLimit))
        {
            Assert.NotNull(processor.Process(System.Text.Encoding.ASCII.GetBytes($"COUNTED {count}\n"), "127.0.0.1", out _));
        }

        var limited = processor.Process(payload, "127.0.0.1", out var rejection);

        Assert.NotNull(limited);
        Assert.Null(rejection);
        Assert.Equal(JobOrigins.TrialLimited, limited.Origin);
        Assert.Equal("Trial Limit Reached", limited.Status);
        Assert.Contains("SECRET LINE 10", limited.Receipt.PlainText);
        Assert.DoesNotContain("SECRET LINE 11", limited.Receipt.PlainText);
        Assert.DoesNotContain("SECRET LINE 15", limited.Receipt.PlainText);
        Assert.Contains("TRIAL LICENSE LIMIT REACHED", limited.Receipt.PlainText);
        Assert.DoesNotContain("SECRET LINE 11", System.Text.Encoding.UTF8.GetString(limited.RawPayload));
        Assert.Single(limited.Receipt.Commands);
        Assert.Equal(0, license.GetStatus().Remaining);
        Assert.Equal(LicenseService.TrialDailyLimit, telemetry.PrintJobs);
    }

    private sealed class RecordingTelemetry : IUsageTelemetry
    {
        public int PrintJobs { get; private set; }
        public void RecordPrintJob() => PrintJobs++;
        public void RecordActivation() { }
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
