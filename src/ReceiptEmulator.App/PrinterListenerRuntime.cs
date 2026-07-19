using System.Collections.Concurrent;
using System.Net;
using System.Net.Sockets;
using System.Threading.Channels;

namespace ReceiptEmulator;

internal sealed record PrinterListenerJobContext(string Id, string Name, int Port);

internal interface IPrinterListenerJobSink
{
    bool Process(
        byte[] payload,
        string sourceIp,
        PrinterProfile profile,
        PrinterListenerJobContext listener,
        out string? rejection);
}

internal sealed class ReceiptProcessorListenerJobSink(ReceiptProcessor processor) : IPrinterListenerJobSink
{
    public bool Process(
        byte[] payload,
        string sourceIp,
        PrinterProfile profile,
        PrinterListenerJobContext listener,
        out string? rejection) => processor.Process(payload, sourceIp, profile, listener, out rejection) is not null;
}

internal sealed class PrinterListenerRuntime : IAsyncDisposable
{
    private sealed record PendingJob(byte[] Payload, string SourceIp);

    private readonly SemaphoreSlim _lifecycle = new(1, 1);
    private readonly ConcurrentDictionary<long, TcpClient> _clients = new();
    private readonly IPrinterListenerJobSink _sink;
    private readonly PrinterProfile _profile;
    private readonly PrinterStateService _printerState;
    private readonly ILogger<PrinterListenerRuntime> _logger;
    private TcpListener? _listener;
    private CancellationTokenSource? _runCancellation;
    private Channel<PendingJob>? _jobQueue;
    private Task? _acceptTask;
    private Task? _workerTask;
    private string _state = PrinterListenerRuntimeStates.Stopped;
    private string? _lastError;
    private long _nextClientId;
    private long _startedAtTicks;
    private long _lastConnectionTicks;
    private long _acceptedConnections;
    private long _receivedJobs;
    private long _completedJobs;
    private long _rejectedJobs;
    private long _failedJobs;
    private long _receivedBytes;
    private int _activeConnections;
    private int _queuedJobs;
    private int _activeJobs;
    private int _listening;

    internal PrinterListenerRuntime(
        PrinterListenerConfiguration configuration,
        PrinterProfile profile,
        IPrinterListenerJobSink sink,
        ILoggerFactory loggerFactory)
    {
        Configuration = configuration;
        _profile = profile;
        _sink = sink;
        _logger = loggerFactory.CreateLogger<PrinterListenerRuntime>();
        _printerState = new PrinterStateService(loggerFactory.CreateLogger<PrinterStateService>());
    }

    public PrinterListenerConfiguration Configuration { get; }
    public PrinterStateService PrinterState => _printerState;

    public PrinterListenerRuntimeStatus GetStatus()
    {
        var startedTicks = Interlocked.Read(ref _startedAtTicks);
        var connectionTicks = Interlocked.Read(ref _lastConnectionTicks);
        return new PrinterListenerRuntimeStatus(
            Configuration,
            Volatile.Read(ref _state),
            Volatile.Read(ref _listening) != 0,
            startedTicks == 0 ? null : new DateTimeOffset(startedTicks, TimeSpan.Zero).ToLocalTime(),
            connectionTicks == 0 ? null : new DateTimeOffset(connectionTicks, TimeSpan.Zero).ToLocalTime(),
            Volatile.Read(ref _lastError),
            new PrinterListenerCounters(
                Interlocked.Read(ref _acceptedConnections),
                Volatile.Read(ref _activeConnections),
                Interlocked.Read(ref _receivedJobs),
                Interlocked.Read(ref _completedJobs),
                Interlocked.Read(ref _rejectedJobs),
                Interlocked.Read(ref _failedJobs),
                Interlocked.Read(ref _receivedBytes),
                Volatile.Read(ref _queuedJobs),
                Volatile.Read(ref _activeJobs)));
    }

