namespace ReceiptEmulator;

public sealed record ReceiptSpan(string Text, bool Bold, bool Underline, int Width, int Height);

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
    public int PayloadSize => RawPayload.Length;
    public int UnsupportedCount => Receipt.Commands.Count(command => !command.Supported);
}

public sealed record JobSummary(
    Guid Id,
    DateTimeOffset ReceivedAt,
    string SourceIp,
    int PayloadSize,
    string Status,
    int UnsupportedCount,
    string Preview);

public sealed record TrialStatus(string Mode, int DailyLimit, int UsedToday, int Remaining, DateOnly LocalDate);

public sealed record ServiceStatus(
    bool Listening,
    string Listener,
    DateTimeOffset? LastConnection,
    string Version,
    TrialStatus Trial);
