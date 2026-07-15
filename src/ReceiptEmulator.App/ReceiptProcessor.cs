namespace ReceiptEmulator;

public sealed class ReceiptProcessor(EscPosParser parser, ReceiptStore store, LicenseService license, ILogger<ReceiptProcessor> logger, IUsageTelemetry? telemetry = null)
{
    public ReceiptJob? Process(byte[] payload, string sourceIp, out string? rejection)
        => ProcessCore(payload, sourceIp, JobOrigins.Live, true, true, null, sourceIp, null, null, out rejection);

    public ReceiptJob? Import(
        byte[] payload,
        string fileName,
        DateTimeOffset? originalReceivedAt,
        string? originalSourceIp,
        Guid? capturedJobId,
        out string? rejection)
        => ProcessCore(payload, originalSourceIp ?? "Imported file", JobOrigins.Imported, false, false,
            originalReceivedAt, originalSourceIp, capturedJobId, Path.GetFileName(fileName), out rejection);

    public ReceiptJob? Replay(ReceiptJob source, out string? rejection)
        => ProcessCore(source.RawPayload, source.SourceIp, JobOrigins.Replayed, false, false,
            source.OriginalReceivedAt ?? source.ReceivedAt,
            source.OriginalSourceIp ?? source.SourceIp,
            source.Id,
            source.ImportedFileName,
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
        out string? rejection)
    {
        rejection = null;
        if (payload.Length == 0)
        {
            rejection = "The connection contained no printable data.";
            return null;
        }

        ParsedReceipt receipt;
        try
        {
            receipt = parser.Parse(payload);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Failed to parse receipt from {SourceIp}", sourceIp);
            rejection = "The ESC/POS payload could not be parsed safely.";
            return null;
        }

        if (!receipt.HasPrintableContent)
        {
            rejection = "The connection contained printer control commands only.";
            logger.LogDebug("Ignored control-only ESC/POS traffic from {SourceIp} ({Length} bytes)", sourceIp, payload.Length);
            return null;
        }

        if (consumeTrial && !license.TryConsume(out _))
        {
            rejection = "Daily trial limit reached. Activate POS Printer Emulator to process more jobs.";
            logger.LogWarning("Rejected receipt from {SourceIp}: trial limit reached", sourceIp);
            return null;
        }

        var receivedAt = DateTimeOffset.Now;
        var job = new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = receivedAt,
            SourceIp = sourceIp,
            RawPayload = payload,
            Receipt = receipt,
            Status = "Completed",
            Origin = origin,
            RendererVersion = ProductInfo.Version,
            OriginalReceivedAt = originalReceivedAt ?? receivedAt,
            OriginalSourceIp = originalSourceIp ?? sourceIp,
            ParentJobId = parentJobId,
            ImportedFileName = importedFileName
        };
        store.Add(job);
        if (recordTelemetry)
        {
            telemetry?.RecordPrintJob();
        }
        logger.LogInformation("Stored {Origin} receipt {ReceiptId} from {SourceIp} ({Length} bytes)", origin, job.Id, sourceIp, payload.Length);
        return job;
    }
}
