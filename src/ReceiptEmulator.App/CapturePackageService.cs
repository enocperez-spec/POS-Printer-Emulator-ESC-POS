using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace ReceiptEmulator;

public sealed class CapturePackageService
{
    public const string FileExtension = ".ppecapture";
    public const int PackageOverheadLimit = 1024 * 1024;
    private const string ManifestEntryName = "manifest.json";
    private const string PayloadEntryName = "payload.bin";
    private const string FormatName = "POS Printer Emulator Capture";
    private const int SchemaVersion = 1;

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    public byte[] Export(ReceiptJob job)
    {
        var payloadHash = Convert.ToHexString(SHA256.HashData(job.RawPayload)).ToLowerInvariant();
        var commands = job.Receipt.Commands
            .GroupBy(command => command.Name, StringComparer.Ordinal)
            .Select(group => new CaptureCommandSummary(
                group.Key,
                group.Count(),
                group.Count(command => !command.Supported)))
            .OrderBy(summary => summary.Name, StringComparer.Ordinal)
            .ToArray();
        var manifest = new CaptureManifest(
            FormatName,
            SchemaVersion,
            ProductInfo.Version,
            job.RendererVersion,
            job.Id,
            job.ReceivedAt,
            job.SourceIp,
            job.Origin,
            job.OriginalReceivedAt ?? job.ReceivedAt,
            job.OriginalSourceIp ?? job.SourceIp,
            job.ParentJobId,
            job.ImportedFileName,
            job.Status,
            job.PayloadSize,
            payloadHash,
            job.Receipt.Commands.Count,
            job.UnsupportedCount,
            commands,
            job.ProfileId,
            job.ProfileName,
            job.ProfilePaperWidthMm,
            job.ProfilePrintableDots,
            job.ListenerId,
            job.ListenerName,
            job.ListenerPort);

        using var output = new MemoryStream();
        using (var archive = new ZipArchive(output, ZipArchiveMode.Create, leaveOpen: true))
        {
            WriteEntry(archive, ManifestEntryName, JsonSerializer.SerializeToUtf8Bytes(manifest, JsonOptions));
            WriteEntry(archive, PayloadEntryName, job.RawPayload);
        }
        return output.ToArray();
    }

