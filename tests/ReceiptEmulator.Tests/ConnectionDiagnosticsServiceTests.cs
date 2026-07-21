using System.IO.Compression;
using System.Text.Json;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class ConnectionDiagnosticsServiceTests
{
    [Fact]
    public async Task SupportPackageManifestRemainsValidJsonAndRemovesPrivateValues()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var license = new LicenseService(new TestEnvironment(), new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root }).Build());
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license, new PrinterOptions(), profiles, NullLogger<PrinterListenerConfigurationService>.Instance);
        await using var manager = new PrinterListenerManager(
            configurations, profiles, new NoOpSink(), () => 1, new ServiceRuntimeState(),
            NullLoggerFactory.Instance, NullLogger<PrinterListenerManager>.Instance);
        var service = new ConnectionDiagnosticsService(manager, license, profiles, new SupportLogProvider(), NullLogger<ConnectionDiagnosticsService>.Instance);
        var report = new ConnectionDiagnosticReport(
            "PPE-DIAG-20260721-1234ABCD", DateTimeOffset.UtcNow, "0.3.33", "Windows user=Alice",
            [new ConnectionDiagnosticCheck("private", "Test", "Private check", DiagnosticStates.Failed,
                "Contact user@example.com at 192.168.1.10", @"Path C:\Users\Alice\receipt.bin; Password: hunter2")],
            [new PosConnectionDetail("default", "POS Printer Emulator", "192.168.1.10", 9100, false)], 0, 0, 1, 0);

        var package = await service.CreatePackageAsync(report);
        using var archive = new ZipArchive(new MemoryStream(package), ZipArchiveMode.Read);
        using var reader = new StreamReader(archive.GetEntry("manifest.json")!.Open());
        var manifest = await reader.ReadToEndAsync();

        using var document = JsonDocument.Parse(manifest);
        Assert.Equal("PPE-DIAG-20260721-1234ABCD", document.RootElement.GetProperty("packageId").GetString());
        Assert.DoesNotContain("user@example.com", manifest);
        Assert.DoesNotContain("192.168.1.10", manifest);
        Assert.DoesNotContain("Alice", manifest);
        Assert.DoesNotContain("hunter2", manifest);

        Microsoft.Data.Sqlite.SqliteConnection.ClearAllPools();
        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    private sealed class NoOpSink : IPrinterListenerJobSink
    {
        public bool Process(byte[] payload, string sourceIp, PrinterProfile profile, PrinterListenerJobContext listener, out string? rejection)
        {
            rejection = null;
            return true;
        }
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
