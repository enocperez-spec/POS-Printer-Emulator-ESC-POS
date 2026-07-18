using System.Text;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class EscPosParserTests
{
    [Fact]
    public void ParsesAlignmentFormattingTextAndCut()
    {
        var bytes = new byte[] { 0x1B, 0x40, 0x1B, 0x61, 0x01, 0x1B, 0x45, 0x01 }
            .Concat(Encoding.ASCII.GetBytes("HELLO\n"))
            .Concat(new byte[] { 0x1D, 0x56, 0x00 })
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);

        Assert.Equal("center", receipt.Lines[0].Alignment);
        Assert.Equal("HELLO", receipt.Lines[0].Spans[0].Text);
        Assert.True(receipt.Lines[0].Spans[0].Bold);
        Assert.Contains(receipt.Commands, command => command.Name == "Cut paper");
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void ReportsUnknownCommandAtOriginalByteOffsetAndContinues()
    {
        var bytes = Encoding.ASCII.GetBytes("OK")
            .Concat(new byte[] { 0x1B, 0x71, 0x01 })
            .Concat(Encoding.ASCII.GetBytes("AFTER\n"))
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);
        var unsupported = Assert.Single(receipt.Commands, command => !command.Supported);

        Assert.Equal(2, unsupported.Offset);
        Assert.Equal("1B 71 01", unsupported.Hex);
        Assert.Contains("OKAFTER", receipt.PlainText);
    }

    [Fact]
    public void CreatesBarcodeLineWithoutChangingRawTextModel()
    {
        var bytes = new byte[] { 0x1D, 0x6B, 0x04 }
            .Concat(Encoding.ASCII.GetBytes("*123*"))
            .Append((byte)0)
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);

        var barcode = Assert.Single(receipt.Lines);
        Assert.Equal("barcode", barcode.Kind);
        Assert.StartsWith("barcode-v1:4:2:162:2:", barcode.Data);
        Assert.EndsWith(Convert.ToBase64String(Encoding.UTF8.GetBytes("*123*")), barcode.Data);
    }

    [Fact]
    public void AppliesBarcodeDimensionsAndHumanReadableTextSettings()
    {
        var bytes = new byte[] { 0x1D, 0x77, 0x04, 0x1D, 0x68, 0x50, 0x1D, 0x48, 0x03, 0x1D, 0x6B, 0x49, 0x03 }
            .Concat(Encoding.ASCII.GetBytes("ABC"))
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);

        Assert.Equal("barcode-v1:73:4:80:3:QUJD", Assert.Single(receipt.Lines).Data);
        Assert.Contains(receipt.Commands, command => command.Name == "Set barcode width");
        Assert.Contains(receipt.Commands, command => command.Name == "Set barcode height");
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void ParsesQrModelSizeErrorCorrectionAndPayload()
    {
        var bytes = new byte[]
        {
            0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00,
            0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, 0x06,
            0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, 0x32,
            0x1D, 0x28, 0x6B, 0x08, 0x00, 0x31, 0x50, 0x30, 0x48, 0x45, 0x4C, 0x4C, 0x4F,
            0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30
        };

        var receipt = new EscPosParser().Parse(bytes);

        Assert.Equal("qr-v1:2:6:50:SEVMTE8=", Assert.Single(receipt.Lines).Data);
        Assert.Contains(receipt.Commands, command => command.Name == "Print QR code" && command.Details.Contains("module 6"));
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void ParsesQrPayloadWhenCustomerPosSendsDataBeforeItsSettings()
    {
        const string url = "https://go.quby.link/api/v3.6/urls/2419-384?src=&sid=11503&oid=6a5749708a7456fe12f1d679";
        var data = Encoding.ASCII.GetBytes(url);
        var bodyLength = data.Length + 3;
        var bytes = new byte[]
            {
                0x1D, 0x28, 0x6B, (byte)(bodyLength & 0xFF), (byte)(bodyLength >> 8), 0x31, 0x50, 0x30
            }
            .Concat(data)
            .Concat(new byte[]
            {
                0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, 0x30,
                0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, 0x07,
                0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00,
                0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30
            })
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);

        Assert.Equal($"qr-v1:2:7:48:{Convert.ToBase64String(Encoding.UTF8.GetBytes(url))}", Assert.Single(receipt.Lines).Data);
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void ConvertsLegacyColumnBitImageToRasterPreview()
    {
        var bytes = new byte[] { 0x1B, 0x2A, 0x00, 0x02, 0x00, 0x80, 0x40 };

        var receipt = new EscPosParser().Parse(bytes);

        var image = Assert.Single(receipt.Lines);
        Assert.Equal("image", image.Kind);
        Assert.Equal("raster-v1:2:8:2:1:gEAAAAAAAAA=", image.Data);
        Assert.Contains(receipt.Commands, command => command.Name == "Print legacy bit image" && command.Supported);
    }

    [Fact]
    public void PreservesExtendedTextModesAndPositioning()
    {
        var bytes = new byte[]
            {
                0x1D, 0x42, 0x01,
                0x1B, 0x72, 0x01,
                0x1B, 0x4D, 0x01,
                0x1B, 0x24, 0x18, 0x00
            }
            .Concat(Encoding.ASCII.GetBytes("SALE\n"))
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);
        var span = Assert.Single(Assert.Single(receipt.Lines).Spans);

        Assert.Equal("  SALE", span.Text);
        Assert.True(span.Inverted);
        Assert.Equal("red", span.Color);
        Assert.Equal("B", span.Font);
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void RendersStandardRasterImageData()
    {
        var bytes = new byte[]
        {
            0x1B, 0x61, 0x01,
            0x1D, 0x76, 0x30, 0x00, 0x01, 0x00, 0x02, 0x00,
            0xAA, 0x55
        };

        var receipt = new EscPosParser().Parse(bytes);

        var image = Assert.Single(receipt.Lines);
        Assert.Equal("center", image.Alignment);
        Assert.Equal("image", image.Kind);
        Assert.Equal("raster-v1:8:2:1:1:qlU=", image.Data);
        var command = Assert.Single(receipt.Commands, item => item.Name == "Print raster image");
        Assert.True(command.Supported);
        Assert.Contains("8 x 2 dots", command.Details);
    }

    [Fact]
    public void ConsumesLengthPrefixedNvGraphicsCommandWithoutRenderingItsParametersAsText()
    {
        var bytes = new byte[]
            {
                0x1B, 0x40,
                0x1D, 0x28, 0x4C, 0x06, 0x00, 0x30, 0x45, 0x30, 0x30, 0x01, 0x01
            }
            .Concat(Encoding.ASCII.GetBytes("SPECIAL OFFER\n"))
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);

        Assert.DoesNotContain("0E00", receipt.PlainText);
        Assert.Contains("SPECIAL OFFER", receipt.PlainText);
        var image = Assert.Single(receipt.Lines, line => line.Kind == "image");
        Assert.Equal("stored-v1:00:1:1", image.Data);
        var command = Assert.Single(receipt.Commands, command => command.Name == "Print NV graphic");
        Assert.True(command.Supported);
        Assert.Contains("not included in this print job", command.Details);
        Assert.Equal("1D 28 4C 06 00 30 45 30 30 01 01", command.Hex);
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }

    [Fact]
    public void RecognizesShortDrawerPulseAsControlOnlyTraffic()
    {
        var bytes = new byte[] { 0x1B, 0x40, 0x1B, 0x70, 0x00, 0x1B, 0x40 };

        var receipt = new EscPosParser().Parse(bytes);

        Assert.False(receipt.HasPrintableContent);
        Assert.Contains(receipt.Commands, command => command.Name == "Generate drawer pulse");
        Assert.DoesNotContain(receipt.Commands, command => !command.Supported);
    }
}
