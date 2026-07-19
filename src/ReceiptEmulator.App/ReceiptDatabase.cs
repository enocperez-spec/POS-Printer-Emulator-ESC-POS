using System.Globalization;
using System.Text.Json;
using Microsoft.Data.Sqlite;

namespace ReceiptEmulator;

internal sealed class ReceiptDatabase
{
    internal const string FileName = "pos-printer-emulator.db";
    private const int SchemaVersion = 2;
    private const string LegacyMigrationKey = "legacy-json-history-v1";
    private readonly string _connectionString;

    public ReceiptDatabase(string rootPath)
    {
        DatabasePath = Path.Combine(rootPath, FileName);
        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = DatabasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Pooling = true
        }.ToString();
        Initialize();
    }

    public string DatabasePath { get; }

    public bool LegacyMigrationCompleted => GetMetadata(LegacyMigrationKey) is not null;

    public IReadOnlyList<ReceiptJob> LoadRecent(
        int capacity,
        Action<string, Exception>? onDamagedRow = null)
    {
        return LoadRecent(capacity, listenerId: null, onDamagedRow);
    }

    public IReadOnlyList<ReceiptJob> LoadRecent(
        int capacity,
        string? listenerId,
        Action<string, Exception>? onDamagedRow = null)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = $"""
            SELECT id, received_at, source_ip, raw_payload, lines_json, commands_json,
                   status, error, origin, renderer_version, original_received_at,
                   original_source_ip, parent_job_id, imported_file_name, profile_id,
                   profile_name, profile_paper_width_mm, profile_printable_dots,
                   captured_profile_id, listener_id, listener_name, listener_port
            FROM receipt_jobs
            {(string.IsNullOrWhiteSpace(listenerId) ? string.Empty : "WHERE listener_id = $listenerId COLLATE NOCASE")}
            ORDER BY sequence DESC
            LIMIT $capacity;
            """;
        command.Parameters.AddWithValue("$capacity", capacity);
        if (!string.IsNullOrWhiteSpace(listenerId))
        {
            command.Parameters.AddWithValue("$listenerId", listenerId);
        }
        using var reader = command.ExecuteReader();
        var jobs = new List<ReceiptJob>();
        while (reader.Read())
        {
            try
            {
                jobs.Add(ReadJob(reader));
            }
            catch (Exception exception)
            {
                var rowId = reader.IsDBNull(0) ? "unknown" : reader.GetValue(0).ToString() ?? "unknown";
                onDamagedRow?.Invoke(rowId, exception);
            }
        }

        return jobs;
    }

    public void Upsert(ReceiptJob job, int capacity)
    {
        using var connection = OpenConnection();
        using var transaction = connection.BeginTransaction();
        Upsert(connection, transaction, job);
        Trim(connection, transaction, capacity);
        transaction.Commit();
    }

    public bool ImportLegacy(IReadOnlyList<ReceiptJob> jobs, int capacity)
    {
        using (var connection = OpenConnection())
        using (var transaction = connection.BeginTransaction())
        {
            foreach (var job in jobs)
            {
                Upsert(connection, transaction, job);
            }

            Trim(connection, transaction, capacity);
            SetMetadata(connection, transaction, LegacyMigrationKey, DateTimeOffset.UtcNow.ToString("O"));
            transaction.Commit();
        }

        var verified = ContainsAll(jobs) && IntegrityCheck();
        if (!verified)
        {
            RemoveMetadata(LegacyMigrationKey);
        }

        return verified;
    }

    public bool Delete(Guid id)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM receipt_jobs WHERE id = $id;";
        command.Parameters.AddWithValue("$id", id.ToString("D"));
        return command.ExecuteNonQuery() > 0;
    }

    public int Clear()
    {
        return Clear(listenerId: null);
    }

    public int Clear(string? listenerId)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        if (string.IsNullOrWhiteSpace(listenerId))
        {
            command.CommandText = "DELETE FROM receipt_jobs;";
        }
        else
        {
            command.CommandText = "DELETE FROM receipt_jobs WHERE listener_id = $listenerId COLLATE NOCASE;";
            command.Parameters.AddWithValue("$listenerId", listenerId);
        }
        return command.ExecuteNonQuery();
    }

    public int Count()
    {
        return Count(listenerId: null);
    }

    public int Count(string? listenerId)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        if (string.IsNullOrWhiteSpace(listenerId))
        {
            command.CommandText = "SELECT COUNT(*) FROM receipt_jobs;";
        }
        else
        {
            command.CommandText = "SELECT COUNT(*) FROM receipt_jobs WHERE listener_id = $listenerId COLLATE NOCASE;";
            command.Parameters.AddWithValue("$listenerId", listenerId);
        }
        return Convert.ToInt32(command.ExecuteScalar(), CultureInfo.InvariantCulture);
    }

    public IReadOnlyList<PrinterListenerConfiguration> LoadListenerConfigurations()
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT id, name, protocol, enabled, bind_address, port, profile_id,
                   idle_job_timeout_ms, maximum_job_bytes, buffer_enabled,
                   buffer_capacity, buffer_processing_delay_ms,
                   buffer_overflow_behavior, created_at, updated_at
            FROM printer_listeners
            ORDER BY CASE WHEN id = 'default' THEN 0 ELSE 1 END, created_at, name;
            """;
        using var reader = command.ExecuteReader();
        var listeners = new List<PrinterListenerConfiguration>();
        while (reader.Read())
        {
            listeners.Add(ReadListenerConfiguration(reader));
        }

        return listeners;
    }

    public void UpsertListenerConfiguration(PrinterListenerConfiguration listener)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = """
            INSERT INTO printer_listeners (
                id, name, protocol, enabled, bind_address, port, profile_id,
                idle_job_timeout_ms, maximum_job_bytes, buffer_enabled,
                buffer_capacity, buffer_processing_delay_ms,
                buffer_overflow_behavior, created_at, updated_at)
            VALUES (
                $id, $name, $protocol, $enabled, $bindAddress, $port, $profileId,
                $idleJobTimeoutMs, $maximumJobBytes, $bufferEnabled,
                $bufferCapacity, $bufferProcessingDelayMs,
                $bufferOverflowBehavior, $createdAt, $updatedAt)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                protocol = excluded.protocol,
                enabled = excluded.enabled,
                bind_address = excluded.bind_address,
                port = excluded.port,
                profile_id = excluded.profile_id,
                idle_job_timeout_ms = excluded.idle_job_timeout_ms,
                maximum_job_bytes = excluded.maximum_job_bytes,
                buffer_enabled = excluded.buffer_enabled,
                buffer_capacity = excluded.buffer_capacity,
                buffer_processing_delay_ms = excluded.buffer_processing_delay_ms,
                buffer_overflow_behavior = excluded.buffer_overflow_behavior,
                updated_at = excluded.updated_at;
            """;
        command.Parameters.AddWithValue("$id", listener.Id);
        command.Parameters.AddWithValue("$name", listener.Name);
        command.Parameters.AddWithValue("$protocol", listener.Protocol);
        command.Parameters.AddWithValue("$enabled", listener.Enabled ? 1 : 0);
        command.Parameters.AddWithValue("$bindAddress", listener.BindAddress);
        command.Parameters.AddWithValue("$port", listener.Port);
        command.Parameters.AddWithValue("$profileId", listener.ProfileId);
        command.Parameters.AddWithValue("$idleJobTimeoutMs", listener.IdleJobTimeoutMilliseconds);
        command.Parameters.AddWithValue("$maximumJobBytes", listener.MaximumJobBytes);
        command.Parameters.AddWithValue("$bufferEnabled", listener.Buffer.Enabled ? 1 : 0);
        command.Parameters.AddWithValue("$bufferCapacity", listener.Buffer.Capacity);
        command.Parameters.AddWithValue("$bufferProcessingDelayMs", listener.Buffer.ProcessingDelayMilliseconds);
        command.Parameters.AddWithValue("$bufferOverflowBehavior", listener.Buffer.OverflowBehavior);
        command.Parameters.AddWithValue("$createdAt", listener.CreatedAt.ToString("O"));
        command.Parameters.AddWithValue("$updatedAt", listener.UpdatedAt.ToString("O"));
        command.ExecuteNonQuery();
    }

    public bool DeleteListenerConfiguration(string id)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM printer_listeners WHERE id = $id;";
        command.Parameters.AddWithValue("$id", id);
        return command.ExecuteNonQuery() > 0;
    }

    public bool IntegrityCheck()
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA integrity_check;";
        return string.Equals(command.ExecuteScalar()?.ToString(), "ok", StringComparison.OrdinalIgnoreCase);
    }

    private void Initialize()
    {
        Directory.CreateDirectory(Path.GetDirectoryName(DatabasePath)!);
        using var connection = OpenConnection();
        using var versionCommand = connection.CreateCommand();
        versionCommand.CommandText = "PRAGMA user_version;";
        var version = Convert.ToInt32(versionCommand.ExecuteScalar(), CultureInfo.InvariantCulture);
        if (version > SchemaVersion)
        {
            throw new InvalidOperationException($"The receipt database schema version {version} is newer than this application supports.");
        }

        using (var journal = connection.CreateCommand())
        {
            journal.CommandText = "PRAGMA journal_mode=WAL;";
            journal.ExecuteScalar();
        }

        if (version == 0)
        {
            ApplySchemaVersion1(connection);
            version = 1;
        }

        if (version == 1)
        {
            ApplySchemaVersion2(connection);
        }
    }

    private static void ApplySchemaVersion1(SqliteConnection connection)
    {
        using var transaction = connection.BeginTransaction();
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
                CREATE TABLE schema_migrations (
                    version INTEGER PRIMARY KEY,
                    name TEXT NOT NULL UNIQUE,
                    applied_at_utc TEXT NOT NULL
                );

                CREATE TABLE app_metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );

                CREATE TABLE receipt_jobs (
                    sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                    id TEXT NOT NULL UNIQUE,
                    received_at TEXT NOT NULL,
                    received_at_utc_ticks INTEGER NOT NULL,
                    source_ip TEXT NOT NULL,
                    raw_payload BLOB NOT NULL,
                    lines_json TEXT NOT NULL,
                    commands_json TEXT NOT NULL,
                    status TEXT NOT NULL,
                    error TEXT,
                    origin TEXT NOT NULL,
                    renderer_version TEXT NOT NULL,
                    original_received_at TEXT,
                    original_source_ip TEXT,
                    parent_job_id TEXT,
                    imported_file_name TEXT,
                    profile_id TEXT NOT NULL,
                    profile_name TEXT NOT NULL,
                    profile_paper_width_mm INTEGER NOT NULL,
                    profile_printable_dots INTEGER NOT NULL,
                    captured_profile_id TEXT,
                    listener_id TEXT NOT NULL DEFAULT 'default'
                );

                CREATE INDEX ix_receipt_jobs_recent ON receipt_jobs(sequence DESC);
                CREATE INDEX ix_receipt_jobs_listener_recent ON receipt_jobs(listener_id, sequence DESC);
                CREATE INDEX ix_receipt_jobs_profile ON receipt_jobs(profile_id);
                CREATE INDEX ix_receipt_jobs_source ON receipt_jobs(source_ip);

                INSERT INTO schema_migrations(version, name, applied_at_utc)
                VALUES (1, 'Initial receipt history', $appliedAt);

                PRAGMA user_version=1;
                """;
        command.Parameters.AddWithValue("$appliedAt", DateTimeOffset.UtcNow.ToString("O"));
        command.ExecuteNonQuery();
        transaction.Commit();
    }

    private static void ApplySchemaVersion2(SqliteConnection connection)
    {
        var addListenerName = !ColumnExists(connection, "receipt_jobs", "listener_name");
        var addListenerPort = !ColumnExists(connection, "receipt_jobs", "listener_port");
        using var transaction = connection.BeginTransaction();
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = $"""
            {(addListenerName ? "ALTER TABLE receipt_jobs ADD COLUMN listener_name TEXT NOT NULL DEFAULT 'POS Printer Emulator';" : string.Empty)}
            {(addListenerPort ? "ALTER TABLE receipt_jobs ADD COLUMN listener_port INTEGER NOT NULL DEFAULT 9100;" : string.Empty)}

            CREATE TABLE IF NOT EXISTS printer_listeners (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                protocol TEXT NOT NULL,
                enabled INTEGER NOT NULL,
                bind_address TEXT NOT NULL,
                port INTEGER NOT NULL,
                profile_id TEXT NOT NULL,
                idle_job_timeout_ms INTEGER NOT NULL,
                maximum_job_bytes INTEGER NOT NULL,
                buffer_enabled INTEGER NOT NULL,
                buffer_capacity INTEGER NOT NULL,
                buffer_processing_delay_ms INTEGER NOT NULL,
                buffer_overflow_behavior TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE UNIQUE INDEX IF NOT EXISTS ux_printer_listeners_port
                ON printer_listeners(port);
            CREATE INDEX IF NOT EXISTS ix_printer_listeners_enabled
                ON printer_listeners(enabled, name);

            INSERT OR IGNORE INTO schema_migrations(version, name, applied_at_utc)
            VALUES (2, 'Enterprise printer listeners', $appliedAt);

            PRAGMA user_version=2;
            """;
        command.Parameters.AddWithValue("$appliedAt", DateTimeOffset.UtcNow.ToString("O"));
        command.ExecuteNonQuery();
        transaction.Commit();
    }

    private static bool ColumnExists(SqliteConnection connection, string tableName, string columnName)
    {
        using var command = connection.CreateCommand();
        command.CommandText = $"PRAGMA table_info({tableName});";
        using var reader = command.ExecuteReader();
        while (reader.Read())
        {
            if (reader.GetString(1).Equals(columnName, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        return false;
    }

    private SqliteConnection OpenConnection()
    {
        var connection = new SqliteConnection(_connectionString);
        try
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = "PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA synchronous=NORMAL;";
            command.ExecuteNonQuery();
            return connection;
        }
        catch
        {
            connection.Dispose();
            throw;
        }
    }

    private static void Upsert(SqliteConnection connection, SqliteTransaction transaction, ReceiptJob job)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            INSERT INTO receipt_jobs (
                id, received_at, received_at_utc_ticks, source_ip, raw_payload,
                lines_json, commands_json, status, error, origin, renderer_version,
                original_received_at, original_source_ip, parent_job_id,
                imported_file_name, profile_id, profile_name, profile_paper_width_mm,
                profile_printable_dots, captured_profile_id, listener_id,
                listener_name, listener_port)
            VALUES (
                $id, $receivedAt, $receivedAtTicks, $sourceIp, $rawPayload,
                $linesJson, $commandsJson, $status, $error, $origin, $rendererVersion,
                $originalReceivedAt, $originalSourceIp, $parentJobId,
                $importedFileName, $profileId, $profileName, $profilePaperWidthMm,
                $profilePrintableDots, $capturedProfileId, $listenerId,
                $listenerName, $listenerPort)
            ON CONFLICT(id) DO UPDATE SET
                received_at = excluded.received_at,
                received_at_utc_ticks = excluded.received_at_utc_ticks,
                source_ip = excluded.source_ip,
                raw_payload = excluded.raw_payload,
                lines_json = excluded.lines_json,
                commands_json = excluded.commands_json,
                status = excluded.status,
                error = excluded.error,
                origin = excluded.origin,
                renderer_version = excluded.renderer_version,
                original_received_at = excluded.original_received_at,
                original_source_ip = excluded.original_source_ip,
                parent_job_id = excluded.parent_job_id,
                imported_file_name = excluded.imported_file_name,
                profile_id = excluded.profile_id,
                profile_name = excluded.profile_name,
                profile_paper_width_mm = excluded.profile_paper_width_mm,
                profile_printable_dots = excluded.profile_printable_dots,
                captured_profile_id = excluded.captured_profile_id,
                listener_id = excluded.listener_id,
                listener_name = excluded.listener_name,
                listener_port = excluded.listener_port;
            """;
        command.Parameters.AddWithValue("$id", job.Id.ToString("D"));
        command.Parameters.AddWithValue("$receivedAt", job.ReceivedAt.ToString("O"));
        command.Parameters.AddWithValue("$receivedAtTicks", job.ReceivedAt.UtcTicks);
        command.Parameters.AddWithValue("$sourceIp", job.SourceIp);
        command.Parameters.Add("$rawPayload", SqliteType.Blob).Value = job.RawPayload;
        command.Parameters.AddWithValue("$linesJson", JsonSerializer.Serialize(job.Receipt.Lines));
        command.Parameters.AddWithValue("$commandsJson", JsonSerializer.Serialize(job.Receipt.Commands));
        command.Parameters.AddWithValue("$status", job.Status);
        AddNullable(command, "$error", job.Error);
        command.Parameters.AddWithValue("$origin", job.Origin);
        command.Parameters.AddWithValue("$rendererVersion", job.RendererVersion);
        AddNullable(command, "$originalReceivedAt", job.OriginalReceivedAt?.ToString("O"));
        AddNullable(command, "$originalSourceIp", job.OriginalSourceIp);
        AddNullable(command, "$parentJobId", job.ParentJobId?.ToString("D"));
        AddNullable(command, "$importedFileName", job.ImportedFileName);
        command.Parameters.AddWithValue("$profileId", job.ProfileId);
        command.Parameters.AddWithValue("$profileName", job.ProfileName);
        command.Parameters.AddWithValue("$profilePaperWidthMm", job.ProfilePaperWidthMm);
        command.Parameters.AddWithValue("$profilePrintableDots", job.ProfilePrintableDots);
        AddNullable(command, "$capturedProfileId", job.CapturedProfileId);
        command.Parameters.AddWithValue("$listenerId", NormalizeListenerId(job.ListenerId));
        command.Parameters.AddWithValue("$listenerName", NormalizeListenerName(job.ListenerName));
        command.Parameters.AddWithValue("$listenerPort", NormalizeListenerPort(job.ListenerPort));
        command.ExecuteNonQuery();
    }

    private static void Trim(SqliteConnection connection, SqliteTransaction transaction, int capacity)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            DELETE FROM receipt_jobs
            WHERE sequence IN (
                SELECT sequence FROM receipt_jobs
                ORDER BY sequence DESC
                LIMIT -1 OFFSET $capacity
            );
            """;
        command.Parameters.AddWithValue("$capacity", capacity);
        command.ExecuteNonQuery();
    }

    private bool ContainsAll(IEnumerable<ReceiptJob> jobs)
    {
        using var connection = OpenConnection();
        foreach (var job in jobs.DistinctBy(job => job.Id))
        {
            using var command = connection.CreateCommand();
            command.CommandText = "SELECT raw_payload FROM receipt_jobs WHERE id = $id LIMIT 1;";
            command.Parameters.AddWithValue("$id", job.Id.ToString("D"));
            if (command.ExecuteScalar() is not byte[] payload || !payload.SequenceEqual(job.RawPayload))
            {
                return false;
            }
        }

        return true;
    }

    private string? GetMetadata(string key)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = "SELECT value FROM app_metadata WHERE key = $key;";
        command.Parameters.AddWithValue("$key", key);
        return command.ExecuteScalar()?.ToString();
    }

    private static void SetMetadata(SqliteConnection connection, SqliteTransaction transaction, string key, string value)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            INSERT INTO app_metadata(key, value) VALUES ($key, $value)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value;
            """;
        command.Parameters.AddWithValue("$key", key);
        command.Parameters.AddWithValue("$value", value);
        command.ExecuteNonQuery();
    }

    private void RemoveMetadata(string key)
    {
        using var connection = OpenConnection();
        using var command = connection.CreateCommand();
        command.CommandText = "DELETE FROM app_metadata WHERE key = $key;";
        command.Parameters.AddWithValue("$key", key);
        command.ExecuteNonQuery();
    }

    private static ReceiptJob ReadJob(SqliteDataReader reader)
    {
        var lines = JsonSerializer.Deserialize<List<ReceiptLine>>(reader.GetString(4)) ?? [];
        var commands = JsonSerializer.Deserialize<List<ParsedCommand>>(reader.GetString(5)) ?? [];
        var receipt = new ParsedReceipt();
        receipt.Lines.AddRange(lines);
        receipt.Commands.AddRange(commands);
        return new ReceiptJob
        {
            Id = Guid.Parse(reader.GetString(0)),
            ReceivedAt = DateTimeOffset.Parse(reader.GetString(1), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind),
            SourceIp = reader.GetString(2),
            RawPayload = (byte[])reader[3],
            Receipt = receipt,
            Status = reader.GetString(6),
            Error = NullableString(reader, 7),
            Origin = reader.GetString(8),
            RendererVersion = reader.GetString(9),
            OriginalReceivedAt = ParseNullableDate(reader, 10),
            OriginalSourceIp = NullableString(reader, 11),
            ParentJobId = ParseNullableGuid(reader, 12),
            ImportedFileName = NullableString(reader, 13),
            ProfileId = reader.GetString(14),
            ProfileName = reader.GetString(15),
            ProfilePaperWidthMm = reader.GetInt32(16),
            ProfilePrintableDots = reader.GetInt32(17),
            CapturedProfileId = NullableString(reader, 18),
            ListenerId = NormalizeListenerId(NullableString(reader, 19)),
            ListenerName = NormalizeListenerName(NullableString(reader, 20)),
            ListenerPort = reader.IsDBNull(21)
                ? PrinterListenerDefaults.DefaultPort
                : NormalizeListenerPort(reader.GetInt32(21))
        };
    }

    private static PrinterListenerConfiguration ReadListenerConfiguration(SqliteDataReader reader)
    {
        var protocol = reader.GetString(2);
        if (!protocol.Equals(PrinterListenerDefaults.RawTcpProtocol, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException($"Printer listener {reader.GetString(0)} uses unsupported protocol {protocol}.");
        }

        return new PrinterListenerConfiguration(
            reader.GetString(0),
            reader.GetString(1),
            reader.GetString(4),
            reader.GetInt32(5),
            reader.GetString(6),
            reader.GetInt64(3) != 0,
            reader.GetInt32(7),
            reader.GetInt32(8),
            new PrinterListenerBufferConfiguration(
                reader.GetInt64(9) != 0,
                reader.GetInt32(10),
                reader.GetInt32(11),
                reader.GetString(12)),
            DateTimeOffset.Parse(reader.GetString(13), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind),
            DateTimeOffset.Parse(reader.GetString(14), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind));
    }

    private static string NormalizeListenerId(string? listenerId) =>
        string.IsNullOrWhiteSpace(listenerId) ? PrinterListenerDefaults.DefaultId : listenerId;

    private static string NormalizeListenerName(string? listenerName) =>
        string.IsNullOrWhiteSpace(listenerName) ? PrinterListenerDefaults.DefaultName : listenerName;

    private static int NormalizeListenerPort(int listenerPort) =>
        listenerPort is >= 1 and <= 65535 ? listenerPort : PrinterListenerDefaults.DefaultPort;

    private static void AddNullable(SqliteCommand command, string name, string? value) =>
        command.Parameters.AddWithValue(name, value is null ? DBNull.Value : value);

    private static string? NullableString(SqliteDataReader reader, int ordinal) =>
        reader.IsDBNull(ordinal) ? null : reader.GetString(ordinal);

    private static Guid? ParseNullableGuid(SqliteDataReader reader, int ordinal) =>
        reader.IsDBNull(ordinal) ? null : Guid.Parse(reader.GetString(ordinal));

    private static DateTimeOffset? ParseNullableDate(SqliteDataReader reader, int ordinal) =>
        reader.IsDBNull(ordinal)
            ? null
            : DateTimeOffset.Parse(reader.GetString(ordinal), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind);
}
