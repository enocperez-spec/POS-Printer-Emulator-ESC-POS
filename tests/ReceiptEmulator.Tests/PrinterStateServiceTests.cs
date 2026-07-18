using Microsoft.Extensions.Logging.Abstractions;

namespace ReceiptEmulator.Tests;

public sealed class PrinterStateServiceTests
{
    [Fact]
    public void ReadyPrinterReturnsEpsonReadyStatusBytes()
    {
        var service = CreateService();

        Assert.Equal(0x12, service.BuildRealTimeStatus(1));
        Assert.Equal(0x12, service.BuildRealTimeStatus(2));
        Assert.Equal(0x12, service.BuildRealTimeStatus(3));
        Assert.Equal(0x12, service.BuildRealTimeStatus(4));
        Assert.Equal([0x10, 0x00, 0x00, 0x00], service.BuildAutomaticStatusBack());
        Assert.True(service.GetStatus().EffectiveOnline);
    }

    [Fact]
    public void FaultsAreEncodedInRealTimeAndAutomaticStatus()
    {
        var service = CreateService();
        service.Update(new(true, "Out", true, true, false, false, false, false));

        Assert.Equal(0x1A, service.BuildRealTimeStatus(1));
        Assert.Equal(0x76, service.BuildRealTimeStatus(2));
        Assert.Equal(0x1A, service.BuildRealTimeStatus(3));
        Assert.Equal(0x72, service.BuildRealTimeStatus(4));
        Assert.Equal([0x38, 0x08, 0x0C, 0x00], service.BuildAutomaticStatusBack());
        Assert.False(service.GetStatus().EffectiveOnline);
    }

    [Fact]
    public void ProtocolExtractsSplitStatusCommandsWithoutConsumingPrintData()
    {
        var service = CreateService();
        var buffer = new List<byte>("SALE"u8.ToArray()) { 0x10, 0x04 };

        var incomplete = EscPosStatusProtocol.Extract(buffer, 0, service);
        Assert.Empty(incomplete.Responses);
        Assert.Equal(6, buffer.Count);

        buffer.Add(0x01);
        buffer.AddRange("\n"u8.ToArray());
        var complete = EscPosStatusProtocol.Extract(buffer, 0, service);

        Assert.Single(complete.Responses);
        Assert.Equal("DLE EOT 1", complete.Responses[0].Command);
        Assert.Equal([0x12], complete.Responses[0].Bytes);
        Assert.Equal("SALE\n", System.Text.Encoding.ASCII.GetString(buffer.ToArray()));
    }

    [Fact]
    public void AutomaticStatusBackCanBeEnabledAndDisabled()
    {
        var service = CreateService();
        var enable = new List<byte> { 0x1D, 0x61, 0x0E };

        var enabled = EscPosStatusProtocol.Extract(enable, 0, service);
        Assert.Equal(0x0E, enabled.AsbMask);
        Assert.Single(enabled.Responses);
        Assert.Empty(enable);

        var disable = new List<byte> { 0x1D, 0x61, 0x00 };
        var disabled = EscPosStatusProtocol.Extract(disable, enabled.AsbMask, service);
        Assert.Equal(0, disabled.AsbMask);
        Assert.Empty(disabled.Responses);
        Assert.Empty(disable);
    }

    [Fact]
    public void ProfileCanDisableRealTimeAndAutomaticStatusProtocols()
    {
        var service = CreateService();
        var buffer = new List<byte> { 0x10, 0x04, 0x01, 0x1D, 0x61, 0x0E };

        var result = EscPosStatusProtocol.Extract(buffer, 0, service, dleEotSupported: false, asbSupported: false);

        Assert.Empty(result.Responses);
        Assert.Equal(0, result.AsbMask);
        Assert.Equal([0x10, 0x04, 0x01, 0x1D, 0x61, 0x0E], buffer);
    }

    [Fact]
    public void RecoveryRequestClearsRecoverableFaults()
    {
        var service = CreateService();
        service.Update(new(true, "Ready", false, true, true, true, true, false));
        var request = new List<byte> { 0x10, 0x05, 0x02 };

        EscPosStatusProtocol.Extract(request, 0, service);

        var status = service.GetStatus();
        Assert.False(status.CutterError);
        Assert.False(status.RecoverableError);
        Assert.False(status.AutoRecoverableError);
        Assert.True(status.UnrecoverableError);
        Assert.Empty(request);
    }

    private static PrinterStateService CreateService() => new(NullLogger<PrinterStateService>.Instance);
}
