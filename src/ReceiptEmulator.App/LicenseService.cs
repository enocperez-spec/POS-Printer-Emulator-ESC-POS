using System.Text;
using System.Text.Json;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator;

public sealed class LicenseService
{
    public const int TrialDailyLimit = 5;
    private readonly object _sync = new();
    private readonly string _trialStatePath;
    private readonly string _registrationPath;
    private readonly string _activationPath;
    private readonly string _publicKeyPem;
    private TrialState _trialState;
    private RegistrationInfo _registration;
    private ActivationRecord? _activation;
    private Exception? _lastStorageError;

    public LicenseService(IHostEnvironment environment, IConfiguration? configuration = null)
    {
        var configuredRoot = configuration?["Data:Root"];
        RootPath = !string.IsNullOrWhiteSpace(configuredRoot)
            ? Path.GetFullPath(configuredRoot)
            : environment.IsEnvironment("Testing")
            ? Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"))
            : DefaultRootPath;
        Directory.CreateDirectory(RootPath);
        _trialStatePath = Path.Combine(RootPath, "trial-state.json");
        _registrationPath = Path.Combine(RootPath, "registration.json");
        _activationPath = Path.Combine(RootPath, "license.json");
        _publicKeyPem = environment.IsEnvironment("Testing") &&
                        !string.IsNullOrWhiteSpace(configuration?["Licensing:PublicKeyPem"])
            ? configuration!["Licensing:PublicKeyPem"]!
            : ActivationKeyCodec.PublicKeyPem;
        _trialState = Load<TrialState>(_trialStatePath) ?? NewTrialState();
        _registration = Load<RegistrationInfo>(_registrationPath) ?? new RegistrationInfo(string.Empty, string.Empty);
        _activation = Load<ActivationRecord>(_activationPath);
    }

