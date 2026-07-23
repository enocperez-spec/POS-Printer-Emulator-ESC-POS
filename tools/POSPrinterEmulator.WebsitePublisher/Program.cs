using System.Security.Cryptography;
using System.Text.Json;
using System.Xml.Linq;
using Renci.SshNet;

const string HostVariable = "PPE_SFTP_HOST";
const string UserVariable = "PPE_SFTP_USER";
const string PasswordVariable = "PPE_SFTP_PASSWORD";
const string FingerprintVariable = "PPE_SFTP_HOST_KEY_SHA256";
const string DatabaseHostVariable = "PPE_DB_HOST";
const string DatabasePortVariable = "PPE_DB_PORT";
const string DatabaseUserVariable = "PPE_DB_USER";
const string DatabasePasswordVariable = "PPE_DB_PASSWORD";
const string DatabaseNameVariable = "PPE_DB_NAME";
const string AdminUserVariable = "PPE_ADMIN_USER";
const string AdminPasswordVariable = "PPE_ADMIN_PASSWORD";
const string LicensePrivateKeyPathVariable = "PPE_LICENSE_PRIVATE_KEY_PATH";
const string GoogleVerificationVariable = "PPE_GOOGLE_SITE_VERIFICATION";
const string BingVerificationVariable = "PPE_BING_SITE_AUTH_TOKEN";
const string WebsiteBaseUrl = "https://www.posprinteremulator.com";

if (args.Length == 0 || args[0] is "-h" or "--help")
{
    Console.WriteLine("Usage:");
    Console.WriteLine("  website-publisher list [remote-directory]");
    Console.WriteLine("  website-publisher publish <local-directory> [remote-directory]");
    Console.WriteLine("  website-publisher configure <schema-file> [remote-directory]");
    Console.WriteLine("  website-publisher upload-schema <schema-file> [remote-directory]");
    Console.WriteLine("  website-publisher upload-protected <local-file> <private/remote-file> [remote-directory]");
    Console.WriteLine("  website-publisher download-protected <private/remote-file> <local-file> [remote-directory]");
    Console.WriteLine("  website-publisher configure-crm-secrets [remote-directory]");
    Console.WriteLine("  website-publisher migrate-crm <https-migration-url>");
    Console.WriteLine();
    Console.WriteLine($"Credentials are read from {HostVariable}, {UserVariable}, {PasswordVariable}, and {FingerprintVariable}.");
    return 0;
}

if (args[0].Equals("migrate-crm", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var migrationUri) ||
        migrationUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The migrate-crm command requires an HTTPS migration URL.");
    }
    await MigrateCrmAsync(migrationUri);
    return 0;
}

var host = RequiredEnvironmentVariable(HostVariable);
var username = RequiredEnvironmentVariable(UserVariable);
var password = RequiredEnvironmentVariable(PasswordVariable);
var expectedFingerprint = RequiredEnvironmentVariable(FingerprintVariable);

using var client = new SftpClient(host, 22, username, password);
client.HostKeyReceived += (_, eventArgs) =>
{
    var actual = "SHA256:" + Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
    eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
        System.Text.Encoding.ASCII.GetBytes(actual),
        System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));

    if (!eventArgs.CanTrust)
    {
        Console.Error.WriteLine($"SFTP host-key mismatch. Received {actual}");
    }
};

client.Connect();