    public async Task StartAsync(CancellationToken cancellationToken = default)
    {
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            if (Volatile.Read(ref _state) is PrinterListenerRuntimeStates.Listening or PrinterListenerRuntimeStates.Starting)
                return;
            if (_runCancellation is not null)
                throw new InvalidOperationException("Stop the printer listener before starting it again.");

            cancellationToken.ThrowIfCancellationRequested();
            Volatile.Write(ref _state, PrinterListenerRuntimeStates.Starting);
            Volatile.Write(ref _lastError, null);
            // The operation token controls startup only. A request-abort token must not become
            // the lifetime token for a listener that is expected to keep running afterward.
            var cancellation = new CancellationTokenSource();
            TcpListener? listener = null;
            try
            {
                var address = IPAddress.Parse(Configuration.BindAddress);
                listener = new TcpListener(address, Configuration.Port);
                listener.Server.ExclusiveAddressUse = true;
                listener.Start();

                _runCancellation = cancellation;
                _listener = listener;
                if (Configuration.Buffer.Enabled)
                {
                    _jobQueue = Channel.CreateBounded<PendingJob>(new BoundedChannelOptions(Configuration.Buffer.Capacity)
                    {
                        AllowSynchronousContinuations = false,
                        FullMode = BoundedChannelFullMode.Wait,
                        SingleReader = false,
                        SingleWriter = false
                    });
                    _workerTask = ProcessBufferedJobsAsync(_jobQueue, cancellation.Token);
                }

                Volatile.Write(ref _listening, 1);
                Interlocked.Exchange(ref _startedAtTicks, DateTimeOffset.UtcNow.Ticks);
                Volatile.Write(ref _state, PrinterListenerRuntimeStates.Listening);
                _acceptTask = AcceptClientsAsync(listener, cancellation.Token);
                _logger.LogInformation(
                    "Printer listener {ListenerId} started on {Address}:{Port} with profile {ProfileId}",
                    Configuration.Id, Configuration.BindAddress, Configuration.Port, _profile.Id);
            }
            catch (Exception exception)
            {
                listener?.Stop();
                cancellation.Cancel();
                cancellation.Dispose();
                _listener = null;
                _jobQueue = null;
                _acceptTask = null;
                _workerTask = null;
                _runCancellation = null;
                Volatile.Write(ref _listening, 0);
                Volatile.Write(ref _lastError, PlainError(exception));
                Volatile.Write(ref _state, PrinterListenerRuntimeStates.Faulted);
                _logger.LogError(exception, "Printer listener {ListenerId} could not bind to {Address}:{Port}",
                    Configuration.Id, Configuration.BindAddress, Configuration.Port);
                throw;
            }
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public async Task StopAsync(CancellationToken cancellationToken = default)
    {
        await _lifecycle.WaitAsync(cancellationToken);
        try
        {
            if (_runCancellation is null)
            {
                Volatile.Write(ref _listening, 0);
                Volatile.Write(ref _state, PrinterListenerRuntimeStates.Stopped);
                return;
            }

            Volatile.Write(ref _state, PrinterListenerRuntimeStates.Stopping);
            Volatile.Write(ref _listening, 0);
            var cancellation = _runCancellation;
            var listener = _listener;
            var queue = _jobQueue;
            var acceptTask = _acceptTask;
            var workerTask = _workerTask;

            cancellation.Cancel();
            listener?.Stop();
            foreach (var client in _clients.Values) client.Dispose();
            queue?.Writer.TryComplete();

            await AwaitQuietlyAsync(acceptTask, cancellationToken);
            await WaitForClientsToCloseAsync(cancellationToken);
            await AwaitQuietlyAsync(workerTask, cancellationToken);
            if (queue is not null)
            {
                while (queue.Reader.TryRead(out _))
                {
                    Interlocked.Decrement(ref _queuedJobs);
                    Interlocked.Increment(ref _rejectedJobs);
                }
            }

            _listener = null;
            _jobQueue = null;
            _acceptTask = null;
            _workerTask = null;
            _runCancellation = null;
            listener?.Stop();
            cancellation.Dispose();
            Interlocked.Exchange(ref _activeConnections, 0);
            Interlocked.Exchange(ref _activeJobs, 0);
            Interlocked.Exchange(ref _queuedJobs, 0);
            Volatile.Write(ref _state, PrinterListenerRuntimeStates.Stopped);
            _logger.LogInformation("Printer listener {ListenerId} stopped", Configuration.Id);
        }
        finally
        {
            _lifecycle.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        try { await StopAsync(); }
        finally { _lifecycle.Dispose(); }
    }

    private async Task AcceptClientsAsync(TcpListener listener, CancellationToken cancellationToken)
    {
        try
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                var client = await listener.AcceptTcpClientAsync(cancellationToken);
                var clientId = Interlocked.Increment(ref _nextClientId);
                _clients[clientId] = client;
                Interlocked.Increment(ref _acceptedConnections);
                Interlocked.Increment(ref _activeConnections);
                Interlocked.Exchange(ref _lastConnectionTicks, DateTimeOffset.UtcNow.Ticks);
                _ = HandleTrackedClientAsync(clientId, client, cancellationToken);
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
        catch (ObjectDisposedException) when (cancellationToken.IsCancellationRequested) { }
        catch (SocketException) when (cancellationToken.IsCancellationRequested) { }
        catch (Exception exception)
        {
            Volatile.Write(ref _listening, 0);
            Volatile.Write(ref _lastError, PlainError(exception));
            Volatile.Write(ref _state, PrinterListenerRuntimeStates.Faulted);
            _runCancellation?.Cancel();
            _logger.LogError(exception, "Printer listener {ListenerId} stopped accepting connections unexpectedly", Configuration.Id);
        }
    }

    private async Task HandleTrackedClientAsync(long clientId, TcpClient client, CancellationToken cancellationToken)
    {
        try { await HandleClientAsync(client, cancellationToken); }
        finally
        {
            if (_clients.TryRemove(clientId, out var tracked)) tracked.Dispose();
            Interlocked.Decrement(ref _activeConnections);
        }
    }

    private async Task HandleClientAsync(TcpClient client, CancellationToken stoppingToken)
    {
        var source = (client.Client.RemoteEndPoint as IPEndPoint)?.Address.ToString() ?? "unknown";
        var collected = new List<byte>();
        var buffer = new byte[8192];
        var asbMask = 0;
        var asbActive = false;
        using var writeGate = new SemaphoreSlim(1, 1);
        Action<PrinterStateSnapshot>? stateChangedHandler = null;

        try
        {
            await using var stream = client.GetStream();
            async Task SendAsync(string command, byte[] response, CancellationToken cancellationToken)
            {
                await writeGate.WaitAsync(cancellationToken);
                try
                {
                    await stream.WriteAsync(response, cancellationToken);
                    await stream.FlushAsync(cancellationToken);
                    _printerState.RecordResponse(command, source);
                }
                finally { writeGate.Release(); }
            }

            void OnPrinterStateChanged(PrinterStateSnapshot snapshot)
            {
                if (Volatile.Read(ref asbMask) == 0) return;
                _ = SendAutomaticStatusBackAsync(snapshot);
            }

            async Task SendAutomaticStatusBackAsync(PrinterStateSnapshot snapshot)
            {
                try
                {
                    await SendAsync("GS a state change", _printerState.BuildAutomaticStatusBack(snapshot), stoppingToken);
                }
                catch (Exception exception) when (exception is IOException or SocketException or ObjectDisposedException or OperationCanceledException)
                {
                    _logger.LogDebug("Automatic status connection to {SourceIp} on listener {ListenerId} closed", source, Configuration.Id);
                }
            }

            stateChangedHandler = OnPrinterStateChanged;
            _printerState.Changed += stateChangedHandler;
            while (!stoppingToken.IsCancellationRequested)
            {
                using var idle = CancellationTokenSource.CreateLinkedTokenSource(stoppingToken);
                idle.CancelAfter(Configuration.IdleJobTimeoutMilliseconds);
                int read;
                try { read = await stream.ReadAsync(buffer, idle.Token); }
                catch (OperationCanceledException) when (!stoppingToken.IsCancellationRequested) { break; }
                if (read == 0) break;
                Interlocked.Add(ref _receivedBytes, read);
                collected.AddRange(buffer.AsSpan(0, read).ToArray());
                if (collected.Count > Configuration.MaximumJobBytes)
                {
                    Interlocked.Increment(ref _receivedJobs);
                    Interlocked.Increment(ref _rejectedJobs);
                    _logger.LogWarning("Rejected oversized print job from {SourceIp} on listener {ListenerId}", source, Configuration.Id);
                    return;
                }

                var capabilities = _profile.Capabilities;
                var statusResult = EscPosStatusProtocol.Extract(
                    collected,
                    asbMask,
                    _printerState,
                    capabilities.DleEotStatus,
                    capabilities.AutomaticStatusBack);
                var wasActive = asbMask != 0;
                asbMask = statusResult.AsbMask;
                var isActive = asbMask != 0;
                if (wasActive != isActive)
                {
                    _printerState.SetAsbConnectionActive(isActive);
                    asbActive = isActive;
                }

                foreach (var response in statusResult.Responses)
                    await SendAsync(response.Command, response.Bytes, stoppingToken);
                foreach (var jobBytes in EscPosJobFramer.ExtractCutJobs(collected))
                    Submit(jobBytes, source);
            }

            if (collected.Count > 0) Submit(collected.ToArray(), source);
        }
        catch (Exception exception) when (exception is IOException or SocketException or ObjectDisposedException or OperationCanceledException)
        {
            _logger.LogDebug(exception, "Print connection from {SourceIp} on listener {ListenerId} closed", source, Configuration.Id);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Interrupted print connection from {SourceIp} on listener {ListenerId}", source, Configuration.Id);
        }
        finally
        {
            if (stateChangedHandler is not null) _printerState.Changed -= stateChangedHandler;
            if (asbActive) _printerState.SetAsbConnectionActive(false);
        }
    }

    private void Submit(byte[] payload, string sourceIp)
    {
        Interlocked.Increment(ref _receivedJobs);
        var queue = _jobQueue;
        if (queue is null)
        {
            Process(payload, sourceIp);
            return;
        }

        var pending = new PendingJob(payload, sourceIp);
        Interlocked.Increment(ref _queuedJobs);
        if (queue.Writer.TryWrite(pending))
        {
            return;
        }
        Interlocked.Decrement(ref _queuedJobs);

        if (Configuration.Buffer.OverflowBehavior == PrinterListenerOverflowBehaviors.DropOldest &&
            queue.Reader.TryRead(out _))
        {
            Interlocked.Decrement(ref _queuedJobs);
            Interlocked.Increment(ref _rejectedJobs);
            Interlocked.Increment(ref _queuedJobs);
            if (queue.Writer.TryWrite(pending))
            {
                return;
            }
            Interlocked.Decrement(ref _queuedJobs);
        }

        Interlocked.Increment(ref _rejectedJobs);
        _logger.LogWarning("Listener {ListenerId} rejected a print job because its {Capacity}-job buffer is full",
            Configuration.Id, Configuration.Buffer.Capacity);
    }

    private async Task ProcessBufferedJobsAsync(Channel<PendingJob> queue, CancellationToken cancellationToken)
    {
        try
        {
            await foreach (var pending in queue.Reader.ReadAllAsync(cancellationToken))
            {
                Interlocked.Decrement(ref _queuedJobs);
                if (Configuration.Buffer.ProcessingDelayMilliseconds > 0)
                    await Task.Delay(Configuration.Buffer.ProcessingDelayMilliseconds, cancellationToken);
                Process(pending.Payload, pending.SourceIp);
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
    }

    private void Process(byte[] payload, string sourceIp)
    {
        Interlocked.Increment(ref _activeJobs);
        try
        {
            var context = new PrinterListenerJobContext(Configuration.Id, Configuration.Name, Configuration.Port);
            if (_sink.Process(payload, sourceIp, _profile, context, out var rejection))
            {
                Interlocked.Increment(ref _completedJobs);
            }
            else
            {
                Interlocked.Increment(ref _rejectedJobs);
                _logger.LogDebug("Listener {ListenerId} rejected a print job: {Reason}", Configuration.Id, rejection);
            }
        }
        catch (Exception exception)
        {
            Interlocked.Increment(ref _failedJobs);
            Volatile.Write(ref _lastError, PlainError(exception));
            _logger.LogError(exception, "Listener {ListenerId} failed to process a print job", Configuration.Id);
        }
        finally
        {
            Interlocked.Decrement(ref _activeJobs);
        }
    }

    private async Task WaitForClientsToCloseAsync(CancellationToken cancellationToken)
    {
        while (!_clients.IsEmpty)
        {
            cancellationToken.ThrowIfCancellationRequested();
            await Task.Delay(10, cancellationToken);
        }
    }

    private async Task AwaitQuietlyAsync(Task? task, CancellationToken cancellationToken)
    {
        if (task is null) return;
        try { await task.WaitAsync(cancellationToken); }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested || _runCancellation?.IsCancellationRequested == true) { }
        catch (ObjectDisposedException) { }
        catch (Exception exception)
        {
            _logger.LogDebug(exception, "Listener {ListenerId} background task ended during shutdown", Configuration.Id);
        }
    }

    private static string PlainError(Exception exception) => exception switch
    {
        SocketException socket when socket.SocketErrorCode == SocketError.AddressAlreadyInUse =>
            "The TCP port is already in use by another application or printer listener.",
        SocketException socket when socket.SocketErrorCode == SocketError.AccessDenied =>
            "Windows denied access to the selected IP address or TCP port.",
        _ => exception.GetBaseException().Message
    };
}
