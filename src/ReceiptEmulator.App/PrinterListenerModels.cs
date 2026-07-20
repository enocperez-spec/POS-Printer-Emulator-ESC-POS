namespace ReceiptEmulator;

public static class PrinterListenerDefaults
{
    public const string DefaultId = "default";
    public const string DefaultName = "POS Printer Emulator";
    public const string RawTcpProtocol = "RawTcp";
    public const string DefaultBindAddress = "0.0.0.0";
    public const int DefaultPort = 9100;
    public const int DefaultIdleJobTimeoutMilliseconds = 1500;
    public const int DefaultMaximumJobBytes = 4 * 1024 * 1024;
    public const int MaximumListeners = 15;
}

public static class PrinterListenerOverflowBehaviors
{
    public const string RejectNewest = "RejectNewest";
    public const string DropOldest = "DropOldest";
}

public static class PrinterListenerRuntimeStates
{
    public const string Stopped = "Stopped";
    public const string Starting = "Starting";
    public const string Listening = "Listening";
    public const string Faulted = "Faulted";
    public const string Stopping = "Stopping";
}

public sealed record PrinterListenerBufferConfiguration(
    bool Enabled = false,
    int Capacity = 100,
    int ProcessingDelayMilliseconds = 0,
    string OverflowBehavior = PrinterListenerOverflowBehaviors.RejectNewest);

public sealed record PrinterListenerInput(
    string Name,
    string BindAddress,
    int Port,
    string ProfileId,
    bool Enabled,
    int IdleJobTimeoutMilliseconds,
    int MaximumJobBytes,
    PrinterListenerBufferConfiguration Buffer);

public sealed record PrinterListenerConfiguration(
    string Id,
    string Name,
    string BindAddress,
    int Port,
    string ProfileId,
    bool Enabled,
    int IdleJobTimeoutMilliseconds,
    int MaximumJobBytes,
    PrinterListenerBufferConfiguration Buffer,
    DateTimeOffset CreatedAt,
    DateTimeOffset UpdatedAt)
{
    public string Protocol => PrinterListenerDefaults.RawTcpProtocol;
}

public sealed record PrinterListenerCounters(
    long AcceptedConnections,
    int ActiveConnections,
    long ReceivedJobs,
    long CompletedJobs,
    long RejectedJobs,
    long FailedJobs,
    long ReceivedBytes,
    int QueuedJobs,
    int ActiveJobs);

public sealed record PrinterListenerRuntimeStatus(
    PrinterListenerConfiguration Configuration,
    string State,
    bool Listening,
    DateTimeOffset? StartedAt,
    DateTimeOffset? LastConnection,
    string? LastError,
    PrinterListenerCounters Counters);

public sealed record PrinterListenerCollectionStatus(
    bool MultipleListenersEnabled,
    int MaximumListeners,
    int RunningCount,
    int ListeningCount,
    IReadOnlyList<PrinterListenerRuntimeStatus> Listeners)
{
    // Retained for compatibility with existing diagnostics and tests while callers migrate.
    public bool EnterpriseEnabled => MultipleListenersEnabled;
}

public sealed record PrinterListenerSummary(
    int Total,
    int Running,
    int Stopped,
    int Faulted);
