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

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
