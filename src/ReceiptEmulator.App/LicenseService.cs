using System.Text;
using System.Text.Json;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator;

public sealed class LicenseService
{
    public const int TrialDailyLimit = 5;
    public static readonly DateTimeOffset GrandfatheredMaintenanceExpiresAt =
        new(2027, 7, 19, 23, 59, 59, TimeSpan.Zero);
    private readonly object _sync = new();
    private readonly string _trialStatePath;
    private readonly string _registrationPath;
    private readonly string _activationPath;
    private readonly string _maintenancePath;
    private readonly string _promotionPath;
    private readonly string _publicKeyPem;
    private readonly Func<DateTimeOffset> _utcNow;
    private TrialState _trialState;
    private RegistrationInfo _registration;
    private ActivationRecord? _activation;
    private MaintenanceRecord? _maintenance;
    private PromotionRecord? _promotion;
    private Guid? _installationId;
    private Exception? _lastStorageError;

    public LicenseService(IHostEnvironment environment, IConfiguration? configuration = null)
        : this(environment, configuration, () => DateTimeOffset.UtcNow)
    {
    }

    internal LicenseService(
        IHostEnvironment environment,
        IConfiguration? configuration,
        Func<DateTimeOffset> utcNow)
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
        _maintenancePath = Path.Combine(RootPath, "maintenance.json");
        _promotionPath = Path.Combine(RootPath, "promotion.json");
        _publicKeyPem = environment.IsEnvironment("Testing") &&
                        !string.IsNullOrWhiteSpace(configuration?["Licensing:PublicKeyPem"])
            ? configuration!["Licensing:PublicKeyPem"]!
            : ActivationKeyCodec.PublicKeyPem;
        _utcNow = utcNow;
        _trialState = Load<TrialState>(_trialStatePath) ?? NewTrialState();
        _registration = Load<RegistrationInfo>(_registrationPath) ?? new RegistrationInfo(string.Empty, string.Empty);
        _activation = Load<ActivationRecord>(_activationPath);
        _maintenance = Load<MaintenanceRecord>(_maintenancePath);
        _promotion = Load<PromotionRecord>(_promotionPath);
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
                return IsPaid(GetEffectiveTier(GetValidatedLicense()).Tier);
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
                return GetEffectiveTier(GetValidatedLicense()).Tier == LicenseTier.Enterprise;
            }
        }
    }

    public bool HasMaintenanceAccess
    {
        get
        {
            lock (_sync)
            {
                return GetMaintenanceStatus(GetValidatedLicense()).IsActive;
            }
        }
    }

    public LicenseStatus GetStatus()
    {
        lock (_sync)
        {
            RollDateForward();
            var license = GetValidatedLicense();
            var effective = GetEffectiveTier(license);
            var tier = effective.Tier;
            var isPaid = IsPaid(tier);
            var isEnterprise = license?.Tier == LicenseTier.Enterprise;
            var maximumListeners = GetMaximumListeners(tier);
            var mode = tier.ToString();
            var maintenance = GetMaintenanceStatus(license);
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
                maintenance,
                effective.Status,
                new FeatureStatus(
                    History: isPaid,
                    Exports: isPaid,
                    PremiumFeatures: isPaid,
                    Watermark: !isPaid,
                    StoredLogos: isPaid,
                    PrinterState: isPaid,
                    PrinterProfiles: isPaid,
                    Updates: maintenance.IsActive,
                    Support: maintenance.IsActive,
                    MultipleListeners: maximumListeners > 1,
                    ReceiptImages: isPaid,
                    DiagnosticReports: isEnterprise));
        }
    }

    public bool TryConsume(out LicenseStatus status)
    {
        lock (_sync)
        {
            RollDateForward();
            if (IsPaid(GetEffectiveTier(GetValidatedLicense()).Tier))
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

            var registration = new RegistrationInfo(
                ActivationKeyCodec.NormalizeCustomerName(customerName),
                ActivationKeyCodec.CanonicalizeEmail(emailAddress));
            var activation = new ActivationRecord(activationKey.Trim(), DateTimeOffset.UtcNow);

            SaveActivationPair(registration, activation);
            _registration = registration;
            _activation = activation;
            if (_maintenance is not null && !IsMaintenanceRecordFor(license))
            {
                _maintenance = null;
                try
                {
                    if (File.Exists(_maintenancePath)) File.Delete(_maintenancePath);
                }
                catch (Exception exception)
                {
                    _lastStorageError = exception;
                }
            }
            return GetStatus();
        }
    }

    public LicenseStatus InstallMaintenanceEntitlement(string entitlementToken)
    {
        lock (_sync)
        {
            var license = GetValidatedLicense()
                ?? throw new InvalidOperationException("Activate a Lite, Pro, or Enterprise License before applying maintenance.");
            if (!MaintenanceEntitlementCodec.TryValidateWithPublicKey(
                    entitlementToken,
                    _publicKeyPem,
                    out var entitlement,
                    out var error) || entitlement is null)
            {
                throw new InvalidOperationException(error);
            }
            if (entitlement.LicenseId != license.LicenseId || entitlement.Tier != license.Tier)
            {
                throw new InvalidOperationException("This maintenance renewal key was issued for a different license or license tier.");
            }

            var compactToken = string.Concat(entitlementToken.Where(character => !char.IsWhiteSpace(character)));
            if (entitlement.MaintenanceExpiresAt <= _utcNow())
            {
                throw new InvalidOperationException("This maintenance renewal period has already expired.");
            }
            if (IsMaintenanceRecordFor(license) &&
                (_maintenance?.RemoteStatus?.ToLowerInvariant() is "expired" or "revoked") &&
                _maintenance.RemoteCheckedAt is { } unavailableCheckedAt &&
                entitlement.IssuedAt <= unavailableCheckedAt)
            {
                throw new InvalidOperationException(
                    "This maintenance renewal key predates the latest maintenance status. Refresh again or apply the newer renewal key.");
            }

            var current = GetMaintenanceStatus(license);
            if (current.ExpiresAt is { } currentExpiration &&
                entitlement.MaintenanceExpiresAt < currentExpiration)
            {
                throw new InvalidOperationException("This maintenance renewal key does not extend the current coverage period.");
            }
            var record = new MaintenanceRecord(
                compactToken,
                _utcNow(),
                RemoteStatus: "active",
                RemoteCheckedAt: _utcNow(),
                RemoteExpiresAt: entitlement.MaintenanceExpiresAt,
                LicenseId: license.LicenseId,
                Tier: license.Tier);
            SavePersistedJson(_maintenancePath, record);
            _maintenance = record;
            return GetStatus();
        }
    }

    public void BindInstallationId(Guid installationId)
    {
        if (installationId == Guid.Empty)
        {
            throw new ArgumentException("The installation identifier is required.", nameof(installationId));
        }
        lock (_sync)
        {
            _installationId = installationId;
        }
    }

    public LicenseStatus InstallPromotionEntitlement(string entitlementToken)
    {
        lock (_sync)
        {
            if (!PromotionEntitlementCodec.TryValidateWithPublicKey(
                    entitlementToken,
                    _publicKeyPem,
                    out var entitlement,
                    out var error) || entitlement is null)
            {
                throw new InvalidOperationException(error);
            }

            var license = GetValidatedLicense();
            var permanentTier = license?.Tier ?? LicenseTier.Trial;
            if (entitlement.PreviousTier != permanentTier)
            {
                throw new InvalidOperationException(
                    "This promotional access key does not match the current permanent license tier.");
            }

            var subjectMatches = entitlement.SubjectType switch
            {
                PromotionSubjectType.License => license is not null && entitlement.SubjectId == license.LicenseId,
                PromotionSubjectType.Installation => license is null &&
                    _installationId is { } installationId &&
                    entitlement.SubjectId == installationId,
                _ => false
            };
            if (!subjectMatches)
            {
                throw new InvalidOperationException(
                    "This promotional access key was issued for a different license or installation.");
            }

            var now = _utcNow();
            if (entitlement.IssuedAt > now.AddMinutes(5) || entitlement.ExpiresAt <= now)
            {
                throw new InvalidOperationException("This promotional access period has expired or is not active yet.");
            }

            var record = new PromotionRecord(
                string.Concat(entitlementToken.Where(character => !char.IsWhiteSpace(character))),
                now,
                now);
            SavePersistedJson(_promotionPath, record);
            _promotion = record;
            return GetStatus();
        }
    }

    public LicenseStatus RecordMaintenanceUnavailable(
        string remoteStatus,
        DateTimeOffset? remoteExpiresAt)
    {
        lock (_sync)
        {
            var license = GetValidatedLicense()
                ?? throw new InvalidOperationException("Activate a Lite, Pro, or Enterprise License before refreshing maintenance.");
            var normalized = remoteStatus.Trim().ToLowerInvariant();
            if (normalized is not "expired" and not "revoked")
            {
                throw new ArgumentOutOfRangeException(
                    nameof(remoteStatus),
                    "Only an authoritative expired or revoked status can disable maintenance locally.");
            }

            var existingRecordMatches = IsMaintenanceRecordFor(license);
            var record = new MaintenanceRecord(
                existingRecordMatches ? _maintenance?.EntitlementToken : null,
                existingRecordMatches ? _maintenance?.InstalledAt ?? _utcNow() : _utcNow(),
                RemoteStatus: normalized,
                RemoteCheckedAt: _utcNow(),
                RemoteExpiresAt: remoteExpiresAt,
                LicenseId: license.LicenseId,
                Tier: license.Tier);
            SavePersistedJson(_maintenancePath, record);
            _maintenance = record;
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
            _registration = new RegistrationInfo(
                ActivationKeyCodec.NormalizeCustomerName(customerName),
                ActivationKeyCodec.CanonicalizeEmail(emailAddress));
            SavePersistedJson(_registrationPath, _registration);
        }
    }

    public int MaximumListeners
    {
        get
        {
            lock (_sync)
            {
                return GetMaximumListeners(GetEffectiveTier(GetValidatedLicense()).Tier);
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
                File.Exists(_maintenancePath),
                File.Exists(_promotionPath),
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
        SaveJson(registrationPath, new RegistrationInfo(
            ActivationKeyCodec.NormalizeCustomerName(customerName),
            ActivationKeyCodec.CanonicalizeEmail(emailAddress)));
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
            new RegistrationInfo(
                ActivationKeyCodec.NormalizeCustomerName(customerName),
                ActivationKeyCodec.CanonicalizeEmail(emailAddress)));

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
        RestoreUpgradeFile("maintenance.json");
        RestoreUpgradeFile("promotion.json");
    }

    public static void CompleteUpgradeStateAtDefaultPath()
    {
        DeleteUpgradeFile("registration.json");
        DeleteUpgradeFile("license.json");
        DeleteUpgradeFile("maintenance.json");
        DeleteUpgradeFile("promotion.json");
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

    private MaintenanceStatus GetMaintenanceStatus(ActivationLicense? license)
    {
        if (license is null || !IsPaid(license.Tier))
        {
            return new MaintenanceStatus(
                IsApplicable: false,
                IsActive: false,
                IsGrandfathered: false,
                ExpiresAt: null,
                State: "NotApplicable",
                RenewalUrl: null,
                Message: "Annual maintenance is included for one year with a paid license purchase.");
        }

        var baseExpiration = license.MaintenanceExpiresAt ?? GrandfatheredMaintenanceExpiresAt;
        var renewal = GetValidatedMaintenanceEntitlement(license);
        var expiration = renewal is not null && renewal.MaintenanceExpiresAt > baseExpiration
            ? renewal.MaintenanceExpiresAt
            : baseExpiration;
        var grandfathered = license.MaintenanceExpiresAt is null &&
                            (renewal is null || renewal.MaintenanceExpiresAt <= GrandfatheredMaintenanceExpiresAt);
        var recordMatches = IsMaintenanceRecordFor(license);
        var authoritativeUnavailable = recordMatches &&
                                       (_maintenance?.RemoteStatus?.ToLowerInvariant() is "expired" or "revoked");
        if (recordMatches &&
            _maintenance?.RemoteStatus?.Equals("expired", StringComparison.OrdinalIgnoreCase) == true &&
            _maintenance.RemoteExpiresAt is { } remoteExpiration)
        {
            expiration = remoteExpiration;
        }
        var active = !authoritativeUnavailable && _utcNow() <= expiration;
        var expirationDate = expiration.UtcDateTime.ToString("MMMM d, yyyy", System.Globalization.CultureInfo.InvariantCulture);
        var renewalUrl = $"https://buy.posprinteremulator.com/?product=maintenance&tier={license.Tier}";
        var state = recordMatches &&
                    _maintenance?.RemoteStatus?.Equals("revoked", StringComparison.OrdinalIgnoreCase) == true
            ? "Revoked"
            : active ? "Active" : "Expired";
        var message = state == "Revoked"
            ? $"Maintenance coverage for this {license.Tier} License was revoked. Permanent licensed features continue working."
            : active
            ? grandfathered
                ? $"Legacy-license maintenance includes updates and technical support through {expirationDate}."
                : $"Application updates and technical support are included through {expirationDate}."
            : $"Maintenance expired on {expirationDate}. Your permanent {license.Tier} License and installed features continue working.";

        return new MaintenanceStatus(
            IsApplicable: true,
            IsActive: active,
            IsGrandfathered: grandfathered,
            ExpiresAt: expiration,
            State: state,
            RenewalUrl: renewalUrl,
            Message: message);
    }

    private EffectiveLicense GetEffectiveTier(ActivationLicense? license)
    {
        var permanentTier = license?.Tier ?? LicenseTier.Trial;
        var notActive = new PromotionStatus(
            IsApplicable: permanentTier != LicenseTier.Enterprise,
            IsActive: false,
            State: "None",
            PreviousTier: null,
            GrantedTier: null,
            StartsAt: null,
            ExpiresAt: null,
            Message: permanentTier == LicenseTier.Enterprise
                ? "Enterprise already includes all product features."
                : "No promotional access is installed.");
        if (_promotion is null)
        {
            return new EffectiveLicense(permanentTier, notActive);
        }
        if (!PromotionEntitlementCodec.TryValidateWithPublicKey(
                _promotion.EntitlementToken,
                _publicKeyPem,
                out var entitlement,
                out _) || entitlement is null)
        {
            return new EffectiveLicense(permanentTier, notActive with
            {
                State = "Invalid",
                Message = "The saved promotional access record is invalid. Your permanent license remains active."
            });
        }

        var subjectMatches = entitlement.SubjectType switch
        {
            PromotionSubjectType.License => license is not null && entitlement.SubjectId == license.LicenseId,
            PromotionSubjectType.Installation => license is null &&
                _installationId is { } installationId &&
                entitlement.SubjectId == installationId,
            _ => false
        };
        if (!subjectMatches || entitlement.PreviousTier != permanentTier)
        {
            return new EffectiveLicense(permanentTier, notActive with
            {
                State = "Mismatch",
                Message = "This promotion belongs to a different license or installation. Your permanent license remains active."
            });
        }

        var now = _utcNow();
        var observed = _promotion.HighestObservedTime > now ? _promotion.HighestObservedTime : now;
        if (now < _promotion.HighestObservedTime.AddMinutes(-5))
        {
            return new EffectiveLicense(permanentTier, notActive with
            {
                State = "ClockRollback",
                PreviousTier = entitlement.PreviousTier.ToString(),
                GrantedTier = entitlement.GrantedTier.ToString(),
                StartsAt = entitlement.IssuedAt,
                ExpiresAt = entitlement.ExpiresAt,
                Message = "Promotional access was paused because the system clock moved backward. Restore the correct time and reopen the application."
            });
        }
        if (observed > _promotion.HighestObservedTime.AddMinutes(1))
        {
            _promotion = _promotion with { HighestObservedTime = observed };
            SavePersistedJson(_promotionPath, _promotion);
        }
        if (observed >= entitlement.ExpiresAt)
        {
            return new EffectiveLicense(permanentTier, notActive with
            {
                State = "Expired",
                PreviousTier = entitlement.PreviousTier.ToString(),
                GrantedTier = entitlement.GrantedTier.ToString(),
                StartsAt = entitlement.IssuedAt,
                ExpiresAt = entitlement.ExpiresAt,
                Message = $"The five-day promotion ended. The {permanentTier} License was restored automatically."
            });
        }
        return new EffectiveLicense(entitlement.GrantedTier, new PromotionStatus(
            IsApplicable: true,
            IsActive: true,
            State: "Active",
            PreviousTier: entitlement.PreviousTier.ToString(),
            GrantedTier: entitlement.GrantedTier.ToString(),
            StartsAt: entitlement.IssuedAt,
            ExpiresAt: entitlement.ExpiresAt,
            Message: $"{entitlement.GrantedTier} promotional access is active until {entitlement.ExpiresAt:u}."));
    }

    private MaintenanceEntitlement? GetValidatedMaintenanceEntitlement(ActivationLicense license)
    {
        if (_maintenance is null)
        {
            _maintenance = Load<MaintenanceRecord>(_maintenancePath);
        }
        if (_maintenance is null || string.IsNullOrWhiteSpace(_maintenance.EntitlementToken) ||
            !MaintenanceEntitlementCodec.TryValidateWithPublicKey(
                _maintenance.EntitlementToken,
                _publicKeyPem,
                out var entitlement,
                out _) ||
            entitlement is null ||
            entitlement.LicenseId != license.LicenseId ||
            entitlement.Tier != license.Tier)
        {
            return null;
        }

        return entitlement;
    }

    private bool IsMaintenanceRecordFor(ActivationLicense license)
    {
        if (_maintenance is null) return false;
        if (_maintenance.LicenseId is not null || _maintenance.Tier is not null)
        {
            return _maintenance.LicenseId == license.LicenseId && _maintenance.Tier == license.Tier;
        }
        if (string.IsNullOrWhiteSpace(_maintenance.EntitlementToken) ||
            !MaintenanceEntitlementCodec.TryValidateWithPublicKey(
                _maintenance.EntitlementToken,
                _publicKeyPem,
                out var entitlement,
                out _) ||
            entitlement is null)
        {
            return false;
        }

        return entitlement.LicenseId == license.LicenseId && entitlement.Tier == license.Tier;
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
    private sealed record MaintenanceRecord(
        string? EntitlementToken,
        DateTimeOffset InstalledAt,
        string? RemoteStatus = null,
        DateTimeOffset? RemoteCheckedAt = null,
        DateTimeOffset? RemoteExpiresAt = null,
        Guid? LicenseId = null,
        LicenseTier? Tier = null);
    private sealed record PromotionRecord(
        string EntitlementToken,
        DateTimeOffset InstalledAt,
        DateTimeOffset HighestObservedTime);
    private sealed record EffectiveLicense(LicenseTier Tier, PromotionStatus Status);
}
