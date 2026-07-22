using System.Net;

namespace ReceiptEmulator.Tests;

public sealed class PrinterListenerApiMapperTests
{
    [Fact]
    public void ResolveConnectionAddress_UsesFirstLanIpv4ForAllInterfaces()
    {
        var result = PrinterListenerApiMapper.ResolveConnectionAddress(
            "0.0.0.0",
            [IPAddress.IPv6Loopback, IPAddress.Loopback, IPAddress.Parse("192.168.1.42")]);

        Assert.Equal("192.168.1.42", result);
    }

    [Fact]
    public void ResolveConnectionAddress_FallsBackToLoopbackWhenLanAddressIsUnavailable()
    {
        var result = PrinterListenerApiMapper.ResolveConnectionAddress(
            "0.0.0.0",
            [IPAddress.IPv6Loopback, IPAddress.Loopback]);

        Assert.Equal("127.0.0.1", result);
    }

    [Fact]
    public void ResolveConnectionAddress_PreservesExplicitBindAddress()
    {
        var result = PrinterListenerApiMapper.ResolveConnectionAddress("10.20.30.40");

        Assert.Equal("10.20.30.40", result);
    }
}
