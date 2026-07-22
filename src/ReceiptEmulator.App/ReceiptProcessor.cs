using System.Text;

namespace ReceiptEmulator;

public sealed class ReceiptProcessor(EscPosParser parser, ReceiptStore store, LicenseService license, ILogger<ReceiptProcessor> logger, IUsageTelemetry? telemetry = null, PrinterProfileService? profiles = null)
{
    public ReceiptJob? Process(byte[] payload, string sourceIp, out string? rejection)
        => ProcessCore(payload, sourceIp, JobOrigins.Live, true, true, null, sourceIp, null, null, null,
            null, null, out rejection);

    internal ReceiptJob? Process(
        byte[] payload,
        string sourceIp,
        PrinterProfile profile,
        PrinterListenerJobContext listener,
        out string? rejection)
        => ProcessCore(payload, sourceIp, JobOrigins.Live, true, true, null, sourceIp, null, null, null,
            profile, listener, out rejection);

    internal ReceiptJob? ProcessTestReceipt(
        byte[] payload,
        PrinterProfile profile,
        PrinterListenerJobContext listener,
        out string? rejection)
        => ProcessCore(payload, "Built-in test", JobOrigins.TestReceipt, false, false, null,
            "Built-in test", null, null, null, profile, listener, out rejection);

    public ReceiptJob? Import(
        byte[] payload,
        string fileName,
        DateTimeOffset? originalReceivedAt,
        string? originalSourceIp,
        Guid? capturedJobId,
        out string? rejection)
        => Import(payload, fileName, originalReceivedAt, originalSourceIp, capturedJobId, null, out rejection);

    public ReceiptJob? Import(
        byte[] payload,
        string fileName,
        DateTimeOffset? originalReceivedAt,
        string? originalSourceIp,
        Guid? capturedJobId,
        string? capturedProfileId,
        out string? rejection)
        => Import(payload, fileName, originalReceivedAt, originalSourceIp, capturedJobId, capturedProfileId,
            null, null, null, out rejection);

    public ReceiptJob? Import(
        byte[] payload,
        string fileName,
        DateTimeOffset? originalReceivedAt,
        string? originalSourceIp,
        Guid? capturedJobId,
        string? capturedProfileId,
        string? listenerId,
        string? listenerName,
        int? listenerPort,
        out string? rejection)
        => ProcessCore(payload, originalSourceIp ?? "Imported file", JobOrigins.Imported, false, false,
            originalReceivedAt, originalSourceIp, capturedJobId, Path.GetFileName(fileName), capturedProfileId,
            null, CreateListenerContext(listenerId, listenerName, listenerPort), out rejection);

    public ReceiptJob? Replay(ReceiptJob source, out string? rejection)
        => ProcessCore(source.RawPayload, source.SourceIp, JobOrigins.Replayed, false, false,
            source.OriginalReceivedAt ?? source.ReceivedAt,
            source.OriginalSourceIp ?? source.SourceIp,
            source.Id,
            source.ImportedFileName,
            source.CapturedProfileId ?? source.ProfileId,
            null,
            new PrinterListenerJobContext(source.ListenerId, source.ListenerName, source.ListenerPort),
            out rejection);