try
{
    switch (args[0].ToLowerInvariant())
    {
        case "list":
            ListDirectory(client, args.Length > 1 ? args[1] : ".");
            break;
        case "publish":
            if (args.Length < 2)
            {
                throw new ArgumentException("The publish command requires a local source directory.");
            }

            var localDirectory = Path.GetFullPath(args[1]);
            var remoteDirectory = args.Length > 2 ? args[2] : ".";
            Publish(client, localDirectory, remoteDirectory);
            UploadWebmasterVerification(client, remoteDirectory);
            SubmitIndexNow(localDirectory);
            break;
        case "configure":
            if (args.Length < 2)
            {
                throw new ArgumentException("The configure command requires a schema file.");
            }

            Configure(client, Path.GetFullPath(args[1]), args.Length > 2 ? args[2] : ".");
            break;
        case "upload-schema":
            if (args.Length < 2)
            {
                throw new ArgumentException("The upload-schema command requires a schema file.");
            }

            UploadSchema(client, Path.GetFullPath(args[1]), args.Length > 2 ? args[2] : ".");
            break;
        case "upload-protected":
            if (args.Length < 3)
            {
                throw new ArgumentException("The upload-protected command requires a local file and a private remote path.");
            }

            UploadProtectedFile(client, Path.GetFullPath(args[1]), args[2], args.Length > 3 ? args[3] : ".");
            break;
        case "download-protected":
            if (args.Length < 3)
            {
                throw new ArgumentException("The download-protected command requires a private remote path and a local file.");
            }

            DownloadProtectedFile(client, args[1], Path.GetFullPath(args[2]), args.Length > 3 ? args[3] : ".");
            break;
        case "configure-crm-secrets":
            ConfigureCrmSecrets(client, args.Length > 1 ? args[1] : ".");
            break;
        default:
            throw new ArgumentException($"Unknown command: {args[0]}");
    }
}
finally
{
    client.Disconnect();
}

return 0;

static string RequiredEnvironmentVariable(string name) =>
    Environment.GetEnvironmentVariable(name) is { Length: > 0 } value
        ? value
        : throw new InvalidOperationException($"Required environment variable {name} is not set.");

static void ListDirectory(SftpClient client, string remoteDirectory)
{
    var resolvedDirectory = ResolveRemotePath(client, remoteDirectory);
    Console.WriteLine($"Remote directory: {resolvedDirectory}");
    foreach (var entry in client.ListDirectory(resolvedDirectory).Where(item => item.Name is not "." and not ".."))
    {
        Console.WriteLine($"{(entry.IsDirectory ? "directory" : "file"),-9} {entry.Length,12:N0}  {entry.Name}");
    }
}

