using System.Text;

namespace ReceiptEmulator;

public sealed class EscPosParser
{
    private const byte Esc = 0x1B;
    private const byte Gs = 0x1D;

    public ParsedReceipt Parse(ReadOnlySpan<byte> payload)
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        var result = new ParsedReceipt();
        var line = new List<ReceiptSpan>();
        var alignment = "left";
        var bold = false;
        var underline = false;
        var width = 1;
        var height = 1;
        var codePage = 437;
        var qrData = string.Empty;

        void FlushLine(string kind = "text", string? data = null)
        {
            result.Lines.Add(new ReceiptLine(alignment, line.ToArray(), kind, data));
            line.Clear();
        }

        void AddCommand(int offset, ReadOnlySpan<byte> bytes, string name, string details, bool supported = true) =>
            result.Commands.Add(new ParsedCommand(offset, Convert.ToHexString(bytes).Chunk(2).Select(chars => new string(chars)).Aggregate((a, b) => a + " " + b), name, details, supported));

        void AddText(string text)
        {
            if (line.Count > 0)
            {
                var last = line[^1];
                if (last.Bold == bold && last.Underline == underline && last.Width == width && last.Height == height)
                {
                    line[^1] = last with { Text = last.Text + text };
                    return;
                }
            }
            line.Add(new ReceiptSpan(text, bold, underline, width, height));
        }

