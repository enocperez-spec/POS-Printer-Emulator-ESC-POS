namespace ReceiptEmulator;

public sealed class PrinterOptions
{
    public int Port { get; set; } = 9100;
    public string BindAddress { get; set; } = "0.0.0.0";
    public int IdleJobTimeoutMilliseconds { get; set; } = 1500;
    public int MaximumJobBytes { get; set; } = 4 * 1024 * 1024;
}
