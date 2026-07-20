using System.Text.Json;
using System.Security.Cryptography;

namespace ReceiptEmulator;

public sealed class ReceiptStore
{
    private const int SessionCapacity = 100;
    private const int HistoryCapacity = 500;
    private readonly object _sync = new();
    private readonly LinkedList<ReceiptJob> _jobs = [];
    private readonly LicenseService _license;
    private readonly string _historyDirectory;
    private readonly string _legacyBackupDirectory;
    private readonly Func<bool>? _persistentHistoryOverride;
    private readonly ILogger<ReceiptStore>? _logger;
    private ReceiptDatabase? _database;
    private bool _historyLoaded;

    public ReceiptStore(LicenseService license, ILogger<ReceiptStore>? logger = null)
        : this(license, logger, null)
    {
    }

    internal ReceiptStore(LicenseService license, bool persistentHistoryEnabled)
        : this(license, () => persistentHistoryEnabled)
    {
    }

    internal ReceiptStore(LicenseService license, Func<bool> persistentHistoryEnabled)
        : this(license, null, persistentHistoryEnabled)
    {
    }

    private ReceiptStore(LicenseService license, ILogger<ReceiptStore>? logger, Func<bool>? persistentHistoryOverride)
    {
        _license = license;
        _logger = logger;
        _persistentHistoryOverride = persistentHistoryOverride;
        _historyDirectory = Path.Combine(license.RootPath, "history");
        _legacyBackupDirectory = Path.Combine(license.RootPath, "history-json-backup");
        if (HasPersistentHistory)
        {
            LoadHistory();
        }
    }

    internal string DatabasePath => Path.Combine(_license.RootPath, ReceiptDatabase.FileName);

    private bool HasPersistentHistory => _persistentHistoryOverride?.Invoke() ?? _license.HasPaidAccess;

    public void Add(ReceiptJob job)
    {
        lock (_sync)
        {
            _jobs.AddFirst(job);
            while (_jobs.Count > (HasPersistentHistory ? HistoryCapacity : SessionCapacity))
            {
                _jobs.RemoveLast();
            }

            if (HasPersistentHistory)
            {
                Persist(job);
            }
        }
    }

    public void EnablePaidHistory()
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

