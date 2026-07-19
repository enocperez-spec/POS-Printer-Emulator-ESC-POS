using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class CapturePackageServiceTests
{
    [Fact]
    public async Task ExportsAndImportsPortableCaptureWithOriginalMetadata()
    {
        var service = new CapturePackageService();
        var original = CreateJob("CAPTURE TEST");

        var package = service.Export(original);
        await using var input = new MemoryStream(package);
        var imported = await service.ImportAsync(input, "customer.ppecapture", 4096);

        Assert.Equal(original.RawPayload, imported.Payload);
        Assert.Equal("customer.ppecapture", imported.FileName);
        Assert.Equal(original.ReceivedAt, imported.OriginalReceivedAt);
        Assert.Equal(original.SourceIp, imported.OriginalSourceIp);
        Assert.Equal(original.Id, imported.CapturedJobId);
        Assert.Equal(original.ProfileId, imported.CapturedProfileId);
        Assert.Equal(original.ListenerId, imported.ListenerId);
        Assert.Equal(original.ListenerName, imported.ListenerName);
        Assert.Equal(original.ListenerPort, imported.ListenerPort);
    }

    [Fact]
    public async Task ImportsRawBinWithoutCaptureMetadata()
    {
        var service = new CapturePackageService();
        var payload = Encoding.ASCII.GetBytes("RAW IMPORT\n");
        await using var input = new MemoryStream(payload);

        var imported = await service.ImportAsync(input, "receipt.bin", 4096);

        Assert.Equal(payload, imported.Payload);
        Assert.Null(imported.OriginalReceivedAt);
        Assert.Null(imported.OriginalSourceIp);
        Assert.Null(imported.CapturedJobId);
        Assert.Null(imported.CapturedProfileId);
    }

    [Fact]
    public async Task RejectsCaptureWhenPayloadChecksumDoesNotMatch()
    {
        var payload = Encoding.ASCII.GetBytes("ALTERED\n");
        var manifest = CreateManifest(payload, new string('0', 64));
        await using var input = new MemoryStream(CreatePackage(manifest, payload));

        var exception = await Assert.ThrowsAsync<InvalidDataException>(() =>
            new CapturePackageService().ImportAsync(input, "bad.ppecapture", 4096));

        Assert.Equal("The capture package integrity check failed.", exception.Message);
    }

    [Fact]
    public async Task RejectsCaptureWithUnexpectedArchivePath()
    {
        var payload = Encoding.ASCII.GetBytes("SAFE\n");
        var manifest = CreateManifest(payload, Convert.ToHexString(SHA256.HashData(payload)).ToLowerInvariant());
        await using var stream = new MemoryStream();
        using (var archive = new ZipArchive(stream, ZipArchiveMode.Create, leaveOpen: true))
        {
            Write(archive, "manifest.json", JsonSerializer.SerializeToUtf8Bytes(manifest, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
            Write(archive, "payload.bin", payload);
            Write(archive, "../outside.txt", Encoding.UTF8.GetBytes("not allowed"));
        }
        stream.Position = 0;

        var exception = await Assert.ThrowsAsync<InvalidDataException>(() =>
            new CapturePackageService().ImportAsync(stream, "unsafe.ppecapture", 4096));

        Assert.Equal("The capture package contains unexpected files.", exception.Message);
    }

    [Fact]
    public async Task RejectsRawCaptureLargerThanConfiguredLimit()
    {
        await using var input = new MemoryStream(new byte[33]);

        await Assert.ThrowsAsync<InvalidDataException>(() =>
            new CapturePackageService().ImportAsync(input, "large.bin", 32));
    }

    private static ReceiptJob CreateJob(string text)
    {
        var receipt = new ParsedReceipt();
        receipt.Lines.Add(new ReceiptLine("left", [new ReceiptSpan(text, false, false, 1, 1)]));
        receipt.Commands.Add(new ParsedCommand(0, "54 45 53 54", "Print text", text));
        return new ReceiptJob
        {
            Id = Guid.NewGuid(),
            ReceivedAt = new DateTimeOffset(2026, 7, 15, 12, 30, 0, TimeSpan.Zero),
            SourceIp = "192.0.2.10",
            RawPayload = Encoding.ASCII.GetBytes(text + "\n"),
            Receipt = receipt,
            Status = "Completed",
            Origin = JobOrigins.Live,
            RendererVersion = ProductInfo.Version,
            ListenerId = "listener-kitchen",
            ListenerName = "Kitchen",
            ListenerPort = 9101
        };
    }

    private static CaptureManifest CreateManifest(byte[] payload, string checksum) => new(
        "POS Printer Emulator Capture",
        1,
        ProductInfo.Version,
        ProductInfo.Version,
        Guid.NewGuid(),
        DateTimeOffset.UtcNow,
        "192.0.2.20",
        JobOrigins.Live,
        DateTimeOffset.UtcNow,
        "192.0.2.20",
        null,
        null,
        "Completed",
        payload.Length,
        checksum,
        1,
        0,
        [new CaptureCommandSummary("Print text", 1, 0)]);

    private static byte[] CreatePackage(CaptureManifest manifest, byte[] payload)
    {
        using var stream = new MemoryStream();
        using (var archive = new ZipArchive(stream, ZipArchiveMode.Create, leaveOpen: true))
        {
            Write(archive, "manifest.json", JsonSerializer.SerializeToUtf8Bytes(manifest, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
            Write(archive, "payload.bin", payload);
        }
        return stream.ToArray();
    }

    private static void Write(ZipArchive archive, string name, byte[] value)
    {
        var entry = archive.CreateEntry(name);
        using var entryStream = entry.Open();
        entryStream.Write(value);
    }
}
