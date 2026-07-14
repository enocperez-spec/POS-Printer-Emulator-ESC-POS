using System.Net;
using System.Net.Sockets;

namespace ReceiptEmulator;

public sealed class TcpReceiptListener(
    PrinterOptions options,
    ReceiptProcessor processor,
    ServiceRuntimeState state,
    ILogger<TcpReceiptListener> logger) : BackgroundService
{
    private TcpListener? _listener;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var address = options.BindAddress == "0.0.0.0" ? IPAddress.Any : IPAddress.Parse(options.BindAddress);
        _listener = new TcpListener(address, options.Port);
        _listener.Start();
        state.Listening = true;
        logger.LogInformation("ESC/POS listener started on {Address}:{Port}", options.BindAddress, options.Port);

        try
        {
            while (!stoppingToken.IsCancellationRequested)
            {
                var client = await _listener.AcceptTcpClientAsync(stoppingToken);
                state.MarkConnection();
                _ = HandleClientAsync(client, stoppingToken);
            }
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { }
        finally
        {
            state.Listening = false;
            _listener.Stop();
        }
    }

    private async Task HandleClientAsync(TcpClient client, CancellationToken stoppingToken)
    {
        using (client)
        {
            var source = (client.Client.RemoteEndPoint as IPEndPoint)?.Address.ToString() ?? "unknown";
            var collected = new List<byte>();
            var buffer = new byte[8192];

            try
            {
                await using var stream = client.GetStream();
                while (!stoppingToken.IsCancellationRequested)
                {
                    using var idle = CancellationTokenSource.CreateLinkedTokenSource(stoppingToken);
                    idle.CancelAfter(options.IdleJobTimeoutMilliseconds);
                    int read;
                    try { read = await stream.ReadAsync(buffer, idle.Token); }
                    catch (OperationCanceledException) when (!stoppingToken.IsCancellationRequested) { break; }
                    if (read == 0) break;
                    collected.AddRange(buffer.AsSpan(0, read).ToArray());
                    if (collected.Count > options.MaximumJobBytes)
                    {
                        logger.LogWarning("Rejected oversized print job from {SourceIp}", source);
                        return;
                    }

                    foreach (var jobBytes in EscPosJobFramer.ExtractCutJobs(collected))
                        processor.Process(jobBytes, source, out _);
                }

                if (collected.Count > 0)
                    processor.Process(collected.ToArray(), source, out _);
            }
            catch (Exception ex)
            {
                logger.LogWarning(ex, "Interrupted print connection from {SourceIp}", source);
            }
        }
    }

}

public static class EscPosJobFramer
{
    public static IReadOnlyList<byte[]> ExtractCutJobs(List<byte> buffer)
    {
        var jobs = new List<byte[]>();
        var search = 0;
        while (search + 2 < buffer.Count)
        {
            if (buffer[search] == 0x1D && buffer[search + 1] == 0x56)
            {
                var cutLength = buffer[search + 2] is 65 or 66 ? 4 : 3;
                if (search + cutLength > buffer.Count) break;
                var length = search + cutLength;
                jobs.Add(buffer.Take(length).ToArray());
                buffer.RemoveRange(0, length);
                search = 0;
                continue;
            }
            search++;
        }
        return jobs;
    }
}