    public async Task<ImportedCapture> ImportAsync(Stream input, string fileName, int maximumPayloadBytes, CancellationToken cancellationToken = default)
    {
        if (maximumPayloadBytes <= 0)
        {
            throw new ArgumentOutOfRangeException(nameof(maximumPayloadBytes));
        }

        var extension = Path.GetExtension(Path.GetFileName(fileName));
        if (extension.Equals(".bin", StringComparison.OrdinalIgnoreCase))
        {
            var rawPayload = await ReadLimitedAsync(input, maximumPayloadBytes, cancellationToken);
            EnsurePayload(rawPayload);
            return new ImportedCapture(rawPayload, Path.GetFileName(fileName), null, null, null, null);
        }
        if (!extension.Equals(FileExtension, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException($"Choose a .bin or {FileExtension} receipt capture file.");
        }

        var package = await ReadLimitedAsync(input, maximumPayloadBytes + PackageOverheadLimit, cancellationToken);
        await using var packageStream = new MemoryStream(package, writable: false);
        using var archive = new ZipArchive(packageStream, ZipArchiveMode.Read, leaveOpen: false);
        if (archive.Entries.Count != 2 || archive.Entries.Any(entry => entry.FullName is not ManifestEntryName and not PayloadEntryName))
        {
            throw new InvalidDataException("The capture package contains unexpected files.");
        }

        var manifestEntry = SingleEntry(archive, ManifestEntryName);
        var payloadEntry = SingleEntry(archive, PayloadEntryName);
        if (manifestEntry.Length > PackageOverheadLimit || payloadEntry.Length > maximumPayloadBytes)
        {
            throw new InvalidDataException("The capture package is larger than the configured receipt limit.");
        }

        CaptureManifest manifest;
        await using (var manifestStream = manifestEntry.Open())
        {
            manifest = await JsonSerializer.DeserializeAsync<CaptureManifest>(manifestStream, JsonOptions, cancellationToken)
                ?? throw new InvalidDataException("The capture manifest is missing or invalid.");
        }
        ValidateManifest(manifest);

        byte[] payload;
        await using (var payloadStream = payloadEntry.Open())
        {
            payload = await ReadLimitedAsync(payloadStream, maximumPayloadBytes, cancellationToken);
        }
        EnsurePayload(payload);
        if (manifest.PayloadLength != payload.Length ||
            !CryptographicOperations.FixedTimeEquals(
                Convert.FromHexString(manifest.PayloadSha256),
                SHA256.HashData(payload)))
        {
            throw new InvalidDataException("The capture package integrity check failed.");
        }

        return new ImportedCapture(
            payload,
            Path.GetFileName(fileName),
            manifest.OriginalReceivedAt,
            manifest.OriginalSourceIp,
            manifest.JobId,
            manifest.ProfileId,
            manifest.ListenerId,
            manifest.ListenerName,
            manifest.ListenerPort);
    }

    private static ZipArchiveEntry SingleEntry(ZipArchive archive, string name)
    {
        var matches = archive.Entries.Where(entry => entry.FullName == name).ToArray();
        return matches.Length == 1
            ? matches[0]
            : throw new InvalidDataException($"The capture package must contain exactly one {name} file.");
    }

    private static void ValidateManifest(CaptureManifest manifest)
    {
        if (manifest.Format != FormatName || manifest.SchemaVersion != SchemaVersion)
        {
            throw new InvalidDataException("This capture package format is not supported.");
        }
        if (manifest.JobId == Guid.Empty || manifest.PayloadLength <= 0 ||
            manifest.PayloadSha256.Length != 64 || !manifest.PayloadSha256.All(Uri.IsHexDigit))
        {
            throw new InvalidDataException("The capture manifest contains invalid metadata.");
        }
        if (string.IsNullOrWhiteSpace(manifest.OriginalSourceIp) || manifest.OriginalSourceIp.Length > 255)
        {
            throw new InvalidDataException("The capture manifest source is invalid.");
        }
    }

    private static void WriteEntry(ZipArchive archive, string name, byte[] content)
    {
        var entry = archive.CreateEntry(name, CompressionLevel.Optimal);
        entry.LastWriteTime = new DateTimeOffset(2000, 1, 1, 0, 0, 0, TimeSpan.Zero);
        using var stream = entry.Open();
        stream.Write(content);
    }

    private static async Task<byte[]> ReadLimitedAsync(Stream input, int maximumBytes, CancellationToken cancellationToken)
    {
        using var output = new MemoryStream(Math.Min(maximumBytes, 64 * 1024));
        var buffer = new byte[64 * 1024];
        while (true)
        {
            var read = await input.ReadAsync(buffer, cancellationToken);
            if (read == 0) break;
            if (output.Length + read > maximumBytes)
            {
                throw new InvalidDataException("The receipt capture is larger than the configured limit.");
            }
            output.Write(buffer, 0, read);
        }
        return output.ToArray();
    }

    private static void EnsurePayload(byte[] payload)
    {
        if (payload.Length == 0)
        {
            throw new InvalidDataException("The receipt capture contains no data.");
        }
    }
}

public sealed record ImportedCapture(
    byte[] Payload,
    string FileName,
    DateTimeOffset? OriginalReceivedAt,
    string? OriginalSourceIp,
    Guid? CapturedJobId,
    string? CapturedProfileId,
    string? ListenerId = null,
    string? ListenerName = null,
    int? ListenerPort = null);

public sealed record CaptureCommandSummary(string Name, int Count, int UnsupportedCount);

public sealed record CaptureManifest(
    string Format,
    int SchemaVersion,
    string ApplicationVersion,
    string RendererVersion,
    Guid JobId,
    DateTimeOffset ReceivedAt,
    string SourceIp,
    string Origin,
    DateTimeOffset OriginalReceivedAt,
    string OriginalSourceIp,
    Guid? ParentJobId,
    string? ImportedFileName,
    string ProcessingResult,
    int PayloadLength,
    string PayloadSha256,
    int ParsedCommandCount,
    int UnsupportedCommandCount,
    IReadOnlyList<CaptureCommandSummary> Commands,
    string? ProfileId = null,
    string? ProfileName = null,
    int? ProfilePaperWidthMm = null,
    int? ProfilePrintableDots = null,
    string? ListenerId = null,
    string? ListenerName = null,
    int? ListenerPort = null);
