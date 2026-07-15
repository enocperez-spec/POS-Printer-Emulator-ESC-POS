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
        var inverted = false;
        var rotated = false;
        var upsideDown = false;
        var color = "black";
        var font = "A";
        var barcodeWidth = 2;
        var barcodeHeight = 162;
        var barcodeHri = 2;
        var qrModel = 2;
        var qrModuleSize = 3;
        var qrErrorCorrection = 48;

        void FlushLine(string kind = "text", string? data = null)
        {
            result.Lines.Add(new ReceiptLine(alignment, line.ToArray(), kind, data));
            line.Clear();
        }

        void AddCommand(int offset, ReadOnlySpan<byte> bytes, string name, string details, bool supported = true)
        {
            const int hexPreviewLimit = 96;
            var preview = bytes.Length > hexPreviewLimit ? bytes[..hexPreviewLimit] : bytes;
            var hex = string.Join(" ", Convert.ToHexString(preview).Chunk(2).Select(chars => new string(chars)));
            if (preview.Length < bytes.Length)
            {
                hex += $" … ({bytes.Length - preview.Length} more bytes)";
            }

            result.Commands.Add(new ParsedCommand(offset, hex, name, details, supported));
        }

        void AddText(string text)
        {
            if (line.Count > 0)
            {
                var last = line[^1];
                if (last.Bold == bold && last.Underline == underline && last.Width == width && last.Height == height &&
                    last.Inverted == inverted && last.Rotated == rotated && last.UpsideDown == upsideDown &&
                    last.Color == color && last.Font == font)
                {
                    line[^1] = last with { Text = last.Text + text };
                    return;
                }
            }
            line.Add(new ReceiptSpan(text, bold, underline, width, height, inverted, rotated, upsideDown, color, font));
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
            if (value == 0x09)
            {
                var characterCount = line.Sum(span => span.Text.Length);
                var spaces = 8 - characterCount % 8;
                AddText(new string(' ', spaces));
                AddCommand(i, payload.Slice(i, 1), "Horizontal tab", $"Advance {spaces} columns");
                i++;
                continue;
            }

            if (value == Esc && i + 1 < payload.Length)
            {
                var command = payload[i + 1];
                if (command == (byte)'@')
                {
                    alignment = "left"; bold = false; underline = false; width = 1; height = 1; codePage = 437;
                    inverted = false; rotated = false; upsideDown = false; color = "black"; font = "A";
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
                    codePage = payload[i + 2] switch
                    {
                        0 => 437, 2 => 850, 3 => 860, 4 => 863, 5 => 865,
                        16 => 1252, 17 => 866, 18 => 852, 19 => 858, 20 => 874,
                        21 => 775, 22 => 855, 23 => 857, 24 => 862, 25 => 864,
                        _ => 437
                    };
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
                if (i + 2 < payload.Length && command == (byte)'M')
                {
                    font = payload[i + 2] is 1 or 49 ? "B" : "A";
                    AddCommand(i, payload.Slice(i, 3), "Select character font", $"Font {font}");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'V')
                {
                    rotated = (payload[i + 2] & 1) != 0;
                    AddCommand(i, payload.Slice(i, 3), "Rotate characters", rotated ? "90 degrees clockwise" : "Off");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'{')
                {
                    upsideDown = (payload[i + 2] & 1) != 0;
                    AddCommand(i, payload.Slice(i, 3), "Upside-down print mode", upsideDown ? "On" : "Off");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'r')
                {
                    color = payload[i + 2] is 1 or 49 ? "red" : "black";
                    AddCommand(i, payload.Slice(i, 3), "Select print color", color);
                    i += 3;
                    continue;
                }
                if (i + 3 < payload.Length && command is (byte)'$' or (byte)'\\')
                {
                    var rawPosition = payload[i + 2] + payload[i + 3] * 256;
                    var columns = Math.Clamp(rawPosition / 12, 0, 96);
                    var current = line.Sum(span => span.Text.Length);
                    if (command == (byte)'$' && columns > current) AddText(new string(' ', columns - current));
                    if (command == (byte)'\\' && columns > 0) AddText(new string(' ', columns));
                    AddCommand(i, payload.Slice(i, 4), command == (byte)'$' ? "Set absolute print position" : "Set relative print position", $"{rawPosition} motion units (~{columns} columns)");
                    i += 4;
                    continue;
                }
                if (i + 4 < payload.Length && command == (byte)'*')
                {
                    var mode = payload[i + 2];
                    var widthDots = payload[i + 3] + payload[i + 4] * 256;
                    var heightDots = mode is 32 or 33 ? 24 : 8;
                    var bytesPerColumn = heightDots / 8;
                    var dataLength = (long)widthDots * bytesPerColumn;
                    var declaredLength = 5L + dataLength;
                    var availableLength = payload.Length - i;
                    var total = (int)Math.Min(availableLength, Math.Min(declaredLength, int.MaxValue));
                    var supportedMode = mode is 0 or 1 or 32 or 33;
                    var complete = supportedMode && widthDots > 0 && declaredLength <= availableLength;
                    if (complete)
                    {
                        var rowBytes = (widthDots + 7) / 8;
                        var raster = new byte[rowBytes * heightDots];
                        var source = payload.Slice(i + 5, (int)dataLength);
                        for (var x = 0; x < widthDots; x++)
                        for (var y = 0; y < heightDots; y++)
                        {
                            var sourceByte = source[x * bytesPerColumn + y / 8];
                            if ((sourceByte & (0x80 >> y % 8)) != 0)
                                raster[y * rowBytes + x / 8] |= (byte)(0x80 >> x % 8);
                        }
                        if (line.Count > 0) FlushLine();
                        var scaleX = mode is 0 or 32 ? 2 : 1;
                        result.Lines.Add(new ReceiptLine(alignment, [], "image", $"raster-v1:{widthDots}:{heightDots}:{scaleX}:1:{Convert.ToBase64String(raster)}"));
                        AddCommand(i, payload.Slice(i, total), "Print legacy bit image", $"{widthDots} x {heightDots} dots, mode {mode}");
                    }
                    else
                    {
                        AddCommand(i, payload.Slice(i, total), "Print legacy bit image", supportedMode ? $"Truncated image; expected {declaredLength} bytes" : $"Unsupported mode {mode}", false);
                    }
                    i += total;
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
                if (i + 7 < payload.Length && command == (byte)'v' && payload[i + 2] == (byte)'0')
                {
                    var mode = payload[i + 3];
                    var normalizedMode = mode >= 48 ? mode - 48 : mode;
                    var widthBytes = payload[i + 4] + payload[i + 5] * 256;
                    var heightDots = payload[i + 6] + payload[i + 7] * 256;
                    var dataLength = (long)widthBytes * heightDots;
                    var declaredLength = 8L + dataLength;
                    var availableLength = payload.Length - i;
                    var total = (int)Math.Min(availableLength, Math.Min(declaredLength, int.MaxValue));
                    var complete = widthBytes > 0 && heightDots > 0 && declaredLength <= availableLength;

                    if (complete)
                    {
                        var scaleX = normalizedMode is 1 or 3 ? 2 : 1;
                        var scaleY = normalizedMode is 2 or 3 ? 2 : 1;
                        var raster = payload.Slice(i + 8, (int)dataLength);
                        if (line.Count > 0) FlushLine();
                        result.Lines.Add(new ReceiptLine(
                            alignment,
                            [],
                            "image",
                            $"raster-v1:{widthBytes * 8}:{heightDots}:{scaleX}:{scaleY}:{Convert.ToBase64String(raster)}"));
                        AddCommand(
                            i,
                            payload.Slice(i, total),
                            "Print raster image",
                            $"{widthBytes * 8} x {heightDots} dots, {scaleX}x width, {scaleY}x height",
                            normalizedMode is >= 0 and <= 3);
                    }
                    else
                    {
                        AddCommand(
                            i,
                            payload.Slice(i, total),
                            "Print raster image",
                            $"Truncated raster; expected {declaredLength} bytes",
                            false);
                    }

                    i += total;
                    continue;
                }
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
                if (i + 2 < payload.Length && command == (byte)'B')
                {
                    inverted = (payload[i + 2] & 1) != 0;
                    AddCommand(i, payload.Slice(i, 3), "Reverse print mode", inverted ? "White on black" : "Off");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'w')
                {
                    barcodeWidth = Math.Clamp((int)payload[i + 2], 2, 6);
                    AddCommand(i, payload.Slice(i, 3), "Set barcode width", $"Module width {barcodeWidth}");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'h')
                {
                    barcodeHeight = Math.Max(1, (int)payload[i + 2]);
                    AddCommand(i, payload.Slice(i, 3), "Set barcode height", $"{barcodeHeight} dots");
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'H')
                {
                    barcodeHri = payload[i + 2] >= 48 ? payload[i + 2] - 48 : payload[i + 2];
                    barcodeHri = Math.Clamp(barcodeHri, 0, 3);
                    AddCommand(i, payload.Slice(i, 3), "Set barcode text position", barcodeHri switch { 0 => "Hidden", 1 => "Above", 2 => "Below", _ => "Above and below" });
                    i += 3;
                    continue;
                }
                if (i + 2 < payload.Length && command == (byte)'f')
                {
                    AddCommand(i, payload.Slice(i, 3), "Select barcode text font", payload[i + 2] is 1 or 49 ? "Font B" : "Font A");
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
                    var barcodeType = mode switch { 0 or 65 => "UPC-A", 1 or 66 => "UPC-E", 2 or 67 => "EAN-13", 3 or 68 => "EAN-8", 4 or 69 => "CODE39", 5 or 70 => "ITF", 6 or 71 => "CODABAR", 72 => "CODE93", 73 => "CODE128", _ => $"Mode {mode}" };
                    AddCommand(i, payload.Slice(i, total), "Print barcode", $"{barcodeType}: {barcode}");
                    if (line.Count > 0) FlushLine();
                    result.Lines.Add(new ReceiptLine(alignment, [], "barcode", $"barcode-v1:{mode}:{barcodeWidth}:{barcodeHeight}:{barcodeHri}:{Convert.ToBase64String(Encoding.UTF8.GetBytes(barcode))}"));
                    i += total;
                    continue;
                }
                if (i + 7 < payload.Length && command == (byte)'(' && payload[i + 2] == (byte)'k')
                {
                    var bodyLength = payload[i + 3] + payload[i + 4] * 256;
                    var total = Math.Min(payload.Length - i, 5 + bodyLength);
                    var complete = total == 5 + bodyLength;
                    var function = payload[i + 6];
                    if (complete && function == 65 && total > 7) qrModel = payload[i + 7] >= 49 ? payload[i + 7] - 48 : payload[i + 7];
                    if (complete && function == 67 && total > 7) qrModuleSize = Math.Clamp((int)payload[i + 7], 1, 16);
                    if (complete && function == 69 && total > 7) qrErrorCorrection = payload[i + 7];
                    if (complete && function == 80 && total > 8)
                        qrData = Encoding.UTF8.GetString(payload.Slice(i + 8, total - 8));
                    if (function == 81)
                    {
                        if (line.Count > 0) FlushLine();
                        result.Lines.Add(new ReceiptLine(alignment, [], "qr", $"qr-v1:{qrModel}:{qrModuleSize}:{qrErrorCorrection}:{Convert.ToBase64String(Encoding.UTF8.GetBytes(qrData))}"));
                    }
                    var details = function switch
                    {
                        65 => $"Model {qrModel}", 67 => $"Module size {qrModuleSize}", 69 => $"Error correction {qrErrorCorrection}",
                        80 => $"Store {Encoding.UTF8.GetByteCount(qrData)} bytes", 81 => $"Model {qrModel}, module {qrModuleSize}, error correction {qrErrorCorrection}", _ => $"Function {function}"
                    };
                    AddCommand(i, payload.Slice(i, total), function == 81 ? "Print QR code" : "Configure QR code", complete ? details : $"Truncated command; expected {5 + bodyLength} bytes", complete && function is 65 or 67 or 69 or 80 or 81);
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