static void Publish(SftpClient client, string localDirectory, string remoteDirectory)
{
    if (!Directory.Exists(localDirectory))
    {
        throw new DirectoryNotFoundException(localDirectory);
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var files = Directory.EnumerateFiles(localDirectory, "*", SearchOption.AllDirectories)
        .Where(path => !path.EndsWith("README.md", StringComparison.OrdinalIgnoreCase))
        .Where(path => !path.Contains($"{Path.DirectorySeparatorChar}.vite{Path.DirectorySeparatorChar}", StringComparison.OrdinalIgnoreCase))
        .Where(path => !IsServerOwnedPrivateFile(localDirectory, path))
        .Order(StringComparer.OrdinalIgnoreCase)
        .ToArray();

    var createdDirectories = new HashSet<string>(StringComparer.Ordinal);
    long uploadedBytes = 0;
    var skippedFiles = 0;

    foreach (var localFile in files)
    {
        var relative = Path.GetRelativePath(localDirectory, localFile).Replace('\\', '/');
        var remoteFile = CombineRemote(remoteRoot, relative);
        var parent = remoteFile[..remoteFile.LastIndexOf('/')];
        EnsureDirectory(client, parent, createdDirectories);

        using var input = File.OpenRead(localFile);
        var remoteLength = client.Exists(remoteFile) ? client.GetAttributes(remoteFile).Size : 0;
        if (remoteLength == input.Length &&
            (input.Length > 1_000_000 || RemotePrefixMatches(client, remoteFile, input, input.Length)))
        {
            Console.WriteLine($"Verified  {relative} ({input.Length:N0} bytes)");
            skippedFiles++;
            continue;
        }

        var matchingPrefixLength = remoteLength > 0 && remoteLength < input.Length
            ? RemoteMatchingPrefixLength(client, remoteFile, input, remoteLength)
            : 0;
        if (matchingPrefixLength > 0)
        {
            Console.WriteLine($"Resuming  {relative} at verified byte {matchingPrefixLength:N0} of {input.Length:N0}");
            input.Position = matchingPrefixLength;
            using var output = client.Open(remoteFile, FileMode.OpenOrCreate, FileAccess.Write);
            if (output.Length != matchingPrefixLength)
            {
                output.SetLength(matchingPrefixLength);
            }
            output.Position = matchingPrefixLength;
            input.CopyTo(output);
        }
        else
        {
            Console.WriteLine($"Uploading {relative} ({input.Length:N0} bytes)");
            client.UploadFile(input, remoteFile, true);
        }

        var remoteAttributes = client.GetAttributes(remoteFile);
        if (remoteAttributes.Size != input.Length)
        {
            throw new IOException($"Size verification failed for {relative}: local {input.Length}, remote {remoteAttributes.Size}.");
        }

        uploadedBytes += input.Length - Math.Min(remoteLength, input.Length);
    }

    Console.WriteLine($"Published {files.Length} files ({skippedFiles} already current, {uploadedBytes:N0} bytes transferred) to {remoteRoot}.");
}

static bool IsServerOwnedPrivateFile(string localRoot, string path)
{
    var relative = Path.GetRelativePath(localRoot, path).Replace('\\', '/');
    if (!relative.StartsWith("private/", StringComparison.OrdinalIgnoreCase))
    {
        return false;
    }

    var fileName = Path.GetFileName(relative);
    return !fileName.Equals(".htaccess", StringComparison.OrdinalIgnoreCase) &&
           !fileName.EndsWith(".example.php", StringComparison.OrdinalIgnoreCase);
}

static void Configure(SftpClient client, string schemaPath, string remoteDirectory)
{
    if (!File.Exists(schemaPath))
    {
        throw new FileNotFoundException("Schema file was not found.", schemaPath);
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var privateDirectory = CombineRemote(remoteRoot, "private");
    EnsureDirectory(client, privateDirectory, new HashSet<string>(StringComparer.Ordinal));

    var salt = RandomNumberGenerator.GetBytes(24);
    var passwordHash = Rfc2898DeriveBytes.Pbkdf2(
        RequiredEnvironmentVariable(AdminPasswordVariable),
        salt,
        210_000,
        HashAlgorithmName.SHA256,
        32);
    var databasePort = uint.TryParse(Environment.GetEnvironmentVariable(DatabasePortVariable), out var port) ? port : 3306;
    var config = $"""
        <?php
        declare(strict_types=1);

        return [
            'database' => [
                'host' => {PhpString(RequiredEnvironmentVariable(DatabaseHostVariable))},
                'port' => {databasePort},
                'username' => {PhpString(RequiredEnvironmentVariable(DatabaseUserVariable))},
                'password' => {PhpString(RequiredEnvironmentVariable(DatabasePasswordVariable))},
                'name' => {PhpString(Environment.GetEnvironmentVariable(DatabaseNameVariable) ?? string.Empty)},
            ],
            'admin' => [
                'username' => {PhpString(RequiredEnvironmentVariable(AdminUserVariable))},
                'salt' => {PhpString(Convert.ToBase64String(salt))},
                'password_hash' => {PhpString(Convert.ToBase64String(passwordHash))},
                'iterations' => 210000,
            ],
        ];
        """;

    UploadText(client, CombineRemote(privateDirectory, "config.php"), config);
    using var schema = File.OpenRead(schemaPath);
    client.UploadFile(schema, CombineRemote(privateDirectory, "schema.sql"), true);
    if (Environment.GetEnvironmentVariable(LicensePrivateKeyPathVariable) is { Length: > 0 } privateKeyPath)
    {
        privateKeyPath = Path.GetFullPath(privateKeyPath);
        if (!File.Exists(privateKeyPath))
        {
            throw new FileNotFoundException("The license signing key was not found.", privateKeyPath);
        }
        using var privateKey = File.OpenRead(privateKeyPath);
        client.UploadFile(privateKey, CombineRemote(privateDirectory, "vendor-private-key.pem"), true);
    }
    Console.WriteLine("Uploaded protected database configuration and schema. No secrets were written to the project directory.");
}

static void UploadSchema(SftpClient client, string schemaPath, string remoteDirectory)
{
    if (!File.Exists(schemaPath))
    {
        throw new FileNotFoundException("Schema file was not found.", schemaPath);
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var privateDirectory = CombineRemote(remoteRoot, "private");
    EnsureDirectory(client, privateDirectory, new HashSet<string>(StringComparer.Ordinal));
    using var schema = File.OpenRead(schemaPath);
    client.UploadFile(schema, CombineRemote(privateDirectory, "schema.sql"), true);
    Console.WriteLine("Uploaded the protected database schema without changing server credentials.");
}

static void UploadProtectedFile(SftpClient client, string localPath, string relativeRemotePath, string remoteDirectory)
{
    if (!File.Exists(localPath))
    {
        throw new FileNotFoundException("The protected local file was not found.", localPath);
    }

    var normalized = relativeRemotePath.Replace('\\', '/').TrimStart('/');
    var segments = normalized.Split('/', StringSplitOptions.RemoveEmptyEntries);
    if (segments.Length < 2 || !segments[0].Equals("private", StringComparison.OrdinalIgnoreCase) ||
        segments.Any(segment => segment is "." or ".."))
    {
        throw new ArgumentException("The protected remote path must stay beneath private/.", nameof(relativeRemotePath));
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var remotePath = CombineRemote(remoteRoot, string.Join('/', segments));
    var parent = remotePath[..remotePath.LastIndexOf('/')];
    EnsureDirectory(client, parent, new HashSet<string>(StringComparer.Ordinal));
    using var input = File.OpenRead(localPath);
    client.UploadFile(input, remotePath, true);
    if (client.GetAttributes(remotePath).Size != input.Length)
    {
        throw new IOException("The protected file upload could not be verified.");
    }

    Console.WriteLine($"Uploaded and size-verified protected file {normalized} ({input.Length:N0} bytes).");
}

static void DownloadProtectedFile(SftpClient client, string relativeRemotePath, string localPath, string remoteDirectory)
{
    var normalized = relativeRemotePath.Replace('\\', '/').TrimStart('/');
    var segments = normalized.Split('/', StringSplitOptions.RemoveEmptyEntries);
    if (segments.Length < 2 || !segments[0].Equals("private", StringComparison.OrdinalIgnoreCase) ||
        segments.Any(segment => segment is "." or ".."))
    {
        throw new ArgumentException("The protected remote path must stay beneath private/.", nameof(relativeRemotePath));
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var remotePath = CombineRemote(remoteRoot, string.Join('/', segments));
    if (!client.Exists(remotePath))
    {
        throw new FileNotFoundException("The protected remote file was not found.", remotePath);
    }

    Directory.CreateDirectory(Path.GetDirectoryName(localPath) ?? Directory.GetCurrentDirectory());
    using var output = File.Create(localPath);
    client.DownloadFile(remotePath, output);
    var remoteSize = client.GetAttributes(remotePath).Size;
    if (output.Length != remoteSize)
    {
        throw new IOException("The protected file download could not be verified.");
    }

    Console.WriteLine($"Downloaded and size-verified protected file {normalized} ({output.Length:N0} bytes).");
}

static void ConfigureCrmSecrets(SftpClient client, string remoteDirectory)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("CRM deployment-secret recovery uses Windows data protection.");
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    var privateDirectory = CombineRemote(remoteRoot, "private");
    EnsureDirectory(client, privateDirectory, new HashSet<string>(StringComparer.Ordinal));

    var serviceToken = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32))
        .TrimEnd('=')
        .Replace('+', '-')
        .Replace('/', '_');
    var serviceTokenHash = Convert.ToHexString(
        SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(serviceToken))).ToLowerInvariant();
    var activationKey = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
    var config = $"""
        <?php
        declare(strict_types=1);

        return [
            'service_api' => [
                'token_hash' => {PhpString(serviceTokenHash)},
            ],
            'data_protection' => [
                'activation_key_key' => {PhpString(activationKey)},
            ],
        ];
        """;
    UploadText(client, CombineRemote(privateDirectory, "crm-secrets.php"), config);

    var recovery = JsonSerializer.SerializeToUtf8Bytes(new
    {
        version = 1,
        createdAtUtc = DateTimeOffset.UtcNow,
        serviceToken,
        activationKey
    });
    var protectedRecovery = ProtectedData.Protect(recovery, null, DataProtectionScope.CurrentUser);
    var recoveryDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "deployment-secrets");
    Directory.CreateDirectory(recoveryDirectory);
    var recoveryPath = Path.Combine(recoveryDirectory, "crm-v0.3.42.secrets.bin");
    File.WriteAllBytes(recoveryPath, protectedRecovery);
    var metadataPath = Path.Combine(recoveryDirectory, "crm-v0.3.42.metadata.json");
    File.WriteAllText(metadataPath, JsonSerializer.Serialize(new
    {
        version = 1,
        createdAtUtc = DateTimeOffset.UtcNow,
        purpose = "Admin CRM service authentication and activation-key data protection",
        protectedFor = Environment.UserName,
        recoveryFile = Path.GetFileName(recoveryPath)
    }, new JsonSerializerOptions { WriteIndented = true }));

    Console.WriteLine("Uploaded protected CRM configuration. Token and encryption-key values were not displayed.");
    Console.WriteLine($"Saved an encrypted current-user recovery copy to {recoveryPath}.");
}

