using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class PrinterListenerConfigurationServiceTests
{
    [Fact]
    public void TrialUsesOneMemoryOnlyDefaultAndRejectsEnterpriseChanges()
    {
        var root = NewRoot();
        var (service, _) = CreateService(root, enterprise: false);

        var listener = Assert.Single(service.GetAll());

        Assert.Equal(PrinterListenerDefaults.DefaultId, listener.Id);
        Assert.Equal(PrinterListenerDefaults.DefaultPort, listener.Port);
        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
        Assert.Throws<UnauthorizedAccessException>(() => service.PrepareCreate(ValidInput("Kitchen", 9101)));
        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
    }

    [Fact]
    public void EnterprisePrepareBindCommitFlowPersistsWithoutPrematureMutation()
    {
        var root = NewRoot();
        var (service, _) = CreateService(root, enterprise: true);
        Assert.Single(service.GetAll());

        var prepared = service.PrepareCreate(ValidInput("Kitchen", 9101) with
        {
            ProfileId = PrinterProfileService.EpsonTmT88VId.ToUpperInvariant()
        });

        Assert.Single(service.GetAll());
        Assert.Equal(PrinterProfileService.EpsonTmT88VId, prepared.ProfileId);
        service.Commit(prepared);

        Assert.Equal(2, service.GetAll().Count);
        var (reloaded, _) = CreateService(root, enterprise: true);
        Assert.Equal(prepared, reloaded.Find(prepared.Id));

        var update = reloaded.PrepareUpdate(prepared.Id, ValidInput("Kitchen Expo", 9102));
        reloaded.Commit(update);
        Assert.Equal(9102, reloaded.Find(prepared.Id)!.Port);
        reloaded.Remove(prepared.Id);
        Assert.Null(reloaded.Find(prepared.Id));
        Assert.Single(CreateService(root, enterprise: true).Service.GetAll());
    }

    [Fact]
    public void RejectsInvalidReservedAndConflictingListenerEndpoints()
    {
        var (service, _) = CreateService(NewRoot(), enterprise: true);
        service.Commit(service.PrepareCreate(ValidInput("Kitchen", 9101)));

        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Zero", 0)));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Viewer", 5187)));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("IPv6", 9102) with { BindAddress = "::1" }));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Duplicate", 9101)));
        Assert.Throws<ArgumentException>(() => service.Remove(PrinterListenerDefaults.DefaultId));
    }

    private static (PrinterListenerConfigurationService Service, LicenseService License) CreateService(
        string root,
        bool enterprise)
    {
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        var profiles = new PrinterProfileService(license);
        var options = new PrinterOptions();
        return (new PrinterListenerConfigurationService(license, options, profiles, () => enterprise), license);
    }

    private static PrinterListenerInput ValidInput(string name, int port) => new(
        name,
        PrinterListenerDefaults.DefaultBindAddress,
        port,
        PrinterProfileService.EpsonTmT88VId,
        true,
        PrinterListenerDefaults.DefaultIdleJobTimeoutMilliseconds,
        PrinterListenerDefaults.DefaultMaximumJobBytes,
        new PrinterListenerBufferConfiguration());

    private static IConfiguration Configuration(string root) => new ConfigurationBuilder()
        .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
        .Build();

    private static string NewRoot() => Path.Combine(
        Path.GetTempPath(),
        "POSPrinterEmulator.ListenerConfigurationTests",
        Guid.NewGuid().ToString("N"));

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
