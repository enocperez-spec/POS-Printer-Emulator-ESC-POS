using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace ReceiptEmulator;

public sealed record BackupPreferences(
    string Theme,
    bool ActivityCollapsed,
    bool InspectorCollapsed);

public sealed record ConfigurationBackupCreateRequest(
    string Password,
    bool IncludeHistory,
    BackupPreferences Preferences);

public sealed record StoredGraphicBackup(
    string KeyCode,
    string Name,
    string FileName,
    byte[] Content);

public sealed record ConfigurationBackupPayload(
    string Format,
    int SchemaVersion,
    string ApplicationVersion,
    DateTimeOffset CreatedAt,
    bool IncludesHistory,
    BackupPreferences Preferences,
    PrinterProfileStatus PrinterProfiles,
    IReadOnlyList<PrinterListenerConfiguration> PrinterListeners,
    IReadOnlyDictionary<string, PrinterStateSnapshot> PrinterStates,
    IReadOnlyList<StoredGraphicBackup> StoredGraphics,
    IReadOnlyList<ReceiptJob> ReceiptHistory);

public sealed record ConfigurationBackupPreview(
    string ApplicationVersion,
    DateTimeOffset CreatedAt,
    bool IncludesHistory,
    int PrinterProfileCount,
    int PrinterListenerCount,
    int StoredLogoCount,
    int ReceiptJobCount,
    int MaximumListeners,
    int PreservedInactiveListeners,
    IReadOnlyList<string> IncludedData,
    IReadOnlyList<string> ExcludedData,
    IReadOnlyList<string> Warnings,
    BackupPreferences Preferences);

public sealed record ConfigurationRestoreResult(
    bool Success,
    DateTimeOffset RestoredAt,
    int RestoredProfiles,
    int RestoredListeners,
    int RestoredLogos,
    int RestoredReceiptJobs,
    int PreservedInactiveListeners,
    string SafetySnapshotId,
    IReadOnlyList<string> Warnings,
    BackupPreferences Preferences);

internal static class BackupPackageCodec
{
    private static readonly byte[] Magic = "PPEBACKUP"u8.ToArray();
    private const byte EnvelopeVersion = 1;
    private const int Iterations = 600_000;
    private const int SaltLength = 16;
    private const int NonceLength = 12;
    private const int TagLength = 16;
    private const int HeaderLength = 9 + 1 + 4 + SaltLength + NonceLength + TagLength;
    internal const int MaximumPackageBytes = 128 * 1024 * 1024;

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        MaxDepth = 128
    };

    public static byte[] Create(ConfigurationBackupPayload payload, string password)
    {
        ValidatePassword(password);
        var plaintext = JsonSerializer.SerializeToUtf8Bytes(payload, JsonOptions);
        if (plaintext.Length > MaximumPackageBytes - HeaderLength)
            throw new InvalidOperationException("The backup is larger than 128 MB. Create a configuration-only backup or remove older receipt history first.");

        var salt = RandomNumberGenerator.GetBytes(SaltLength);
        var nonce = RandomNumberGenerator.GetBytes(NonceLength);
        var key = Rfc2898DeriveBytes.Pbkdf2(password, salt, Iterations, HashAlgorithmName.SHA256, 32);
        try
        {
            var output = new byte[HeaderLength + plaintext.Length];
            Magic.CopyTo(output, 0);
            output[Magic.Length] = EnvelopeVersion;
            BinaryPrimitives.WriteInt32LittleEndian(output.AsSpan(Magic.Length + 1, 4), Iterations);
            salt.CopyTo(output, Magic.Length + 5);
            nonce.CopyTo(output, Magic.Length + 5 + SaltLength);
            var tagOffset = Magic.Length + 5 + SaltLength + NonceLength;
            var ciphertext = output.AsSpan(HeaderLength);
            using var aes = new AesGcm(key, TagLength);
            aes.Encrypt(nonce, plaintext, ciphertext, output.AsSpan(tagOffset, TagLength), output.AsSpan(0, tagOffset));
            return output;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(key);
            CryptographicOperations.ZeroMemory(plaintext);
        }
    }

    public static ConfigurationBackupPayload Read(ReadOnlySpan<byte> package, string password)
    {
        ValidatePassword(password);
        if (package.Length < HeaderLength || package.Length > MaximumPackageBytes)
            throw new InvalidDataException("The selected file is not a supported POS Printer Emulator backup.");
        if (!package[..Magic.Length].SequenceEqual(Magic) || package[Magic.Length] != EnvelopeVersion)
            throw new InvalidDataException("The selected file is not a supported POS Printer Emulator backup.");

        var iterations = BinaryPrimitives.ReadInt32LittleEndian(package.Slice(Magic.Length + 1, 4));
        if (iterations is < 100_000 or > 2_000_000)
            throw new InvalidDataException("The backup uses unsupported password protection settings.");
        var salt = package.Slice(Magic.Length + 5, SaltLength);
        var nonce = package.Slice(Magic.Length + 5 + SaltLength, NonceLength);
        var tagOffset = Magic.Length + 5 + SaltLength + NonceLength;
        var tag = package.Slice(tagOffset, TagLength);
        var ciphertext = package[HeaderLength..];
        var plaintext = new byte[ciphertext.Length];
        var key = Rfc2898DeriveBytes.Pbkdf2(password, salt, iterations, HashAlgorithmName.SHA256, 32);
        try
        {
            using var aes = new AesGcm(key, TagLength);
            try
            {
                aes.Decrypt(nonce, ciphertext, tag, plaintext, package[..tagOffset]);
            }
            catch (CryptographicException exception)
            {
                throw new InvalidDataException("The backup password is incorrect or the backup file is damaged.", exception);
            }

            var payload = JsonSerializer.Deserialize<ConfigurationBackupPayload>(plaintext, JsonOptions)
                ?? throw new InvalidDataException("The backup does not contain configuration data.");
            if (payload.Format != "POS Printer Emulator Backup" || payload.SchemaVersion != 1)
                throw new InvalidDataException("This backup version is not supported by the installed application.");
            return payload;
        }
        catch (JsonException exception)
        {
            throw new InvalidDataException("The backup contains invalid configuration data.", exception);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(key);
            CryptographicOperations.ZeroMemory(plaintext);
        }
    }

    private static void ValidatePassword(string password)
    {
        if (string.IsNullOrWhiteSpace(password) || password.Length < 10 || password.Length > 256)
            throw new ArgumentException("Use a backup password containing 10 to 256 characters.", nameof(password));
    }
}