static async Task MigrateCrmAsync(Uri migrationUri)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("CRM deployment-secret recovery uses Windows data protection.");
    }
    var recoveryPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "deployment-secrets",
        "crm-v0.3.42.secrets.bin");
    if (!File.Exists(recoveryPath))
    {
        throw new FileNotFoundException("The protected CRM deployment-secret recovery file is unavailable.", recoveryPath);
    }
    var protectedRecovery = await File.ReadAllBytesAsync(recoveryPath);
    var recovery = ProtectedData.Unprotect(protectedRecovery, null, DataProtectionScope.CurrentUser);
    using var document = JsonDocument.Parse(recovery);
    var serviceToken = document.RootElement.GetProperty("serviceToken").GetString();
    if (string.IsNullOrWhiteSpace(serviceToken))
    {
        throw new InvalidDataException("The protected CRM service token is unavailable.");
    }

    using var request = new HttpRequestMessage(HttpMethod.Post, migrationUri);
    request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", serviceToken);
    request.Content = new StringContent("{}", System.Text.Encoding.UTF8, "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException($"CRM migration failed with HTTP {(int)response.StatusCode}.");
    }
    using var result = JsonDocument.Parse(body);
    if (!result.RootElement.TryGetProperty("ok", out var ok) || !ok.GetBoolean())
    {
        throw new InvalidDataException("The CRM migration response was invalid.");
    }
    Console.WriteLine("Protected customer CRM migration completed successfully.");
}

