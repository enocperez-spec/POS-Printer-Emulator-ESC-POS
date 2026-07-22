using System.Text;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class SampleReceiptTests
{
    [Fact]
    public void UsesCurrentAddressCheckAndServerDetails()
    {
        var receipt = Encoding.ASCII.GetString(SampleReceipt.Create());

        Assert.Contains("1234 Glenridge Rd. NW\nAtlanta, GA 30342", receipt);
        Assert.Contains("CHECK #1198", receipt);
        Assert.Contains("TEST RECEIPT", receipt);
        Assert.Contains("Server: E. Perez", receipt);
        Assert.DoesNotContain("123 Market Street", receipt);
        Assert.DoesNotContain("ORDER #1198", receipt);
        Assert.DoesNotContain("Server: Alex", receipt);

        var parsed = new EscPosParser().Parse(SampleReceipt.Create());
        var logo = Assert.Single(parsed.Lines, line => line.Kind == "image");
        Assert.StartsWith("raster-v1:160:160:1:1:", logo.Data);
        Assert.Contains(parsed.Commands, command => command.Name == "Print raster image" && command.Supported);
    }
}
