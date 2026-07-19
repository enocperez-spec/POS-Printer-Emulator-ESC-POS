namespace ReceiptEmulator;

public sealed record PrinterListenerApiCounters(
    int ActiveConnections,
    long TotalConnections,
    long BytesReceived,
    long JobsReceived,
    long JobsCompleted,
    long JobsRejected,
    long JobsFailed,
    int Queued,
    int Processing);

public sealed record PrinterListenerResponse(
    string Id,
    string Name,
    string BindAddress,
    int Port,
    string ProfileId,
    string ProfileName,
    string Protocol,
    bool Enabled,
    bool IsDefault,
    int IdleJobTimeoutMilliseconds,
    int MaximumJobBytes,
    PrinterListenerBufferConfiguration Buffer,
    string Status,
    bool Listening,
    string Endpoint,
    string ConnectionAddress,
    DateTimeOffset? LastConnection,
    string? LastError,
    PrinterListenerApiCounters Counters);

public sealed record PrinterListenerCollectionResponse(
    IReadOnlyList<PrinterListenerResponse> Listeners,
    int MaximumListeners);

public static class PrinterListenerApiMapper
{
    public static PrinterListenerResponse ToResponse(
        this PrinterListenerRuntimeStatus runtime,
        PrinterProfileService profiles)
    {
        var configuration = runtime.Configuration;
        var profileName = profiles.TryGet(configuration.ProfileId, out var profile) && profile is not null
            ? profile.Name
            : "Unavailable profile";
        var connectionAddress = configuration.BindAddress == PrinterListenerDefaults.DefaultBindAddress
            ? Environment.MachineName
            : configuration.BindAddress;
        var status = runtime.State == PrinterListenerRuntimeStates.Listening
            ? "Running"
            : runtime.State;

        return new PrinterListenerResponse(
            configuration.Id,
            configuration.Name,
            configuration.BindAddress,
            configuration.Port,
            configuration.ProfileId,
            profileName,
            configuration.Protocol,
            configuration.Enabled,
            configuration.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase),
            configuration.IdleJobTimeoutMilliseconds,
            configuration.MaximumJobBytes,
            configuration.Buffer,
            status,
            runtime.Listening,
            $"{connectionAddress}:{configuration.Port}",
            connectionAddress,
            runtime.LastConnection,
            runtime.LastError,
            new PrinterListenerApiCounters(
                runtime.Counters.ActiveConnections,
                runtime.Counters.AcceptedConnections,
                runtime.Counters.ReceivedBytes,
                runtime.Counters.ReceivedJobs,
                runtime.Counters.CompletedJobs,
                runtime.Counters.RejectedJobs,
                runtime.Counters.FailedJobs,
                runtime.Counters.QueuedJobs,
                runtime.Counters.ActiveJobs));
    }

    public static PrinterListenerSummary ToSummary(this PrinterListenerCollectionStatus status) => new(
        status.Listeners.Count,
        status.Listeners.Count(listener => listener.Listening),
        status.Listeners.Count(listener => listener.State == PrinterListenerRuntimeStates.Stopped),
        status.Listeners.Count(listener => listener.State == PrinterListenerRuntimeStates.Faulted));
}
