using System.Diagnostics;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using MigraDoc.DocumentObjectModel;
using MigraDoc.DocumentObjectModel.Tables;
using MigraDoc.Rendering;
using PdfSharp.Fonts;

namespace ReceiptEmulator;

public sealed partial class DiagnosticPdfService(
    ReceiptStore jobs,
    LicenseService license,
    PrinterListenerManager listeners,
    SupportLogProvider logs,
    ILogger<DiagnosticPdfService> logger)
{
    public const string ReportFormatVersion = "1.0";
    private const int MaximumReceiptImageBytes = 20 * 1024 * 1024;
    private const int MaximumCommandsInAdvancedPdf = 500;
    private const int MaximumRawPreviewBytes = 2048;
    private const int MaximumLogCharacters = 24_000;
    private static readonly object FontResolverSync = new();
    private static readonly string[] AdvancedSections =
    [
        "Report summary and customer-provided issue details",
        "Rendered receipt preview or privacy-safe omission notice",
        "Diagnostic summary",
        "Complete parsed ESC/POS command analysis (up to 500 commands)",
        "Optional shortened raw print-data preview",
        "Print-job and listener configuration",
        "Application and Windows environment",
        "Performance timeline",
        "Warnings, errors, and unsupported commands",
        "Relevant redacted application logs",
        "SHA-256 integrity information",
        "Privacy and redaction manifest"
    ];
    private static readonly string[] StandardSections =
    [
        "Report summary and customer-provided issue details",
        "Rendered receipt preview or privacy-safe omission notice",
        "Application, job, listener, and printer-profile summary",
        "High-priority warnings and unsupported-command summary",
        "Recent relevant redacted errors",
        "Recommended next actions",
        "SHA-256 integrity information",
        "Privacy and redaction manifest"
    ];
    private static readonly string[] AlwaysExcluded =
    [
        "activation, maintenance, and promotional license keys",
        "passwords, API keys, authentication tokens, cookies, and encryption keys",
        "customer registration name and email address",
        "Windows username, full computer name, product key, and hardware serial numbers",
        "receipt jobs other than the selected job",
        "unrelated application logs"
    ];

    public DiagnosticPdfPreview PreviewAdvanced(DiagnosticPdfRequest request)
    {
        EnsureEnterprise();
        var job = GetJob(request.JobId);
        var findings = DetectSensitiveData(job);
        var receiptTreatment = ReceiptImageTreatment(request, findings);
        var rawTreatment = request.IncludeRawDataPreview
            ? request.RedactSensitiveData
                ? "A shortened, decoded preview is included after deterministic redaction; reconstructable raw hexadecimal bytes are excluded."
                : "A shortened raw hexadecimal and decoded preview is included without redaction."
            : "Raw print data is excluded. Only byte counts and SHA-256 integrity values are included.";
        var excluded = new List<string>();
        if (!request.IncludeReceiptImage || receiptTreatment.StartsWith("Omitted", StringComparison.Ordinal))
            excluded.Add("Rendered receipt image");
        if (!request.IncludeRawDataPreview)
            excluded.Add("Raw print-data preview");
        if (!request.IncludeSourceIp)
            excluded.Add("Source IP address");
        if (request.RedactSensitiveData)
            excluded.Add("Detected sensitive values (masked or omitted)");

        return new DiagnosticPdfPreview(
            DiagnosticPdfKinds.Advanced,
            CreateReportId(),
            job.Id,
            JobLabel(job),
            AdvancedSections,
            excluded,
            AlwaysExcluded,
            findings,
            request.RedactSensitiveData,
            true,
            receiptTreatment,
            rawTreatment,
            EstimateSize(job, request));
    }

    public DiagnosticPdfFile CreateAdvanced(DiagnosticPdfRequest request)
    {
        EnsureEnterprise();
        if (!request.ConsentToCreate)
            throw new InvalidOperationException("Review the diagnostic report contents and provide consent before creating the PDF.");

        var job = GetJob(request.JobId);
        var findings = DetectSensitiveData(job);
        var reportId = CreateReportId();
        byte[]? receiptImage = null;
        var receiptTreatment = ReceiptImageTreatment(request, findings);
        if (request.IncludeReceiptImage &&
            !receiptTreatment.StartsWith("Omitted", StringComparison.Ordinal) &&
            !string.IsNullOrWhiteSpace(request.ReceiptImageBase64))
        {
            receiptImage = DecodeReceiptImage(request.ReceiptImageBase64);
        }

        var started = Stopwatch.StartNew();
        var document = BuildAdvancedDocument(reportId, request, job, findings, receiptImage, receiptTreatment);
        var bytes = Render(document);
        started.Stop();
        logger.LogInformation(
            "Created redacted advanced diagnostic PDF {ReportId} for job {JobId} in {ElapsedMilliseconds} ms; bytes {Size}",
            reportId,
            job.Id,
            started.ElapsedMilliseconds,
            bytes.Length);
        return new DiagnosticPdfFile(
            bytes,
            $"POS-Printer-Emulator-Advanced-Diagnostics-{reportId}.pdf",
            reportId,
            Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant());
    }

    public DiagnosticPdfPreview PreviewStandard(DiagnosticPdfRequest request)
    {
        EnsureEnterprise();
        var job = GetJob(request.JobId);
        var standardRequest = request with
        {
            IncludeRawDataPreview = false,
            IncludeSourceIp = false,
            RedactSensitiveData = true
        };
        var findings = DetectSensitiveData(job);
        var receiptTreatment = ReceiptImageTreatment(standardRequest, findings);
        var excluded = new List<string>
        {
            "Raw print-data preview and complete command table",
            "Source and bind IP addresses",
            "Detailed performance and environment tables",
            "Unredacted export mode"
        };
        if (!standardRequest.IncludeReceiptImage ||
            receiptTreatment.StartsWith("Omitted", StringComparison.Ordinal))
            excluded.Add("Rendered receipt image");

        return new DiagnosticPdfPreview(
            DiagnosticPdfKinds.Standard,
            CreateReportId(),
            job.Id,
            JobLabel(job),
            StandardSections,
            excluded,
            AlwaysExcluded,
            findings,
            true,
            true,
            receiptTreatment,
            "Raw print data is excluded. Only byte counts and a SHA-256 integrity value are included.",
            Math.Max(110_000, EstimateSize(job, standardRequest) / 3));
    }

    public DiagnosticPdfFile CreateStandard(DiagnosticPdfRequest request)
    {
        EnsureEnterprise();
        if (!request.ConsentToCreate)
            throw new InvalidOperationException("Review the diagnostic report contents and provide consent before creating the PDF.");

        var standardRequest = request with
        {
            IncludeRawDataPreview = false,
            IncludeSourceIp = false,
            RedactSensitiveData = true
        };
        var job = GetJob(standardRequest.JobId);
        var findings = DetectSensitiveData(job);
        var reportId = CreateReportId();
        byte[]? receiptImage = null;
        var receiptTreatment = ReceiptImageTreatment(standardRequest, findings);
        if (standardRequest.IncludeReceiptImage &&
            !receiptTreatment.StartsWith("Omitted", StringComparison.Ordinal) &&
            !string.IsNullOrWhiteSpace(standardRequest.ReceiptImageBase64))
        {
            receiptImage = DecodeReceiptImage(standardRequest.ReceiptImageBase64);
        }

        var started = Stopwatch.StartNew();
        var document = BuildStandardDocument(
            reportId, standardRequest, job, findings, receiptImage, receiptTreatment);
        var bytes = Render(document);
        started.Stop();
        logger.LogInformation(
            "Created redacted standard diagnostic PDF {ReportId} for job {JobId} in {ElapsedMilliseconds} ms; bytes {Size}",
            reportId,
            job.Id,
            started.ElapsedMilliseconds,
            bytes.Length);
        return new DiagnosticPdfFile(
            bytes,
            $"POS-Printer-Emulator-Standard-Diagnostics-{reportId}.pdf",
            reportId,
            Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant());
    }

    private Document BuildAdvancedDocument(
        string reportId,
        DiagnosticPdfRequest request,
        ReceiptJob job,
        IReadOnlyList<DiagnosticSensitiveFinding> findings,
        byte[]? receiptImage,
        string receiptTreatment)
    {
        EnsureFontResolver();
        var status = license.GetStatus();
        var listener = listeners.GetStatus().Listeners.FirstOrDefault(candidate =>
            candidate.Configuration.Id.Equals(job.ListenerId, StringComparison.OrdinalIgnoreCase));
        var document = CreateDocument(reportId, DiagnosticPdfKinds.Advanced);
        var section = document.AddSection();
        ConfigureSection(section, reportId);
        AddReportHeader(section, "Advanced Diagnostics Report", reportId);
        AddNotice(section,
            request.RedactSensitiveData
                ? "Privacy protection is enabled. Detected sensitive values are masked, and credential material is always excluded."
                : "WARNING: Redaction was disabled by the customer. This report may contain sensitive receipt or network data.",
            request.RedactSensitiveData ? "#E8F7F1" : "#FFF0E6",
            request.RedactSensitiveData ? "#087A55" : "#A34600");

        AddHeading(section, "1. Report summary", 1);
        AddKeyValueTable(section,
        [
            ("Report ID", reportId),
            ("Diagnostic report format", ReportFormatVersion),
            ("Created", DateTimeOffset.Now.ToString("yyyy-MM-dd HH:mm:ss zzz")),
            ("Application", $"POS Printer Emulator {ProductInfo.Version}"),
            ("License tier", status.Mode),
            ("Maintenance", status.Maintenance.State),
            ("Print-job ID", job.Id.ToString()),
            ("Job received", job.ReceivedAt.ToString("yyyy-MM-dd HH:mm:ss zzz")),
            ("Report status", "Generated successfully"),
            ("Redaction", request.RedactSensitiveData ? "Enabled" : "Disabled by customer consent"),
            ("Support ticket", Safe(request.SupportTicketNumber, request.RedactSensitiveData))
        ]);

        AddHeading(section, "2. Customer-provided issue information", 1);
        AddIssueBlock(section, "Issue title", request.IssueTitle, request.RedactSensitiveData);
        AddIssueBlock(section, "Problem description", request.ProblemDescription, request.RedactSensitiveData);
        AddIssueBlock(section, "Expected behavior", request.ExpectedBehavior, request.RedactSensitiveData);
        AddIssueBlock(section, "Actual behavior", request.ActualBehavior, request.RedactSensitiveData);
        AddIssueBlock(section, "Reproduction steps", request.ReproductionSteps, request.RedactSensitiveData);
        AddIssueBlock(section, "Additional notes", request.AdditionalNotes, request.RedactSensitiveData);

        AddHeading(section, "3. Receipt preview", 1);
        section.AddParagraph(receiptTreatment).Format.Font.Color = Color.Parse("#52647A");
        if (receiptImage is not null)
        {
            var image = section.AddImage(ToBase64Image(receiptImage));
            image.LockAspectRatio = true;
            image.Width = Unit.FromInch(3.4);
            image.Interpolate = false;
            AddCaption(section, $"Receipt image: {receiptImage.Length:N0} bytes; paper width {job.ProfilePaperWidthMm} mm; profile {job.ProfileName}.");
        }
        else
        {
            AddNotice(section, "No receipt pixels are present in this report. The original local print job was not changed.", "#F4F7FA", "#52647A");
        }
        AddKeyValueTable(section,
        [
            ("Paper width", $"{job.ProfilePaperWidthMm} mm"),
            ("Printable width", $"{job.ProfilePrintableDots} dots"),
            ("Printer profile", job.ProfileName),
            ("Characters per line", EstimateCharactersPerLine(job).ToString()),
            ("Rendering mode", "ESC/POS receipt viewer"),
            ("Background", "White")
        ]);

        AddHeading(section, "4. Diagnostic summary", 1);
        var commands = job.Receipt.Commands;
        var textCharacters = job.Receipt.PlainText.Length;
        var imageCount = job.Receipt.Lines.Count(line => line.Kind is "image" or "graphic");
        var barcodeCount = job.Receipt.Lines.Count(line => line.Kind.Equals("barcode", StringComparison.OrdinalIgnoreCase));
        var qrCount = job.Receipt.Lines.Count(line => line.Kind.Equals("qr", StringComparison.OrdinalIgnoreCase));
        AddKeyValueTable(section,
        [
            ("Total bytes received", job.RawPayload.Length.ToString("N0")),
            ("Parsed commands", commands.Count.ToString("N0")),
            ("Printable characters", textCharacters.ToString("N0")),
            ("Images and graphics", imageCount.ToString("N0")),
            ("Barcodes", barcodeCount.ToString("N0")),
            ("QR codes", qrCount.ToString("N0")),
            ("Unsupported commands", job.UnsupportedCount.ToString("N0")),
            ("Warnings", job.UnsupportedCount.ToString("N0")),
            ("Errors", string.IsNullOrWhiteSpace(job.Error) ? "0" : "1"),
            ("Final job status", DiagnosticStatus(job))
        ]);

        AddHeading(section, "5. Parsed ESC/POS command analysis", 1);
        AddCommandTable(section, job, request.RedactSensitiveData);

        AddHeading(section, "6. Raw print data", 1);
        AddRawData(section, job, request);

        AddHeading(section, "7. Print-job details", 1);
        AddKeyValueTable(section,
        [
            ("Job ID", job.Id.ToString()),
            ("Status", job.Status),
            ("Origin", job.Origin),
            ("Listener", $"{job.ListenerName} ({job.ListenerId})"),
            ("Source address", request.IncludeSourceIp ? RedactIp(job.SourceIp, request.RedactSensitiveData) : "Excluded by customer selection"),
            ("Destination", $"{listener?.Configuration.BindAddress ?? "Not captured"}:{job.ListenerPort}"),
            ("Protocol", listener?.Configuration.Protocol ?? PrinterListenerDefaults.RawTcpProtocol),
            ("Received", job.ReceivedAt.ToString("O")),
            ("Payload", $"{job.PayloadSize:N0} bytes"),
            ("Printer profile", $"{job.ProfileName} ({job.ProfileId})"),
            ("Paper width", $"{job.ProfilePaperWidthMm} mm"),
            ("Renderer version", job.RendererVersion),
            ("Parent job", job.ParentJobId?.ToString() ?? "Not applicable"),
            ("Imported file", Safe(job.ImportedFileName, request.RedactSensitiveData))
        ]);

        AddHeading(section, "8. Listener configuration snapshot", 1);
        if (listener is null)
        {
            section.AddParagraph("The listener configuration active for this job is no longer available.");
        }
        else
        {
            var configuration = listener.Configuration;
            AddKeyValueTable(section,
            [
                ("Listener name", configuration.Name),
                ("Listener ID", configuration.Id),
                ("Bind address", request.IncludeSourceIp ? RedactIp(configuration.BindAddress, request.RedactSensitiveData) : "Excluded by customer selection"),
                ("Port", configuration.Port.ToString()),
                ("Protocol", configuration.Protocol),
                ("Enabled", configuration.Enabled ? "Yes" : "No"),
                ("Runtime state", listener.State),
                ("Printer profile ID", configuration.ProfileId),
                ("Idle job timeout", $"{configuration.IdleJobTimeoutMilliseconds:N0} ms"),
                ("Maximum job bytes", configuration.MaximumJobBytes.ToString("N0")),
                ("Buffer enabled", configuration.Buffer.Enabled ? "Yes" : "No"),
                ("Buffer capacity", configuration.Buffer.Capacity.ToString()),
                ("Buffer delay", $"{configuration.Buffer.ProcessingDelayMilliseconds:N0} ms"),
                ("Overflow behavior", configuration.Buffer.OverflowBehavior),
                ("Configuration revision", configuration.UpdatedAt.ToString("O"))
            ]);
        }

        AddHeading(section, "9. Application environment", 1);
        using (var process = Process.GetCurrentProcess())
        {
            AddKeyValueTable(section,
            [
                ("Application version", ProductInfo.Version),
                ("Windows", RuntimeInformation.OSDescription),
                ("System architecture", RuntimeInformation.OSArchitecture.ToString()),
                ("Process architecture", RuntimeInformation.ProcessArchitecture.ToString()),
                (".NET runtime", RuntimeInformation.FrameworkDescription),
                ("64-bit process", Environment.Is64BitProcess ? "Yes" : "No"),
                ("Application uptime", FormatDuration(DateTimeOffset.Now - process.StartTime)),
                ("Application memory", $"{process.WorkingSet64 / 1024d / 1024d:N1} MB"),
                ("License tier", status.Mode),
                ("Maintenance", status.Maintenance.State),
                ("Windows user/computer names", "Always excluded")
            ]);
        }

        AddHeading(section, "10. Performance timeline", 1);
        AddKeyValueTable(section,
        [
            ("Connection opened", "Not captured by this report format"),
            ("First byte received", "Not captured by this report format"),
            ("Last byte received", job.ReceivedAt.ToString("O")),
            ("Parsing completed", "Completed before the job was stored"),
            ("Rendering completed", $"Renderer {job.RendererVersion}"),
            ("Receipt displayed", "Client-side event not retained"),
            ("Job saved", status.Features.History ? "Stored in local paid history" : "Session only"),
            ("Connection closed", "Not captured by this report format")
        ]);

        AddHeading(section, "11. Warnings and errors", 1);
        AddWarningTable(section, job);

        AddHeading(section, "12. Relevant application logs", 1);
        var relatedLogs = RelevantLogs(job, request.RedactSensitiveData);
        AddCodeBlock(section, relatedLogs.Length == 0
            ? "No job-specific log entries were found. Unrelated log entries were excluded."
            : relatedLogs);

        AddHeading(section, "13. Data integrity", 1);
        AddKeyValueTable(section,
        [
            ("Original payload SHA-256", Convert.ToHexString(SHA256.HashData(job.RawPayload)).ToLowerInvariant()),
            ("Original byte count", job.RawPayload.Length.ToString("N0")),
            ("Report generated", DateTimeOffset.Now.ToString("O")),
            ("Report format", ReportFormatVersion),
            ("Redacted", request.RedactSensitiveData ? "Yes" : "No"),
            ("Original local job modified", "No"),
            ("Raw preview shortened", request.IncludeRawDataPreview && job.RawPayload.Length > MaximumRawPreviewBytes ? "Yes" : "Not applicable"),
            ("Section failures", "None")
        ]);

        AddHeading(section, "14. Privacy and redaction manifest", 1);
        section.AddParagraph("Detected categories (values are never displayed in this manifest):");
        if (findings.Count == 0)
            section.AddParagraph("No common sensitive-data patterns were detected. This does not guarantee that the receipt contains no confidential information.");
        else
            AddFindingTable(section, findings);
        section.AddParagraph("Always excluded:");
        AddBullets(section, AlwaysExcluded);
        section.AddParagraph("The original print job remains unchanged. Redaction affects only this exported report.");

        return document;
    }

    private Document BuildStandardDocument(
        string reportId,
        DiagnosticPdfRequest request,
        ReceiptJob job,
        IReadOnlyList<DiagnosticSensitiveFinding> findings,
        byte[]? receiptImage,
        string receiptTreatment)
    {
        EnsureFontResolver();
        var status = license.GetStatus();
        var listener = listeners.GetStatus().Listeners.FirstOrDefault(candidate =>
            candidate.Configuration.Id.Equals(job.ListenerId, StringComparison.OrdinalIgnoreCase));
        var document = CreateDocument(reportId, DiagnosticPdfKinds.Standard);
        var section = document.AddSection();
        ConfigureSection(section, reportId);
        AddReportHeader(section, "Standard Diagnostics Report", reportId);
        AddNotice(section,
            "Privacy protection is always enabled in the Standard report. Sensitive values are masked, raw bytes are excluded, and credential material is never collected.",
            "#E8F7F1",
            "#087A55");

        AddHeading(section, "1. Report summary", 1);
        AddKeyValueTable(section,
        [
            ("Report ID", reportId),
            ("Diagnostic report format", ReportFormatVersion),
            ("Created", DateTimeOffset.Now.ToString("yyyy-MM-dd HH:mm:ss zzz")),
            ("Application", $"POS Printer Emulator {ProductInfo.Version}"),
            ("License tier", status.Mode),
            ("Maintenance", status.Maintenance.State),
            ("Print-job ID", job.Id.ToString()),
            ("Job received", job.ReceivedAt.ToString("yyyy-MM-dd HH:mm:ss zzz")),
            ("Overall result", DiagnosticStatus(job)),
            ("Privacy mode", "Standard - redaction required"),
            ("Support ticket", Safe(request.SupportTicketNumber, true))
        ]);

        AddHeading(section, "2. What the customer observed", 1);
        AddIssueBlock(section, "Issue title", request.IssueTitle, true);
        AddIssueBlock(section, "Problem description", request.ProblemDescription, true);
        AddIssueBlock(section, "Expected behavior", request.ExpectedBehavior, true);
        AddIssueBlock(section, "Actual behavior", request.ActualBehavior, true);
        AddIssueBlock(section, "Reproduction steps", request.ReproductionSteps, true);

        AddHeading(section, "3. Receipt preview", 1);
        section.AddParagraph(receiptTreatment).Format.Font.Color = Color.Parse("#52647A");
        if (receiptImage is not null)
        {
            var image = section.AddImage(ToBase64Image(receiptImage));
            image.LockAspectRatio = true;
            image.Width = Unit.FromInch(2.75);
            image.Interpolate = false;
            AddCaption(section, $"Privacy-reviewed receipt preview; {receiptImage.Length:N0} bytes.");
        }
        else
        {
            AddNotice(section,
                "No receipt pixels are present in this report. The original local print job was not changed.",
                "#F4F7FA",
                "#52647A");
        }

        section.AddPageBreak();
        AddHeading(section, "4. Application and printer summary", 1);
        AddKeyValueTable(section,
        [
            ("Application version", ProductInfo.Version),
            ("Windows", RuntimeInformation.OSDescription),
            ("Job status", job.Status),
            ("Payload", $"{job.RawPayload.Length:N0} bytes"),
            ("Parsed commands", job.Receipt.Commands.Count.ToString("N0")),
            ("Unsupported commands", job.UnsupportedCount.ToString("N0")),
            ("Printer listener", string.IsNullOrWhiteSpace(job.ListenerName) ? "Not captured" : job.ListenerName),
            ("Listener state", listener?.State ?? "Not available"),
            ("Listener port", job.ListenerPort.ToString()),
            ("Network addresses", "Excluded from the Standard report"),
            ("Printer profile", job.ProfileName),
            ("Paper width", $"{job.ProfilePaperWidthMm} mm"),
            ("Renderer", job.RendererVersion)
        ]);

        AddHeading(section, "5. Important warnings and errors", 1);
        AddWarningTable(section, job);

        AddHeading(section, "6. Recent relevant errors", 1);
        var relatedLogs = RelevantLogs(job, true);
        var recentLogLines = relatedLogs
            .Split(["\r\n", "\n"], StringSplitOptions.RemoveEmptyEntries)
            .TakeLast(4);
        var conciseLogs = string.Join(Environment.NewLine, recentLogLines);
        AddCodeBlock(section, relatedLogs.Length == 0
            ? "No job-specific error entries were found. Unrelated logs were excluded."
            : Limit(conciseLogs, 3_000));

        AddHeading(section, "7. Recommended next actions", 1);
        var recommendations = new List<string>();
        if (job.UnsupportedCount > 0)
            recommendations.Add("Review the selected Printer Profile and provide the Advanced Diagnostics PDF if command-level investigation is required.");
        if (!string.IsNullOrWhiteSpace(job.Error))
            recommendations.Add("Run Connection Diagnostics, retry the print job, and submit a Support Request if the error repeats.");
        if (listener is null || !listener.State.Equals("Running", StringComparison.OrdinalIgnoreCase))
            recommendations.Add("Open Printer Listeners and confirm that the selected listener is running.");
        if (recommendations.Count == 0)
            recommendations.Add("The selected job completed without a recorded error. Compare the receipt result with the expected output and include that difference in a Support Request.");
        recommendations.Add("Share this PDF only through the official Support Request process or another trusted private channel.");
        AddBullets(section, recommendations);

        AddHeading(section, "8. Integrity and privacy", 1);
        AddKeyValueTable(section,
        [
            ("Original payload SHA-256", Convert.ToHexString(SHA256.HashData(job.RawPayload)).ToLowerInvariant()),
            ("Original byte count", job.RawPayload.Length.ToString("N0")),
            ("Report format", ReportFormatVersion),
            ("Raw print data included", "No"),
            ("Network addresses included", "No"),
            ("Redaction", "Always enabled"),
            ("Original local job modified", "No")
        ]);
        section.AddParagraph("Detected categories (values are never displayed):");
        if (findings.Count == 0)
            section.AddParagraph("No common sensitive-data patterns were detected. Manual review is still recommended.");
        else
            section.AddParagraph(string.Join("; ", findings.Select(finding =>
                $"{finding.Category} ({finding.Count})")) + ". Detected values are masked or omitted.");
        section.AddParagraph(
            "Always excluded: license keys, credentials, registration details, Windows identity, other receipt jobs, and unrelated logs.");

        return document;
    }

    private static Document CreateDocument(string reportId, string reportKind)
    {
        var document = new Document
        {
            Info =
            {
                Title = $"POS Printer Emulator {reportKind} Diagnostics - {reportId}",
                Subject = "Privacy-reviewed diagnostic report for one selected receipt job",
                Author = $"POS Printer Emulator {ProductInfo.Version}"
            }
        };
        var normal = document.Styles[StyleNames.Normal]!;
        normal.Font.Name = "Poppins";
        normal.Font.Size = 8.5;
        normal.Font.Color = Color.Parse("#16233A");
        normal.ParagraphFormat.SpaceAfter = Unit.FromPoint(4);
        normal.ParagraphFormat.LineSpacingRule = LineSpacingRule.Multiple;
        normal.ParagraphFormat.LineSpacing = 1.05;
        return document;
    }

    private static void ConfigureSection(Section section, string reportId)
    {
        section.PageSetup.PageFormat = PageFormat.Letter;
        section.PageSetup.TopMargin = Unit.FromInch(.48);
        section.PageSetup.BottomMargin = Unit.FromInch(.52);
        section.PageSetup.LeftMargin = Unit.FromInch(.55);
        section.PageSetup.RightMargin = Unit.FromInch(.55);
        var footer = section.Footers.Primary.AddParagraph();
        footer.Format.Font.Size = 7;
        footer.Format.Font.Color = Color.Parse("#6D7C91");
        footer.AddText($"POS Printer Emulator | {reportId} | Confidential diagnostic export | Page ");
        footer.AddPageField();
    }

    private static void AddReportHeader(Section section, string title, string reportId)
    {
        var table = section.AddTable();
        table.Borders.Visible = false;
        table.AddColumn(Unit.FromInch(2.05));
        table.AddColumn(Unit.FromInch(5.25));
        var row = table.AddRow();
        var image = row.Cells[0].AddImage(ToBase64Image(ReadResource("ReceiptEmulator.DiagnosticLogo")));
        image.LockAspectRatio = true;
        image.Width = Unit.FromInch(1.75);
        var titleParagraph = row.Cells[1].AddParagraph();
        titleParagraph.Format.Alignment = ParagraphAlignment.Right;
        titleParagraph.Format.SpaceBefore = Unit.FromPoint(8);
        var titleText = titleParagraph.AddFormattedText(title, TextFormat.Bold);
        titleText.Font.Size = 18;
        titleText.Font.Color = Color.Parse("#071D3E");
        titleParagraph.AddLineBreak();
        var sub = titleParagraph.AddFormattedText($"Report {reportId} | Format {ReportFormatVersion}");
        sub.Font.Size = 8;
        sub.Font.Color = Color.Parse("#5F7189");
        var rule = section.AddParagraph();
        rule.Format.Borders.Bottom.Width = Unit.FromPoint(1.3);
        rule.Format.Borders.Bottom.Color = Color.Parse("#12B9DD");
        rule.Format.SpaceAfter = Unit.FromPoint(9);
    }

    private static void AddHeading(Section section, string text, int level)
    {
        var paragraph = section.AddParagraph();
        paragraph.Format.KeepWithNext = true;
        paragraph.Format.SpaceBefore = Unit.FromPoint(level == 1 ? 10 : 7);
        paragraph.Format.SpaceAfter = Unit.FromPoint(5);
        var formatted = paragraph.AddFormattedText(text, TextFormat.Bold);
        formatted.Font.Size = level == 1 ? 12 : 10;
        formatted.Font.Color = Color.Parse(level == 1 ? "#071D3E" : "#0D6992");
    }

    private static void AddNotice(Section section, string text, string background, string foreground)
    {
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(7.35));
        table.Borders.Width = Unit.FromPoint(.6);
        table.Borders.Color = Color.Parse(foreground);
        var cell = table.AddRow().Cells[0];
        cell.Shading.Color = Color.Parse(background);
        cell.Format.LeftIndent = Unit.FromPoint(6);
        cell.Format.RightIndent = Unit.FromPoint(6);
        var paragraph = cell.AddParagraph(text);
        paragraph.Format.Font.Color = Color.Parse(foreground);
        paragraph.Format.Font.Size = 8;
        paragraph.Format.SpaceBefore = Unit.FromPoint(5);
        paragraph.Format.SpaceAfter = Unit.FromPoint(5);
    }

    private static void AddKeyValueTable(Section section, IEnumerable<(string Key, string? Value)> values)
    {
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(2.25));
        table.AddColumn(Unit.FromInch(5.1));
        table.Borders.Width = Unit.FromPoint(.4);
        table.Borders.Color = Color.Parse("#D7E0EA");
        var shaded = false;
        foreach (var (key, value) in values)
        {
            var row = table.AddRow();
            row.Shading.Color = Color.Parse(shaded ? "#F6F9FC" : "#FFFFFF");
            shaded = !shaded;
            var label = row.Cells[0].AddParagraph(key);
            label.Format.Font.Bold = true;
            label.Format.Font.Color = Color.Parse("#40536B");
            row.Cells[1].AddParagraph(string.IsNullOrWhiteSpace(value) ? "Not provided" : value);
            for (var cellIndex = 0; cellIndex < row.Cells.Count; cellIndex++)
            {
                var cell = row.Cells[cellIndex];
                cell.VerticalAlignment = VerticalAlignment.Center;
                cell.Format.LeftIndent = Unit.FromPoint(4);
                cell.Format.RightIndent = Unit.FromPoint(4);
            }
        }
        section.AddParagraph().Format.SpaceAfter = Unit.FromPoint(2);
    }

    private static void AddIssueBlock(Section section, string label, string? value, bool redact)
    {
        var paragraph = section.AddParagraph();
        paragraph.Format.KeepTogether = true;
        var heading = paragraph.AddFormattedText(label, TextFormat.Bold);
        heading.Font.Color = Color.Parse("#40536B");
        paragraph.AddLineBreak();
        paragraph.AddText(Safe(value, redact));
    }

    private static void AddCommandTable(Section section, ReceiptJob job, bool redact)
    {
        var commands = job.Receipt.Commands.Take(MaximumCommandsInAdvancedPdf).ToArray();
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(.45));
        table.AddColumn(Unit.FromInch(.55));
        table.AddColumn(Unit.FromInch(1.2));
        table.AddColumn(Unit.FromInch(1.65));
        table.AddColumn(Unit.FromInch(2.65));
        table.AddColumn(Unit.FromInch(.85));
        table.Borders.Width = Unit.FromPoint(.35);
        table.Borders.Color = Color.Parse("#D7E0EA");
        AddHeaderRow(table, ["#", "Offset", "Bytes", "Command", "Details", "Support"]);
        for (var index = 0; index < commands.Length; index++)
        {
            var command = commands[index];
            var row = table.AddRow();
            row.Shading.Color = Color.Parse(command.Supported
                ? index % 2 == 0 ? "#FFFFFF" : "#F7F9FC"
                : "#FFF2E5");
            SetCell(row.Cells[0], (index + 1).ToString());
            SetCell(row.Cells[1], $"0x{command.Offset:X4}");
            SetCell(row.Cells[2], Limit(command.Hex, 44));
            SetCell(row.Cells[3], Safe(command.Name, redact, 120));
            SetCell(row.Cells[4], Safe(command.Details, redact, 320));
            SetCell(row.Cells[5], command.Supported ? "Supported" : "Unsupported");
        }
        if (job.Receipt.Commands.Count > MaximumCommandsInAdvancedPdf)
            AddCaption(section, $"The PDF includes the first {MaximumCommandsInAdvancedPdf:N0} of {job.Receipt.Commands.Count:N0} commands.");
        else
            AddCaption(section, $"All {job.Receipt.Commands.Count:N0} parsed commands are included in receive order.");
    }

    private static void AddRawData(Section section, ReceiptJob job, DiagnosticPdfRequest request)
    {
        if (!request.IncludeRawDataPreview)
        {
            section.AddParagraph("Raw print data was not included. The report retains the original byte count and SHA-256 checksum for integrity verification.");
            return;
        }

        var count = Math.Min(job.RawPayload.Length, MaximumRawPreviewBytes);
        if (request.RedactSensitiveData)
        {
            var decoded = Encoding.Latin1.GetString(job.RawPayload, 0, count);
            AddCodeBlock(section, Redact(decoded));
            AddCaption(section,
                $"Privacy-safe decoded preview: {count:N0} of {job.RawPayload.Length:N0} bytes. Reconstructable hexadecimal bytes are excluded while redaction is enabled.");
        }
        else
        {
            AddCodeBlock(section, HexDump(job.RawPayload.AsSpan(0, count)));
            AddCaption(section,
                $"UNREDACTED raw preview: {count:N0} of {job.RawPayload.Length:N0} bytes. Review this report before sharing it.");
        }
    }

    private static void AddWarningTable(Section section, ReceiptJob job)
    {
        var unsupported = job.Receipt.Commands.Where(command => !command.Supported).ToArray();
        if (unsupported.Length == 0 && string.IsNullOrWhiteSpace(job.Error))
        {
            section.AddParagraph("No unsupported commands or job errors were recorded.");
            return;
        }
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(.75));
        table.AddColumn(Unit.FromInch(1.3));
        table.AddColumn(Unit.FromInch(3.7));
        table.AddColumn(Unit.FromInch(1.6));
        table.Borders.Width = Unit.FromPoint(.35);
        table.Borders.Color = Color.Parse("#E2B67E");
        AddHeaderRow(table, ["Severity", "Component", "Explanation", "Suggested action"]);
        foreach (var command in unsupported.Take(100))
        {
            var row = table.AddRow();
            row.Shading.Color = Color.Parse("#FFF8EF");
            SetCell(row.Cells[0], "Warning");
            SetCell(row.Cells[1], $"ESC/POS at 0x{command.Offset:X4}");
            SetCell(row.Cells[2], $"{command.Name}: {command.Details}");
            SetCell(row.Cells[3], "Review printer profile support or provide this report to development.");
        }
        if (!string.IsNullOrWhiteSpace(job.Error))
        {
            var row = table.AddRow();
            row.Shading.Color = Color.Parse("#FFF0F0");
            SetCell(row.Cells[0], "Error");
            SetCell(row.Cells[1], "Receipt processing");
            SetCell(row.Cells[2], Redact(job.Error));
            SetCell(row.Cells[3], "Run Connection Diagnostics and attach this report to a support request.");
        }
    }

    private static void AddFindingTable(Section section, IReadOnlyList<DiagnosticSensitiveFinding> findings)
    {
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(1.55));
        table.AddColumn(Unit.FromInch(2.05));
        table.AddColumn(Unit.FromInch(.65));
        table.AddColumn(Unit.FromInch(3.1));
        table.Borders.Width = Unit.FromPoint(.35);
        table.Borders.Color = Color.Parse("#D7E0EA");
        AddHeaderRow(table, ["Category", "Location", "Count", "Export treatment"]);
        foreach (var finding in findings)
        {
            var row = table.AddRow();
            SetCell(row.Cells[0], finding.Category);
            SetCell(row.Cells[1], finding.Location);
            SetCell(row.Cells[2], finding.Count.ToString());
            SetCell(row.Cells[3], finding.Treatment);
        }
    }

    private static void AddHeaderRow(Table table, IReadOnlyList<string> labels)
    {
        var row = table.AddRow();
        row.HeadingFormat = true;
        row.Shading.Color = Color.Parse("#0B2A52");
        for (var index = 0; index < labels.Count; index++)
        {
            var paragraph = row.Cells[index].AddParagraph(labels[index]);
            paragraph.Format.Font.Bold = true;
            paragraph.Format.Font.Color = Colors.White;
            paragraph.Format.Font.Size = 7;
            row.Cells[index].Format.LeftIndent = Unit.FromPoint(3);
            row.Cells[index].Format.RightIndent = Unit.FromPoint(3);
        }
    }

    private static void SetCell(Cell cell, string text)
    {
        cell.Format.LeftIndent = Unit.FromPoint(3);
        cell.Format.RightIndent = Unit.FromPoint(3);
        var paragraph = cell.AddParagraph(text);
        paragraph.Format.Font.Size = 6.7;
    }

    private static void AddCodeBlock(Section section, string text)
    {
        var table = section.AddTable();
        table.AddColumn(Unit.FromInch(7.35));
        table.Borders.Width = Unit.FromPoint(.4);
        table.Borders.Color = Color.Parse("#C9D4E0");
        var cell = table.AddRow().Cells[0];
        cell.Shading.Color = Color.Parse("#F4F7FA");
        cell.Format.LeftIndent = Unit.FromPoint(5);
        cell.Format.RightIndent = Unit.FromPoint(5);
        var paragraph = cell.AddParagraph(Limit(text, 40_000));
        paragraph.Format.Font.Name = "Poppins";
        paragraph.Format.Font.Size = 6.5;
        paragraph.Format.SpaceBefore = Unit.FromPoint(4);
        paragraph.Format.SpaceAfter = Unit.FromPoint(4);
    }

    private static void AddBullets(Section section, IEnumerable<string> values)
    {
        foreach (var value in values)
        {
            var paragraph = section.AddParagraph($"- {value}");
            paragraph.Format.LeftIndent = Unit.FromPoint(9);
        }
    }

    private static void AddCaption(Section section, string text)
    {
        var paragraph = section.AddParagraph(text);
        paragraph.Format.Font.Size = 7;
        paragraph.Format.Font.Color = Color.Parse("#6D7C91");
        paragraph.Format.SpaceBefore = Unit.FromPoint(3);
        paragraph.Format.SpaceAfter = Unit.FromPoint(5);
    }

    private IReadOnlyList<DiagnosticSensitiveFinding> DetectSensitiveData(ReceiptJob job)
    {
        var text = job.Receipt.PlainText;
        var findings = new List<DiagnosticSensitiveFinding>();
        AddFinding(findings, "Payment-card-like number", "Receipt text", CardNumberRegex().Matches(text).Count,
            "Masked in decoded text; receipt image omitted by default");
        AddFinding(findings, "Email address", "Receipt text", EmailRegex().Matches(text).Count,
            "Masked in text and logs; receipt image omitted by default");
        AddFinding(findings, "Phone number", "Receipt text", PhoneRegex().Matches(text).Count,
            "Masked in text; receipt image omitted by default");
        AddFinding(findings, "IP address", "Job and listener details", IpAddressRegex().Matches($"{text} {job.SourceIp}").Count,
            "Excluded unless selected; private values are masked when redaction is enabled");
        AddFinding(findings, "Credential-like value", "Receipt text", CredentialRegex().Matches(text).Count,
            "Always excluded");
        AddFinding(findings, "Transaction or authorization identifier", "Receipt text", TransactionRegex().Matches(text).Count,
            "Masked in decoded text; receipt image omitted by default");
        return findings;
    }

    private static void AddFinding(
        ICollection<DiagnosticSensitiveFinding> findings,
        string category,
        string location,
        int count,
        string treatment)
    {
        if (count > 0) findings.Add(new(category, location, count, treatment));
    }

    private string RelevantLogs(ReceiptJob job, bool redact)
    {
        var content = logs.ReadLog();
        if (string.IsNullOrWhiteSpace(content)) return string.Empty;
        var jobId = job.Id.ToString();
        var listenerId = job.ListenerId;
        var matches = content.Split(["\r\n", "\n"], StringSplitOptions.RemoveEmptyEntries)
            .Where(line => line.Contains(jobId, StringComparison.OrdinalIgnoreCase) ||
                           line.Contains(listenerId, StringComparison.OrdinalIgnoreCase))
            .TakeLast(120);
        var result = string.Join(Environment.NewLine, matches);
        if (redact) result = Redact(result);
        return Limit(result, MaximumLogCharacters);
    }

    private static string ReceiptImageTreatment(
        DiagnosticPdfRequest request,
        IReadOnlyList<DiagnosticSensitiveFinding> findings)
    {
        if (!request.IncludeReceiptImage) return "Not included by customer selection.";
        if (string.IsNullOrWhiteSpace(request.ReceiptImageBase64))
            return "Omitted because the rendered receipt image was not supplied by the viewer.";
        if (request.RedactSensitiveData && findings.Count > 0)
            return "Omitted because sensitive data was detected and pixel-level redaction cannot be verified automatically. Disable redaction only after reviewing the warning.";
        return request.RedactSensitiveData
            ? "Included because no common sensitive-data patterns were detected. Manual review is still recommended."
            : "Included without redaction after explicit customer review and consent.";
    }

    private static byte[] DecodeReceiptImage(string base64)
    {
        var comma = base64.IndexOf(',');
        if (comma >= 0) base64 = base64[(comma + 1)..];
        if (base64.Length > MaximumReceiptImageBytes * 4 / 3 + 16)
            throw new InvalidOperationException("The receipt image is too large for a diagnostic PDF.");
        byte[] bytes;
        try { bytes = Convert.FromBase64String(base64); }
        catch (FormatException exception) { throw new InvalidOperationException("The receipt image is not valid base64 data.", exception); }
        if (bytes.Length == 0 || bytes.Length > MaximumReceiptImageBytes)
            throw new InvalidOperationException("The receipt image is empty or exceeds the 20 MB report limit.");
        var png = bytes.Length >= 8 && bytes.AsSpan(0, 8).SequenceEqual(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 });
        var jpeg = bytes.Length >= 3 && bytes[0] == 0xFF && bytes[1] == 0xD8 && bytes[2] == 0xFF;
        if (!png && !jpeg)
            throw new InvalidOperationException("Diagnostic receipt images must be PNG or JPEG files.");
        return bytes;
    }

    private static byte[] Render(Document document)
    {
        var renderer = new PdfDocumentRenderer { Document = document };
        renderer.RenderDocument();
        using var stream = new MemoryStream();
        renderer.PdfDocument.Save(stream, closeStream: false);
        return stream.ToArray();
    }

    private void EnsureEnterprise()
    {
        if (!license.GetStatus().IsEnterprise)
            throw new UnauthorizedAccessException("Advanced Diagnostic PDF Reports require an Enterprise License.");
    }

    private ReceiptJob GetJob(Guid jobId) => jobs.Get(jobId)
        ?? throw new KeyNotFoundException("The selected receipt job is no longer available.");

    private static void EnsureFontResolver()
    {
        if (GlobalFontSettings.FontResolver is not null) return;
        lock (FontResolverSync)
        {
            GlobalFontSettings.FontResolver ??= new PoppinsFontResolver();
            MigraDoc.PredefinedFontsAndChars.ErrorFontName = "Poppins";
            MigraDoc.PredefinedFontsAndChars.RtfDocumentInfoFontName = "Poppins";
            MigraDoc.PredefinedFontsAndChars.Bullets.Level1FontName = "Poppins";
            MigraDoc.PredefinedFontsAndChars.Bullets.Level2FontName = "Poppins";
            MigraDoc.PredefinedFontsAndChars.Bullets.Level3FontName = "Poppins";
        }
    }

    private static byte[] ReadResource(string name)
    {
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(name)
            ?? throw new InvalidOperationException($"Embedded diagnostic resource {name} was not found.");
        using var memory = new MemoryStream();
        stream.CopyTo(memory);
        return memory.ToArray();
    }

    private static string ToBase64Image(byte[] bytes) => "base64:" + Convert.ToBase64String(bytes);

    private static string CreateReportId() =>
        $"PPE-{DateTime.UtcNow:yyyyMMddHHmmss}-{Convert.ToHexString(RandomNumberGenerator.GetBytes(4))}";

    private static string JobLabel(ReceiptJob job)
    {
        var preview = job.Receipt.PlainText.Split(["\r\n", "\n"], StringSplitOptions.RemoveEmptyEntries)
            .FirstOrDefault(line => !string.IsNullOrWhiteSpace(line));
        return $"{job.ReceivedAt:yyyy-MM-dd HH:mm:ss} - {Limit(preview ?? "Receipt job", 80)}";
    }

    private static long EstimateSize(ReceiptJob job, DiagnosticPdfRequest request)
    {
        var imageBytes = string.IsNullOrWhiteSpace(request.ReceiptImageBase64)
            ? 0
            : Math.Min(MaximumReceiptImageBytes, request.ReceiptImageBase64.Length * 3L / 4L);
        return 180_000 + imageBytes + Math.Min(job.RawPayload.Length, MaximumRawPreviewBytes) * 4L +
               Math.Min(job.Receipt.Commands.Count, MaximumCommandsInAdvancedPdf) * 260L;
    }

    private static int EstimateCharactersPerLine(ReceiptJob job) =>
        job.ProfilePaperWidthMm <= 58 ? 32 : 48;

    private static string DiagnosticStatus(ReceiptJob job) =>
        !string.IsNullOrWhiteSpace(job.Error) ? "Failed" :
        job.UnsupportedCount > 0 ? "Completed with warnings" :
        job.Status.Equals("Completed", StringComparison.OrdinalIgnoreCase) ? "Successful" :
        job.Status;

    private static string Safe(string? value, bool redact, int maximumLength = 8_000)
    {
        if (string.IsNullOrWhiteSpace(value)) return "Not provided";
        var safe = new string(value.Where(character => character is '\r' or '\n' or '\t' || !char.IsControl(character)).ToArray());
        safe = Limit(safe, maximumLength);
        return redact ? Redact(safe) : safe;
    }

    internal static string Redact(string value)
    {
        if (string.IsNullOrEmpty(value)) return value;
        value = CredentialRegex().Replace(value, match => $"{match.Groups[1].Value}[ALWAYS EXCLUDED]");
        value = LocalPathRegex().Replace(value, "[REDACTED LOCAL PATH]");
        value = UncPathRegex().Replace(value, "[REDACTED NETWORK PATH]");
        value = EmailRegex().Replace(value, "[REDACTED EMAIL]");
        value = CardNumberRegex().Replace(value, "[REDACTED NUMBER]");
        value = PhoneRegex().Replace(value, "[REDACTED PHONE]");
        value = IpAddressRegex().Replace(value, "[REDACTED IP]");
        value = TransactionRegex().Replace(value, match => $"{match.Groups[1].Value}[REDACTED IDENTIFIER]");
        return value;
    }

    private static string RedactIp(string value, bool redact)
    {
        if (!redact) return value;
        if (value is "127.0.0.1" or "0.0.0.0" or "::1") return value;
        return "[REDACTED IP]";
    }

    private static string HexDump(ReadOnlySpan<byte> bytes)
    {
        var builder = new StringBuilder();
        for (var offset = 0; offset < bytes.Length; offset += 16)
        {
            var count = Math.Min(16, bytes.Length - offset);
            builder.Append(offset.ToString("X8")).Append("  ");
            for (var index = 0; index < 16; index++)
                builder.Append(index < count ? $"{bytes[offset + index]:X2} " : "   ");
            builder.Append(" |");
            for (var index = 0; index < count; index++)
            {
                var value = bytes[offset + index];
                builder.Append(value is >= 32 and <= 126 ? (char)value : '.');
            }
            builder.AppendLine("|");
        }
        return builder.ToString();
    }

    private static string Limit(string? value, int maximumLength)
    {
        value ??= string.Empty;
        return value.Length <= maximumLength ? value : value[..maximumLength] + " ... [shortened]";
    }

    private static string FormatDuration(TimeSpan duration) =>
        duration.TotalDays >= 1 ? $"{duration.TotalDays:N1} days" :
        duration.TotalHours >= 1 ? $"{duration.TotalHours:N1} hours" :
        $"{duration.TotalMinutes:N1} minutes";

    [GeneratedRegex(@"(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)", RegexOptions.Compiled)]
    private static partial Regex CardNumberRegex();

    [GeneratedRegex(@"\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b", RegexOptions.IgnoreCase | RegexOptions.Compiled)]
    private static partial Regex EmailRegex();

    [GeneratedRegex(@"(?<!\d)(?:\+?1[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?)\d{3}[\s.\-]?\d{4}(?!\d)", RegexOptions.Compiled)]
    private static partial Regex PhoneRegex();

    [GeneratedRegex(@"\b(?:\d{1,3}\.){3}\d{1,3}\b", RegexOptions.Compiled)]
    private static partial Regex IpAddressRegex();

    [GeneratedRegex(@"(?i)\b(password|passphrase|api[ _-]?key|activation[ _-]?key|access[ _-]?token|auth(?:entication)?[ _-]?token|secret|cookie)\s*[:=]\s*(\S+)", RegexOptions.Compiled)]
    private static partial Regex CredentialRegex();

    [GeneratedRegex(@"(?i)\b(transaction|authorization|auth|loyalty|employee|merchant|account|check|order)[ #:_-]*([A-Z0-9][A-Z0-9\-]{3,})", RegexOptions.Compiled)]
    private static partial Regex TransactionRegex();

    [GeneratedRegex(@"(?i)\b[A-Z]:\\[^\r\n;]+", RegexOptions.Compiled)]
    private static partial Regex LocalPathRegex();

    [GeneratedRegex(@"\\\\[^\\\r\n]+\\[^\r\n;]+", RegexOptions.Compiled)]
    private static partial Regex UncPathRegex();
}