    private ReceiptJob? ProcessCore(
        byte[] payload,
        string sourceIp,
        string origin,
        bool consumeTrial,
        bool recordTelemetry,
        DateTimeOffset? originalReceivedAt,
        string? originalSourceIp,
        Guid? parentJobId,
        string? importedFileName,
        string? capturedProfileId,
        PrinterProfile? explicitProfile,
        PrinterListenerJobContext? listener,
        out string? rejection)
    {
        rejection = null;
        if (payload.Length == 0)
        {
            rejection = "The connection contained no printable data.";
            return null;
        }

        var profile = explicitProfile ?? profiles?.GetSelected() ?? new PrinterProfile(
            PrinterProfileService.EpsonTmT88VId, "EPSON TM-T88V Receipt5", string.Empty, true, 80, 576, 576, 2304, 437,
            [437, 850, 852, 855, 857, 858, 860, 862, 863, 864, 865, 866, 874, 1252], 48, 64,
            new PrinterCapabilities(true, true, true, true, true, true, true, true, true));
        ParsedReceipt receipt;
        try
        {
            receipt = parser.Parse(payload, profile.DefaultCodePage);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Failed to parse receipt from {SourceIp}", sourceIp);
            rejection = "The ESC/POS payload could not be parsed safely.";
            return null;
        }

        profiles?.ApplyCapabilities(receipt, profile);

        if (!receipt.HasPrintableContent)
        {
            rejection = "The connection contained printer control commands only.";
            logger.LogDebug("Ignored control-only ESC/POS traffic from {SourceIp} ({Length} bytes)", sourceIp, payload.Length);
            return null;
        }

        var trialLimitReached = consumeTrial && !license.TryConsume(out _);
        if (trialLimitReached)
        {
            receipt = CreateTrialLimitedReceipt(receipt);
            payload = Encoding.UTF8.GetBytes(receipt.PlainText);
            origin = JobOrigins.TrialLimited;
            recordTelemetry = false;
            logger.LogInformation(
                "Accepted a privacy-redacted Trial-limit receipt from {SourceIp}; original payload content was discarded",
                sourceIp);
        }

        var receivedAt = DateTimeOffset.Now;
        var job = new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = receivedAt,
            SourceIp = sourceIp,
            RawPayload = payload,
            Receipt = receipt,
            Status = trialLimitReached ? "Trial Limit Reached" : "Completed",
            Origin = origin,
            RendererVersion = ProductInfo.Version,
            OriginalReceivedAt = originalReceivedAt ?? receivedAt,
            OriginalSourceIp = originalSourceIp ?? sourceIp,
            ParentJobId = parentJobId,
            ImportedFileName = importedFileName,
            ProfileId = profile.Id,
            ProfileName = profile.Name,
            ProfilePaperWidthMm = profile.PaperWidthMm,
            ProfilePrintableDots = profile.PrintableDots,
            CapturedProfileId = capturedProfileId,
            ListenerId = listener?.Id ?? PrinterListenerDefaults.DefaultId,
            ListenerName = listener?.Name ?? PrinterListenerDefaults.DefaultName,
            ListenerPort = listener?.Port ?? PrinterListenerDefaults.DefaultPort
        };
        store.Add(job);
        if (recordTelemetry)
        {
            telemetry?.RecordPrintJob();
        }
        logger.LogInformation(
            "Stored {Origin} receipt {ReceiptId} from {SourceIp} on listener {ListenerId} ({Length} bytes)",
            origin, job.Id, sourceIp, job.ListenerId, payload.Length);
        return job;
    }

    private static ParsedReceipt CreateTrialLimitedReceipt(ParsedReceipt source)
    {
        const int visibleLineLimit = 10;
        var limited = new ParsedReceipt();
        limited.Lines.AddRange(source.Lines.Take(visibleLineLimit));
        limited.Lines.Add(new ReceiptLine("center", []));
        limited.Lines.Add(new ReceiptLine("center",
            [new ReceiptSpan("TRIAL LICENSE LIMIT REACHED", true, false, 1, 1)]));
        limited.Lines.Add(new ReceiptLine("center",
            [new ReceiptSpan("The Trial License is limited to five complete", false, false, 1, 1)]));
        limited.Lines.Add(new ReceiptLine("center",
            [new ReceiptSpan("emulated print jobs per day.", false, false, 1, 1)]));
        limited.Lines.Add(new ReceiptLine("center",
            [new ReceiptSpan("Upgrade to Lite, Pro, or Enterprise to view", false, false, 1, 1)]));
        limited.Lines.Add(new ReceiptLine("center",
            [new ReceiptSpan("complete receipts and unlock additional features.", false, false, 1, 1)]));
        limited.Commands.Add(new ParsedCommand(
            0,
            string.Empty,
            "Trial limit notice",
            "Original raw bytes and remaining receipt content were discarded for privacy."));
        return limited;
    }

    private static PrinterListenerJobContext? CreateListenerContext(
        string? listenerId,
        string? listenerName,
        int? listenerPort) =>
        string.IsNullOrWhiteSpace(listenerId) ||
        string.IsNullOrWhiteSpace(listenerName) ||
        listenerPort is not (>= 1 and <= 65535)
            ? null
            : new PrinterListenerJobContext(listenerId, listenerName, listenerPort.Value);
}
