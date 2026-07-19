using System.Net;
using System.Net.Sockets;

namespace ReceiptEmulator;

public sealed class PrinterListenerConfigurationService
{
    private const int ReservedViewerPort = 5187;
    private readonly object _sync = new();
    private readonly LicenseService _license;
    private readonly Func<bool> _hasEnterpriseAccess;
    private readonly PrinterOptions _options;
    private readonly PrinterProfileService _profiles;
    private readonly ILogger<PrinterListenerConfigurationService>? _logger;
    private readonly DateTimeOffset _legacyCreatedAt = DateTimeOffset.UtcNow;
    private readonly List<PrinterListenerConfiguration> _listeners = [];
    private ReceiptDatabase? _database;
    private bool _enterpriseLoaded;

    public PrinterListenerConfigurationService(
        LicenseService license,
        PrinterOptions options,
        PrinterProfileService profiles,
        ILogger<PrinterListenerConfigurationService>? logger = null)
        : this(license, options, profiles, () => license.HasEnterpriseAccess, logger)
    {
    }

    internal PrinterListenerConfigurationService(
        LicenseService license,
        PrinterOptions options,
        PrinterProfileService profiles,
        Func<bool> hasEnterpriseAccess,
        ILogger<PrinterListenerConfigurationService>? logger = null)
    {
        _license = license;
        _options = options;
        _profiles = profiles;
        _hasEnterpriseAccess = hasEnterpriseAccess;
        _logger = logger;
    }

    public bool HasEnterpriseAccess => _hasEnterpriseAccess();

    public IReadOnlyList<PrinterListenerConfiguration> GetAll()
    {
        lock (_sync)
        {
            if (!HasEnterpriseAccess)
            {
                return [CreateLegacyDefault()];
            }

            EnsureEnterpriseLoadedUnsafe();
            return _listeners.ToArray();
        }
    }

    public PrinterListenerConfiguration? Get(string id)
    {
        lock (_sync)
        {
            if (!HasEnterpriseAccess)
            {
                var defaultListener = CreateLegacyDefault();
                return id.Equals(defaultListener.Id, StringComparison.OrdinalIgnoreCase)
                    ? defaultListener
                    : null;
            }

            EnsureEnterpriseLoadedUnsafe();
            return FindUnsafe(id);
        }
    }

    public PrinterListenerConfiguration EnsureDefault()
    {
        lock (_sync)
        {
            if (!HasEnterpriseAccess)
            {
                return CreateLegacyDefault();
            }

            EnsureEnterpriseLoadedUnsafe();
            return FindUnsafe(PrinterListenerDefaults.DefaultId)!;
        }
    }

    public PrinterListenerConfiguration Create(PrinterListenerInput input)
    {
        lock (_sync)
        {
            var listener = PrepareCreateUnsafe(input);
            CommitUnsafe(listener);
            return listener;
        }
    }

    public PrinterListenerConfiguration Update(string id, PrinterListenerInput input)
    {
        lock (_sync)
        {
            var listener = PrepareUpdateUnsafe(id, input);
            CommitUnsafe(listener);
            return listener;
        }
    }

    public bool Delete(string id)
    {
        lock (_sync)
        {
            EnsureEnterpriseAccess();
            EnsureEnterpriseLoadedUnsafe();
            var existing = FindUnsafe(id);
            if (existing is null)
            {
                return false;
            }
            RemoveUnsafe(existing);
            return true;
        }
    }

    public IReadOnlyList<PrinterListenerConfiguration> GetEffectiveConfigurations() => GetAll();

    public PrinterListenerConfiguration? Find(string id) => Get(id);

    public PrinterListenerConfiguration PrepareCreate(PrinterListenerInput input)
    {
        lock (_sync)
        {
            return PrepareCreateUnsafe(input);
        }
    }

    public PrinterListenerConfiguration PrepareUpdate(string id, PrinterListenerInput input)
    {
        lock (_sync)
        {
            return PrepareUpdateUnsafe(id, input);
        }
    }

    public void Commit(PrinterListenerConfiguration listener)
    {
        lock (_sync)
        {
            EnsureEnterpriseAccess();
            EnsureEnterpriseLoadedUnsafe();
            var canonical = Validate(
                ToInput(listener),
                listener.Id,
                listener.CreatedAt,
                listener.UpdatedAt);
            CommitUnsafe(canonical);
        }
    }

    public void Remove(string id)
    {
        lock (_sync)
        {
            EnsureEnterpriseAccess();
            EnsureEnterpriseLoadedUnsafe();
            var existing = FindUnsafe(id) ?? throw new KeyNotFoundException("The printer listener was not found.");
            RemoveUnsafe(existing);
        }
    }

