using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.Hosting;
using Microsoft.Data.Sqlite;
using ReceiptEmulator;
using System.Text.Json;

namespace ReceiptEmulator.Tests;

public sealed class ReceiptStoreTests
{
    [Fact]
    public void DeletesOneJobAndThenClearsAllRemainingJobs()
    {
        var license = new LicenseService(new TestEnvironment());
        var store = new ReceiptStore(license);
        var first = CreateJob("FIRST");
        var second = CreateJob("SECOND");
        store.Add(first);
        store.Add(second);

        Assert.True(store.Delete(first.Id));
        Assert.False(store.Delete(first.Id));
        Assert.Single(store.GetSummaries());
        Assert.Equal(1, store.Clear());
        Assert.Empty(store.GetSummaries());
    }

    [Fact]
    public void TrialHistoryRemainsMemoryOnly()
    {
        var root = NewRoot();
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        var store = new ReceiptStore(license);
        store.Add(CreateJob("TRIAL"));

        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
        Assert.False(Directory.Exists(Path.Combine(root, "history")));
    }

    [Fact]
    public void PaidHistoryRoundTripsThroughOneSqliteDatabase()
    {
        var root = NewRoot();
        var original = CreateDetailedJob();
        var firstStore = PersistentStore(root);
        firstStore.Add(original);

        Assert.True(File.Exists(firstStore.DatabasePath));
        Assert.False(Directory.Exists(Path.Combine(root, "history")));

        var reloaded = PersistentStore(root).Get(original.Id);
        Assert.NotNull(reloaded);
        Assert.Equal(original.ReceivedAt, reloaded.ReceivedAt);
        Assert.Equal(original.RawPayload, reloaded.RawPayload);
        Assert.Equal(JsonSerializer.Serialize(original.Receipt.Lines), JsonSerializer.Serialize(reloaded.Receipt.Lines));
        Assert.Equal(JsonSerializer.Serialize(original.Receipt.Commands), JsonSerializer.Serialize(reloaded.Receipt.Commands));
        Assert.Equal(original.Origin, reloaded.Origin);
        Assert.Equal(original.ParentJobId, reloaded.ParentJobId);
        Assert.Equal(original.ProfileId, reloaded.ProfileId);
        Assert.Equal(original.CapturedProfileId, reloaded.CapturedProfileId);
        Assert.Equal(original.ListenerId, reloaded.ListenerId);
        Assert.Equal(original.ListenerName, reloaded.ListenerName);
        Assert.Equal(original.ListenerPort, reloaded.ListenerPort);
    }

    [Fact]
    public void MigratesLegacyJsonAndPreservesRollbackBackup()
    {
        var root = NewRoot();
        var history = Directory.CreateDirectory(Path.Combine(root, "history"));
        var legacy = CreateDetailedJob();
        var fileName = WriteLegacyJob(history.FullName, legacy);
        File.WriteAllText(Path.Combine(history.FullName, "damaged.json"), "{not-json");

        var store = PersistentStore(root);

        Assert.NotNull(store.Get(legacy.Id));
        Assert.True(File.Exists(Path.Combine(root, "history-json-backup", fileName)));
        Assert.True(File.Exists(Path.Combine(root, "history-json-backup", "damaged.json")));
        Assert.False(File.Exists(Path.Combine(history.FullName, fileName)));
        Assert.False(File.Exists(Path.Combine(history.FullName, "damaged.json")));
        Assert.True(new ReceiptDatabase(root).IntegrityCheck());
        Assert.Single(PersistentStore(root).GetSummaries());
    }

    [Fact]
    public void ImportsLegacyFallbackJobsAfterTheInitialMigrationMarker()
    {
        var root = NewRoot();
        Assert.Empty(PersistentStore(root).GetSummaries());
        var history = Directory.CreateDirectory(Path.Combine(root, "history"));
        var fallbackJob = CreateDetailedJob();
        WriteLegacyJob(history.FullName, fallbackJob);

        var reloaded = PersistentStore(root);

        Assert.NotNull(reloaded.Get(fallbackJob.Id));
        Assert.Single(reloaded.GetSummaries());
        Assert.Empty(Directory.EnumerateFiles(history.FullName, "*.json"));
    }

    [Fact]
    public void SqliteSchemaUsesWalAndRequiredIndexes()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        using var connection = new SqliteConnection($"Data Source={store.DatabasePath}");
        connection.Open();