public sealed class ConfigurationBackupService
{
    public const string FileExtension = ".ppebackup";

    public static bool IsSupportedFileName(string fileName)
    {
        var name = Path.GetFileName(fileName);
        return name.EndsWith(FileExtension, StringComparison.OrdinalIgnoreCase) ||
               name.EndsWith(FileExtension + ".zip", StringComparison.OrdinalIgnoreCase);
    }

    private const int MaximumProfiles = 100;
    private const int MaximumGraphics = 100;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly LicenseService _license;
    private readonly PrinterProfileService _profiles;
    private readonly PrinterListenerConfigurationService _configurations;
    private readonly PrinterListenerManager _listeners;
    private readonly StoredGraphicService _graphics;
    private readonly ReceiptStore _receipts;
    private readonly ILogger<ConfigurationBackupService> _logger;

    public ConfigurationBackupService(
        LicenseService license,
        PrinterProfileService profiles,
        PrinterListenerConfigurationService configurations,
        PrinterListenerManager listeners,
        StoredGraphicService graphics,
        ReceiptStore receipts,
        ILogger<ConfigurationBackupService> logger)
    {
        _license = license;
        _profiles = profiles;
        _configurations = configurations;
        _listeners = listeners;
        _graphics = graphics;
        _receipts = receipts;
        _logger = logger;
    }