    public static string DefaultRootPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "POSPrinterEmulator");

    public string RootPath { get; }

    public bool HasPaidAccess
    {
        get
        {
            lock (_sync)
            {
                return IsPaid(GetValidatedLicense()?.Tier ?? LicenseTier.Trial);
            }
        }
    }

    // Retained for source compatibility while callers migrate to the unambiguous paid-access name.
    public bool HasProAccess => HasPaidAccess;

    public bool HasEnterpriseAccess
    {
        get
        {
            lock (_sync)
            {
                return GetValidatedLicense()?.Tier == LicenseTier.Enterprise;
            }
        }
    }

    public LicenseStatus GetStatus()
    {
        lock (_sync)
        {
            RollDateForward();
            var license = GetValidatedLicense();
            var tier = license?.Tier ?? LicenseTier.Trial;
            var isPaid = IsPaid(tier);
            var isEnterprise = license?.Tier == LicenseTier.Enterprise;
            var maximumListeners = GetMaximumListeners(tier);
            var mode = tier.ToString();
            return new LicenseStatus(
                mode,
                isPaid,
                isEnterprise,
                maximumListeners,
                TrialDailyLimit,
                _trialState.Used,
                isPaid ? -1 : Math.Max(0, TrialDailyLimit - _trialState.Used),
                _trialState.Date,
                _registration.CustomerName,
                _registration.EmailAddress,
                license?.LicenseId,
                new FeatureStatus(
                    History: isPaid,
                    Exports: isPaid,
                    PremiumFeatures: isPaid,
                    Watermark: !isPaid,
                    StoredLogos: isPaid,
                    PrinterState: isPaid,
                    PrinterProfiles: isPaid,
                    Updates: isPaid,
                    Support: isPaid,
                    MultipleListeners: maximumListeners > 1));
        }
    }

    public bool TryConsume(out LicenseStatus status)
    {
        lock (_sync)
        {
            RollDateForward();
            if (GetValidatedLicense() is not null)
            {
                status = GetStatus();
                return true;
            }

            if (_trialState.Used >= TrialDailyLimit)
            {
                status = GetStatus();
                return false;
            }

            _trialState = _trialState with { Used = _trialState.Used + 1 };
            SavePersistedJson(_trialStatePath, _trialState);
            status = GetStatus();
            return true;
        }
    }

    public LicenseStatus Activate(string customerName, string emailAddress, string activationKey)
    {
        lock (_sync)
        {
            if (!ActivationKeyCodec.TryValidateWithPublicKey(
                    activationKey,
                    customerName,
                    emailAddress,
                    _publicKeyPem,
                    out var license,
                    out var error) || license is null)
            {
                throw new InvalidOperationException(error);
            }

            var registration = new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant());
            var activation = new ActivationRecord(activationKey.Trim(), DateTimeOffset.UtcNow);

            SaveActivationPair(registration, activation);
            _registration = registration;
            _activation = activation;
            return GetStatus();
        }
    }

    public void RegisterInstallation(string customerName, string emailAddress)
    {
        lock (_sync)
        {
            if (!string.IsNullOrWhiteSpace(_registration.CustomerName) &&
                !string.IsNullOrWhiteSpace(_registration.EmailAddress))
            {
                return;
            }

            ActivationKeyCodec.ValidateRegistration(customerName, emailAddress);
            _registration = new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant());
            SavePersistedJson(_registrationPath, _registration);
        }
    }

    public int MaximumListeners
    {
        get
        {
            lock (_sync)
            {
                return GetMaximumListeners(GetValidatedLicense()?.Tier ?? LicenseTier.Trial);
            }
        }
    }

    public bool CanManageMultipleListeners => MaximumListeners > 1;

    public LicenseStorageDiagnostics GetStorageDiagnostics()
    {
        lock (_sync)
        {
            return new LicenseStorageDiagnostics(
                RootPath,
                Directory.Exists(RootPath),
                File.Exists(_registrationPath),
                File.Exists(_activationPath),
                _lastStorageError?.GetType().FullName,
                _lastStorageError?.Message);
        }
    }

    public static void RegisterInstallationAtDefaultPath(string customerName, string emailAddress)
        => RegisterInstallation(DefaultRootPath, customerName, emailAddress);

    internal static void RegisterInstallation(string rootPath, string customerName, string emailAddress)
    {
        ActivationKeyCodec.ValidateRegistration(customerName, emailAddress);
        Directory.CreateDirectory(rootPath);
        var registrationPath = Path.Combine(rootPath, "registration.json");
        SaveJson(registrationPath, new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant()));
    }

    public static string? GetRequiredPersistedLicenseModeAtDefaultPath() =>
        GetRequiredPersistedLicenseMode(DefaultRootPath, ActivationKeyCodec.PublicKeyPem);

    public static string? ValidatePersistedLicenseForRegistrationAtDefaultPath(
        string customerName,
        string emailAddress) =>
        ValidatePersistedLicenseForRegistration(
            DefaultRootPath,
            ActivationKeyCodec.PublicKeyPem,
            customerName,
            emailAddress);

    internal static string? ValidatePersistedLicenseForRegistration(
        string rootPath,
        string publicKeyPem,
        string customerName,
        string emailAddress) =>
        GetRequiredPersistedLicenseMode(
            rootPath,
            publicKeyPem,
            new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant()));

    internal static string? GetRequiredPersistedLicenseMode(string rootPath, string publicKeyPem)
        => GetRequiredPersistedLicenseMode(rootPath, publicKeyPem, registrationOverride: null);

    private static string? GetRequiredPersistedLicenseMode(
        string rootPath,
        string publicKeyPem,
        RegistrationInfo? registrationOverride)
    {
        var activationPath = Path.Combine(rootPath, "license.json");
        ActivationRecord activation;
        try
        {
            activation = JsonSerializer.Deserialize<ActivationRecord>(File.ReadAllText(activationPath))
                ?? throw new InvalidOperationException("The existing activation license could not be read. The preserved upgrade files were not removed.");
        }
        catch (FileNotFoundException)
        {
            return null;
        }
        catch (DirectoryNotFoundException)
        {
            return null;
        }

        var registration = registrationOverride;
        if (registration is null)
        {
            var registrationPath = Path.Combine(rootPath, "registration.json");
            try
            {
                registration = JsonSerializer.Deserialize<RegistrationInfo>(File.ReadAllText(registrationPath))
                    ?? throw new InvalidOperationException("The existing customer registration could not be read. The preserved upgrade files were not removed.");
            }
            catch (Exception exception) when (exception is FileNotFoundException or DirectoryNotFoundException)
            {
                throw new InvalidOperationException(
                    "The existing activation license is missing its customer registration. The preserved upgrade files were not removed.",
                    exception);
            }
        }
        if (!ActivationKeyCodec.TryValidateWithPublicKey(
                activation.ActivationKey,
                registration.CustomerName,
                registration.EmailAddress,
                publicKeyPem,
                out var license,
                out var error) || license is null)
        {
            throw new InvalidOperationException($"The existing activation license could not be validated: {error} The preserved upgrade files were not removed.");
        }

        return license.Tier.ToString();
    }

    public static void RestoreUpgradeStateAtDefaultPath()
    {
        Directory.CreateDirectory(DefaultRootPath);
        RestoreUpgradeFile("registration.json");
        RestoreUpgradeFile("license.json");
    }

    public static void CompleteUpgradeStateAtDefaultPath()
    {
        DeleteUpgradeFile("registration.json");
        DeleteUpgradeFile("license.json");
    }

    private ActivationLicense? GetValidatedLicense()
    {
        if (_activation is null)
        {
            _activation = Load<ActivationRecord>(_activationPath);
        }

        if (string.IsNullOrWhiteSpace(_registration.CustomerName) ||
            string.IsNullOrWhiteSpace(_registration.EmailAddress))
        {
            _registration = Load<RegistrationInfo>(_registrationPath) ?? _registration;
        }

        if (_activation is null ||
            string.IsNullOrWhiteSpace(_registration.CustomerName) ||
            string.IsNullOrWhiteSpace(_registration.EmailAddress))
        {
            return null;
        }

        return ActivationKeyCodec.TryValidateWithPublicKey(
            _activation.ActivationKey,
            _registration.CustomerName,
            _registration.EmailAddress,
            _publicKeyPem,
            out var license,
            out _)
            ? license
            : null;
    }

    private T? Load<T>(string path)
    {
        try
        {
            return File.Exists(path) ? JsonSerializer.Deserialize<T>(File.ReadAllText(path)) : default;
        }
        catch (Exception exception)
        {
            _lastStorageError = exception;
            if (typeof(T) == typeof(TrialState))
            {
                return (T)(object)new TrialState(DateOnly.FromDateTime(DateTime.Now), TrialDailyLimit);
            }

            return default;
        }
    }

    private void SavePersistedJson<T>(string path, T value)
    {
        try
        {
            SaveJson(path, value);
            _lastStorageError = null;
        }
        catch (Exception exception)
        {
            _lastStorageError = exception;
            throw;
        }
    }

    private void SaveActivationPair(RegistrationInfo registration, ActivationRecord activation)
    {
        var registrationSnapshot = ReadSnapshot(_registrationPath);
        var activationSnapshot = ReadSnapshot(_activationPath);
        try
        {
            SavePersistedJson(_registrationPath, registration);
            SavePersistedJson(_activationPath, activation);
        }
        catch (Exception originalException)
        {
            TryRestoreSnapshot(_registrationPath, registrationSnapshot);
            TryRestoreSnapshot(_activationPath, activationSnapshot);
            _lastStorageError = originalException;
            throw;
        }
    }

    private static void SaveJson<T>(string path, T value) =>
        WriteJsonWithFallback(path, JsonSerializer.Serialize(value), WriteJsonAtomically);

    internal static void WriteJsonWithFallback(
        string path,
        string json,
        Action<string, string> atomicWriter)
    {
        try
        {
            atomicWriter(path, json);
        }
        catch (Exception exception) when (exception is UnauthorizedAccessException or IOException)
        {
            WriteJsonDirectly(path, json);
        }
    }

    private static void WriteJsonAtomically(string path, string json)
    {
        var temporaryPath = $"{path}.{Guid.NewGuid():N}.tmp";
        try
        {
            File.WriteAllText(temporaryPath, json, new UTF8Encoding(false));
            File.Move(temporaryPath, path, overwrite: true);
        }
        finally
        {
            if (File.Exists(temporaryPath))
            {
                try { File.Delete(temporaryPath); }
                catch { }
            }
        }
    }

    private static void WriteJsonDirectly(string path, string json)
    {
        using var stream = new FileStream(path, FileMode.Create, FileAccess.Write, FileShare.None);
        using var writer = new StreamWriter(stream, new UTF8Encoding(false), leaveOpen: true);
        writer.Write(json);
        writer.Flush();
        stream.Flush(flushToDisk: true);
    }

    private static FileSnapshot ReadSnapshot(string path) => ReadSnapshot(path, File.ReadAllBytes);

    internal static FileSnapshot ReadSnapshot(string path, Func<string, byte[]> readAllBytes)
    {
        try
        {
            return new(true, readAllBytes(path));
        }
        catch (FileNotFoundException)
        {
            return new(false, null);
        }
        catch (DirectoryNotFoundException)
        {
            return new(false, null);
        }
    }

    private static void TryRestoreSnapshot(string path, FileSnapshot snapshot)
    {
        try
        {
            if (!snapshot.Exists)
            {
                if (File.Exists(path)) File.Delete(path);
                return;
            }

            using var stream = new FileStream(path, FileMode.Create, FileAccess.Write, FileShare.None);
            stream.Write(snapshot.Content!);
            stream.Flush(flushToDisk: true);
        }
        catch
        {
            // Preserve the original save failure for activation diagnostics.
        }
    }

    private static void RestoreUpgradeFile(string fileName)
    {
        var path = Path.Combine(DefaultRootPath, fileName);
        var backupPath = path + ".upgrade-backup";
        if (File.Exists(backupPath))
        {
            File.Copy(backupPath, path, overwrite: true);
        }
    }

    private static void DeleteUpgradeFile(string fileName)
    {
        var backupPath = Path.Combine(DefaultRootPath, fileName + ".upgrade-backup");
        if (File.Exists(backupPath))
        {
            File.Delete(backupPath);
        }
    }

    private void RollDateForward()
    {
        var today = DateOnly.FromDateTime(DateTime.Now);
        if (today > _trialState.Date)
        {
            _trialState = NewTrialState();
            SavePersistedJson(_trialStatePath, _trialState);
        }
    }

    private static TrialState NewTrialState() => new(DateOnly.FromDateTime(DateTime.Now), 0);

    internal static bool IsPaid(LicenseTier tier) =>
        tier is LicenseTier.Lite or LicenseTier.Pro or LicenseTier.Enterprise;

    internal static int GetMaximumListeners(LicenseTier tier) => tier switch
    {
        LicenseTier.Pro => 2,
        LicenseTier.Enterprise => PrinterListenerDefaults.MaximumListeners,
        _ => 1
    };

    internal sealed record FileSnapshot(bool Exists, byte[]? Content);
    private sealed record TrialState(DateOnly Date, int Used);
    private sealed record ActivationRecord(string ActivationKey, DateTimeOffset ActivatedAt);
}