        Assert.Equal(2L, ExecuteLong(connection, "PRAGMA user_version;"));
        Assert.Equal("wal", ExecuteString(connection, "PRAGMA journal_mode;"));
        Assert.Equal("ok", ExecuteString(connection, "PRAGMA integrity_check;"));
        Assert.Equal(4L, ExecuteLong(connection, "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name LIKE 'ix_receipt_jobs_%';"));
        Assert.Equal(1L, ExecuteLong(connection, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='printer_listeners';"));
        Assert.Equal(3L, ExecuteLong(connection, "SELECT COUNT(*) FROM pragma_table_info('receipt_jobs') WHERE name IN ('listener_id', 'listener_name', 'listener_port');"));
    }

    [Fact]
    public void ListenerMetadataRoundTripsAndFiltersHistory()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        var defaultJob = CreateJob("DEFAULT");
        var kitchenJob = WithListener(CreateJob("KITCHEN"), "listener-kitchen", "Kitchen", 9101);
        store.Add(defaultJob);
        store.Add(kitchenJob);

        var reloaded = PersistentStore(root);
        var summary = Assert.Single(reloaded.GetSummaries("listener-kitchen"));

        Assert.Equal(kitchenJob.Id, summary.Id);
        Assert.Equal("Kitchen", summary.ListenerName);
        Assert.Equal(9101, summary.ListenerPort);
        Assert.Single(reloaded.GetSummaries(PrinterListenerDefaults.DefaultId));
        Assert.Equal(1, reloaded.Clear("listener-kitchen"));
        Assert.Null(PersistentStore(root).Get(kitchenJob.Id));
        Assert.NotNull(PersistentStore(root).Get(defaultJob.Id));
    }

    [Fact]
    public void PaidDeleteAndClearRemainDeletedAfterRestart()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        var first = CreateJob("FIRST");
        var second = CreateJob("SECOND");
        store.Add(first);
        store.Add(second);

