using POSPrinterEmulator.Update;

namespace ReceiptEmulator.Tests;

public sealed class UpdatePackageSecurityTests
{
    [Fact]
    public void ReadsTheChecksumForTheExpectedInstaller()
    {
        const string hash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
        var parsed = UpdatePackageSecurity.ParseSha256($"{hash}  POSPrinterEmulatorSetup.exe", "POSPrinterEmulatorSetup.exe");
        Assert.Equal(hash, parsed);
    }

    [Fact]
    public async Task RejectsAnInstallerThatDoesNotMatchTheChecksum()
    {
        var path = Path.GetTempFileName();
        try
        {
            await File.WriteAllTextAsync(path, "not the expected package");
            await Assert.ThrowsAsync<InvalidDataException>(() =>
                UpdatePackageSecurity.VerifySha256Async(path,
                    "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"));
        }
        finally { File.Delete(path); }
    }

    [Fact]
    public void AcceptsOnlyHttpsGitHubReleaseAssets()
    {
        Assert.True(UpdatePackageSecurity.IsTrustedGitHubAsset(
            new Uri("https://github.com/example/project/releases/download/v1/setup.exe"), ".exe"));
        Assert.False(UpdatePackageSecurity.IsTrustedGitHubAsset(
            new Uri("http://github.com/example/project/releases/download/v1/setup.exe"), ".exe"));
        Assert.False(UpdatePackageSecurity.IsTrustedGitHubAsset(
            new Uri("https://example.com/setup.exe"), ".exe"));
    }
}
