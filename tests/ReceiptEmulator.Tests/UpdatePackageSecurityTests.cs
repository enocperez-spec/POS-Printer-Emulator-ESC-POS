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

    [Fact]
    public async Task DownloadClosesTheTemporaryFileBeforePromotingIt()
    {
        var directory = Path.Combine(Path.GetTempPath(), "PPE-UpdateDownloadTests", Guid.NewGuid().ToString("N"));
        var destination = Path.Combine(directory, "POSPrinterEmulatorSetup.exe");
        var content = Enumerable.Range(0, 512 * 1024)
            .Select(index => (byte)(index % 251))
            .ToArray();
        var progress = new List<int>();
        try
        {
            using var client = new HttpClient(new ByteContentHandler(content));

            await UpdatePackageSecurity.DownloadToFileAsync(
                client,
                new Uri("https://github.com/example/project/releases/download/v1/setup.exe"),
                destination,
                progress.Add);

            Assert.Equal(content, await File.ReadAllBytesAsync(destination));
            Assert.False(File.Exists(destination + ".download"));
            Assert.Equal(100, progress.Last());
            using var exclusive = new FileStream(destination, FileMode.Open, FileAccess.Read, FileShare.None);
            Assert.Equal(content.Length, exclusive.Length);
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
    }

    private sealed class ByteContentHandler(byte[] content) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(System.Net.HttpStatusCode.OK)
            {
                Content = new ByteArrayContent(content),
                RequestMessage = request
            });
    }
}
