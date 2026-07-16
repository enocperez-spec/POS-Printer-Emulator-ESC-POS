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
    private TrialState _trialState;
    private RegistrationInfo _registration;
    private ActivationRecord? _activation;

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
        _trialState = Load<TrialState>(_trialStatePath) ?? NewTrialState();
        _registration = Load<RegistrationInfo>(_registrationPath) ?? new RegistrationInfo(string.Empty, string.Empty);
        _activation = Load<ActivationRecord>(_activationPath);
    }

    public static string DefaultRootPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "POSPrinterEmulator");

    public string RootPath { get; }

    public bool HasProAccess
    {
        get
        {
            lock (_sync)
            {
                return GetValidatedLicense()?.Tier is LicenseTier.Pro or LicenseTier.Enterprise;
            }
        }
    }

    public LicenseStatus GetStatus()
    {
        lock (_sync)
        {
            RollDateForward();
            var license = GetValidatedLicense();
            var hasProAccess = license?.Tier is LicenseTier.Pro or LicenseTier.Enterprise;
            var isEnterprise = license?.Tier == LicenseTier.Enterprise;
            var mode = license?.Tier.ToString() ?? LicenseTier.Trial.ToString();
            return new LicenseStatus(
                mode,
                hasProAccess,
                isEnterprise,
                TrialDailyLimit,
                _trialState.Used,
                hasProAccess ? -1 : Math.Max(0, TrialDailyLimit - _trialState.Used),
                _trialState.Date,
                _registration.CustomerName,
                _registration.EmailAddress,
                license?.LicenseId,
                new FeatureStatus(
                    History: hasProAccess,
                    Exports: hasProAccess,
                    PremiumFeatures: hasProAccess,
                    Watermark: !hasProAccess,
                    StoredLogos: hasProAccess,
                    PrinterState: hasProAccess,
                    Updates: hasProAccess,
                    Support: hasProAccess));
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
            SaveJson(_trialStatePath, _trialState);
            status = GetStatus();
            return true;
        }
    }

    public LicenseStatus Activate(string customerName, string emailAddress, string activationKey)
    {
        lock (_sync)
        {
            if (!ActivationKeyCodec.TryValidate(
                    activationKey,
                    customerName,
                    emailAddress,
                    out var license,
                    out var error) || license is null)
            {
                throw new InvalidOperationException(error);
            }

            _registration = new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant());
            _activation = new ActivationRecord(activationKey.Trim(), DateTimeOffset.UtcNow);
            SaveJson(_registrationPath, _registration);
            SaveJson(_activationPath, _activation);
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
            SaveJson(_registrationPath, _registration);
        }
    }

    public static void RegisterInstallationAtDefaultPath(string customerName, string emailAddress)
    {
        ActivationKeyCodec.ValidateRegistration(customerName, emailAddress);
        Directory.CreateDirectory(DefaultRootPath);
        var registrationPath = Path.Combine(DefaultRootPath, "registration.json");
        var activationPath = Path.Combine(DefaultRootPath, "license.json");
        if (!File.Exists(activationPath))
        {
            SaveJson(registrationPath, new RegistrationInfo(customerName.Trim(), emailAddress.Trim().ToLowerInvariant()));
        }
    }

    private ActivationLicense? GetValidatedLicense()
    {
        if (_activation is null ||
            string.IsNullOrWhiteSpace(_registration.CustomerName) ||
            string.IsNullOrWhiteSpace(_registration.EmailAddress))
        {
            return null;
        }

        return ActivationKeyCodec.TryValidate(
            _activation.ActivationKey,
            _registration.CustomerName,
            _registration.EmailAddress,
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
        catch
        {
            if (typeof(T) == typeof(TrialState))
            {
                return (T)(object)new TrialState(DateOnly.FromDateTime(DateTime.Now), TrialDailyLimit);
            }

            return default;
        }
    }

    private static void SaveJson<T>(string path, T value)
    {
        var temporaryPath = path + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(value));
        File.Move(temporaryPath, path, overwrite: true);
    }

    private void RollDateForward()
    {
        var today = DateOnly.FromDateTime(DateTime.Now);
        if (today > _trialState.Date)
        {
            _trialState = NewTrialState();
            SaveJson(_trialStatePath, _trialState);
        }
    }

    private static TrialState NewTrialState() => new(DateOnly.FromDateTime(DateTime.Now), 0);

    private sealed record TrialState(DateOnly Date, int Used);
    private sealed record ActivationRecord(string ActivationKey, DateTimeOffset ActivatedAt);
}
