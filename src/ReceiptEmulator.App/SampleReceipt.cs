using System.Text;

namespace ReceiptEmulator;

public static class SampleReceipt
{
    public static byte[] Create()
    {
        var bytes = new List<byte>();
        void Command(params byte[] command) => bytes.AddRange(command);
        void Text(string value) => bytes.AddRange(Encoding.ASCII.GetBytes(value));

        Command(0x1B, 0x40);
        Command(0x1B, 0x61, 0x01);
        Command(0x1D, 0x21, 0x11);
        Text("POS PRINTER EMULATOR\n");
        Command(0x1D, 0x21, 0x00);
        Text("1234 Main Street\nAtlanta, GA 30342\n");
        Text("------------------------------------------\n");
        Command(0x1B, 0x45, 0x01);
        Text("CHECK #1198\n");
        Command(0x1B, 0x45, 0x00);
        Command(0x1B, 0x61, 0x00);
        Text("Date: Jul 14, 2026  14:32\nServer: E. Perez         POS: 01\n");
        Text("------------------------------------------\n");
        Text("Latte                 1    $4.75\nCappuccino            1    $4.50\nBlueberry Muffin      1    $2.95\n");
        Text("------------------------------------------\n");
        Command(0x1B, 0x61, 0x02);
        Text("Subtotal       $12.20\nTax (8.25%)     $1.01\n");
        Command(0x1D, 0x21, 0x11);
        Text("TOTAL          $13.21\n");
        Command(0x1D, 0x21, 0x00);
        Command(0x1B, 0x61, 0x01);
        Text("\nThank you!\nLocal data stays on this device.\n\n");
        Command(0x1D, 0x6B, 0x04);
        Text("*1198*");
        Command(0x00);
        Text("\n\n");
        Command(0x1D, 0x56, 0x42, 0x18);
        return bytes.ToArray();
    }
}
