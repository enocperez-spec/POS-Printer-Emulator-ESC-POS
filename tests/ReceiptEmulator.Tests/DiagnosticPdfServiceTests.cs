using System.Security.Cryptography;
using System.Text;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using PdfSharp.Pdf.IO;
using POSPrinterEmulator.Licensing;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class DiagnosticPdfServiceTests
{
    [Fact]
    public async Task TrialCannotPreviewOrGenerateAnAdvancedReport()
    {
        var root = NewRoot();
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        var store = new ReceiptStore(license);
        var job = CreateJob();
        store.Add(job);
        await using var manager = Manager(license);
        var service = new DiagnosticPdfService(
            store, license, manager, new SupportLogProvider(), NullLogger<DiagnosticPdfService>.Instance);
        var request = new DiagnosticPdfRequest(job.Id);

        Assert.Throws<UnauthorizedAccessException>(() => service.PreviewAdvanced(request));
        Assert.Throws<UnauthorizedAccessException>(() => service.CreateAdvanced(request with { ConsentToCreate = true }));
    }

    [Fact]
    public async Task EnterpriseReportIsAReadablePdfWithBrandingMetadataAndIntegrity()
    {
        var root = NewRoot();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var license = new LicenseService(new TestEnvironment(), Configuration(root, key));
        var activation = ActivationKeyCodec.Issue(
            key.ExportECPrivateKeyPem(), "Enterprise Customer", "enterprise@example.com", LicenseTier.Enterprise);
        license.Activate("Enterprise Customer", "enterprise@example.com", activation);
        var store = new ReceiptStore(license);
        var job = CreateJob();
        store.Add(job);
        await using var manager = Manager(license);
        var service = new DiagnosticPdfService(
            store, license, manager, new SupportLogProvider(), NullLogger<DiagnosticPdfService>.Instance);

        var preview = service.PreviewAdvanced(new DiagnosticPdfRequest(
            job.Id,
            IssueTitle: "Receipt emailed to guest@example.com",
            ProblemDescription: "Card 4111 1111 1111 1111 was visible.",
            IncludeRawDataPreview: true));
        var report = service.CreateAdvanced(new DiagnosticPdfRequest(
            job.Id,
            IssueTitle: "Receipt emailed to guest@example.com",
            ProblemDescription: "Card 4111 1111 1111 1111 was visible.",
            IncludeRawDataPreview: true,
            ConsentToCreate: true));

        Assert.Equal(DiagnosticPdfKinds.Advanced, preview.ReportKind);
        Assert.Contains(preview.SensitiveFindings, finding => finding.Category == "Email address");
        Assert.StartsWith("%PDF-", Encoding.ASCII.GetString(report.Content, 0, 5));
        Assert.Equal(64, report.Sha256.Length);
        using var pdf = PdfReader.Open(new MemoryStream(report.Content), PdfDocumentOpenMode.Import);
        Assert.True(pdf.PageCount >= 2);
        Assert.Contains("POS Printer Emulator Advanced Diagnostics", pdf.Info.Title);
        Assert.Equal($"POS Printer Emulator {ProductInfo.Version}", pdf.Info.Author);
    }

    [Fact]
    public void RedactionMasksCredentialsAndCommonReceiptIdentifiers()
    {
        const string input =
            @"Email guest@example.com IP 192.168.1.25 Password: hunter2 Transaction #ABC-1234 Card 4111 1111 1111 1111 "
            + @"Local C:\Users\Alice\receipt.bin Network \\fileserver\private\receipt.bin";

        var result = DiagnosticPdfService.Redact(input);

        Assert.DoesNotContain("guest@example.com", result);
        Assert.DoesNotContain("192.168.1.25", result);
        Assert.DoesNotContain("hunter2", result);
        Assert.DoesNotContain("ABC-1234", result);
        Assert.DoesNotContain("4111 1111 1111 1111", result);
        Assert.DoesNotContain("Alice", result);
        Assert.DoesNotContain("fileserver", result);
    }

    private static ReceiptJob CreateJob()
    {
        var payload = Encoding.ASCII.GetBytes(
            "\u001b@CUSTOMER guest@example.com\nCARD 4111 1111 1111 1111\nTRANSACTION ABC-1234\nTHANK YOU\n");
        return new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = DateTimeOffset.Now,
            SourceIp = "192.168.1.25",
            RawPayload = payload,
            Receipt = new EscPosParser().Parse(payload),
            Status = "Completed"
        };
    }

    private static PrinterListenerManager Manager(LicenseService license)
    {
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license,
            new PrinterOptions(),
            profiles,
            NullLogger<PrinterListenerConfigurationService>.Instance);
        return new PrinterListenerManager(
            configurations,
            profiles,
            new NoOpSink(),
            () => license.MaximumListeners,
            new ServiceRuntimeState(),
            NullLoggerFactory.Instance,
            NullLogger<PrinterListenerManager>.Instance);
    }

    private static IConfiguration Configuration(string root, ECDsa? key = null) =>
        new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = key?.ExportSubjectPublicKeyInfoPem()
        }).Build();

    private static string NewRoot() =>
        Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));

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