        Assert.True(store.Delete(first.Id));
        Assert.Null(PersistentStore(root).Get(first.Id));
        Assert.Equal(1, store.Clear());
        Assert.Empty(PersistentStore(root).GetSummaries());
    }

    [Fact]
    public void FailedPersistentDeleteDoesNotRemoveTheVisibleJobOrReportSuccess()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        var job = CreateJob("KEEP ME");
        store.Add(job);
        SqliteConnection.ClearAllPools();
        var unavailableDatabase = store.DatabasePath;
        var savedDatabase = unavailableDatabase + ".saved";
        File.Move(unavailableDatabase, savedDatabase);
        Directory.CreateDirectory(unavailableDatabase);

        try
        {
            Assert.Throws<InvalidOperationException>(() => store.Delete(job.Id));
            Assert.NotNull(store.Get(job.Id));
        }
        finally
        {
            Directory.Delete(unavailableDatabase);
            File.Move(savedDatabase, unavailableDatabase);
        }

        Assert.NotNull(PersistentStore(root).Get(job.Id));
    }

    [Fact]
    public void OneDamagedSqliteRowDoesNotHideValidHistory()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        var valid = CreateJob("VALID");
        var damaged = CreateJob("DAMAGED");
        store.Add(valid);
        store.Add(damaged);
        SqliteConnection.ClearAllPools();
        using (var connection = new SqliteConnection($"Data Source={store.DatabasePath}"))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = "UPDATE receipt_jobs SET lines_json = '{not-json' WHERE id = $id;";
            command.Parameters.AddWithValue("$id", damaged.Id.ToString("D"));
            command.ExecuteNonQuery();
        }

        var reloaded = PersistentStore(root);

        Assert.NotNull(reloaded.Get(valid.Id));
        Assert.Null(reloaded.Get(damaged.Id));
        Assert.Single(reloaded.GetSummaries());
    }

    [Fact]
    public void TrialToProHistoryMergeKeepsTheConfiguredCapacity()
    {
        var root = NewRoot();
        var history = Enumerable.Range(0, 500).Select(index => CreateJob($"HISTORY {index}")).ToArray();
        Assert.True(new ReceiptDatabase(root).ImportLegacy(history, capacity: 500));
        var persistent = false;
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        var store = new ReceiptStore(license, () => persistent);
        foreach (var index in Enumerable.Range(0, 100))
        {
            store.Add(CreateJob($"SESSION {index}"));
        }

        persistent = true;
        store.EnableProHistory();

        Assert.Equal(500, store.GetSummaries().Count);
        Assert.Equal(500, new ReceiptDatabase(root).Count());
    }

    [Fact]
    public async Task ConcurrentPaidAddsAreSerializedWithoutLosingJobs()
    {
        var root = NewRoot();
        var store = PersistentStore(root);
        var jobs = Enumerable.Range(0, 40).Select(index => CreateJob($"JOB {index}")).ToArray();

        await Task.WhenAll(jobs.Select(job => Task.Run(() => store.Add(job))));

        Assert.Equal(jobs.Length, PersistentStore(root).GetSummaries().Count);
        Assert.Equal(jobs.Length, new ReceiptDatabase(root).Count());
    }

    [Fact]
    public void SqliteRetentionKeepsOnlyTheNewestConfiguredJobs()
    {
        var root = NewRoot();
        var database = new ReceiptDatabase(root);
        var jobs = Enumerable.Range(0, 5).Select(index => CreateJob($"JOB {index}")).ToArray();

        foreach (var job in jobs)
        {
            database.Upsert(job, capacity: 3);
        }

        Assert.Equal(3, database.Count());
        Assert.Equal(jobs.Skip(2).Reverse().Select(job => job.Id), database.LoadRecent(3).Select(job => job.Id));
    }

    [Fact]
    public void RefusesNewerDatabaseSchemaWithoutChangingIt()
    {
        var root = NewRoot();
        Directory.CreateDirectory(root);
        var path = Path.Combine(root, ReceiptDatabase.FileName);
        using (var connection = new SqliteConnection($"Data Source={path}"))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = "PRAGMA user_version=99;";
            command.ExecuteNonQuery();
        }

        var exception = Assert.Throws<InvalidOperationException>(() => new ReceiptDatabase(root));

        Assert.Contains("newer than this application supports", exception.Message);
        using var verification = new SqliteConnection($"Data Source={path}");
        verification.Open();
        Assert.Equal(99L, ExecuteLong(verification, "PRAGMA user_version;"));
    }

    private static ReceiptJob CreateJob(string text)
    {
        var receipt = new ParsedReceipt();
        receipt.Lines.Add(new ReceiptLine("left", [new ReceiptSpan(text, false, false, 1, 1)]));
        return new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = DateTimeOffset.Now,
            SourceIp = "127.0.0.1",
            RawPayload = System.Text.Encoding.ASCII.GetBytes(text),
            Receipt = receipt,
            Status = "Completed"
        };
    }

    private static ReceiptJob CreateDetailedJob()
    {
        var receipt = new ParsedReceipt();
        receipt.Lines.Add(new ReceiptLine("center", [new ReceiptSpan("DETAILED", true, true, 2, 2, true, false, false, "red", "B")], "text"));
        receipt.Commands.Add(new ParsedCommand(12, "1B 40", "Initialize printer", "ESC @", false));
        return new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = new DateTimeOffset(2026, 7, 18, 9, 30, 15, TimeSpan.FromHours(-4)).AddTicks(1234),
            SourceIp = "192.0.2.25",
            RawPayload = [0x1B, 0x40, 0x44, 0x45, 0x54, 0x41, 0x49, 0x4C],
            Receipt = receipt,
            Status = "Completed",
            Error = "Profile warning",
            Origin = JobOrigins.Replayed,
            RendererVersion = "0.3.19",
            OriginalReceivedAt = new DateTimeOffset(2026, 7, 17, 8, 0, 0, TimeSpan.FromHours(-4)),
            OriginalSourceIp = "198.51.100.10",
            ParentJobId = Guid.NewGuid(),
            ImportedFileName = "detailed.ppecapture",
            ProfileId = "custom-profile",
            ProfileName = "Custom Profile",
            ProfilePaperWidthMm = 58,
            ProfilePrintableDots = 384,
            CapturedProfileId = "captured-profile",
            ListenerId = "listener-front-counter",
            ListenerName = "Front Counter",
            ListenerPort = 9102
        };
    }

    private static ReceiptJob WithListener(ReceiptJob job, string id, string name, int port) => new()
    {
        Id = job.Id,
        ReceivedAt = job.ReceivedAt,
        SourceIp = job.SourceIp,
        RawPayload = job.RawPayload,
        Receipt = job.Receipt,
        Status = job.Status,
        Error = job.Error,
        Origin = job.Origin,
        RendererVersion = job.RendererVersion,
        OriginalReceivedAt = job.OriginalReceivedAt,
        OriginalSourceIp = job.OriginalSourceIp,
        ParentJobId = job.ParentJobId,
        ImportedFileName = job.ImportedFileName,
        ProfileId = job.ProfileId,
        ProfileName = job.ProfileName,
        ProfilePaperWidthMm = job.ProfilePaperWidthMm,
        ProfilePrintableDots = job.ProfilePrintableDots,
        CapturedProfileId = job.CapturedProfileId,
        ListenerId = id,
        ListenerName = name,
        ListenerPort = port
    };

    private static string WriteLegacyJob(string historyDirectory, ReceiptJob job)
    {
        var legacyDocument = new
        {
            job.Id,
            job.ReceivedAt,
            job.SourceIp,
            job.RawPayload,
            Lines = job.Receipt.Lines,
            Commands = job.Receipt.Commands,
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
            job.CapturedProfileId
        };
        var fileName = $"{job.ReceivedAt.UtcTicks:D19}-{job.Id:N}.json";
        File.WriteAllText(Path.Combine(historyDirectory, fileName), JsonSerializer.Serialize(legacyDocument));
        return fileName;
    }

    private static ReceiptStore PersistentStore(string root)
    {
        var license = new LicenseService(new TestEnvironment(), Configuration(root));
        return new ReceiptStore(license, persistentHistoryEnabled: true);
    }

    private static IConfiguration Configuration(string root) => new ConfigurationBuilder()
        .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
        .Build();

    private static string NewRoot() => Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));

    private static long ExecuteLong(SqliteConnection connection, string sql)
    {
        using var command = connection.CreateCommand();
        command.CommandText = sql;
        return Convert.ToInt64(command.ExecuteScalar());
    }

    private static string ExecuteString(SqliteConnection connection, string sql)
    {
        using var command = connection.CreateCommand();
        command.CommandText = sql;
        return Convert.ToString(command.ExecuteScalar())!;
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
