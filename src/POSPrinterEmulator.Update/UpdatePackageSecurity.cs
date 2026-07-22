using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace POSPrinterEmulator.Update;

public static partial class UpdatePackageSecurity
{
    public static bool IsTrustedGitHubAsset(Uri uri, string extension) =>
        uri.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase) &&
        uri.Host.Equals("github.com", StringComparison.OrdinalIgnoreCase) &&
        uri.AbsolutePath.EndsWith(extension, StringComparison.OrdinalIgnoreCase) &&
        !string.IsNullOrWhiteSpace(Path.GetFileName(uri.LocalPath));

    public static string ParseSha256(string checksumDocument, string installerFileName)
    {
        foreach (var rawLine in checksumDocument.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            var line = rawLine.Trim();
            var match = Sha256Line().Match(line);
            if (!match.Success) continue;
            var namedFile = match.Groups[2].Value.TrimStart('*').Trim();
            if (namedFile.Length == 0 || Path.GetFileName(namedFile).Equals(installerFileName, StringComparison.OrdinalIgnoreCase))
                return match.Groups[1].Value.ToLowerInvariant();
        }

        throw new InvalidDataException("The release checksum file does not contain the installer SHA-256 value.");
    }

    public static async Task<string> ComputeSha256Async(string filePath, CancellationToken cancellationToken = default)
    {
        await using var input = new FileStream(filePath, FileMode.Open, FileAccess.Read, FileShare.Read,
            128 * 1024, FileOptions.Asynchronous | FileOptions.SequentialScan);
        return Convert.ToHexString(await SHA256.HashDataAsync(input, cancellationToken)).ToLowerInvariant();
    }

    public static async Task VerifySha256Async(string filePath, string expectedSha256, CancellationToken cancellationToken = default)
    {
        if (!Sha256Only().IsMatch(expectedSha256))
            throw new InvalidDataException("The expected installer checksum is invalid.");
        var actual = await ComputeSha256Async(filePath, cancellationToken);
        if (!CryptographicOperations.FixedTimeEquals(
                Convert.FromHexString(actual), Convert.FromHexString(expectedSha256)))
            throw new InvalidDataException("The downloaded installer failed SHA-256 verification and was not opened.");
    }

    public static string ResultPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator", "Updates", "last-update-result.json");

    public static void SaveResult(UpdateInstallResult result)
    {
        var path = ResultPath;
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var temporary = path + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(result, JsonOptions));
        File.Move(temporary, path, true);
    }

    public static UpdateInstallResult? TakeResult()
    {
        var path = ResultPath;
        if (!File.Exists(path)) return null;
        try { return JsonSerializer.Deserialize<UpdateInstallResult>(File.ReadAllText(path), JsonOptions); }
        finally { try { File.Delete(path); } catch { } }
    }

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    [GeneratedRegex("^([A-Fa-f0-9]{64})(?:\\s+(.+))?$")]
    private static partial Regex Sha256Line();

    [GeneratedRegex("^[A-Fa-f0-9]{64}$")]
    private static partial Regex Sha256Only();
}

public sealed record UpdateInstallResult(
    bool Success,
    string TargetVersion,
    int InstallerExitCode,
    string Message,
    string? SafetySnapshotId,
    DateTimeOffset CompletedAt);
