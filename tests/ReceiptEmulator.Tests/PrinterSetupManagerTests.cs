namespace ReceiptEmulator.Tests;

public sealed class PrinterSetupManagerTests
{
    [Fact]
    public void QueueDefinitionSuppliesEveryRequiredAddPrinterField()
    {
        var definition = WindowsPrinterQueue.CreateDefinition(
            "POS Printer Emulator",
            "PPE_127_0_0_1_9100",
            PrinterSetupManager.DriverName,
            "Managed by POS Printer Emulator");

        Assert.Equal("POS Printer Emulator", definition.PrinterName);
        Assert.Equal("PPE_127_0_0_1_9100", definition.PortName);
        Assert.Equal("EPSON TM-T88V Receipt5", definition.DriverName);
        Assert.Equal("winprint", definition.PrintProcessor);
        Assert.Equal("RAW", definition.DataType);
        Assert.Equal("Managed by POS Printer Emulator", definition.Comment);
    }

    [Fact]
    public void PortSelectionUsesStartingPortWhenItIsAvailable()
    {
        Assert.Equal(9100, PrinterSetupManager.FindFirstAvailablePort(9100, [9101, 9102]));
    }

    [Fact]
    public void PortSelectionSkipsSequentialAssignments()
    {
        Assert.Equal(9103, PrinterSetupManager.FindFirstAvailablePort(9100, [9100, 9101, 9102]));
    }

    [Fact]
    public void PortSelectionStopsAtFirstGap()
    {
        Assert.Equal(9101, PrinterSetupManager.FindFirstAvailablePort(9100, [9100, 9102, 9103]));
    }

    [Fact]
    public void PortSelectionReservesLoopbackListenerForLanAddress()
    {
        var listeners = new[] { Listener("127.0.0.1", 9100) };

        Assert.Equal(9101, PrinterSetupManager.FindFirstAvailablePort(
            9100,
            [],
            "192.168.1.25",
            listeners));
    }

    [Theory]
    [InlineData("0.0.0.0")]
    [InlineData("192.168.1.25")]
    public void PortSelectionCanReuseWildcardOrExactListenerForLanAddress(string bindAddress)
    {
        var listeners = new[] { Listener(bindAddress, 9100) };

        Assert.Equal(9100, PrinterSetupManager.FindFirstAvailablePort(
            9100,
            [],
            "192.168.1.25",
            listeners));
    }

    [Fact]
    public void PortSelectionReservesListenerWithNonEpsonProfile()
    {
        var listeners = new[] { Listener("0.0.0.0", 9100, PrinterProfileService.GenericEscPosId) };

        Assert.Equal(9101, PrinterSetupManager.FindFirstAvailablePort(
            9100,
            [],
            "192.168.1.25",
            listeners));
    }

    [Fact]
    public void AdjustedPrinterPortBuildsMatchingEnterpriseListener()
    {
        var input = PrinterSetupManager.BuildSetupListenerInput("POS Printer Emulator QA", 9101);

        Assert.Equal("POS Printer Emulator QA - 9101", input.Name);
        Assert.Equal("0.0.0.0", input.BindAddress);
        Assert.Equal(9101, input.Port);
        Assert.Equal(PrinterProfileService.EpsonTmT88VId, input.ProfileId);
        Assert.True(input.Enabled);
    }

    [Fact]
    public void AdjustedListenerNameRetainsItsPortWhenPrinterNameIsLong()
    {
        var input = PrinterSetupManager.BuildSetupListenerInput(new string('P', 120), 9102);

        Assert.Equal(80, input.Name.Length);
        Assert.EndsWith(" - 9102", input.Name);
    }

    private static PrinterListenerConfiguration Listener(
        string bindAddress,
        int port,
        string profileId = PrinterProfileService.EpsonTmT88VId)
    {
        var now = DateTimeOffset.UtcNow;
        return new PrinterListenerConfiguration(
            $"listener-{port}",
            $"Listener {port}",
            bindAddress,
            port,
            profileId,
            true,
            PrinterListenerDefaults.DefaultIdleJobTimeoutMilliseconds,
            PrinterListenerDefaults.DefaultMaximumJobBytes,
            new PrinterListenerBufferConfiguration(),
            now,
            now);
    }
}
