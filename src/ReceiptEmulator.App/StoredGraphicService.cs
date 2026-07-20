using System.Text.Json;
using System.Text.RegularExpressions;

namespace ReceiptEmulator;

public sealed record StoredGraphicInfo(
    string KeyCode,
    string Name,
    string FileName,
    string ContentType,
    long Size,
    DateTimeOffset UpdatedAt,
    string ContentUrl);

public sealed class StoredGraphicService
{
    public const int MaximumFileBytes = 2 * 1024 * 1024;
    private static readonly Regex KeyPattern = new("^[A-Z0-9]{2}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly string _directory;

    public StoredGraphicService(LicenseService license) : this(license.RootPath) { }

    public StoredGraphicService(string dataRoot)
    {
        _directory = Path.Combine(dataRoot, "stored-graphics");
        Directory.CreateDirectory(_directory);
    }

    public IReadOnlyList<StoredGraphicInfo> List()
    {
        if (!Directory.Exists(_directory))
        {
            return [];
        }

        var graphics = new List<StoredGraphicInfo>();
        foreach (var metadataPath in Directory.EnumerateFiles(_directory, "*.json"))
        {
            try
            {
                var metadata = JsonSerializer.Deserialize<StoredGraphicMetadata>(File.ReadAllText(metadataPath));
                if (metadata is null || !KeyPattern.IsMatch(metadata.KeyCode)) continue;
                var contentPath = Path.Combine(_directory, metadata.StoredFileName);
                if (!File.Exists(contentPath)) continue;
                graphics.Add(ToInfo(metadata, new FileInfo(contentPath).Length));
            }
            catch (IOException) { }
            catch (JsonException) { }
        }

        return graphics.OrderBy(graphic => graphic.KeyCode, StringComparer.Ordinal).ToArray();
    }

    public async Task<StoredGraphicInfo> ImportAsync(
        string keyCode,
        string? name,
        string? originalFileName,
        Stream content,
        CancellationToken cancellationToken = default)
    {
        var normalizedKey = NormalizeKey(keyCode);
        await using var buffer = new MemoryStream();
        var copyBuffer = new byte[81920];
        while (true)
        {
            var read = await content.ReadAsync(copyBuffer, cancellationToken);
            if (read == 0) break;
            if (buffer.Length + read > MaximumFileBytes)
            {
                throw new ArgumentException("Logo files must be 2 MB or smaller.");
            }
            await buffer.WriteAsync(copyBuffer.AsMemory(0, read), cancellationToken);
        }

        var bytes = buffer.ToArray();
        var (contentType, extension) = DetectImageType(bytes);
        var displayName = NormalizeName(name, originalFileName, normalizedKey);
        var storedFileName = normalizedKey + extension;
        var updatedAt = DateTimeOffset.UtcNow;
        var metadata = new StoredGraphicMetadata(normalizedKey, displayName, Path.GetFileName(originalFileName ?? storedFileName), storedFileName, contentType, updatedAt);

        await _gate.WaitAsync(cancellationToken);
        try
        {
            Directory.CreateDirectory(_directory);
            var contentPath = Path.Combine(_directory, storedFileName);
            var temporaryContent = contentPath + ".tmp";
            var metadataPath = Path.Combine(_directory, normalizedKey + ".json");
            var temporaryMetadata = metadataPath + ".tmp";

            await File.WriteAllBytesAsync(temporaryContent, bytes, cancellationToken);
            await File.WriteAllTextAsync(temporaryMetadata, JsonSerializer.Serialize(metadata), cancellationToken);

            foreach (var oldPath in Directory.EnumerateFiles(_directory, normalizedKey + ".*"))
            {
                if (oldPath.EndsWith(".json", StringComparison.OrdinalIgnoreCase) || oldPath.EndsWith(".tmp", StringComparison.OrdinalIgnoreCase)) continue;
                File.Delete(oldPath);
            }

            File.Move(temporaryContent, contentPath, overwrite: true);
            File.Move(temporaryMetadata, metadataPath, overwrite: true);
            return ToInfo(metadata, bytes.LongLength);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task<bool> DeleteAsync(string keyCode, CancellationToken cancellationToken = default)
    {
        var normalizedKey = NormalizeKey(keyCode);
        await _gate.WaitAsync(cancellationToken);
        try
        {
            if (!Directory.Exists(_directory))
            {
                return false;
            }

            var found = false;
            foreach (var path in Directory.EnumerateFiles(_directory, normalizedKey + ".*"))
            {
                File.Delete(path);
                found = true;
            }
            return found;
        }
        finally
        {
            _gate.Release();
        }
    }

    public bool TryRead(string keyCode, out byte[] content, out string contentType)
    {
        content = [];
        contentType = "application/octet-stream";
        string normalizedKey;
        try { normalizedKey = NormalizeKey(keyCode); }
        catch (ArgumentException) { return false; }

        var metadataPath = Path.Combine(_directory, normalizedKey + ".json");
        if (!File.Exists(metadataPath)) return false;
        try
        {
            var metadata = JsonSerializer.Deserialize<StoredGraphicMetadata>(File.ReadAllText(metadataPath));
            if (metadata is null) return false;
            var contentPath = Path.Combine(_directory, metadata.StoredFileName);
            if (!File.Exists(contentPath)) return false;
            content = File.ReadAllBytes(contentPath);
            contentType = metadata.ContentType;
            return true;
        }
        catch (IOException) { return false; }
        catch (JsonException) { return false; }
    }

    public static string NormalizeKey(string keyCode)
    {
        var normalized = (keyCode ?? string.Empty).Trim().ToUpperInvariant();
        if (!KeyPattern.IsMatch(normalized))
        {
            throw new ArgumentException("The Epson storage key must contain exactly two letters or numbers, such as 00.");
        }
        return normalized;
    }

    private static (string ContentType, string Extension) DetectImageType(byte[] bytes)
    {
        if (bytes.Length >= 8 && bytes.AsSpan(0, 8).SequenceEqual(new byte[] { 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A }))
            return ("image/png", ".png");
        if (bytes.Length >= 3 && bytes[0] == 0xFF && bytes[1] == 0xD8 && bytes[2] == 0xFF)
            return ("image/jpeg", ".jpg");
        if (bytes.Length >= 12 && bytes.AsSpan(0, 4).SequenceEqual("RIFF"u8) && bytes.AsSpan(8, 4).SequenceEqual("WEBP"u8))
            return ("image/webp", ".webp");
        throw new ArgumentException("Select a PNG, JPEG, or WebP image file.");
    }

    private static string NormalizeName(string? name, string? originalFileName, string keyCode)
    {
        var value = string.IsNullOrWhiteSpace(name) ? Path.GetFileNameWithoutExtension(originalFileName) : name.Trim();
        if (string.IsNullOrWhiteSpace(value)) value = $"Stored logo {keyCode}";
        return value.Length <= 80 ? value : value[..80];
    }

    private static StoredGraphicInfo ToInfo(StoredGraphicMetadata metadata, long size) => new(
        metadata.KeyCode,
        metadata.Name,
        metadata.OriginalFileName,
        metadata.ContentType,
        size,
        metadata.UpdatedAt,
        $"/api/stored-graphics/{Uri.EscapeDataString(metadata.KeyCode)}/content?v={metadata.UpdatedAt.ToUnixTimeMilliseconds()}");

    private sealed record StoredGraphicMetadata(
        string KeyCode,
        string Name,
        string OriginalFileName,
        string StoredFileName,
        string ContentType,
        DateTimeOffset UpdatedAt);
}
