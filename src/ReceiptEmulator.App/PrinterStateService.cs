namespace ReceiptEmulator;

public sealed record PrinterStateUpdateRequest(
    bool Online,
    string PaperStatus,
    bool CoverOpen,
    bool CutterError,
    bool RecoverableError,
    bool UnrecoverableError,
    bool AutoRecoverableError,
    bool DrawerOpen);

public sealed record PrinterStateSnapshot(
    bool Online,
    string PaperStatus,
    bool CoverOpen,
    bool CutterError,
    bool RecoverableError,
    bool UnrecoverableError,
    bool AutoRecoverableError,
    bool DrawerOpen)
{
    public bool EffectiveOnline => Online && !CoverOpen && PaperStatus != "Out" &&
        !CutterError && !RecoverableError && !UnrecoverableError && !AutoRecoverableError;

    public bool HasError => CutterError || RecoverableError || UnrecoverableError || AutoRecoverableError;
}

public sealed record PrinterStateStatus(
    bool Online,
    bool EffectiveOnline,
    string PaperStatus,
    bool CoverOpen,
    bool CutterError,
    bool RecoverableError,
    bool UnrecoverableError,
    bool AutoRecoverableError,
    bool DrawerOpen,
    string Summary,
    long ResponsesSent,
    int AsbConnections,
    DateTimeOffset? LastStatusQuery,
    bool DleEotSupported = true,
    bool AsbSupported = true);

public sealed class PrinterStateService(ILogger<PrinterStateService> logger)
{
    private readonly object _gate = new();
    private PrinterStateSnapshot _state = ReadyState();
    private long _responsesSent;
    private long _lastStatusQueryTicks;
    private int _asbConnections;

    public event Action<PrinterStateSnapshot>? Changed;

    public PrinterStateSnapshot Snapshot()
    {
        lock (_gate) return _state;
    }

    public PrinterStateStatus GetStatus()
    {
        var state = Snapshot();
        var ticks = Interlocked.Read(ref _lastStatusQueryTicks);
        return new(
            state.Online,
            state.EffectiveOnline,
            state.PaperStatus,
            state.CoverOpen,
            state.CutterError,
            state.RecoverableError,
            state.UnrecoverableError,
            state.AutoRecoverableError,
            state.DrawerOpen,
            Describe(state),
            Interlocked.Read(ref _responsesSent),
            Volatile.Read(ref _asbConnections),
            ticks == 0 ? null : new DateTimeOffset(ticks, TimeSpan.Zero).ToLocalTime());
    }

    public PrinterStateStatus Update(PrinterStateUpdateRequest request)
    {
        var paper = NormalizePaperStatus(request.PaperStatus);
        var next = new PrinterStateSnapshot(
            request.Online,
            paper,
            request.CoverOpen,
            request.CutterError,
            request.RecoverableError,
            request.UnrecoverableError,
            request.AutoRecoverableError,
            request.DrawerOpen);

        var changed = false;
        lock (_gate)
        {
            if (_state != next)
            {
                _state = next;
                changed = true;
            }
        }

        if (changed)
        {
            logger.LogInformation("Printer simulation state changed: {Summary}", Describe(next));
            Changed?.Invoke(next);
        }

        return GetStatus();
    }

    public PrinterStateStatus Reset() => Update(ToRequest(ReadyState()));

    public void ClearRecoverableErrors()
    {
        var current = Snapshot();
        Update(ToRequest(current with { CutterError = false, RecoverableError = false, AutoRecoverableError = false }));
    }

    public byte BuildRealTimeStatus(byte function)
    {
        var state = Snapshot();
        const byte fixedBits = 0x12;
        return function switch
        {
            1 => (byte)(fixedBits | (state.DrawerOpen ? 0x04 : 0) | (!state.EffectiveOnline ? 0x08 : 0)),
            2 => (byte)(fixedBits | (state.CoverOpen ? 0x04 : 0) | (state.PaperStatus == "Out" ? 0x20 : 0) |
                (state.HasError ? 0x40 : 0)),
            3 => (byte)(fixedBits | (state.RecoverableError ? 0x04 : 0) | (state.CutterError ? 0x08 : 0) |
                (state.UnrecoverableError ? 0x20 : 0) | (state.AutoRecoverableError ? 0x40 : 0)),
            4 => (byte)(fixedBits | (state.PaperStatus == "Low" ? 0x0C : 0) | (state.PaperStatus == "Out" ? 0x60 : 0)),
            _ => throw new ArgumentOutOfRangeException(nameof(function), "DLE EOT supports status functions 1 through 4.")
        };
    }