static void UploadText(SftpClient client, string remotePath, string content)
{
    using var stream = new MemoryStream(System.Text.Encoding.UTF8.GetBytes(content));
    client.UploadFile(stream, remotePath, true);
}

static void UploadWebmasterVerification(SftpClient client, string remoteDirectory)
{
    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }

    if (Environment.GetEnvironmentVariable(GoogleVerificationVariable) is { Length: > 0 } googleToken)
    {
        googleToken = googleToken.Trim().Replace(".html", string.Empty, StringComparison.OrdinalIgnoreCase);
        ValidateWebmasterToken(googleToken, GoogleVerificationVariable);
        UploadText(client, CombineRemote(remoteRoot, $"{googleToken}.html"), $"google-site-verification: {googleToken}.html");
        Console.WriteLine("Uploaded Google Search Console verification file.");
    }

    if (Environment.GetEnvironmentVariable(BingVerificationVariable) is { Length: > 0 } bingToken)
    {
        bingToken = bingToken.Trim();
        ValidateWebmasterToken(bingToken, BingVerificationVariable);
        var document = new XDocument(new XElement("users", new XElement("user", bingToken)));
        UploadText(client, CombineRemote(remoteRoot, "BingSiteAuth.xml"), document.ToString(SaveOptions.DisableFormatting));
        Console.WriteLine("Uploaded Bing Webmaster Tools verification file.");
    }
}

static void ValidateWebmasterToken(string token, string variableName)
{
    if (token.Length is < 8 or > 128 || token.Any(character => !char.IsAsciiLetterOrDigit(character) && character is not '_' and not '-'))
    {
        throw new InvalidOperationException($"{variableName} contains an invalid webmaster verification token.");
    }
}

