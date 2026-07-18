using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class WindowsSetupCommandTests
{
    [Theory]
    [InlineData("--install-windows", WindowsSetupAction.Install)]
    [InlineData("--uninstall-windows", WindowsSetupAction.Uninstall)]
    [InlineData("--health-check", WindowsSetupAction.HealthCheck)]
    [InlineData("--INSTALL-WINDOWS", WindowsSetupAction.Install)]
    public void ParseActionRecognizesSetupCommands(string argument, WindowsSetupAction expected)
    {
        Assert.Equal(expected, WindowsSetupCommand.ParseAction([argument]));
    }

    [Fact]
    public void ParseActionAllowsNormalApplicationStartup()
    {
        Assert.Equal(WindowsSetupAction.None, WindowsSetupCommand.ParseAction([]));
        Assert.Equal(WindowsSetupAction.None, WindowsSetupCommand.ParseAction(["--urls", "http://localhost:5000"]));
    }
}
