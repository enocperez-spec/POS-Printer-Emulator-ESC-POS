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

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
