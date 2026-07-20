using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class PrinterListenerConfigurationServiceTests
{
    [Fact]
    public void SingleListenerTierUsesOneMemoryOnlyDefaultAndRejectsManagedChanges()
    {
        var root = NewRoot();
        var (service, _) = CreateService(root, maximumListeners: 1);

        var listener = Assert.Single(service.GetAll());

        Assert.Equal(PrinterListenerDefaults.DefaultId, listener.Id);
        Assert.Equal(PrinterListenerDefaults.DefaultPort, listener.Port);
        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
        Assert.Throws<UnauthorizedAccessException>(() => service.PrepareCreate(ValidInput("Kitchen", 9101)));
        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
    }

    [Fact]
    public void ManagedPrepareBindCommitFlowPersistsWithoutPrematureMutation()
    {
        var root = NewRoot();
        var (service, _) = CreateService(root, maximumListeners: 15);
        Assert.Single(service.GetAll());

        var prepared = service.PrepareCreate(ValidInput("Kitchen", 9101) with
        {
            ProfileId = PrinterProfileService.EpsonTmT88VId.ToUpperInvariant()
        });

        Assert.Single(service.GetAll());
        Assert.Equal(PrinterProfileService.EpsonTmT88VId, prepared.ProfileId);
        service.Commit(prepared);

        Assert.Equal(2, service.GetAll().Count);
        var (reloaded, _) = CreateService(root, maximumListeners: 15);
        Assert.Equal(prepared, reloaded.Find(prepared.Id));

        var update = reloaded.PrepareUpdate(prepared.Id, ValidInput("Kitchen Expo", 9102));
        reloaded.Commit(update);
        Assert.Equal(9102, reloaded.Find(prepared.Id)!.Port);
        reloaded.Remove(prepared.Id);
        Assert.Null(reloaded.Find(prepared.Id));
        Assert.Single(CreateService(root, maximumListeners: 15).Service.GetAll());
    }

    [Fact]
    public void RejectsInvalidReservedAndConflictingListenerEndpoints()
    {
        var (service, _) = CreateService(NewRoot(), maximumListeners: 15);
        service.Commit(service.PrepareCreate(ValidInput("Kitchen", 9101)));

        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Zero", 0)));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Viewer", 5187)));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("IPv6", 9102) with { BindAddress = "::1" }));
        Assert.Throws<ArgumentException>(() => service.PrepareCreate(ValidInput("Duplicate", 9101)));
        Assert.Throws<ArgumentException>(() => service.Remove(PrinterListenerDefaults.DefaultId));
    }

    [Theory]
    [InlineData(2)]
    [InlineData(15)]
    public void EnforcesLicensedListenerCap(int maximumListeners)
    {
        var (service, _) = CreateService(NewRoot(), maximumListeners);

        for (var index = 1; index < maximumListeners; index++)
        {
            service.Commit(service.PrepareCreate(ValidInput($"Listener {index + 1}", 9200 + index)));
        }

        Assert.Equal(maximumListeners, service.GetAll().Count);
        var exception = Assert.Throws<InvalidOperationException>(() =>
            service.PrepareCreate(ValidInput("Over limit", 9300)));
        Assert.Contains(maximumListeners.ToString(), exception.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void LowerTierHidesButPreservesConfigurationsAboveItsCapAndBlocksNewOnes()
    {
        var root = NewRoot();
        var (enterprise, _) = CreateService(root, maximumListeners: 15);
        for (var index = 1; index < 15; index++)
        {
            enterprise.Commit(enterprise.PrepareCreate(ValidInput($"Listener {index + 1}", 9400 + index)));
        }

        var database = new ReceiptDatabase(root);
        database.UpsertListenerConfiguration(new PrinterListenerConfiguration(
            "preserved-over-cap",
            "Preserved over cap",
            PrinterListenerDefaults.DefaultBindAddress,
            9499,
            PrinterProfileService.EpsonTmT88VId,
            true,
            PrinterListenerDefaults.DefaultIdleJobTimeoutMilliseconds,
            PrinterListenerDefaults.DefaultMaximumJobBytes,
            new PrinterListenerBufferConfiguration(),
            DateTimeOffset.UtcNow.AddMinutes(1),
            DateTimeOffset.UtcNow.AddMinutes(1)));
        Assert.Equal(16, database.LoadListenerConfigurations().Count);

        var (pro, _) = CreateService(root, maximumListeners: 2);
        Assert.Equal(2, pro.GetAll().Count);
        Assert.Equal(16, database.LoadListenerConfigurations().Count);
        Assert.Throws<InvalidOperationException>(() => pro.PrepareCreate(ValidInput("Blocked", 9500)));
        Assert.Equal(16, database.LoadListenerConfigurations().Count);

        pro.Remove(pro.GetAll()[1].Id);
        Assert.Equal(2, pro.GetAll().Count);
        Assert.Equal(15, database.LoadListenerConfigurations().Count);
    }

    private static (PrinterListenerConfigurationService Service, LicenseService License) CreateService(
        string root,
        int maximumListeners)
    {
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        var profiles = new PrinterProfileService(license);
        var options = new PrinterOptions();
        return (new PrinterListenerConfigurationService(license, options, profiles, () => maximumListeners), license);
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
