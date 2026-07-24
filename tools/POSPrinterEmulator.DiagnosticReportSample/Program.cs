using System.Security.Cryptography;
using System.Text;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using POSPrinterEmulator.Licensing;
using ReceiptEmulator;

var output = args.Length > 0
    ? Path.GetFullPath(args[0])
    : Path.GetFullPath(Path.Combine("output", "pdf", "POS-Printer-Emulator-Advanced-Diagnostics-Sample.pdf"));
var standard = args.Length > 1 && args[1].Equals("standard", StringComparison.OrdinalIgnoreCase);
Directory.CreateDirectory(Path.GetDirectoryName(output)!);
var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "DiagnosticReportSample", Guid.NewGuid().ToString("N"));
using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
{
    ["Data:Root"] = root,
    ["Licensing:PublicKeyPem"] = key.ExportSubjectPublicKeyInfoPem()
}).Build();
var license = new LicenseService(new SampleEnvironment(), configuration);
license.Activate(
    "Sample Enterprise Customer",
    "sample@example.com",
    ActivationKeyCodec.Issue(
        key.ExportECPrivateKeyPem(),
        "Sample Enterprise Customer",
        "sample@example.com",
        LicenseTier.Enterprise));
var store = new ReceiptStore(license);
var parser = new EscPosParser();
var processor = new ReceiptProcessor(parser, store, license, NullLogger<ReceiptProcessor>.Instance);
var payload = Encoding.ASCII.GetBytes(
    "\u001b@\u001ba\u0001POS PRINTER EMULATOR\nADVANCED DIAGNOSTIC SAMPLE\n\u001ba\u0000" +
    "1234 Glenridge Rd. NW\nAtlanta, GA 30342\n--------------------------------\n" +
    "ORDER #A-1042\nLatte                 $4.75\nBlueberry Muffin      $2.95\n" +
    "--------------------------------\nTOTAL                 $7.70\nCUSTOMER guest@example.com\n" +
    "CARD 4111 1111 1111 1111\nTHANK YOU\n\u001dV\u0000");
var job = processor.Process(payload, "192.168.1.25", out var rejection)
          ?? throw new InvalidOperationException(rejection ?? "The sample receipt was not created.");
var profiles = new PrinterProfileService(license);
var listenerConfigurations = new PrinterListenerConfigurationService(
    license,
    new PrinterOptions(),
    profiles,
    NullLogger<PrinterListenerConfigurationService>.Instance);
await using var listenerManager = new PrinterListenerManager(
    listenerConfigurations,
    processor,
    profiles,
    license,
    new ServiceRuntimeState(),
    NullLoggerFactory.Instance,
    NullLogger<PrinterListenerManager>.Instance);
var reports = new DiagnosticPdfService(
    store,
    license,
    listenerManager,
    new SupportLogProvider(),
    NullLogger<DiagnosticPdfService>.Instance);
var request = new DiagnosticPdfRequest(
    job.Id,
    IssueTitle: "Receipt content and command validation",
    ProblemDescription: "The receipt includes private fields and an unsupported command that must be reviewed safely.",
    ExpectedBehavior: "The developer receives a branded, organized, privacy-reviewed report.",
    ActualBehavior: "The receipt rendered with one command warning.",
    ReproductionSteps: $"1. Send the sample ESC/POS payload.\n2. Select the job.\n3. Export {(standard ? "Standard" : "Advanced")} Diagnostics PDF.",
    AdditionalNotes: "This sample intentionally contains an email address, a card-like number, and a private IP address.",
    SupportTicketNumber: "PPE-SAMPLE-1001",
    IncludeRawDataPreview: true,
    IncludeSourceIp: true,
    ConsentToCreate: true);
var report = standard ? reports.CreateStandard(request) : reports.CreateAdvanced(request);
await File.WriteAllBytesAsync(output, report.Content);
Console.WriteLine(output);
Console.WriteLine($"SHA-256: {report.Sha256}");
Console.WriteLine($"Bytes: {report.Content.Length:N0}");

sealed class SampleEnvironment : IHostEnvironment
{
    public string EnvironmentName { get; set; } = "Testing";
    public string ApplicationName { get; set; } = "POSPrinterEmulator.DiagnosticReportSample";
    public string ContentRootPath { get; set; } = Directory.GetCurrentDirectory();
    public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
}
