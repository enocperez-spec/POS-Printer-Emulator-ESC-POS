using System.Collections.Concurrent;
using System.Net;
using System.Net.Sockets;

namespace ReceiptEmulator;

public sealed class PrinterListenerManager : IHostedService, IAsyncDisposable
{
    private readonly SemaphoreSlim _lifecycle = new(1, 1);
    private readonly ConcurrentDictionary<string, PrinterListenerRuntime> _runtimes =
        new(StringComparer.OrdinalIgnoreCase);
    private readonly ConcurrentDictionary<string, string> _configurationErrors =
        new(StringComparer.OrdinalIgnoreCase);
    private readonly PrinterListenerConfigurationService _configurations;
    private readonly PrinterProfileService _profiles;
    private readonly IPrinterListenerJobSink _sink;
    private readonly Func<int> _maximumListeners;
    private readonly ServiceRuntimeState _legacyState;
    private readonly ILoggerFactory _loggerFactory;
    private readonly ILogger<PrinterListenerManager> _logger;
    private int _started;
    private int _disposed;
    private int _statusStorageFailureLogged;

    public PrinterListenerManager(
        PrinterListenerConfigurationService configurations,
        ReceiptProcessor processor,
        PrinterProfileService profiles,
        LicenseService license,
        ServiceRuntimeState legacyState,
        ILoggerFactory loggerFactory,
        ILogger<PrinterListenerManager> logger)
        : this(
            configurations,
            profiles,
            new ReceiptProcessorListenerJobSink(processor),
            () => license.MaximumListeners,
            legacyState,
            loggerFactory,
            logger)
    {
    }

    internal PrinterListenerManager(
        PrinterListenerConfigurationService configurations,
        PrinterProfileService profiles,
        IPrinterListenerJobSink sink,
        Func<int> maximumListeners,
        ServiceRuntimeState legacyState,
        ILoggerFactory loggerFactory,
        ILogger<PrinterListenerManager> logger)
    {
        _configurations = configurations;
        _profiles = profiles;
        _sink = sink;
        _maximumListeners = maximumListeners;
        _legacyState = legacyState;
        _loggerFactory = loggerFactory;
        _logger = logger;
    }

    public async Task StartAsync(CancellationToken cancellationToken)
    {
        if (Interlocked.Exchange(ref _started, 1) != 0) return;
        await ReconcileAsync(cancellationToken);
    }

