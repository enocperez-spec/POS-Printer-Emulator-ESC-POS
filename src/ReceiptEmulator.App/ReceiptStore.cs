using System.Text.Json;

namespace ReceiptEmulator;

public sealed class ReceiptStore
{
    private const int SessionCapacity = 100;
    private const int HistoryCapacity = 500;
    private readonly object _sync = new();
    private readonly LinkedList<ReceiptJob> _jobs = [];
    private readonly LicenseService _license;
    private readonly string _historyDirectory;
    private bool _historyLoaded;

    public ReceiptStore(LicenseService license)
    {
        _license = license;
        _historyDirectory = Path.Combine(license.RootPath, "history");
        if (license.IsFullVersion)
        {
            LoadHistory();
        }
    }

    public void Add(ReceiptJob job)
    {
        lock (_sync)
        {
            _jobs.AddFirst(job);
            while (_jobs.Count > (_license.IsFullVersion ? HistoryCapacity : SessionCapacity))
            {
                _jobs.RemoveLast();
            }

            if (_license.IsFullVersion)
            {
                Persist(job);
            }
        }
    }

    public void EnableFullHistory()
    {
        lock (_sync)
        {
            if (_historyLoaded)
            {
                return;
            }

            var sessionJobs = _jobs.ToArray();
            LoadHistoryUnsafe();
            foreach (var job in sessionJobs.OrderBy(job => job.ReceivedAt))
            {
                if (_jobs.All(existing => existing.Id != job.Id))
                {
                    _jobs.AddFirst(job);
                }

                Persist(job);
            }

            TrimHistoryFiles();
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
        {
            return _jobs.FirstOrDefault(job => job.Id == id);
        }
    }

    private void LoadHistory()
    {
        lock (_sync)
        {
            LoadHistoryUnsafe();
        }
    }

    private void LoadHistoryUnsafe()
    {
        if (_historyLoaded)
        {
            return;
        }

        Directory.CreateDirectory(_historyDirectory);
        foreach (var path in Directory.EnumerateFiles(_historyDirectory, "*.json")
                     .OrderByDescending(File.GetLastWriteTimeUtc)
                     .Take(HistoryCapacity))
        {
            try
            {
                var stored = JsonSerializer.Deserialize<StoredJob>(File.ReadAllText(path));
                if (stored is not null && _jobs.All(existing => existing.Id != stored.Id))
                {
                    _jobs.AddLast(stored.ToReceiptJob());
                }
            }
            catch
            {
                // One damaged history entry does not prevent the remaining history from loading.
            }
        }

        _historyLoaded = true;
    }

    private void Persist(ReceiptJob job)
    {
        Directory.CreateDirectory(_historyDirectory);
        var path = Path.Combine(_historyDirectory, $"{job.ReceivedAt.UtcTicks:D19}-{job.Id:N}.json");
        var temporaryPath = path + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(StoredJob.From(job)));
        File.Move(temporaryPath, path, overwrite: true);
        TrimHistoryFiles();
    }

    private void TrimHistoryFiles()
    {
        foreach (var path in Directory.EnumerateFiles(_historyDirectory, "*.json")
                     .OrderByDescending(File.GetLastWriteTimeUtc)
                     .Skip(HistoryCapacity))
        {
            File.Delete(path);
        }
    }

    private static string FirstUsefulLine(ParsedReceipt receipt) => receipt.Lines
        .Select(line => string.Concat(line.Spans.Select(span => span.Text)).Trim())
        .FirstOrDefault(text => text.Length > 0) ?? "Receipt job";

    private sealed record StoredJob(
        Guid Id,
        DateTimeOffset ReceivedAt,
        string SourceIp,
        byte[] RawPayload,
        List<ReceiptLine> Lines,
        List<ParsedCommand> Commands,
        string Status,
        string? Error)
    {
        public static StoredJob From(ReceiptJob job) => new(
            job.Id,
            job.ReceivedAt,
            job.SourceIp,
            job.RawPayload,
            [.. job.Receipt.Lines],
            [.. job.Receipt.Commands],
            job.Status,
            job.Error);

        public ReceiptJob ToReceiptJob()
        {
            var receipt = new ParsedReceipt();
            receipt.Lines.AddRange(Lines);
            receipt.Commands.AddRange(Commands);
            return new ReceiptJob
            {
                Id = Id,
                ReceivedAt = ReceivedAt,
                SourceIp = SourceIp,
                RawPayload = RawPayload,
                Receipt = receipt,
                Status = Status,
                Error = Error
            };
        }
    }
}
