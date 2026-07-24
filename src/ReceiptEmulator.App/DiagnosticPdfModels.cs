namespace ReceiptEmulator;

public static class DiagnosticPdfKinds
{
    public const string Advanced = "Advanced";
    public const string Standard = "Standard";
}

public sealed record DiagnosticPdfRequest(
    Guid JobId,
    string? IssueTitle = null,
    string? ProblemDescription = null,
    string? ExpectedBehavior = null,
    string? ActualBehavior = null,
    string? ReproductionSteps = null,
    string? AdditionalNotes = null,
    string? SupportTicketNumber = null,
    bool IncludeReceiptImage = true,
    string? ReceiptImageBase64 = null,
    bool IncludeRawDataPreview = false,
    bool IncludeSourceIp = false,
    bool RedactSensitiveData = true,
    bool ConsentToCreate = false);

public sealed record DiagnosticSensitiveFinding(
    string Category,
    string Location,
    int Count,
    string Treatment);

public sealed record DiagnosticPdfPreview(
    string ReportKind,
    string ReportId,
    Guid JobId,
    string JobLabel,
    IReadOnlyList<string> IncludedSections,
    IReadOnlyList<string> ExcludedSections,
    IReadOnlyList<string> AlwaysExcluded,
    IReadOnlyList<DiagnosticSensitiveFinding> SensitiveFindings,
    bool RedactionEnabled,
    bool RequiresConsent,
    string ReceiptImageTreatment,
    string RawDataTreatment,
    long EstimatedSizeBytes);

public sealed record DiagnosticPdfFile(
    byte[] Content,
    string FileName,
    string ReportId,
    string Sha256);