    public bool IsProfileInUse(string profileId)
    {
        lock (_sync)
        {
            IReadOnlyList<PrinterListenerConfiguration> listeners;
            if (HasEnterpriseAccess)
            {
                listeners = GetEnterpriseListenersUnsafe();
            }
            else
            {
                var preservedDatabasePath = Path.Combine(_license.RootPath, ReceiptDatabase.FileName);
                if (!File.Exists(preservedDatabasePath))
                {
                    listeners = [CreateLegacyDefault()];
                }
                else
                {
                    try
                    {
                        listeners = new[] { CreateLegacyDefault() }
                            .Concat(new ReceiptDatabase(_license.RootPath).LoadListenerConfigurations())
                            .DistinctBy(listener => listener.Id, StringComparer.OrdinalIgnoreCase)
                            .ToArray();
                    }
                    catch (Exception exception)
                    {
                        _logger?.LogWarning(exception,
                            "Preserved Enterprise listener configuration could not be checked before deleting profile {ProfileId}",
                            profileId);
                        return true;
                    }
                }
            }
            return listeners.Any(listener => listener.ProfileId.Equals(profileId, StringComparison.OrdinalIgnoreCase));
        }
    }

    private IReadOnlyList<PrinterListenerConfiguration> GetEnterpriseListenersUnsafe()
    {
        EnsureEnterpriseLoadedUnsafe();
        return _listeners;
    }

    private PrinterListenerConfiguration PrepareCreateUnsafe(PrinterListenerInput input)
    {
        EnsureEnterpriseAccess();
        EnsureEnterpriseLoadedUnsafe();
        if (_listeners.Count >= PrinterListenerDefaults.MaximumListeners)
        {
            throw new InvalidOperationException($"Enterprise installations support up to {PrinterListenerDefaults.MaximumListeners} printer listeners.");
        }

        var now = DateTimeOffset.UtcNow;
        return Validate(input, $"listener-{Guid.NewGuid():N}", now, now);
    }

    private PrinterListenerConfiguration PrepareUpdateUnsafe(string id, PrinterListenerInput input)
    {
        EnsureEnterpriseAccess();
        EnsureEnterpriseLoadedUnsafe();
        var existing = FindUnsafe(id) ?? throw new KeyNotFoundException("The printer listener was not found.");
        return Validate(input, existing.Id, existing.CreatedAt, DateTimeOffset.UtcNow);
    }

    private void CommitUnsafe(PrinterListenerConfiguration listener)
    {
        var existing = FindUnsafe(listener.Id);
        if (existing is null && _listeners.Count >= PrinterListenerDefaults.MaximumListeners)
        {
            throw new InvalidOperationException($"Enterprise installations support up to {PrinterListenerDefaults.MaximumListeners} printer listeners.");
        }

        PersistUnsafe(listener);
        if (existing is null)
        {
            _listeners.Add(listener);
        }
        else
        {
            _listeners[_listeners.IndexOf(existing)] = listener;
        }
        SortUnsafe();
    }

    private void RemoveUnsafe(PrinterListenerConfiguration existing)
    {
        if (existing.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase))
        {
            throw new ArgumentException("The default printer listener cannot be deleted.");
        }

        if (!EnsureDatabaseUnsafe().DeleteListenerConfiguration(existing.Id))
        {
            throw new InvalidOperationException("The printer listener could not be removed from local storage.");
        }

