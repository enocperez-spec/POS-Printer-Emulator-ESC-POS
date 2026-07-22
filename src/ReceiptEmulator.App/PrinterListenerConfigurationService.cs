using System.Net;
using System.Net.Sockets;
using System.Text.Json;

namespace ReceiptEmulator;

public sealed class PrinterListenerConfigurationService
{
    private const int ReservedViewerPort = 5187;
    private readonly object _sync = new();
    private readonly LicenseService _license;
    private readonly Func<int> _maximumListeners;
    private readonly PrinterOptions _options;
    private readonly PrinterProfileService _profiles;
    private readonly ILogger<PrinterListenerConfigurationService>? _logger;
    private readonly string _singleListenerPath;
    private readonly DateTimeOffset _legacyCreatedAt = DateTimeOffset.UtcNow;
    private readonly List<PrinterListenerConfiguration> _listeners = [];
    private ReceiptDatabase? _database;
    private bool _managedListenersLoaded;
    private bool _singleListenerOverrideLoaded;
    private SingleListenerOverride? _singleListenerOverride;

    public PrinterListenerConfigurationService(
        LicenseService license,
        PrinterOptions options,
        PrinterProfileService profiles,
        ILogger<PrinterListenerConfigurationService>? logger = null)
        : this(license, options, profiles, () => license.MaximumListeners, logger)
    {
    }

    internal PrinterListenerConfigurationService(
        LicenseService license,
        PrinterOptions options,
        PrinterProfileService profiles,
        Func<int> maximumListeners,
        ILogger<PrinterListenerConfigurationService>? logger = null)
    {
        _license = license;
        _options = options;
        _profiles = profiles;
        _maximumListeners = maximumListeners;
        _logger = logger;
        _singleListenerPath = Path.Combine(license.RootPath, "single-listener.json");
    }

    public int MaximumListeners => Math.Clamp(_maximumListeners(), 1, PrinterListenerDefaults.MaximumListeners);

    public bool CanManageMultipleListeners => MaximumListeners > 1;

    public IReadOnlyList<PrinterListenerConfiguration> GetStoredConfigurations()
    {
        lock (_sync)
        {
            if (!CanManageMultipleListeners &&
                !File.Exists(Path.Combine(_license.RootPath, ReceiptDatabase.FileName)))
                return [CreateLegacyDefault()];
            EnsureManagedListenersLoadedUnsafe();
            return _listeners.ToArray();
        }
    }

