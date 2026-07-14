namespace ReceiptEmulator;

public sealed class ReceiptStore
{
    private readonly object _sync = new();
    private readonly LinkedList<ReceiptJob> _jobs = [];
    private const int SessionCapacity = 100;

    public void Add(ReceiptJob job)
    {
        lock (_sync)
        {
            _jobs.AddFirst(job);
            while (_jobs.Count > SessionCapacity)
                _jobs.RemoveLast();
        }
    }

    public IReadOnlyList<JobSummary> GetSummaries()
    {
        lock (_sync)
        {
            return _jobs.Select(job => new JobSummary(
                job.Id,
                job.ReceivedAt,
                job.SourceIp,
                job.PayloadSize,
                job.Status,
                job.UnsupportedCount,
                FirstUsefulLine(job.Receipt))).ToArray();
        }
    }

    public ReceiptJob? Get(Guid id)
    {
        lock (_sync)
            return _jobs.FirstOrDefault(job => job.Id == id);
    }

    private static string FirstUsefulLine(ParsedReceipt receipt) => receipt.Lines
        .Select(line => string.Concat(line.Spans.Select(span => span.Text)).Trim())
        .FirstOrDefault(text => text.Length > 0) ?? "Receipt job";
}
