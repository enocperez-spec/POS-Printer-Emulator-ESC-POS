using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;

namespace ReceiptEmulator.Tests;

public sealed class LicenseServiceTests
{
    [Fact]
    public void NewInstallationStartsInTrialAndStopsAfterFiveJobs()
    {
        var service = new LicenseService(new TestEnvironment());

        Assert.Equal("Trial", service.GetStatus().Mode);
        for (var count = 0; count < LicenseService.TrialDailyLimit; count++)
        {
            Assert.True(service.TryConsume(out _));
        }

        Assert.False(service.TryConsume(out var status));
        Assert.Equal(0, status.Remaining);
        Assert.True(status.Features.Watermark);
        Assert.False(status.Features.History);
        Assert.False(status.HasProAccess);
        Assert.False(status.IsEnterprise);
        Assert.False(status.Features.StoredLogos);
        Assert.False(status.Features.PrinterState);
        Assert.False(status.Features.PrinterProfiles);
        Assert.False(status.Features.Updates);
        Assert.False(status.Features.Support);
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
