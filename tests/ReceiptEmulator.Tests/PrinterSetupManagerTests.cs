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
}
