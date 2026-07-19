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
}