    public async Task StopAsync(CancellationToken cancellationToken)
    {
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            foreach (var runtime in _runtimes.Values)
                await StopQuietlyAsync(runtime, cancellationToken);
            _legacyState.Listening = false;
            Interlocked.Exchange(ref _started, 0);
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public PrinterListenerCollectionStatus GetStatus()
    {
        var maximumListeners = Math.Clamp(_maximumListeners(), 1, PrinterListenerDefaults.MaximumListeners);
        PrinterListenerRuntimeStatus[] listeners;
        try
        {
            var configurations = _configurations.GetEffectiveConfigurations();
            listeners = configurations.Select(configuration =>
            {
                if (_runtimes.TryGetValue(configuration.Id, out var runtime)) return runtime.GetStatus();
                if (_configurationErrors.TryGetValue(configuration.Id, out var error)) return FaultedStatus(configuration, error);
                return StoppedStatus(configuration);
            }).ToArray();
            Interlocked.Exchange(ref _statusStorageFailureLogged, 0);
        }
        catch (InvalidOperationException exception)
        {
            if (Interlocked.Exchange(ref _statusStorageFailureLogged, 1) == 0)
            {
                _logger.LogError(
                    exception,
                    "Printer listener configuration storage is unavailable; reporting the active listener runtimes instead");
            }

            listeners = _runtimes.Values
                .Select(runtime => runtime.GetStatus())
                .OrderBy(status => status.Configuration.CreatedAt)
                .ThenBy(status => status.Configuration.Name, StringComparer.OrdinalIgnoreCase)
                .ToArray();
        }

        return new PrinterListenerCollectionStatus(
            maximumListeners > 1,
            maximumListeners,
            listeners.Count(listener => listener.State is PrinterListenerRuntimeStates.Starting
                or PrinterListenerRuntimeStates.Listening
                or PrinterListenerRuntimeStates.Stopping),
            listeners.Count(listener => listener.Listening),
            listeners);
    }

    public PrinterListenerRuntimeStatus Get(string id)
    {
        var configuration = _configurations.Find(id)
            ?? throw new KeyNotFoundException("The printer listener was not found.");
        return _runtimes.TryGetValue(configuration.Id, out var runtime)
            ? runtime.GetStatus()
            : _configurationErrors.TryGetValue(configuration.Id, out var error)
            ? FaultedStatus(configuration, error)
            : StoppedStatus(configuration);
    }

    public async Task<PrinterListenerRuntimeStatus> CreateAsync(
        PrinterListenerInput input,
        CancellationToken cancellationToken = default)
    {
        EnsureMultipleListenerAccess();
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var configuration = _configurations.PrepareCreate(input);
            if (configuration.Enabled) ProbePort(configuration, current: null);
            var runtime = CreateRuntime(configuration);
            try
            {
                if (configuration.Enabled) await runtime.StartAsync(cancellationToken);
                _configurations.Commit(configuration);
                if (!_runtimes.TryAdd(configuration.Id, runtime))
                    throw new InvalidOperationException("A printer listener with this identifier already exists.");
                _configurationErrors.TryRemove(configuration.Id, out _);
                RefreshLegacyState();
                return runtime.GetStatus();
            }
            catch
            {
                await StopQuietlyAsync(runtime, cancellationToken);
                await runtime.DisposeAsync();
                throw;
            }
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public async Task<PrinterListenerRuntimeStatus> UpdateAsync(
        string id,
        PrinterListenerInput input,
        CancellationToken cancellationToken = default)
    {
        EnsureMultipleListenerAccess();
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var replacementConfiguration = _configurations.PrepareUpdate(id, input);
            _runtimes.TryGetValue(replacementConfiguration.Id, out var current);
            if (replacementConfiguration.Enabled) ProbePort(replacementConfiguration, current);
            return await ReplaceRuntimeAsync(current, replacementConfiguration, cancellationToken);
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public async Task DeleteAsync(string id, CancellationToken cancellationToken = default)
    {
        EnsureMultipleListenerAccess();
        if (id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("The default printer listener cannot be deleted.");
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var configuration = _configurations.Find(id)
                ?? throw new KeyNotFoundException("The printer listener was not found.");
            _runtimes.TryGetValue(configuration.Id, out var runtime);
            if (runtime is not null) await runtime.StopAsync(cancellationToken);
            try
            {
                _configurations.Remove(configuration.Id);
            }
            catch
            {
                if (runtime is not null && configuration.Enabled)
                    await StartQuietlyAsync(runtime, cancellationToken);
                throw;
            }

            _runtimes.TryRemove(configuration.Id, out _);
            _configurationErrors.TryRemove(configuration.Id, out _);
            if (runtime is not null) await runtime.DisposeAsync();
            RefreshLegacyState();
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public Task<PrinterListenerRuntimeStatus> StartListenerAsync(
        string id,
        CancellationToken cancellationToken = default) =>
        ChangeEnabledAsync(id, enabled: true, cancellationToken);

    public Task<PrinterListenerRuntimeStatus> StopListenerAsync(
        string id,
        CancellationToken cancellationToken = default) =>
        ChangeEnabledAsync(id, enabled: false, cancellationToken);

    public async Task<PrinterListenerRuntimeStatus> RestartListenerAsync(
        string id,
        CancellationToken cancellationToken = default)
    {
        EnsureMultipleListenerAccess();
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var configuration = _configurations.Find(id)
                ?? throw new KeyNotFoundException("The printer listener was not found.");
            if (!configuration.Enabled)
                throw new InvalidOperationException("Start the printer listener before restarting it.");
            if (!_runtimes.TryGetValue(configuration.Id, out var runtime))
            {
                ProbePort(configuration, current: null);
                runtime = CreateRuntime(configuration);
                _runtimes[configuration.Id] = runtime;
            }
            else
            {
                await runtime.StopAsync(cancellationToken);
            }

            await runtime.StartAsync(cancellationToken);
            RefreshLegacyState();
            return runtime.GetStatus();
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public async Task<PrinterListenerCollectionStatus> ReconcileAsync(CancellationToken cancellationToken = default)
    {
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var effective = _configurations.GetEffectiveConfigurations();
            var effectiveIds = effective.Select(configuration => configuration.Id).ToHashSet(StringComparer.OrdinalIgnoreCase);
            foreach (var orphan in _runtimes.Where(pair => !effectiveIds.Contains(pair.Key)).ToArray())
            {
                if (_runtimes.TryRemove(orphan.Key, out var runtime))
                {
                    await StopQuietlyAsync(runtime, cancellationToken);
                    await runtime.DisposeAsync();
                }
            }
            foreach (var orphanError in _configurationErrors.Keys.Where(id => !effectiveIds.Contains(id)).ToArray())
                _configurationErrors.TryRemove(orphanError, out _);

            foreach (var configuration in effective)
            {
                if (_runtimes.TryGetValue(configuration.Id, out var existing) && existing.Configuration != configuration)
                {
                    await StopQuietlyAsync(existing, cancellationToken);
                    _runtimes.TryRemove(configuration.Id, out _);
                    await existing.DisposeAsync();
                    existing = null;
                }

                PrinterListenerRuntime runtime;
                try
                {
                    runtime = existing ?? CreateRuntime(configuration);
                    _configurationErrors.TryRemove(configuration.Id, out _);
                }
                catch (Exception exception)
                {
                    _configurationErrors[configuration.Id] = exception.GetBaseException().Message;
                    _logger.LogError(exception,
                        "Printer listener {ListenerId} configuration could not initialize; other listeners remain available",
                        configuration.Id);
                    continue;
                }
                _runtimes[configuration.Id] = runtime;
                try
                {
                    if (configuration.Enabled)
                    {
                        var status = runtime.GetStatus();
                        if (!status.Listening)
                        {
                            if (status.State == PrinterListenerRuntimeStates.Faulted)
                                await runtime.StopAsync(cancellationToken);
                            await runtime.StartAsync(cancellationToken);
                        }
                    }
                    else if (runtime.GetStatus().State != PrinterListenerRuntimeStates.Stopped)
                    {
                        await runtime.StopAsync(cancellationToken);
                    }
                }
                catch (Exception exception)
                {
                    _logger.LogError(exception,
                        "Printer listener {ListenerId} could not reach its desired runtime state; other listeners remain available",
                        configuration.Id);
                }
            }

            RefreshLegacyState();
            return GetStatus();
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public PrinterStateStatus GetPrinterState(string id) => FindRuntime(id).PrinterState.GetStatus();

    public PrinterStateStatus UpdatePrinterState(string id, PrinterStateUpdateRequest request) =>
        FindRuntime(id).PrinterState.Update(request);

    public PrinterStateStatus ResetPrinterState(string id) => FindRuntime(id).PrinterState.Reset();

    public async ValueTask DisposeAsync()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0) return;

        try
        {
            await StopAsync(CancellationToken.None);
            foreach (var runtime in _runtimes.Values) await runtime.DisposeAsync();
            _runtimes.Clear();
            _configurationErrors.Clear();
        }
        finally
        {
            _lifecycle.Dispose();
        }
    }

    private async Task<PrinterListenerRuntimeStatus> ChangeEnabledAsync(
        string id,
        bool enabled,
        CancellationToken cancellationToken)
    {
        EnsureMultipleListenerAccess();
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            var current = _configurations.Find(id)
                ?? throw new KeyNotFoundException("The printer listener was not found.");
            var input = ToInput(current) with { Enabled = enabled };
            var replacement = _configurations.PrepareUpdate(current.Id, input);
            _runtimes.TryGetValue(current.Id, out var runtime);
            if (enabled) ProbePort(replacement, runtime);
            return await ReplaceRuntimeAsync(runtime, replacement, cancellationToken);
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    private async Task<PrinterListenerRuntimeStatus> ReplaceRuntimeAsync(
        PrinterListenerRuntime? current,
        PrinterListenerConfiguration replacementConfiguration,
        CancellationToken cancellationToken)
    {
        var previousConfiguration = current?.Configuration;
        if (current is not null) await current.StopAsync(cancellationToken);
        var replacement = CreateRuntime(replacementConfiguration);
        try
        {
            if (replacementConfiguration.Enabled) await replacement.StartAsync(cancellationToken);
            _configurations.Commit(replacementConfiguration);
        }
        catch
        {
            await StopQuietlyAsync(replacement, cancellationToken);
            await replacement.DisposeAsync();
            if (current is not null && previousConfiguration?.Enabled == true)
                await StartQuietlyAsync(current, cancellationToken);
            throw;
        }

        _runtimes[replacementConfiguration.Id] = replacement;
        _configurationErrors.TryRemove(replacementConfiguration.Id, out _);
        if (current is not null) await current.DisposeAsync();
        RefreshLegacyState();
        return replacement.GetStatus();
    }

    private PrinterListenerRuntime CreateRuntime(PrinterListenerConfiguration configuration)
    {
        var profile = _profiles.GetStatus().Profiles.FirstOrDefault(candidate =>
            candidate.Id.Equals(configuration.ProfileId, StringComparison.OrdinalIgnoreCase))
            ?? throw new InvalidOperationException(
                $"Printer listener '{configuration.Name}' references a printer profile that is no longer available.");
        return new PrinterListenerRuntime(configuration, profile, _sink, _loggerFactory);
    }

    private PrinterListenerRuntime FindRuntime(string id)
    {
        if (!_runtimes.TryGetValue(id, out var runtime))
            throw new KeyNotFoundException("The printer listener was not found or has not initialized yet.");
        return runtime;
    }

    private void ProbePort(PrinterListenerConfiguration configuration, PrinterListenerRuntime? current)
    {
        if (current is not null && current.GetStatus().Listening &&
            current.Configuration.Port == configuration.Port &&
            current.Configuration.BindAddress.Equals(configuration.BindAddress, StringComparison.OrdinalIgnoreCase))
        {
            return;
        }

        TcpListener? probe = null;
        try
        {
            probe = new TcpListener(IPAddress.Parse(configuration.BindAddress), configuration.Port);
            probe.Server.ExclusiveAddressUse = true;
            probe.Start();
        }
        catch (SocketException exception)
        {
            throw new InvalidOperationException(
                $"TCP port {configuration.Port} is already in use. Choose another port or stop the application using it.",
                exception);
        }
        finally
        {
            probe?.Stop();
        }
    }

    private void RefreshLegacyState() =>
        _legacyState.Listening = _runtimes.Values.Any(runtime => runtime.GetStatus().Listening);

    private void EnsureMultipleListenerAccess()
    {
        if (_maximumListeners() <= 1)
            throw new UnauthorizedAccessException("Multiple printer listeners require a Pro or Enterprise license.");
    }

    private static PrinterListenerInput ToInput(PrinterListenerConfiguration configuration) => new(
        configuration.Name,
        configuration.BindAddress,
        configuration.Port,
        configuration.ProfileId,
        configuration.Enabled,
        configuration.IdleJobTimeoutMilliseconds,
        configuration.MaximumJobBytes,
        configuration.Buffer);

    private static PrinterListenerRuntimeStatus StoppedStatus(PrinterListenerConfiguration configuration) => new(
        configuration,
        PrinterListenerRuntimeStates.Stopped,
        false,
        null,
        null,
        null,
        new PrinterListenerCounters(0, 0, 0, 0, 0, 0, 0, 0, 0));

    private static PrinterListenerRuntimeStatus FaultedStatus(
        PrinterListenerConfiguration configuration,
        string error) => new(
        configuration,
        PrinterListenerRuntimeStates.Faulted,
        false,
        null,
        null,
        error,
        new PrinterListenerCounters(0, 0, 0, 0, 0, 0, 0, 0, 0));

    private async Task StopQuietlyAsync(PrinterListenerRuntime runtime, CancellationToken cancellationToken)
    {
        try { await runtime.StopAsync(cancellationToken); }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Printer listener {ListenerId} did not stop cleanly", runtime.Configuration.Id);
        }
    }

    private async Task StartQuietlyAsync(PrinterListenerRuntime runtime, CancellationToken cancellationToken)
    {
        try { await runtime.StartAsync(cancellationToken); }
        catch (Exception exception)
        {
            _logger.LogError(exception, "Printer listener {ListenerId} could not be restored", runtime.Configuration.Id);
        }
    }
}