static void SubmitIndexNow(string localDirectory)
{
    var keyPath = Path.Combine(localDirectory, "indexnow-key.txt");
    var sitemapPath = Path.Combine(localDirectory, "sitemap.xml");
    if (!File.Exists(keyPath) || !File.Exists(sitemapPath))
    {
        Console.WriteLine("IndexNow notification skipped because the key or sitemap is missing.");
        return;
    }

    var key = File.ReadAllText(keyPath).Trim();
    ValidateWebmasterToken(key, "indexnow-key.txt");
    var urls = XDocument.Load(sitemapPath)
        .Descendants()
        .Where(element => element.Name.LocalName == "loc")
        .Select(element => element.Value.Trim())
        .Where(url => url.StartsWith(WebsiteBaseUrl, StringComparison.OrdinalIgnoreCase))
        .Distinct(StringComparer.OrdinalIgnoreCase)
        .ToArray();
    if (urls.Length == 0)
    {
        Console.WriteLine("IndexNow notification skipped because the sitemap has no public URLs.");
        return;
    }

    var payload = JsonSerializer.Serialize(new
    {
        host = new Uri(WebsiteBaseUrl).Host,
        key,
        keyLocation = $"{WebsiteBaseUrl}/indexnow-key.txt",
        urlList = urls
    });
    using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
    using var content = new StringContent(payload, System.Text.Encoding.UTF8, "application/json");
    using var response = http.PostAsync("https://api.indexnow.org/indexnow", content).GetAwaiter().GetResult();
    if (!response.IsSuccessStatusCode && response.StatusCode != System.Net.HttpStatusCode.Accepted)
    {
        throw new HttpRequestException($"IndexNow rejected the sitemap URLs with HTTP {(int)response.StatusCode}.");
    }

    Console.WriteLine($"Submitted {urls.Length} public URLs to IndexNow (HTTP {(int)response.StatusCode}).");
}

static string PhpString(string value) =>
    "'" + value.Replace("\\", "\\\\", StringComparison.Ordinal).Replace("'", "\\'", StringComparison.Ordinal) + "'";

static void EnsureDirectory(SftpClient client, string directory, HashSet<string> createdDirectories)
{
    if (directory is "" or "/" || !createdDirectories.Add(directory))
    {
        return;
    }

    var parent = directory[..directory.LastIndexOf('/')];
    EnsureDirectory(client, parent, createdDirectories);

    if (!client.Exists(directory))
    {
        client.CreateDirectory(directory);
    }
}

static string CombineRemote(string root, string relative) =>
    root == "/" ? "/" + relative : root + "/" + relative;

static bool RemotePrefixMatches(SftpClient client, string remoteFile, FileStream localFile, long length)
    => RemoteMatchingPrefixLength(client, remoteFile, localFile, length) == length;

static long RemoteMatchingPrefixLength(SftpClient client, string remoteFile, FileStream localFile, long length)
{
    using var remoteFileStream = client.OpenRead(remoteFile);
    var localBuffer = new byte[64 * 1024];
    var remoteBuffer = new byte[64 * 1024];
    long compared = 0;

    while (compared < length)
    {
        var requested = (int)Math.Min(localBuffer.Length, length - compared);
        var localRead = localFile.Read(localBuffer, 0, requested);
        var remoteRead = remoteFileStream.Read(remoteBuffer, 0, requested);
        if (localRead != requested || remoteRead != requested ||
            !localBuffer.AsSpan(0, requested).SequenceEqual(remoteBuffer.AsSpan(0, requested)))
        {
            localFile.Position = 0;
            return compared;
        }

        compared += requested;
    }

    localFile.Position = 0;
    return compared;
}

static string ResolveRemotePath(SftpClient client, string path)
{
    if (path.StartsWith('/'))
    {
        return path;
    }

    var workingDirectory = string.IsNullOrEmpty(client.WorkingDirectory)
        ? "/"
        : client.WorkingDirectory.TrimEnd('/');
    return path is "." or "" ? workingDirectory : CombineRemote(workingDirectory, path);
}
