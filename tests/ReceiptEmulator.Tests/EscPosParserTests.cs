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
            .Concat(new byte[] { 0x1B, 0x7B, 0x01 })
            .Concat(Encoding.ASCII.GetBytes("AFTER\n"))
            .ToArray();

        var receipt = new EscPosParser().Parse(bytes);
        var unsupported = Assert.Single(receipt.Commands, command => !command.Supported);

        Assert.Equal(2, unsupported.Offset);
        Assert.Equal("1B 7B 01", unsupported.Hex);
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
        Assert.Equal("*123*", barcode.Data);
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
        Assert.Equal("NV graphic 00", image.Data);
        var warning = Assert.Single(receipt.Commands, command => !command.Supported);
        Assert.Equal("Print NV graphic", warning.Name);
        Assert.Equal("1D 28 4C 06 00 30 45 30 30 01 01", warning.Hex);
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