    public byte[] BuildAutomaticStatusBack(PrinterStateSnapshot? supplied = null)
    {
        var state = supplied ?? Snapshot();
        var first = (byte)(0x10 | (state.DrawerOpen ? 0x04 : 0) | (!state.EffectiveOnline ? 0x08 : 0) |
            (state.CoverOpen ? 0x20 : 0));
        var second = (byte)((state.RecoverableError ? 0x04 : 0) | (state.CutterError ? 0x08 : 0) |
            (state.UnrecoverableError ? 0x20 : 0) | (state.AutoRecoverableError ? 0x40 : 0));
        var third = (byte)((state.PaperStatus == "Low" ? 0x03 : 0) | (state.PaperStatus == "Out" ? 0x0C : 0));
        return [first, second, third, 0x00];
    }

    public void RecordResponse(string command, string sourceIp)
    {
        Interlocked.Increment(ref _responsesSent);
        Interlocked.Exchange(ref _lastStatusQueryTicks, DateTimeOffset.UtcNow.Ticks);
        logger.LogDebug("Sent {Command} printer status response to {SourceIp}", command, sourceIp);
    }

    public void SetAsbConnectionActive(bool active)
    {
        if (active) Interlocked.Increment(ref _asbConnections);
        else Interlocked.Decrement(ref _asbConnections);
    }

    private static PrinterStateSnapshot ReadyState() => new(true, "Ready", false, false, false, false, false, false);

    private static PrinterStateUpdateRequest ToRequest(PrinterStateSnapshot state) => new(
        state.Online, state.PaperStatus, state.CoverOpen, state.CutterError, state.RecoverableError,
        state.UnrecoverableError, state.AutoRecoverableError, state.DrawerOpen);

    private static string NormalizePaperStatus(string? value) => value?.Trim().ToLowerInvariant() switch
    {
        "ready" => "Ready",
        "low" => "Low",
        "out" => "Out",
        _ => throw new ArgumentException("Paper status must be Ready, Low, or Out.", nameof(value))
    };

    private static string Describe(PrinterStateSnapshot state)
    {
        if (!state.Online) return "Manually offline";
        if (state.CoverOpen) return "Cover open";
        if (state.PaperStatus == "Out") return "Paper out";
        if (state.CutterError) return "Cutter error";
        if (state.UnrecoverableError) return "Unrecoverable error";
        if (state.RecoverableError) return "Recoverable error";
        if (state.AutoRecoverableError) return "Automatically recoverable error";
        if (state.PaperStatus == "Low") return "Online · paper low";
        return "Online · ready";
    }
}

public sealed record EscPosStatusResponse(string Command, byte[] Bytes);

public sealed record EscPosStatusProtocolResult(int AsbMask, IReadOnlyList<EscPosStatusResponse> Responses);

public static class EscPosStatusProtocol
{
    public static EscPosStatusProtocolResult Extract(
        List<byte> buffer,
        int currentAsbMask,
        PrinterStateService state,
        bool dleEotSupported = true,
        bool asbSupported = true)
    {
        var responses = new List<EscPosStatusResponse>();
        var index = 0;
        while (index < buffer.Count)
        {
            if (buffer[index] == 0x10)
            {
                if (index + 1 >= buffer.Count) break;
                if (buffer[index + 1] == 0x04)
                {
                    if (index + 2 >= buffer.Count) break;
                    var function = buffer[index + 2];
                    if (dleEotSupported && function is >= 1 and <= 4)
                    {
                        responses.Add(new($"DLE EOT {function}", [state.BuildRealTimeStatus(function)]));
                        buffer.RemoveRange(index, 3);
                        continue;
                    }
                }
                else if (buffer[index + 1] == 0x05)
                {
                    if (index + 2 >= buffer.Count) break;
                    if (buffer[index + 2] == 2) state.ClearRecoverableErrors();
                    buffer.RemoveRange(index, 3);
                    continue;
                }
            }

            if (buffer[index] == 0x1D)
            {
                if (index + 1 >= buffer.Count) break;
                if (buffer[index + 1] == 0x61)
                {
                    if (index + 2 >= buffer.Count) break;
                    if (!asbSupported) { index += 3; continue; }
                    currentAsbMask = buffer[index + 2];
                    buffer.RemoveRange(index, 3);
                    if (currentAsbMask != 0)
                        responses.Add(new("GS a", state.BuildAutomaticStatusBack()));
                    continue;
                }
            }

            index++;
        }

        return new(currentAsbMask, responses);
    }
}
