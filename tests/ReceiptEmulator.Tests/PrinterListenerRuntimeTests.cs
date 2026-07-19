using System.Collections.Concurrent;
using System.Net;
using System.Net.Sockets;
using System.Text;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Data.Sqlite;

namespace ReceiptEmulator.Tests;

public sealed class PrinterListenerRuntimeTests
{
    [Fact]
    public async Task TwoListenersRouteJobsIndependentlyAndOneCanStopWithoutInterruptingTheOther()
    {
        var firstPort = FreePort();
        var secondPort = FreePort();
        var sink = new RecordingSink();
        await using var first = Runtime(Configuration("front-counter", "Front Counter", firstPort), Profile("front"), sink);
        await using var second = Runtime(Configuration("drive-thru", "Drive Thru", secondPort), Profile("drive"), sink);

        await first.StartAsync();
        await second.StartAsync();
        await SendJobAsync(firstPort, "FRONT ORDER");
        await SendJobAsync(secondPort, "DRIVE ORDER");
        await WaitUntilAsync(() => sink.Jobs.Count >= 2);

        Assert.Contains(sink.Jobs, job => job.Listener.Id == "front-counter" && job.Profile.Id == "front");
        Assert.Contains(sink.Jobs, job => job.Listener.Id == "drive-thru" && job.Profile.Id == "drive");

        await first.StopAsync();
        await SendJobAsync(secondPort, "SECOND DRIVE ORDER");
        await WaitUntilAsync(() => sink.Jobs.Count >= 3);

        Assert.Equal(PrinterListenerRuntimeStates.Stopped, first.GetStatus().State);
        Assert.True(second.GetStatus().Listening);
        Assert.Equal(2, second.GetStatus().Counters.CompletedJobs);
        Assert.Equal(1, first.GetStatus().Counters.CompletedJobs);
    }

    [Fact]
    public async Task BindFailureFaultsOnlyTheConflictingRuntime()
    {
        var port = FreePort();
        var sink = new RecordingSink();
        await using var running = Runtime(Configuration("running", "Running", port), Profile("running"), sink);
        await using var conflict = Runtime(Configuration("conflict", "Conflict", port), Profile("conflict"), sink);
        await running.StartAsync();

        await Assert.ThrowsAsync<SocketException>(() => conflict.StartAsync());
        Assert.Equal(PrinterListenerRuntimeStates.Faulted, conflict.GetStatus().State);
        Assert.Contains("already in use", conflict.GetStatus().LastError, StringComparison.OrdinalIgnoreCase);

        await SendJobAsync(port, "STILL RUNNING");
        await WaitUntilAsync(() => sink.Jobs.Count == 1);
        Assert.True(running.GetStatus().Listening);
        Assert.Equal("running", sink.Jobs.Single().Listener.Id);
    }

