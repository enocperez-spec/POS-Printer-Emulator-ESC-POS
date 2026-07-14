using System.Text;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class SampleReceiptTests
{
    [Fact]
    public void UsesCurrentAddressCheckAndServerDetails()
    {
        var receipt = Encoding.ASCII.GetString(SampleReceipt.Create());

        Assert.Contains("1234 Main Street\nAtlanta, GA 30342", receipt);
        Assert.Contains("CHECK #1198", receipt);
        Assert.Contains("Server: E. Perez", receipt);
        Assert.DoesNotContain("123 Market Street", receipt);
        Assert.DoesNotContain("ORDER #1198", receipt);
        Assert.DoesNotContain("Server: Alex", receipt);
    }
}