        _listeners.Remove(existing);
    }

    private void EnsureEnterpriseLoadedUnsafe()
    {
        if (_enterpriseLoaded)
        {
            return;
        }

        _listeners.Clear();
        try
        {
            _listeners.AddRange(EnsureDatabaseUnsafe().LoadListenerConfigurations());
        }
        catch (Exception exception)
        {
            _logger?.LogError(exception, "Enterprise printer listener configuration could not be loaded");
            throw new InvalidOperationException("Printer listener settings could not be loaded from local storage.", exception);
        }

        if (FindUnsafe(PrinterListenerDefaults.DefaultId) is null)
        {
            var defaultListener = CreateLegacyDefault();
            PersistUnsafe(defaultListener);
            _listeners.Add(defaultListener);
        }

        SortUnsafe();
        _enterpriseLoaded = true;
    }

    private PrinterListenerConfiguration CreateLegacyDefault()
    {
        var profile = _profiles.GetSelected();
        return new PrinterListenerConfiguration(
            PrinterListenerDefaults.DefaultId,
            PrinterListenerDefaults.DefaultName,
            _options.BindAddress,
            _options.Port,
            profile.Id,
            true,
            _options.IdleJobTimeoutMilliseconds,
            _options.MaximumJobBytes,
            new PrinterListenerBufferConfiguration(),
            _legacyCreatedAt,
            _legacyCreatedAt);
    }

    private PrinterListenerConfiguration Validate(
        PrinterListenerInput input,
        string id,
        DateTimeOffset createdAt,
        DateTimeOffset updatedAt)
    {
        var name = input.Name?.Trim() ?? string.Empty;
        if (name.Length is < 2 or > 80)
        {
            throw new ArgumentException("Printer listener names must contain 2 to 80 characters.");
        }

        if (_listeners.Any(listener =>
                !listener.Id.Equals(id, StringComparison.OrdinalIgnoreCase) &&
                listener.Name.Equals(name, StringComparison.OrdinalIgnoreCase)))
        {
            throw new ArgumentException("Choose a unique printer listener name.");
        }

        if (!IPAddress.TryParse(input.BindAddress?.Trim(), out var address) ||
            address.AddressFamily != AddressFamily.InterNetwork)
        {
            throw new ArgumentException("Enter a valid IPv4 bind address such as 0.0.0.0 or 127.0.0.1.");
        }

        if (input.Port is < 1 or > 65535)
        {
            throw new ArgumentException("Printer listener ports must be between 1 and 65535.");
        }

        if (input.Port == ReservedViewerPort)
        {
            throw new ArgumentException($"Port {ReservedViewerPort} is reserved for the local receipt viewer.");
        }

        if (_listeners.Any(listener =>
                !listener.Id.Equals(id, StringComparison.OrdinalIgnoreCase) &&
                listener.Port == input.Port))
        {
            throw new ArgumentException($"Port {input.Port} is already assigned to another printer listener.");
        }

        if (!_profiles.TryGet(input.ProfileId, out var profile) || profile is null)
        {
            throw new ArgumentException("Choose an available printer profile.");
        }

        if (input.IdleJobTimeoutMilliseconds is < 250 or > 60_000)
        {
            throw new ArgumentException("Idle job timeout must be between 250 and 60,000 milliseconds.");
        }

        if (input.MaximumJobBytes is < 1_024 or > 64 * 1_024 * 1_024)
        {
            throw new ArgumentException("Maximum job size must be between 1 KB and 64 MB.");
        }

        var buffer = input.Buffer ?? new PrinterListenerBufferConfiguration();
        if (buffer.Capacity is < 1 or > 10_000)
        {
            throw new ArgumentException("Buffer capacity must be between 1 and 10,000 jobs.");
        }

        if (buffer.ProcessingDelayMilliseconds is < 0 or > 60_000)
        {
            throw new ArgumentException("Buffer processing delay must be between 0 and 60,000 milliseconds.");
        }

        var overflowBehavior = buffer.OverflowBehavior?.Trim();
        if (overflowBehavior is not PrinterListenerOverflowBehaviors.RejectNewest and not PrinterListenerOverflowBehaviors.DropOldest)
        {
            throw new ArgumentException("Choose RejectNewest or DropOldest for buffer overflow handling.");
        }

        return new PrinterListenerConfiguration(
            id,
            name,
            address.ToString(),
            input.Port,
            profile.Id,
            input.Enabled,
            input.IdleJobTimeoutMilliseconds,
            input.MaximumJobBytes,
            buffer with { OverflowBehavior = overflowBehavior },
            createdAt,
            updatedAt);
    }

    private void PersistUnsafe(PrinterListenerConfiguration listener)
    {
        try
        {
            EnsureDatabaseUnsafe().UpsertListenerConfiguration(listener);
        }
        catch (Exception exception)
        {
            _logger?.LogError(exception, "Printer listener {ListenerId} could not be persisted", listener.Id);
            throw new InvalidOperationException("Printer listener settings could not be saved to local storage.", exception);
        }
    }

    private ReceiptDatabase EnsureDatabaseUnsafe() =>
        _database ??= new ReceiptDatabase(_license.RootPath);

    private void EnsureEnterpriseAccess()
    {
        if (!HasEnterpriseAccess)
        {
            throw new UnauthorizedAccessException("Multiple printer listeners require an Enterprise license.");
        }
    }

    private static PrinterListenerInput ToInput(PrinterListenerConfiguration listener) => new(
        listener.Name,
        listener.BindAddress,
        listener.Port,
        listener.ProfileId,
        listener.Enabled,
        listener.IdleJobTimeoutMilliseconds,
        listener.MaximumJobBytes,
        listener.Buffer);

    private PrinterListenerConfiguration? FindUnsafe(string id) =>
        _listeners.FirstOrDefault(listener => listener.Id.Equals(id, StringComparison.OrdinalIgnoreCase));

    private void SortUnsafe()
    {
        _listeners.Sort((left, right) =>
        {
            if (left.Id == PrinterListenerDefaults.DefaultId) return right.Id == PrinterListenerDefaults.DefaultId ? 0 : -1;
            if (right.Id == PrinterListenerDefaults.DefaultId) return 1;
            var created = left.CreatedAt.CompareTo(right.CreatedAt);
            return created != 0 ? created : StringComparer.OrdinalIgnoreCase.Compare(left.Name, right.Name);
        });
    }
}
