namespace ReceiptEmulator;

public sealed class ReceiptProcessor(EscPosParser parser, ReceiptStore store, LicenseService license, ILogger<ReceiptProcessor> logger, IUsageTelemetry? telemetry = null)
{
    public ReceiptJob? Process(byte[] payload, string sourceIp, out string? rejection)
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

        if (!license.TryConsume(out _))
        {
            rejection = "Daily trial limit reached. Activate POS Printer Emulator to process more jobs.";
            logger.LogWarning("Rejected receipt from {SourceIp}: trial limit reached", sourceIp);
            return null;
        }

        var job = new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = DateTimeOffset.Now,
            SourceIp = sourceIp,
            RawPayload = payload,
            Receipt = receipt,
            Status = "Completed"
        };
        store.Add(job);
        telemetry?.RecordPrintJob();
        logger.LogInformation("Captured receipt {ReceiptId} from {SourceIp} ({Length} bytes)", job.Id, sourceIp, payload.Length);
        return job;
    }
}