        var i = 0;
        while (i < payload.Length)
        {
            var start = i;
            var value = payload[i];

            if (value == 0x0A)
            {
                AddCommand(i, payload.Slice(i, 1), "Line feed", "Advance one line");
                FlushLine();
                i++;
                continue;
            }
            if (value == 0x0D)
            {
                AddCommand(i, payload.Slice(i, 1), "Carriage return", "Return to line start");
                i++;
                continue;
            }

            if (value == Esc && i + 1 < payload.Length)
            {
                var command = payload[i + 1];
                if (command == (byte)'@')
                {
                    alignment = "left"; bold = false; underline = false; width = 1; height = 1; codePage = 437;
                    AddCommand(i, payload.Slice(i, 2), "Initialize printer", "ESC @");
                    i += 2;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'a')
                {
                    alignment = payload[i + 2] switch { 1 or 49 => "center", 2 or 50 => "right", _ => "left" };
                    AddCommand(i, payload.Slice(i, 3), "Select alignment", alignment);
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'E')
                {
                    bold = payload[i + 2] != 0;
                    AddCommand(i, payload.Slice(i, 3), "Emphasis", bold ? "On" : "Off");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'-')
                {
                    underline = payload[i + 2] != 0;
                    AddCommand(i, payload.Slice(i, 3), "Underline", underline ? "On" : "Off");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'!')
                {
                    var mode = payload[i + 2];
                    bold = (mode & 0x08) != 0;
                    height = (mode & 0x10) != 0 ? 2 : 1;
                    width = (mode & 0x20) != 0 ? 2 : 1;
                    underline = (mode & 0x80) != 0;
                    AddCommand(i, payload.Slice(i, 3), "Select print mode", $"{width}x width, {height}x height");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'t')
                {
                    codePage = payload[i + 2] switch { 0 => 437, 2 => 850, 3 => 860, 4 => 863, 5 => 865, _ => 437 };
                    AddCommand(i, payload.Slice(i, 3), "Select code page", $"CP{codePage}");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command is (byte)'J' or (byte)'d')
                {
                    AddCommand(i, payload.Slice(i, 3), "Feed paper", $"{payload[i + 2]} {(command == (byte)'d' ? "lines" : "units")}");
                    FlushLine();
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'p')
                {
                    var connector = payload[i + 2] is 1 or 49 ? 5 : 2;
                    var length = i + 4 < payload.Length && payload[i + 3] is not Esc and not Gs
                        ? 5
                        : 3;
                    var details = length == 5
                        ? $"Connector pin {connector}; {payload[i + 3] * 2} ms on, {payload[i + 4] * 2} ms off"
                        : $"Connector pin {connector}; timing bytes not supplied";
                    AddCommand(i, payload.Slice(i, length), "Generate drawer pulse", details);
                    i += length;
                    continue;
                }

                var unknownLength = Math.Min(3, payload.Length - i);
                AddCommand(i, payload.Slice(i, unknownLength), "Unsupported ESC command", $"Byte offset {i}", false);
                i += unknownLength;
                continue;
            }

            if (value == Gs && i + 1 < payload.Length)
            {
                var command = payload[i + 1];
                if (i + 4 < payload.Length && command == (byte)'(' && payload[i + 2] == (byte)'L')
                {
                    var bodyLength = payload[i + 3] + payload[i + 4] * 256;
                    var declaredLength = 5 + bodyLength;
                    var total = Math.Min(payload.Length - i, declaredLength);
                    var complete = total == declaredLength;
                    var function = bodyLength >= 2 && i + 6 < payload.Length ? payload[i + 6] : (byte)0;

                    if (complete && function == 69 && bodyLength >= 6)
                    {
                        var keyCode = Encoding.ASCII.GetString(payload.Slice(i + 7, 2));
                        var scaleX = payload[i + 9];
                        var scaleY = payload[i + 10];
                        if (line.Count > 0) FlushLine();
                        result.Lines.Add(new ReceiptLine("center", [], "image", $"NV graphic {keyCode}"));
                        AddCommand(i, payload.Slice(i, total), "Print NV graphic",
                            $"Stored image {keyCode}, {scaleX}x{scaleY}; image data is stored in the physical printer", false);
                    }
                    else
                    {
                        AddCommand(i, payload.Slice(i, total), "Graphics command",
                            complete ? $"Function {function}; {bodyLength} data bytes" : $"Truncated command; expected {declaredLength} bytes", false);
                    }

                    i += total;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'!')
                {
                    var size = payload[i + 2];
                    width = ((size >> 4) & 0x07) + 1;
                    height = (size & 0x07) + 1;
                    AddCommand(i, payload.Slice(i, 3), "Set character size", $"{width}x width, {height}x height");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'V')
                {
                    var length = payload[i + 2] is 65 or 66 && i + 3 < payload.Length ? 4 : 3;
                    AddCommand(i, payload.Slice(i, length), "Cut paper", payload[i + 2] is 1 or 49 or 66 ? "Partial" : "Full");
                    if (line.Count > 0) FlushLine();
                    i += length;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'k')
                {
                    var mode = payload[i + 2];
                    var dataStart = i + 3;
                    var dataLength = 0;
                    if (mode >= 65 && dataStart < payload.Length)
                    {
                        dataLength = Math.Min(payload[dataStart], payload.Length - dataStart - 1);
                        dataStart++;
                    }
                    else
                    {
                        while (dataStart + dataLength < payload.Length && payload[dataStart + dataLength] != 0) dataLength++;
                    }
                    var barcode = Encoding.ASCII.GetString(payload.Slice(dataStart, dataLength));
                    var total = Math.Min(payload.Length - i, dataStart + dataLength - i + (mode < 65 ? 1 : 0));
                    AddCommand(i, payload.Slice(i, total), "Print barcode", barcode);
                    if (line.Count > 0) FlushLine();
                    result.Lines.Add(new ReceiptLine("center", [], "barcode", barcode));
                    i += total;
                    continue;
                }
                if (i + 7 < payload.Length && command == (byte)'(' && payload[i + 2] == (byte)'k')
                {
                    var bodyLength = payload[i + 3] + payload[i + 4] * 256;
                    var total = Math.Min(payload.Length - i, 5 + bodyLength);
                    var function = payload[i + 6];
                    if (function == 80 && total > 8)
                        qrData = Encoding.UTF8.GetString(payload.Slice(i + 8, total - 8));
                    if (function == 81)
                    {
                        if (line.Count > 0) FlushLine();
                        result.Lines.Add(new ReceiptLine("center", [], "qr", qrData));
                    }
                    AddCommand(i, payload.Slice(i, total), function == 81 ? "Print QR code" : "Configure QR code", function == 80 ? $"Store {qrData.Length} bytes" : $"Function {function}");
                    i += total;
                    continue;
                }

                var unknownLength = Math.Min(3, payload.Length - i);
                AddCommand(i, payload.Slice(i, unknownLength), "Unsupported GS command", $"Byte offset {i}", false);
                i += unknownLength;
                continue;
            }

            if (value < 0x20)
            {
                AddCommand(i, payload.Slice(i, 1), "Unsupported control", $"0x{value:X2} at byte offset {i}", false);
                i++;
                continue;
            }

            while (i < payload.Length && payload[i] >= 0x20 && payload[i] != Esc && payload[i] != Gs) i++;
            var textBytes = payload.Slice(start, i - start);
            var text = Encoding.GetEncoding(codePage).GetString(textBytes);
            AddText(text);
            AddCommand(start, textBytes, "Print text", text.Length > 48 ? text[..48] + "…" : text);
        }

        if (line.Count > 0) FlushLine();
        return result;
    }
}
