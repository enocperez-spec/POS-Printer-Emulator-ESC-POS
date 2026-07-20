namespace ReceiptEmulator;

public sealed record ReceiptSpan(
    string Text,
    bool Bold,
    bool Underline,
    int Width,
    int Height,
    bool Inverted = false,
    bool Rotated = false,
    bool UpsideDown = false,
    string Color = "black",
    string Font = "A");

public sealed record ReceiptLine(
    string Alignment,
    IReadOnlyList<ReceiptSpan> Spans,
    string Kind = "text",
    string? Data = null);

public sealed record ParsedCommand(
    int Offset,
    string Hex,
    string Name,
    string Details,
    bool Supported = true);

public sealed class ParsedReceipt
{
    public List<ReceiptLine> Lines { get; } = [];
    public List<ParsedCommand> Commands { get; } = [];
    public bool HasPrintableContent => Lines.Any(line =>
        line.Kind != "text" || line.Spans.Any(span => !string.IsNullOrWhiteSpace(span.Text)));
    public string PlainText => string.Join(Environment.NewLine,
        Lines.Select(line => string.Concat(line.Spans.Select(span => span.Text))));
}

public sealed class ReceiptJob
{
    public required Guid Id { get; init; }
    public required DateTimeOffset ReceivedAt { get; init; }
    public required string SourceIp { get; init; }
    public required byte[] RawPayload { get; init; }
    public required ParsedReceipt Receipt { get; init; }
    public required string Status { get; init; }
    public string? Error { get; init; }
    public string Origin { get; init; } = JobOrigins.Live;
    public string RendererVersion { get; init; } = ProductInfo.Version;
    public DateTimeOffset? OriginalReceivedAt { get; init; }
    public string? OriginalSourceIp { get; init; }
    public Guid? ParentJobId { get; init; }
    public string? ImportedFileName { get; init; }
    public string ProfileId { get; init; } = PrinterProfileService.EpsonTmT88VId;
    public string ProfileName { get; init; } = "EPSON TM-T88V Receipt5";
    public int ProfilePaperWidthMm { get; init; } = 80;
    public int ProfilePrintableDots { get; init; } = 576;
    public string? CapturedProfileId { get; init; }
    public string ListenerId { get; init; } = PrinterListenerDefaults.DefaultId;
    public string ListenerName { get; init; } = PrinterListenerDefaults.DefaultName;
    public int ListenerPort { get; init; } = PrinterListenerDefaults.DefaultPort;
    public int PayloadSize => RawPayload.Length;
    public int UnsupportedCount => Receipt.Commands.Count(command => !command.Supported);
}

public static class JobOrigins
{
    public const string Live = "Live";
    public const string Imported = "Imported";
    public const string Replayed = "Replayed";
}

public sealed record JobSummary(
    Guid Id,
    DateTimeOffset ReceivedAt,
    string SourceIp,
    int PayloadSize,
    string Status,
    int UnsupportedCount,
    string Preview,
    string Origin,
    string RendererVersion,
    Guid? ParentJobId,
    string? ImportedFileName,
    string ProfileId,
    string ProfileName,
    int ProfilePaperWidthMm,
    int ProfilePrintableDots,
    string ListenerId = PrinterListenerDefaults.DefaultId,
    string ListenerName = PrinterListenerDefaults.DefaultName,
    int ListenerPort = PrinterListenerDefaults.DefaultPort);

public sealed record RegistrationInfo(string CustomerName, string EmailAddress);

public sealed record FeatureStatus(
    bool History,
    bool Exports,
    bool PremiumFeatures,
    bool Watermark,
    bool StoredLogos,
    bool PrinterState,
    bool PrinterProfiles,
    bool Updates,
    bool Support,
    bool MultipleListeners = false);

public sealed record LicenseStatus(
    string Mode,
    bool IsPaid,
    bool IsEnterprise,
    int MaximumListeners,
    int DailyLimit,
    int UsedToday,
    int Remaining,
    DateOnly LocalDate,
    string CustomerName,
    string EmailAddress,
    Guid? LicenseId,
    FeatureStatus Features)
{
    // Kept in the JSON contract for compatibility with pre-Lite viewer bundles.
    public bool HasProAccess => IsPaid;
}

public sealed record ActivationRequest(string CustomerName, string EmailAddress, string ActivationKey);

public sealed record LicenseStorageDiagnostics(
    string DataPath,
    bool DataDirectoryExists,
    bool RegistrationFileExists,
    bool LicenseFileExists,
    string? LastErrorType,
    string? LastErrorMessage);

public sealed record ServiceStatus(
    bool Listening,
    string Listener,
    DateTimeOffset? LastConnection,
    string Version,
    LicenseStatus License,
    PrinterListenerSummary? ListenerSummary = null);
