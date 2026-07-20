using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class StoredGraphicServiceTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.StoredGraphics.Tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task ImportsListsReadsAndDeletesStoredLogo()
    {
        var service = new StoredGraphicService(_root);
        var png = Convert.FromBase64String("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=");

        await using var stream = new MemoryStream(png);
        var imported = await service.ImportAsync("00", "Front counter logo", "logo.png", stream);

        Assert.Equal("00", imported.KeyCode);
        Assert.Equal("Front counter logo", imported.Name);
        Assert.Equal("image/png", imported.ContentType);
        Assert.Contains("/api/stored-graphics/00/content", imported.ContentUrl);
        Assert.Equal(imported, Assert.Single(service.List()));
        Assert.True(service.TryRead("00", out var content, out var contentType));
        Assert.Equal(png, content);
        Assert.Equal("image/png", contentType);
        Assert.True(await service.DeleteAsync("00"));
        Assert.Empty(service.List());
    }

    [Fact]
    public async Task MissingStorageDirectoryBehavesLikeAnEmptyStoreAndIsRecreatedOnImport()
    {
        var service = new StoredGraphicService(_root);
        Directory.Delete(Path.Combine(_root, "stored-graphics"));

        Assert.Empty(service.List());
        Assert.False(await service.DeleteAsync("00"));
        Assert.False(service.TryRead("00", out _, out _));

        var png = Convert.FromBase64String("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=");
        await using var stream = new MemoryStream(png);
        await service.ImportAsync("00", "Restored logo", "logo.png", stream);

        Assert.Single(service.List());
    }

    [Theory]
    [InlineData("")]
    [InlineData("0")]
    [InlineData("000")]
    [InlineData("../")]
    public void RejectsInvalidStorageKeys(string keyCode)
    {
        Assert.Throws<ArgumentException>(() => StoredGraphicService.NormalizeKey(keyCode));
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }
}