            while (_jobs.Count > HistoryCapacity)
            {
                _jobs.RemoveLast();
            }
        }
    }

    public void EnableProHistory() => EnablePaidHistory();

    public IReadOnlyList<JobSummary> GetSummaries(string? listenerId = null)
    {
        lock (_sync)
        {
            return _jobs
                .Where(job => MatchesListener(job, listenerId))
                .Select(job => new JobSummary(
                job.Id,
                job.ReceivedAt,
                job.SourceIp,
                job.PayloadSize,
                job.Status,
                job.UnsupportedCount,
                FirstUsefulLine(job.Receipt),
                job.Origin,
                job.RendererVersion,
                job.ParentJobId,
                job.ImportedFileName,
                job.ProfileId,
                job.ProfileName,
                job.ProfilePaperWidthMm,
                job.ProfilePrintableDots,
                NormalizeListenerId(job.ListenerId),
                NormalizeListenerName(job.ListenerName),
                NormalizeListenerPort(job.ListenerPort))).ToArray();
        }
    }

    public ReceiptJob? Get(Guid id, string? listenerId = null)
    {
        lock (_sync)
        {
            return _jobs.FirstOrDefault(job => job.Id == id && MatchesListener(job, listenerId));
        }
    }

    public bool Delete(Guid id)
    {
        lock (_sync)
        {
            var job = _jobs.FirstOrDefault(candidate => candidate.Id == id);
            if (job is null)
            {
                return false;
            }

            if (HasPersistentHistory)
            {
                try
                {
                    EnsureDatabase().Delete(id);
                }
                catch (Exception exception)
                {
                    _logger?.LogError(exception, "SQLite receipt history delete failed for job {JobId}", id);
                    throw new InvalidOperationException("The receipt job could not be deleted from local history. Please retry.", exception);
                }
            }

            DeleteLegacyFiles($"*-{id:N}*.json");
            _jobs.Remove(job);

            return true;
        }
    }

    public int Clear(string? listenerId = null)
    {
        lock (_sync)
        {
            if (HasPersistentHistory)
            {
                try
                {
                    EnsureDatabase().Clear(listenerId);
                }
                catch (Exception exception)
                {
                    _logger?.LogError(exception, "SQLite receipt history clear failed");
                    throw new InvalidOperationException("Local receipt history could not be cleared. Please retry.", exception);
                }
            }

            if (string.IsNullOrWhiteSpace(listenerId))
            {
                DeleteLegacyFiles("*.json");
            }
            else
            {
                DeleteLegacyFilesForListener(listenerId);
            }
            var removed = _jobs.Count(job => MatchesListener(job, listenerId));
            if (string.IsNullOrWhiteSpace(listenerId))
            {
                _jobs.Clear();
            }
            else
            {
                var matches = _jobs.Where(job => MatchesListener(job, listenerId)).ToArray();
                foreach (var job in matches)
                {
                    _jobs.Remove(job);
                }
            }

            return removed;
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

        try
        {
            _database = new ReceiptDatabase(_license.RootPath);
            MigrateLegacyHistoryUnsafe();
            foreach (var job in _database.LoadRecent(
                         HistoryCapacity,
                         (rowId, exception) => _logger?.LogWarning(
                             exception,
                             "Skipped damaged SQLite receipt history row {RowId}",
                             rowId)))
            {
                if (_jobs.All(existing => existing.Id != job.Id))
                {
                    _jobs.AddLast(job);
                }
            }

            _historyLoaded = true;
            return;
        }
        catch (Exception exception)
        {
            _logger?.LogError(exception, "SQLite receipt history could not be opened. Falling back to legacy JSON history for this run.");
        }

        LoadLegacyHistoryUnsafe();
        _historyLoaded = true;
    }

    private void LoadLegacyHistoryUnsafe()
    {
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
                _logger?.LogWarning("Skipped damaged legacy receipt history file {FileName}", Path.GetFileName(path));
            }
        }
    }

    private void Persist(ReceiptJob job)
    {
        try
        {
            EnsureDatabase().Upsert(job, HistoryCapacity);
            return;
        }
        catch (Exception exception)
        {
            _logger?.LogError(exception, "SQLite receipt history write failed. Preserving this job in legacy JSON history.");
        }

        PersistLegacy(job);
    }

    private void PersistLegacy(ReceiptJob job)
    {
        Directory.CreateDirectory(_historyDirectory);
        var path = Path.Combine(
            _historyDirectory,
            $"{job.ReceivedAt.UtcTicks:D19}-{job.Id:N}-{SafeFileName(NormalizeListenerId(job.ListenerId))}.json");
        var temporaryPath = path + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(StoredJob.From(job)));
        File.Move(temporaryPath, path, overwrite: true);
        TrimLegacyHistoryFiles();
    }

    private void TrimLegacyHistoryFiles()
    {
        foreach (var path in Directory.EnumerateFiles(_historyDirectory, "*.json")
                     .OrderByDescending(File.GetLastWriteTimeUtc)
                     .Skip(HistoryCapacity))
        {
            File.Delete(path);
        }
    }

    private ReceiptDatabase EnsureDatabase() =>
        _database ??= new ReceiptDatabase(_license.RootPath);

    private void DeleteLegacyFiles(string searchPattern)
    {
        if (!Directory.Exists(_historyDirectory))
        {
            return;
        }

        foreach (var path in Directory.EnumerateFiles(_historyDirectory, searchPattern))
        {
            TryDeleteLegacyFile(path);
        }
    }

    private void DeleteLegacyFilesForListener(string listenerId)
    {
        if (!Directory.Exists(_historyDirectory))
        {
            return;
        }

        foreach (var path in Directory.EnumerateFiles(_historyDirectory, "*.json"))
        {
            try
            {
                var stored = JsonSerializer.Deserialize<StoredJob>(File.ReadAllText(path));
                if (stored is not null &&
                    NormalizeListenerId(stored.ListenerId).Equals(listenerId, StringComparison.OrdinalIgnoreCase))
                {
                    TryDeleteLegacyFile(path);
                }
            }
            catch
            {
                _logger?.LogWarning("Could not inspect legacy receipt history file {FileName} while clearing listener history", Path.GetFileName(path));
            }
        }
    }

    private void TryDeleteLegacyFile(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            // SQLite is authoritative after migration. A stale or locked legacy file must not
            // turn a successful database delete into an HTTP 500 or leave the UI out of sync.
            _logger?.LogWarning(
                exception,
                "Could not remove stale legacy receipt history file {FileName}",
                Path.GetFileName(path));
        }
    }

    private void MigrateLegacyHistoryUnsafe()
    {
        if (_database is null)
        {
            return;
        }

        var allPaths = Directory.Exists(_historyDirectory)
            ? Directory.EnumerateFiles(_historyDirectory, "*.json").ToArray()
            : Array.Empty<string>();
        var paths = allPaths
                .OrderByDescending(File.GetLastWriteTimeUtc)
                .Take(HistoryCapacity)
                .OrderBy(File.GetLastWriteTimeUtc)
                .ToArray();
        if (paths.Length == 0)
        {
            if (!_database.LegacyMigrationCompleted)
            {
                _database.ImportLegacy([], HistoryCapacity);
            }

            return;
        }

        CreateLegacyBackup();
        var jobs = new List<ReceiptJob>();
        var skipped = 0;
        foreach (var path in paths)
        {
            try
            {
                var stored = JsonSerializer.Deserialize<StoredJob>(File.ReadAllText(path));
                if (stored is not null && jobs.All(existing => existing.Id != stored.Id))
                {
                    jobs.Add(stored.ToReceiptJob());
                }
            }
            catch
            {
                skipped++;
                _logger?.LogWarning("Skipped damaged legacy receipt history file {FileName} during SQLite migration", Path.GetFileName(path));
            }
        }

        if (!_database.ImportLegacy(jobs, HistoryCapacity))
        {
            throw new InvalidDataException("The migrated SQLite receipt history could not be verified.");
        }

        foreach (var path in allPaths)
        {
            File.Delete(path);
        }

        _logger?.LogInformation(
            "Migrated {MigratedCount} legacy receipt history jobs to SQLite; {SkippedCount} damaged files were preserved in the backup",
            jobs.Count,
            skipped);
    }

    private void CreateLegacyBackup()
    {
        Directory.CreateDirectory(_legacyBackupDirectory);
        foreach (var sourcePath in Directory.EnumerateFiles(_historyDirectory, "*", SearchOption.AllDirectories))
        {
            var relativePath = Path.GetRelativePath(_historyDirectory, sourcePath);
            var destinationPath = Path.Combine(_legacyBackupDirectory, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(destinationPath)!);
            File.Copy(sourcePath, destinationPath, overwrite: true);
            using var source = File.OpenRead(sourcePath);
            using var destination = File.OpenRead(destinationPath);
            if (source.Length != destination.Length ||
                !SHA256.HashData(source).SequenceEqual(SHA256.HashData(destination)))
            {
                throw new IOException($"The legacy history backup could not be verified for {relativePath}.");
            }
        }
    }

    private static string FirstUsefulLine(ParsedReceipt receipt) => receipt.Lines
        .Select(line => string.Concat(line.Spans.Select(span => span.Text)).Trim())
        .FirstOrDefault(text => text.Length > 0) ?? "Receipt job";

    private static bool MatchesListener(ReceiptJob job, string? listenerId) =>
        string.IsNullOrWhiteSpace(listenerId) ||
        NormalizeListenerId(job.ListenerId).Equals(listenerId, StringComparison.OrdinalIgnoreCase);

    private static string NormalizeListenerId(string? listenerId) =>
        string.IsNullOrWhiteSpace(listenerId) ? PrinterListenerDefaults.DefaultId : listenerId;

    private static string NormalizeListenerName(string? listenerName) =>
        string.IsNullOrWhiteSpace(listenerName) ? PrinterListenerDefaults.DefaultName : listenerName;

    private static int NormalizeListenerPort(int listenerPort) =>
        listenerPort is >= 1 and <= 65535 ? listenerPort : PrinterListenerDefaults.DefaultPort;

    private static string SafeFileName(string value)
    {
        var invalid = Path.GetInvalidFileNameChars();
        return new string(value.Select(character => invalid.Contains(character) ? '_' : character).ToArray());
    }

    private sealed record StoredJob(
        Guid Id,
        DateTimeOffset ReceivedAt,
        string SourceIp,
        byte[] RawPayload,
        List<ReceiptLine> Lines,
        List<ParsedCommand> Commands,
        string Status,
        string? Error,
        string? Origin = null,
        string? RendererVersion = null,
        DateTimeOffset? OriginalReceivedAt = null,
        string? OriginalSourceIp = null,
        Guid? ParentJobId = null,
        string? ImportedFileName = null,
        string? ProfileId = null,
        string? ProfileName = null,
        int? ProfilePaperWidthMm = null,
        int? ProfilePrintableDots = null,
        string? CapturedProfileId = null,
        string? ListenerId = null,
        string? ListenerName = null,
        int? ListenerPort = null)
    {
        public static StoredJob From(ReceiptJob job) => new(
            job.Id,
            job.ReceivedAt,
            job.SourceIp,
            job.RawPayload,
            [.. job.Receipt.Lines],
            [.. job.Receipt.Commands],
            job.Status,
            job.Error,
            job.Origin,
            job.RendererVersion,
            job.OriginalReceivedAt,
            job.OriginalSourceIp,
            job.ParentJobId,
            job.ImportedFileName,
            job.ProfileId,
            job.ProfileName,
            job.ProfilePaperWidthMm,
            job.ProfilePrintableDots,
            job.CapturedProfileId,
            NormalizeListenerId(job.ListenerId),
            NormalizeListenerName(job.ListenerName),
            NormalizeListenerPort(job.ListenerPort));

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
                Error = Error,
                Origin = string.IsNullOrWhiteSpace(Origin) ? JobOrigins.Live : Origin,
                RendererVersion = string.IsNullOrWhiteSpace(RendererVersion) ? "Legacy" : RendererVersion,
                OriginalReceivedAt = OriginalReceivedAt ?? ReceivedAt,
                OriginalSourceIp = string.IsNullOrWhiteSpace(OriginalSourceIp) ? SourceIp : OriginalSourceIp,
                ParentJobId = ParentJobId,
                ImportedFileName = ImportedFileName,
                ProfileId = string.IsNullOrWhiteSpace(ProfileId) ? PrinterProfileService.EpsonTmT88VId : ProfileId,
                ProfileName = string.IsNullOrWhiteSpace(ProfileName) ? "EPSON TM-T88V Receipt5" : ProfileName,
                ProfilePaperWidthMm = ProfilePaperWidthMm ?? 80,
                ProfilePrintableDots = ProfilePrintableDots ?? 576,
                CapturedProfileId = CapturedProfileId,
                ListenerId = NormalizeListenerId(ListenerId),
                ListenerName = NormalizeListenerName(ListenerName),
                ListenerPort = NormalizeListenerPort(ListenerPort ?? PrinterListenerDefaults.DefaultPort)
            };
        }
    }
}
