using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class WindowsSetupCommandTests
{
    [Theory]
    [InlineData("--install-windows", WindowsSetupAction.Install)]
    [InlineData("--uninstall-windows", WindowsSetupAction.Uninstall)]
    [InlineData("--health-check", WindowsSetupAction.HealthCheck)]
    [InlineData("--repair-firewall", WindowsSetupAction.RepairFirewall)]
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

    [Fact]
    public void TakeOwnershipArguments_RecursivelyAssignAdministrators()
    {
        var directory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Data");

        var arguments = WindowsSetupCommand.BuildTakeOwnershipArguments(directory);

        Assert.Equal(["/F", Path.GetFullPath(directory), "/A", "/R", "/D", "Y"], arguments);
    }

    [Fact]
    public void DataDirectoryAclArguments_GrantRequiredPrincipalsWithoutIgnoringFailures()
    {
        var directory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Data");

        var arguments = WindowsSetupCommand.BuildDataDirectoryAclArguments(directory);

        Assert.Equal(Path.GetFullPath(directory), arguments[0]);
        Assert.Contains("*S-1-5-18:(OI)(CI)F", arguments);
        Assert.Contains("*S-1-5-32-544:(OI)(CI)F", arguments);
        Assert.Contains("*S-1-5-19:(OI)(CI)M", arguments);
        Assert.DoesNotContain("/T", arguments);
        Assert.DoesNotContain("/C", arguments);
    }

    [Fact]
    public void DataDirectoryChildAclResetArguments_RemoveLegacyExplicitRestrictionsRecursively()
    {
        var directory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Data");

        var arguments = WindowsSetupCommand.BuildDataDirectoryChildAclResetArguments(directory);

        Assert.Equal([Path.Combine(Path.GetFullPath(directory), "*"), "/reset", "/T", "/C"], arguments);
    }

    [Fact]
    public void DataDirectoryChildInheritanceArguments_EnableInheritanceRecursively()
    {
        var directory = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Data");

        var arguments = WindowsSetupCommand.BuildDataDirectoryChildInheritanceArguments(directory);

        Assert.Equal([Path.Combine(Path.GetFullPath(directory), "*"), "/inheritance:e", "/T", "/C"], arguments);
    }
}