    [Fact]
    public async Task NonEnterpriseDomainMutationIsRejectedWithoutCreatingSqlite()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var license = new LicenseService(new TestEnvironment(), new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
            .Build());
        var options = new PrinterOptions { BindAddress = "127.0.0.1", Port = FreePort() };
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license, options, profiles, NullLogger<PrinterListenerConfigurationService>.Instance);
        await using var manager = new PrinterListenerManager(
            configurations,
            profiles,
            new RecordingSink(),
            () => false,
            new ServiceRuntimeState(),
            NullLoggerFactory.Instance,
            NullLogger<PrinterListenerManager>.Instance);

        await Assert.ThrowsAsync<UnauthorizedAccessException>(() => manager.CreateAsync(new PrinterListenerInput(
            "Unauthorized listener", "127.0.0.1", FreePort(), PrinterProfileService.EpsonTmT88VId,
            true, 1500, 4 * 1024 * 1024, new PrinterListenerBufferConfiguration())));

        Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
        SqliteConnection.ClearAllPools();
        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    [Fact]
    public async Task TrialManagerRunsExactlyOneMemoryOnlyDefaultListener()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var license = new LicenseService(new TestEnvironment(), new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
            .Build());
        var options = new PrinterOptions { BindAddress = "127.0.0.1", Port = FreePort() };
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license, options, profiles, NullLogger<PrinterListenerConfigurationService>.Instance);
        await using (var manager = new PrinterListenerManager(
                         configurations,
                         profiles,
                         new RecordingSink(),
                         () => false,
                         new ServiceRuntimeState(),
                         NullLoggerFactory.Instance,
                         NullLogger<PrinterListenerManager>.Instance))
        {
            await manager.StartAsync(CancellationToken.None);
            var status = manager.GetStatus();
            Assert.False(status.EnterpriseEnabled);
            Assert.Single(status.Listeners);
            Assert.Equal(PrinterListenerDefaults.DefaultId, status.Listeners[0].Configuration.Id);
            Assert.True(status.Listeners[0].Listening);
            Assert.False(File.Exists(Path.Combine(root, ReceiptDatabase.FileName)));
        }

        SqliteConnection.ClearAllPools();
        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    [Fact]
    public async Task StatusFallsBackToActiveRuntimeWhenEnterpriseStorageCannotBeOpened()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var enterprise = false;
        var license = new LicenseService(new TestEnvironment(), new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
            .Build());
        var options = new PrinterOptions { BindAddress = "127.0.0.1", Port = FreePort() };
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license, options, profiles, () => enterprise, NullLogger<PrinterListenerConfigurationService>.Instance);
        await using (var manager = new PrinterListenerManager(
                         configurations,
                         profiles,
                         new RecordingSink(),
                         () => enterprise,
                         new ServiceRuntimeState(),
                         NullLoggerFactory.Instance,
                         NullLogger<PrinterListenerManager>.Instance))
        {
            await manager.StartAsync(CancellationToken.None);
            Directory.CreateDirectory(Path.Combine(root, ReceiptDatabase.FileName));
            enterprise = true;

            var status = manager.GetStatus();

            Assert.True(status.EnterpriseEnabled);
            Assert.Single(status.Listeners);
            Assert.True(status.Listeners[0].Listening);
            Assert.Equal(PrinterListenerDefaults.DefaultId, status.Listeners[0].Configuration.Id);
        }

        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    [Fact]
    public async Task ManagerDisposalIsIdempotentAfterHostedServiceStop()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var (manager, _) = EnterpriseManager(root, FreePort(), new RecordingSink());

        await manager.StartAsync(CancellationToken.None);
        await manager.StopAsync(CancellationToken.None);
        await manager.DisposeAsync();
        await manager.DisposeAsync();

        SqliteConnection.ClearAllPools();
        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    [Fact]
    public async Task EnterpriseManagerPersistsListenersAcrossRestartAndRejectsExternalPortConflicts()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var defaultPort = FreePort();
        var secondPort = FreePort();
        var firstSink = new RecordingSink();
        var (firstManager, firstConfigurations) = EnterpriseManager(root, defaultPort, firstSink);
        await using (firstManager)
        {
            await firstManager.StartAsync(CancellationToken.None);
            await firstManager.CreateAsync(new PrinterListenerInput(
                "Drive Thru", "127.0.0.1", secondPort, PrinterProfileService.EpsonTmT88VId,
                true, 500, 1024 * 1024, new PrinterListenerBufferConfiguration()));
            await SendJobAsync(defaultPort, "DEFAULT JOB");
            await SendJobAsync(secondPort, "DRIVE JOB");
            await WaitUntilAsync(() => firstSink.Jobs.Count == 2);
            Assert.Equal(2, firstManager.GetStatus().ListeningCount);

            var blockedPort = FreePort();
            var blocker = new TcpListener(IPAddress.Loopback, blockedPort);
            blocker.Start();
            try
            {
                await Assert.ThrowsAsync<InvalidOperationException>(() => firstManager.CreateAsync(new PrinterListenerInput(
                    "Blocked", "127.0.0.1", blockedPort, PrinterProfileService.EpsonTmT88VId,
                    true, 500, 1024 * 1024, new PrinterListenerBufferConfiguration())));
                Assert.Equal(2, firstConfigurations.GetAll().Count);
            }
            finally
            {
                blocker.Stop();
            }
        }

        var secondSink = new RecordingSink();
        var (secondManager, _) = EnterpriseManager(root, defaultPort, secondSink);
        await using (secondManager)
        {
            await secondManager.StartAsync(CancellationToken.None);
            Assert.Equal(2, secondManager.GetStatus().ListeningCount);
            Assert.Contains(secondManager.GetStatus().Listeners,
                listener => listener.Configuration.Name == "Drive Thru" && listener.Configuration.Port == secondPort);
            await SendJobAsync(secondPort, "AFTER RESTART");
            await WaitUntilAsync(() => secondSink.Jobs.Count == 1);
        }

        SqliteConnection.ClearAllPools();
        if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
    }

    private static PrinterListenerRuntime Runtime(
        PrinterListenerConfiguration configuration,
        PrinterProfile profile,
        IPrinterListenerJobSink sink) =>
        new(configuration, profile, sink, NullLoggerFactory.Instance);

    private static (PrinterListenerManager Manager, PrinterListenerConfigurationService Configurations) EnterpriseManager(
        string root,
        int defaultPort,
        IPrinterListenerJobSink sink)
    {
        var license = new LicenseService(new TestEnvironment(), new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["Data:Root"] = root })
            .Build());
        var options = new PrinterOptions { BindAddress = "127.0.0.1", Port = defaultPort };
        var profiles = new PrinterProfileService(license);
        var configurations = new PrinterListenerConfigurationService(
            license, options, profiles, () => true, NullLogger<PrinterListenerConfigurationService>.Instance);
        var manager = new PrinterListenerManager(
            configurations,
            profiles,
            sink,
            () => true,
            new ServiceRuntimeState(),
            NullLoggerFactory.Instance,
            NullLogger<PrinterListenerManager>.Instance);
        return (manager, configurations);
    }

    private static PrinterListenerConfiguration Configuration(string id, string name, int port) => new(
        id,
        name,
        "127.0.0.1",
        port,
        id,
        true,
        500,
        1024 * 1024,
        new PrinterListenerBufferConfiguration(),
        DateTimeOffset.UtcNow,
        DateTimeOffset.UtcNow);

    private static PrinterProfile Profile(string id) => new(
        id,
        id,
        string.Empty,
        true,
        80,
        576,
        576,
        2304,
        437,
        [437],
        48,
        64,
        new PrinterCapabilities(true, true, true, true, true, true, true, true, true));

    private static int FreePort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private static async Task SendJobAsync(int port, string text)
    {
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port);
        await using var stream = client.GetStream();
        var payload = Encoding.ASCII.GetBytes(text + "\n\u001dV\0");
        await stream.WriteAsync(payload);
        await stream.FlushAsync();
        client.Client.Shutdown(SocketShutdown.Send);
    }

    private static async Task WaitUntilAsync(Func<bool> condition)
    {
        var timeout = DateTimeOffset.UtcNow.AddSeconds(5);
        while (!condition())
        {
            if (DateTimeOffset.UtcNow >= timeout) throw new TimeoutException("The printer listener did not finish in time.");
            await Task.Delay(20);
        }
    }

    private sealed class RecordingSink : IPrinterListenerJobSink
    {
        public ConcurrentQueue<RecordedJob> Jobs { get; } = new();

        public bool Process(
            byte[] payload,
            string sourceIp,
            PrinterProfile profile,
            PrinterListenerJobContext listener,
            out string? rejection)
        {
            Jobs.Enqueue(new RecordedJob(payload, sourceIp, profile, listener));
            rejection = null;
            return true;
        }
    }

    private sealed record RecordedJob(
        byte[] Payload,
        string SourceIp,
        PrinterProfile Profile,
        PrinterListenerJobContext Listener);

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