    public void ReplaceAll(IReadOnlyList<PrinterListenerConfiguration> listeners)
    {
        lock (_sync)
        {
            if (listeners.Count is < 1 or > PrinterListenerDefaults.MaximumListeners)
                throw new InvalidDataException($"Backups must contain between 1 and {PrinterListenerDefaults.MaximumListeners} printer listeners.");
            if (!listeners.Any(listener => listener.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase)))
                throw new InvalidDataException("The required default printer listener is missing from the backup.");

            EnsureManagedListenersLoadedUnsafe();
            var previous = _listeners.ToArray();
            try
            {
                _listeners.Clear();
                foreach (var source in listeners
                             .OrderBy(listener => listener.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase) ? 0 : 1)
                             .ThenBy(listener => listener.CreatedAt)
                             .ThenBy(listener => listener.Name, StringComparer.OrdinalIgnoreCase))
                {
                    var id = source.Id?.Trim() ?? string.Empty;
                    if (id.Length is < 1 or > 96 || id.Any(character => !char.IsAsciiLetterOrDigit(character) && character is not '-' and not '_'))
                        throw new InvalidDataException("A printer listener identifier in the backup is invalid.");
                    var validated = Validate(ToInput(source), id, source.CreatedAt, source.UpdatedAt);
                    _listeners.Add(validated);
                }
                SortUnsafe();
                EnsureDatabaseUnsafe().ReplaceListenerConfigurations(_listeners);
                _managedListenersLoaded = true;
            }
            catch
            {
                _listeners.Clear();
                _listeners.AddRange(previous);
                SortUnsafe();
                throw;
            }
        }
    }

    public IReadOnlyList<PrinterListenerConfiguration> GetAll()
    {
        lock (_sync)
        {
            if (!CanManageMultipleListeners)
            {
                return [CreateLegacyDefault()];
            }

            EnsureManagedListenersLoadedUnsafe();
            return GetEffectiveListenersUnsafe().ToArray();
        }
    }

    public PrinterListenerConfiguration? Get(string id)
    {
        lock (_sync)
        {
            if (!CanManageMultipleListeners)
            {
                var defaultListener = CreateLegacyDefault();
                return id.Equals(defaultListener.Id, StringComparison.OrdinalIgnoreCase)
                    ? defaultListener
                    : null;
            }

            EnsureManagedListenersLoadedUnsafe();
            return FindEffectiveUnsafe(id);
        }
    }

    public PrinterListenerConfiguration EnsureDefault()
    {
        lock (_sync)
        {
            if (!CanManageMultipleListeners)
            {
                return CreateLegacyDefault();
            }

            EnsureManagedListenersLoadedUnsafe();
            return FindEffectiveUnsafe(PrinterListenerDefaults.DefaultId)!;
        }
    }

    internal PrinterListenerConfiguration PrepareSingleListenerSetup(int port)
    {
        lock (_sync)
        {
            if (CanManageMultipleListeners)
                throw new InvalidOperationException("Single-listener setup is only available for Trial or Lite licenses.");
            var current = CreateLegacyDefault();
            return Validate(
                ToInput(current) with
                {
                    BindAddress = PrinterListenerDefaults.DefaultBindAddress,
                    Port = port,
                    ProfileId = PrinterProfileService.EpsonTmT88VId,
                    Enabled = true
                },
                current.Id,
                current.CreatedAt,
                DateTimeOffset.UtcNow);
        }
    }

    internal void CommitSingleListenerSetup(PrinterListenerConfiguration listener)
    {
        lock (_sync)
        {
            if (CanManageMultipleListeners ||
                !listener.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Only the default single printer listener can be configured here.");

            var validated = Validate(ToInput(listener), listener.Id, listener.CreatedAt, listener.UpdatedAt);
            var temporaryPath = _singleListenerPath + ".tmp";
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(_singleListenerPath)!);
                File.WriteAllText(temporaryPath, JsonSerializer.Serialize(new SingleListenerOverride(
                    validated.BindAddress,
                    validated.Port,
                    validated.ProfileId,
                    validated.UpdatedAt)));
                File.Move(temporaryPath, _singleListenerPath, overwrite: true);
                _singleListenerOverride = new SingleListenerOverride(
                    validated.BindAddress,
                    validated.Port,
                    validated.ProfileId,
                    validated.UpdatedAt);
                _singleListenerOverrideLoaded = true;
            }
            catch (Exception exception)
            {
                try { if (File.Exists(temporaryPath)) File.Delete(temporaryPath); } catch { }
                _logger?.LogError(exception, "The single printer listener configuration could not be saved");
                throw new InvalidOperationException("The Trial printer listener settings could not be saved.", exception);
            }
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
            EnsureMultipleListenerAccess();
            EnsureManagedListenersLoadedUnsafe();
            var existing = FindEffectiveUnsafe(id);
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
            EnsureMultipleListenerAccess();
            EnsureManagedListenersLoadedUnsafe();
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
            EnsureMultipleListenerAccess();
            EnsureManagedListenersLoadedUnsafe();
            var existing = FindEffectiveUnsafe(id) ?? throw new KeyNotFoundException("The printer listener was not found.");
            RemoveUnsafe(existing);
        }
    }

    public bool IsProfileInUse(string profileId)
    {
        lock (_sync)
        {
            IReadOnlyList<PrinterListenerConfiguration> listeners;
            if (CanManageMultipleListeners)
            {
                listeners = GetManagedListenersUnsafe();
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
                            "Preserved listener configuration could not be checked before deleting profile {ProfileId}",
                            profileId);
                        return true;
                    }
                }
            }
            return listeners.Any(listener => listener.ProfileId.Equals(profileId, StringComparison.OrdinalIgnoreCase));
        }
    }

    private IReadOnlyList<PrinterListenerConfiguration> GetManagedListenersUnsafe()
    {
        EnsureManagedListenersLoadedUnsafe();
        return _listeners;
    }

    private PrinterListenerConfiguration PrepareCreateUnsafe(PrinterListenerInput input)
    {
        EnsureMultipleListenerAccess();
        EnsureManagedListenersLoadedUnsafe();
        if (_listeners.Count >= MaximumListeners)
        {
            throw ListenerLimitReached();
        }

        var now = DateTimeOffset.UtcNow;
        return Validate(input, $"listener-{Guid.NewGuid():N}", now, now);
    }

    private PrinterListenerConfiguration PrepareUpdateUnsafe(string id, PrinterListenerInput input)
    {
        EnsureMultipleListenerAccess();
        EnsureManagedListenersLoadedUnsafe();
        var existing = FindEffectiveUnsafe(id) ?? throw new KeyNotFoundException("The printer listener was not found.");
        return Validate(input, existing.Id, existing.CreatedAt, DateTimeOffset.UtcNow);
    }

    private void CommitUnsafe(PrinterListenerConfiguration listener)
    {
        var existing = FindUnsafe(listener.Id);
        if (existing is not null && FindEffectiveUnsafe(listener.Id) is null)
        {
            throw new InvalidOperationException("This printer listener is preserved above the current license limit and cannot be changed until the license is upgraded or another active listener is removed.");
        }
        if (existing is null && _listeners.Count >= MaximumListeners)
        {
            throw ListenerLimitReached();
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

    private void EnsureManagedListenersLoadedUnsafe()
    {
        if (_managedListenersLoaded)
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
            _logger?.LogError(exception, "Printer listener configuration could not be loaded");
            throw new InvalidOperationException("Printer listener settings could not be loaded from local storage.", exception);
        }

        if (FindUnsafe(PrinterListenerDefaults.DefaultId) is null)
        {
            var defaultListener = CreateLegacyDefault();
            PersistUnsafe(defaultListener);
            _listeners.Add(defaultListener);
        }

        SortUnsafe();
        _managedListenersLoaded = true;
    }

    private PrinterListenerConfiguration CreateLegacyDefault()
    {
        var profile = _profiles.GetSelected();
        var bindAddress = _options.BindAddress;
        var port = _options.Port;
        var updatedAt = _legacyCreatedAt;
        var saved = LoadSingleListenerOverrideUnsafe();
        if (saved is not null)
        {
            if (IPAddress.TryParse(saved.BindAddress, out var savedAddress) &&
                savedAddress.AddressFamily == AddressFamily.InterNetwork &&
                saved.Port is >= 1 and <= 65535 &&
                _profiles.TryGet(saved.ProfileId, out _))
            {
                bindAddress = saved.BindAddress;
                port = saved.Port;
                profile = _profiles.Get(saved.ProfileId);
                updatedAt = saved.UpdatedAt;
            }
        }
        return new PrinterListenerConfiguration(
            PrinterListenerDefaults.DefaultId,
            PrinterListenerDefaults.DefaultName,
            bindAddress,
            port,
            profile.Id,
            true,
            _options.IdleJobTimeoutMilliseconds,
            _options.MaximumJobBytes,
            new PrinterListenerBufferConfiguration(),
            _legacyCreatedAt,
            updatedAt);
    }

    private SingleListenerOverride? LoadSingleListenerOverrideUnsafe()
    {
        if (_singleListenerOverrideLoaded)
            return _singleListenerOverride;
        _singleListenerOverrideLoaded = true;
        if (!File.Exists(_singleListenerPath))
            return null;
        try
        {
            _singleListenerOverride = JsonSerializer.Deserialize<SingleListenerOverride>(
                File.ReadAllText(_singleListenerPath));
        }
        catch (Exception exception)
        {
            _logger?.LogWarning(exception, "Ignored damaged single printer listener settings and restored defaults");
        }
        return _singleListenerOverride;
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

    private void EnsureMultipleListenerAccess()
    {
        if (!CanManageMultipleListeners)
        {
            throw new UnauthorizedAccessException("Multiple printer listeners require a Pro or Enterprise license.");
        }
    }

    private IReadOnlyList<PrinterListenerConfiguration> GetEffectiveListenersUnsafe() =>
        _listeners.Take(MaximumListeners).ToArray();

    private PrinterListenerConfiguration? FindEffectiveUnsafe(string id) =>
        GetEffectiveListenersUnsafe().FirstOrDefault(listener =>
            listener.Id.Equals(id, StringComparison.OrdinalIgnoreCase));

    private InvalidOperationException ListenerLimitReached() => new(
        $"The {_license.GetStatus().Mode} License supports up to {MaximumListeners} printer listener{(MaximumListeners == 1 ? string.Empty : "s")}.");

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

    private sealed record SingleListenerOverride(
        string BindAddress,
        int Port,
        string ProfileId,
        DateTimeOffset UpdatedAt);
}
