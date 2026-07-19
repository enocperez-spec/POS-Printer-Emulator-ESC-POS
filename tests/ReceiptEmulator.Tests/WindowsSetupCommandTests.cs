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

    [Fact]
    public void FirewallRuleCoversConfiguredListenerPortsWithoutPublicNetworkAccess()
    {
        var executable = Path.Combine(Path.GetTempPath(), "POS Printer Emulator", "ReceiptEmulator.exe");

        var arguments = WindowsSetupCommand.BuildFirewallRuleArguments(executable);

        Assert.Contains("name=POS Printer Emulator RAW TCP Listeners", arguments);
        Assert.Contains("protocol=TCP", arguments);
        Assert.Contains("localport=any", arguments);
        Assert.Contains("profile=private,domain", arguments);
        Assert.DoesNotContain(arguments, argument => argument.Contains("public", StringComparison.OrdinalIgnoreCase));
        Assert.Contains($"program={Path.GetFullPath(executable)}", arguments);
    }
}
