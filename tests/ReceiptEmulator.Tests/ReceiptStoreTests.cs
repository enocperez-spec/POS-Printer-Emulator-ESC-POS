using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class ReceiptStoreTests
{
    [Fact]
    public void DeletesOneJobAndThenClearsAllRemainingJobs()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var first = CreateJob("FIRST");
        var second = CreateJob("SECOND");
        store.Add(first);
        store.Add(second);

        Assert.True(store.Delete(first.Id));
        Assert.False(store.Delete(first.Id));
        Assert.Single(store.GetSummaries());
        Assert.Equal(1, store.Clear());
        Assert.Empty(store.GetSummaries());
    }

    private static ReceiptJob CreateJob(string text)
    {
        var receipt = new ParsedReceipt();
        receipt.Lines.Add(new ReceiptLine("left", [new ReceiptSpan(text, false, false, 1, 1)]));
        return new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = DateTimeOffset.Now,
            SourceIp = "127.0.0.1",
            RawPayload = System.Text.Encoding.ASCII.GetBytes(text),
            Receipt = receipt,
            Status = "Completed"
        };
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
