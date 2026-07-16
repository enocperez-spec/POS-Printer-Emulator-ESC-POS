using System.Text;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class PrinterProfileServiceTests
{
    [Fact]
    public void StartsWithProtectedEpsonAndGenericProfiles()
    {
        var (_, profiles) = CreateServices();

        var status = profiles.GetStatus();

        Assert.Equal(PrinterProfileService.EpsonTmT88VId, status.SelectedProfileId);
        Assert.Equal(2, status.Profiles.Count);
        Assert.All(status.Profiles, profile => Assert.True(profile.BuiltIn));
        Assert.Contains(status.Profiles, profile => profile.Id == PrinterProfileService.GenericEscPosId && profile.PrintableDots == 512);
    }

    [Fact]
    public void CustomProfilePersistsAndCanBeSelected()
    {
        var root = NewRoot();
        var (license, profiles) = CreateServices(root);
        var created = profiles.Create(CustomInput("Kitchen Printer"));
        profiles.Select(created.Id);

        var reloaded = new PrinterProfileService(new LicenseService(new TestEnvironment(), Configuration(root)));
        var status = reloaded.GetStatus();

        Assert.Equal(created.Id, status.SelectedProfileId);
        Assert.Contains(status.Profiles, profile => profile.Name == "Kitchen Printer" && !profile.BuiltIn);
        GC.KeepAlive(license);
    }

    [Fact]
    public void RejectsAProfileWhoseDefaultCodePageIsNotSupported()
    {
        var (_, profiles) = CreateServices();
        var input = CustomInput("Invalid") with { DefaultCodePage = 1252, SupportedCodePages = [437, 850] };

        var exception = Assert.Throws<ArgumentException>(() => profiles.Create(input));

        Assert.Contains("default code page", exception.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ExportAndImportUseVersionedProfileDocument()
    {
        var (_, profiles) = CreateServices();
        var created = profiles.Create(CustomInput("Front Counter"));
        var document = profiles.Export(created.Id);
        profiles.Delete(created.Id);

        await using var input = new MemoryStream(document);
        var imported = await profiles.ImportAsync(input);

        Assert.Equal("Front Counter", imported.Name);
        Assert.False(imported.BuiltIn);
        Assert.Equal([437, 850, 1252], imported.SupportedCodePages);
    }

    [Fact]
    public void CapabilityMismatchBecomesPlainLanguageUnsupportedWarning()
    {
        var (_, profiles) = CreateServices();
        var profile = profiles.Create(CustomInput("Text Only") with
        {
            SupportedCodePages = [437],
            Capabilities = new PrinterCapabilities(false, false, false, false, false, false, false, false, false)
        });
        var receipt = new EscPosParser().Parse([0x1B, 0x74, 0x10, 0x1D, 0x56, 0x00, 0x41, 0x0A]);

        profiles.ApplyCapabilities(receipt, profile);

        Assert.Contains(receipt.Commands, command => !command.Supported && command.Details.Contains("CP1252", StringComparison.Ordinal));
        Assert.Contains(receipt.Commands, command => !command.Supported && command.Details.Contains("paper cutting", StringComparison.Ordinal));
    }

    [Fact]
    public void RasterImageLimitBecomesProfileWarning()
    {
        var (_, profiles) = CreateServices();
        var profile = profiles.Create(CustomInput("Small Image Buffer") with { MaximumRasterWidthDots = 400, MaximumRasterHeightDots = 500 });
        var receipt = new ParsedReceipt();
        receipt.Commands.Add(new ParsedCommand(0, "1D 76 30", "Print raster image", "576 x 600 dots, 1x width, 1x height"));

        profiles.ApplyCapabilities(receipt, profile);

        var warning = Assert.Single(receipt.Commands);
        Assert.False(warning.Supported);
        Assert.Contains("larger than 400 x 500", warning.Details);
    }

    [Fact]
    public void ReplayUsesCurrentProfileAndKeepsSourceProfileReference()
    {
        var (license, profiles) = CreateServices();
        profiles.Select(PrinterProfileService.GenericEscPosId);
        var store = new ReceiptStore(license);
        var processor = new ReceiptProcessor(new EscPosParser(), store, license, NullLogger<ReceiptProcessor>.Instance, null, profiles);
        var payload = new byte[] { 0x1B, 0x72, 0x01 }.Concat(Encoding.ASCII.GetBytes("PROFILE TEST\n")).ToArray();
        var original = processor.Process(payload, "127.0.0.1", out var firstRejection)!;
        profiles.Select(PrinterProfileService.EpsonTmT88VId);

        var replay = processor.Replay(original, out var replayRejection)!;

        Assert.Null(firstRejection);
        Assert.Null(replayRejection);
        Assert.Equal("Generic ESC/POS 80 mm", original.ProfileName);
        Assert.Contains(original.Receipt.Commands, command => !command.Supported && command.Details.Contains("two-color", StringComparison.Ordinal));
        Assert.Equal("EPSON TM-T88V Receipt5", replay.ProfileName);
        Assert.DoesNotContain(replay.Receipt.Commands, command => !command.Supported);
        Assert.Equal(original.ProfileId, replay.CapturedProfileId);
    }

    private static (LicenseService License, PrinterProfileService Profiles) CreateServices(string? root = null)
    {
        var license = new LicenseService(new TestEnvironment(), Configuration(root ?? NewRoot()));
        return (license, new PrinterProfileService(license));
    }

    private static PrinterProfileInput CustomInput(string name) => new(
        name, "Custom test profile", 80, 576, 576, 2304, 437, [437, 850, 1252], 48, 64,
        new PrinterCapabilities(true, true, true, true, true, true, false, true, true));

    private static IConfiguration Configuration(string root) => new ConfigurationBuilder()
        .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root }).Build();

    private static string NewRoot() => Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.ProfileTests", Guid.NewGuid().ToString("N"));

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