    public async Task<byte[]> CreateAsync(ConfigurationBackupCreateRequest request, CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            var payload = await CaptureAsync(request.IncludeHistory, NormalizePreferences(request.Preferences), cancellationToken);
            return BackupPackageCodec.Create(payload, request.Password);
        }
        finally
        {
            _gate.Release();
        }
    }

    public ConfigurationBackupPreview Inspect(ReadOnlySpan<byte> package, string password)
    {
        var payload = BackupPackageCodec.Read(package, password);
        ValidatePayload(payload);
        var preserved = Math.Max(0, payload.PrinterListeners.Count - _license.MaximumListeners);
        var warnings = BuildWarnings(payload, preserved);
        return new(
            payload.ApplicationVersion,
            payload.CreatedAt,
            payload.IncludesHistory,
            payload.PrinterProfiles.Profiles.Count(profile => !profile.BuiltIn),
            payload.PrinterListeners.Count,
            payload.StoredGraphics.Count,
            payload.ReceiptHistory.Count,
            _license.MaximumListeners,
            preserved,
            [
                "Printer listener configuration",
                "Custom printer profiles and selected profile",
                "Stored logos",
                "Simulated printer state",
                "Application appearance preferences",
                .. payload.IncludesHistory ? ["Local receipt history"] : Array.Empty<string>()
            ],
            ["Activation and maintenance keys", "Customer registration", "Credentials", "Application logs", "Windows drivers and printer queues"],
            warnings,
            NormalizePreferences(payload.Preferences));
    }

    public async Task<ConfigurationRestoreResult> RestoreAsync(
        ReadOnlyMemory<byte> package,
        string password,
        CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            var payload = BackupPackageCodec.Read(package.Span, password);
            ValidatePayload(payload);
            var safetyId = await SaveSafetySnapshotAsync(cancellationToken);
            var previous = await CaptureAsync(_license.HasPaidAccess, NormalizePreferences(payload.Preferences), cancellationToken);
            try
            {
                _profiles.Replace(payload.PrinterProfiles);
                await _listeners.RestoreConfigurationsAsync(payload.PrinterListeners, cancellationToken);
                await _graphics.ReplaceAllAsync(payload.StoredGraphics, cancellationToken);
                RestorePrinterStates(payload.PrinterStates);
                var restoredHistory = 0;
                if (payload.IncludesHistory && _license.HasPaidAccess)
                {
                    _receipts.ReplaceHistory(payload.ReceiptHistory);
                    restoredHistory = payload.ReceiptHistory.Count;
                }

                var preserved = Math.Max(0, payload.PrinterListeners.Count - _license.MaximumListeners);
                return new(
                    true,
                    DateTimeOffset.UtcNow,
                    payload.PrinterProfiles.Profiles.Count(profile => !profile.BuiltIn),
                    payload.PrinterListeners.Count,
                    payload.StoredGraphics.Count,
                    restoredHistory,
                    preserved,
                    safetyId,
                    BuildWarnings(payload, preserved),
                    NormalizePreferences(payload.Preferences));
            }
            catch (Exception restoreException)
            {
                _logger.LogError(restoreException, "Configuration restore failed; rolling back to the pre-restore state");
                try
                {
                    _profiles.Replace(previous.PrinterProfiles);
                    await _listeners.RestoreConfigurationsAsync(previous.PrinterListeners, CancellationToken.None);
                    await _graphics.ReplaceAllAsync(previous.StoredGraphics, CancellationToken.None);
                    RestorePrinterStates(previous.PrinterStates);
                    if (previous.IncludesHistory && _license.HasPaidAccess)
                        _receipts.ReplaceHistory(previous.ReceiptHistory);
                }
                catch (Exception rollbackException)
                {
                    _logger.LogCritical(rollbackException, "Configuration restore rollback did not finish");
                    throw new InvalidOperationException(
                        "The backup could not be restored and automatic rollback did not finish. Run Connection Diagnostics and contact support with the safety snapshot reference.",
                        new AggregateException(restoreException, rollbackException));
                }
                throw new InvalidOperationException("The backup could not be restored. The previous configuration was restored automatically.", restoreException);
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    private async Task<ConfigurationBackupPayload> CaptureAsync(
        bool includeHistory,
        BackupPreferences preferences,
        CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        var graphics = await _graphics.ExportAllAsync(cancellationToken);
        var listenerStatus = _listeners.GetStatus();
        var states = new Dictionary<string, PrinterStateSnapshot>(StringComparer.OrdinalIgnoreCase);
        foreach (var listener in listenerStatus.Listeners)
        {
            try
            {
                var state = _listeners.GetPrinterState(listener.Configuration.Id);
                states[listener.Configuration.Id] = ToSnapshot(state);
            }
            catch (KeyNotFoundException) { }
        }
        var history = includeHistory && _license.HasPaidAccess ? _receipts.ExportHistory() : [];
        return new(
            "POS Printer Emulator Backup",
            1,
            ProductInfo.Version,
            DateTimeOffset.UtcNow,
            includeHistory && _license.HasPaidAccess,
            preferences,
            _profiles.GetStatus(),
            _configurations.GetStoredConfigurations(),
            states,
            graphics,
            history);
    }

    private async Task<string> SaveSafetySnapshotAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Automatic safety snapshots require Windows data protection.");
        var id = $"safety-{DateTimeOffset.UtcNow:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}"[..38];
        var directory = Path.Combine(_license.RootPath, "backup-snapshots");
        Directory.CreateDirectory(directory);
        var password = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
        try
        {
            var payload = await CaptureAsync(_license.HasPaidAccess, new("system", false, false), cancellationToken);
            var package = BackupPackageCodec.Create(payload, password);
            var protectedPassword = ProtectedData.Protect(
                Encoding.UTF8.GetBytes(password),
                "POS Printer Emulator safety backup"u8.ToArray(),
                DataProtectionScope.LocalMachine);
            await File.WriteAllBytesAsync(Path.Combine(directory, id + FileExtension), package, cancellationToken);
            await File.WriteAllBytesAsync(Path.Combine(directory, id + ".key"), protectedPassword, cancellationToken);
            foreach (var old in Directory.EnumerateFiles(directory, "safety-*.ppebackup")
                         .OrderByDescending(File.GetCreationTimeUtc)
                         .Skip(5))
            {
                TryDelete(old);
                TryDelete(Path.ChangeExtension(old, ".key"));
            }
            return id;
        }
        finally
        {
            password = string.Empty;
        }
    }

    private void RestorePrinterStates(IReadOnlyDictionary<string, PrinterStateSnapshot> states)
    {
        foreach (var (listenerId, state) in states)
        {
            try
            {
                _listeners.UpdatePrinterState(listenerId, new(
                    state.Online,
                    state.PaperStatus,
                    state.CoverOpen,
                    state.CutterError,
                    state.RecoverableError,
                    state.UnrecoverableError,
                    state.AutoRecoverableError,
                    state.DrawerOpen));
            }
            catch (KeyNotFoundException) { }
        }
    }

    private void ValidatePayload(ConfigurationBackupPayload payload)
    {
        if (payload.PrinterProfiles.Profiles.Count(profile => !profile.BuiltIn) > MaximumProfiles)
            throw new InvalidDataException($"Backups can contain no more than {MaximumProfiles} custom printer profiles.");
        if (payload.PrinterListeners.Count is < 1 or > PrinterListenerDefaults.MaximumListeners)
            throw new InvalidDataException($"Backups must contain between 1 and {PrinterListenerDefaults.MaximumListeners} printer listeners.");
        if (payload.StoredGraphics.Count > MaximumGraphics)
            throw new InvalidDataException($"Backups can contain no more than {MaximumGraphics} stored logos.");
        if (payload.ReceiptHistory.Count > 500)
            throw new InvalidDataException("Backups can contain no more than 500 receipt jobs.");
        if (!payload.PrinterListeners.Any(listener => listener.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase)))
            throw new InvalidDataException("The backup does not contain the required default printer listener.");
        if (payload.PrinterListeners.Select(listener => listener.Id).Distinct(StringComparer.OrdinalIgnoreCase).Count() != payload.PrinterListeners.Count ||
            payload.PrinterListeners.Select(listener => listener.Name).Distinct(StringComparer.OrdinalIgnoreCase).Count() != payload.PrinterListeners.Count ||
            payload.PrinterListeners.Select(listener => listener.Port).Distinct().Count() != payload.PrinterListeners.Count)
            throw new InvalidDataException("The backup contains duplicate printer listener identifiers, names, or ports.");
        if (payload.StoredGraphics.Select(graphic => graphic.KeyCode).Distinct(StringComparer.OrdinalIgnoreCase).Count() != payload.StoredGraphics.Count)
            throw new InvalidDataException("The backup contains duplicate stored-logo keys.");
    }

    private IReadOnlyList<string> BuildWarnings(ConfigurationBackupPayload payload, int preserved)
    {
        var warnings = new List<string>();
        if (Version.TryParse(payload.ApplicationVersion, out var backupVersion) &&
            Version.TryParse(ProductInfo.Version, out var installedVersion) && backupVersion > installedVersion)
            warnings.Add("This backup was created by a newer application version. Unsupported settings will be rejected before changes are applied.");
        if (preserved > 0)
            warnings.Add($"{preserved} listener configuration{(preserved == 1 ? "" : "s")} exceed the current {_license.GetStatus().Mode} License allowance. They will be preserved but remain inactive.");
        if (payload.IncludesHistory && !_license.HasPaidAccess)
            warnings.Add("Receipt history requires a paid license and will not be restored while this installation is in Trial mode.");
        return warnings;
    }

    private static BackupPreferences NormalizePreferences(BackupPreferences? preferences)
    {
        var theme = preferences?.Theme?.Trim().ToLowerInvariant() == "light" ? "light" : "dark";
        return new(theme, preferences?.ActivityCollapsed == true, preferences?.InspectorCollapsed == true);
    }

    private static PrinterStateSnapshot ToSnapshot(PrinterStateStatus state) => new(
        state.Online,
        state.PaperStatus,
        state.CoverOpen,
        state.CutterError,
        state.RecoverableError,
        state.UnrecoverableError,
        state.AutoRecoverableError,
        state.DrawerOpen);

    private static void TryDelete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); }
        catch (IOException) { }
        catch (UnauthorizedAccessException) { }
    }
}
