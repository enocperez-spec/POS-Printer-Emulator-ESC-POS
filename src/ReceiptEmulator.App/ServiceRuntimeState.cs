namespace ReceiptEmulator;

public sealed class ServiceRuntimeState
{
    private long _lastConnectionTicks;
    public bool Listening { get; set; }
    public DateTimeOffset? LastConnection
    {
        get
        {
            var ticks = Interlocked.Read(ref _lastConnectionTicks);
            return ticks == 0 ? null : new DateTimeOffset(ticks, TimeSpan.Zero).ToLocalTime();
        }
    }
    public void MarkConnection() => Interlocked.Exchange(ref _lastConnectionTicks, DateTimeOffset.UtcNow.Ticks);
}
