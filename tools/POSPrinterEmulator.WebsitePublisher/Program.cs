using System.Diagnostics;
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
const string PortalBaseUrlVariable = "PPE_PORTAL_BASE_URL";
const string PortalMailTransportVariable = "PPE_PORTAL_MAIL_TRANSPORT";
const string PortalMailFromVariable = "PPE_PORTAL_MAIL_FROM";
const string BuyBaseUrlVariable = "PPE_BUY_BASE_URL";
const string BrevoApiKeyVariable = "PPE_BREVO_API_KEY";
const string BrevoSenderEmailVariable = "PPE_BREVO_SENDER_EMAIL";
const string BrevoSenderNameVariable = "PPE_BREVO_SENDER_NAME";
const string BrevoReplyToEmailVariable = "PPE_BREVO_REPLY_TO_EMAIL";
const string BrevoModeVariable = "PPE_BREVO_MODE";
const string BrevoTestAllowlistVariable = "PPE_BREVO_TEST_ALLOWLIST";
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
    Console.WriteLine("  website-publisher configure-communications [remote-directory]");
    Console.WriteLine("  website-publisher migrate-crm <https-migration-url>");
    Console.WriteLine("  website-publisher migrate-communications <https-migration-url>");
    Console.WriteLine("  website-publisher configure-customer-portal [remote-directory]");
    Console.WriteLine("  website-publisher configure-customer-portal-from-admin <admin-remote-directory> [portal-remote-directory]");
    Console.WriteLine("  website-publisher migrate-customer-portal <https-migration-url>");
    Console.WriteLine("  website-publisher migrate-self-service-commerce <https-migration-url>");
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
if (args[0].Equals("migrate-communications", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var migrationUri) ||
        migrationUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The migrate-communications command requires an HTTPS migration URL.");
    }
    await MigrateCommunicationsAsync(migrationUri);
    return 0;
}
if (args[0].Equals("migrate-customer-portal", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var migrationUri) ||
        migrationUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The migrate-customer-portal command requires an HTTPS migration URL.");
    }
    await MigrateCustomerPortalAsync(migrationUri);
    return 0;
}
if (args[0].Equals("migrate-self-service-commerce", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var migrationUri) ||
        migrationUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The migrate-self-service-commerce command requires an HTTPS migration URL.");
    }
    await MigrateSelfServiceCommerceAsync(migrationUri);
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
        case "configure-communications":
            ConfigureCommunications(client, args.Length > 1 ? args[1] : ".");
            break;
        case "configure-customer-portal":
            ConfigureCustomerPortal(client, args.Length > 1 ? args[1] : ".");
            break;
        case "configure-customer-portal-from-admin":
            if (args.Length < 2)
            {
                throw new ArgumentException(
                    "The configure-customer-portal-from-admin command requires the Admin Portal remote directory.");
            }

            ConfigureCustomerPortalFromAdmin(
                client,
                args[1],
                args.Length > 2 ? args[2] : ".");
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

static void ConfigureCommunications(SftpClient client, string remoteDirectory)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("Communications deployment-secret recovery uses Windows data protection.");
    }
    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0) remoteRoot = "/";
    var privateDirectory = CombineRemote(remoteRoot, "private");
    EnsureDirectory(client, privateDirectory, new HashSet<string>(StringComparer.Ordinal));

    var apiKey = RequiredEnvironmentVariable(BrevoApiKeyVariable).Trim();
    if (apiKey.Length is < 32 or > 256 || apiKey.Any(char.IsWhiteSpace))
    {
        throw new InvalidOperationException($"{BrevoApiKeyVariable} is not a valid Brevo REST API key.");
    }
    var senderEmail = RequiredEnvironmentVariable(BrevoSenderEmailVariable).Trim();
    var replyToEmail = (Environment.GetEnvironmentVariable(BrevoReplyToEmailVariable) ?? senderEmail).Trim();
    if (!System.Net.Mail.MailAddress.TryCreate(senderEmail, out _) ||
        !System.Net.Mail.MailAddress.TryCreate(replyToEmail, out _))
    {
        throw new InvalidOperationException("The Brevo sender or reply-to address is invalid.");
    }
    var senderName = (Environment.GetEnvironmentVariable(BrevoSenderNameVariable) ?? "POS Printer Emulator").Trim();
    if (senderName.Length is < 2 or > 100)
    {
        throw new InvalidOperationException($"{BrevoSenderNameVariable} must contain 2 to 100 characters.");
    }
    var mode = (Environment.GetEnvironmentVariable(BrevoModeVariable) ?? "test").Trim().ToLowerInvariant();
    if (mode is not "test" and not "live" and not "disabled")
    {
        throw new InvalidOperationException($"{BrevoModeVariable} must be disabled, test, or live.");
    }
    var allowlist = (Environment.GetEnvironmentVariable(BrevoTestAllowlistVariable) ?? string.Empty)
        .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
        .Distinct(StringComparer.OrdinalIgnoreCase)
        .ToArray();
    if (allowlist.Any(address => !System.Net.Mail.MailAddress.TryCreate(address, out _)))
    {
        throw new InvalidOperationException($"{BrevoTestAllowlistVariable} contains an invalid email address.");
    }
    if (mode == "test" && allowlist.Length == 0)
    {
        throw new InvalidOperationException($"{BrevoTestAllowlistVariable} must contain at least one address in test mode.");
    }

    var recoveryDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "deployment-secrets");
    Directory.CreateDirectory(recoveryDirectory);
    var recoveryPath = Path.Combine(recoveryDirectory, "communications-v0.3.45.secrets.bin");
    string webhookToken;
    long? webhookId = null;
    if (File.Exists(recoveryPath))
    {
        var recovered = ProtectedData.Unprotect(File.ReadAllBytes(recoveryPath), null, DataProtectionScope.CurrentUser);
        using var document = JsonDocument.Parse(recovered);
        webhookToken = document.RootElement.GetProperty("webhookToken").GetString()
            ?? throw new InvalidDataException("The protected communications webhook token is unavailable.");
        if (document.RootElement.TryGetProperty("webhookId", out var storedId) && storedId.TryGetInt64(out var parsedId))
        {
            webhookId = parsedId;
        }
    }
    else
    {
        webhookToken = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32))
            .TrimEnd('=').Replace('+', '-').Replace('/', '_');
    }
    var phpAllowlist = string.Join(", ", allowlist.Select(address => PhpString(address.ToLowerInvariant())));
    var config = $"""
        <?php
        declare(strict_types=1);

        return [
            'communications' => [
                'enabled' => {PhpString(mode == "disabled" ? "0" : "1")} === '1',
                'mode' => {PhpString(mode)},
                'brevo_api_base' => 'https://api.brevo.com/v3',
                'brevo_api_key' => {PhpString(apiKey)},
                'webhook_token' => {PhpString(webhookToken)},
                'sender_email' => {PhpString(senderEmail)},
                'sender_name' => {PhpString(senderName)},
                'reply_to_email' => {PhpString(replyToEmail)},
                'reply_to_name' => {PhpString(senderName + " Support")},
                'provider_daily_limit' => 300,
                'automated_daily_limit' => 290,
                'service_reserve' => 50,
                'timezone' => 'America/New_York',
                'quiet_hours_start' => 20,
                'quiet_hours_end' => 8,
                'test_allowlist' => [{phpAllowlist}],
            ],
        ];
        """;
    webhookId = ConfigureBrevoWebhook(apiKey, webhookToken, webhookId);
    var recovery = JsonSerializer.SerializeToUtf8Bytes(new
    {
        version = 1,
        createdAtUtc = DateTimeOffset.UtcNow,
        mode,
        senderEmail,
        replyToEmail,
        webhookToken,
        webhookId
    });
    File.WriteAllBytes(recoveryPath, ProtectedData.Protect(recovery, null, DataProtectionScope.CurrentUser));
    UploadText(client, CombineRemote(privateDirectory, "communications.php"), config);
    var cron = """
        <?php
        declare(strict_types=1);

        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit;
        }
        require __DIR__ . '/../includes/bootstrap.php';
        require __DIR__ . '/../includes/communications.php';
        $pdo = database();
        communication_schedule_lifecycle($pdo);
        for ($index = 0; $index < 50; $index++) {
            $result = communication_worker_process_one($pdo);
            if ($result['status'] === 'idle') break;
        }
        """;
    UploadText(client, CombineRemote(privateDirectory, "communications-cron.php"), cron);

    Console.WriteLine("Uploaded protected Brevo communications configuration. Provider credentials were not displayed.");
    Console.WriteLine($"Saved encrypted webhook recovery metadata to {recoveryPath}.");
}

static long ConfigureBrevoWebhook(string apiKey, string webhookToken, long? existingId)
{
    var endpoint = existingId is > 0
        ? new Uri($"https://api.brevo.com/v3/webhooks/{existingId.Value}")
        : new Uri("https://api.brevo.com/v3/webhooks");
    var payload = JsonSerializer.Serialize(new
    {
        description = "POS Printer Emulator transactional delivery events",
        url = "https://admin.posprinteremulator.com/api/v1/brevo-webhook.php",
        events = new[] { "sent", "delivered", "hardBounce", "softBounce", "blocked", "spam", "invalid", "deferred", "click", "opened", "unsubscribed" },
        type = "transactional",
        auth = new { type = "bearer", token = webhookToken },
        batched = false
    });
    using var request = new HttpRequestMessage(existingId is > 0 ? HttpMethod.Put : HttpMethod.Post, endpoint);
    request.Headers.Add("api-key", apiKey);
    request.Headers.Accept.ParseAdd("application/json");
    request.Content = new StringContent(payload, System.Text.Encoding.UTF8, "application/json");
    using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
    using var response = http.Send(request);
    var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException($"Brevo webhook configuration failed with HTTP {(int)response.StatusCode}.");
    }
    if (existingId is > 0) return existingId.Value;
    using var result = JsonDocument.Parse(body);
    if (!result.RootElement.TryGetProperty("id", out var id) || !id.TryGetInt64(out var createdId) || createdId < 1)
    {
        throw new InvalidDataException("Brevo did not return a valid webhook identifier.");
    }
    return createdId;
}

static void ConfigureCustomerPortal(SftpClient client, string remoteDirectory)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("Customer Portal deployment-secret recovery uses Windows data protection.");
    }

    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0)
    {
        remoteRoot = "/";
    }
    var privateDirectory = CombineRemote(remoteRoot, "private");
    EnsureDirectory(client, privateDirectory, new HashSet<string>(StringComparer.Ordinal));

    var serviceToken = RecoverCrmServiceToken();
    var recoveryDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "deployment-secrets");
    Directory.CreateDirectory(recoveryDirectory);
    var recoveryPath = Path.Combine(recoveryDirectory, "customer-portal-v0.3.43.secrets.bin");
    string encryptionKey;
    if (File.Exists(recoveryPath))
    {
        var protectedRecovery = File.ReadAllBytes(recoveryPath);
        var recovery = ProtectedData.Unprotect(protectedRecovery, null, DataProtectionScope.CurrentUser);
        using var document = JsonDocument.Parse(recovery);
        encryptionKey = document.RootElement.GetProperty("encryptionKey").GetString()
            ?? throw new InvalidDataException("The protected Customer Portal encryption key is unavailable.");
    }
    else
    {
        encryptionKey = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
        var recovery = JsonSerializer.SerializeToUtf8Bytes(new
        {
            version = 1,
            createdAtUtc = DateTimeOffset.UtcNow,
            encryptionKey
        });
        File.WriteAllBytes(
            recoveryPath,
            ProtectedData.Protect(recovery, null, DataProtectionScope.CurrentUser));
    }

    var databasePort = uint.TryParse(Environment.GetEnvironmentVariable(DatabasePortVariable), out var port) ? port : 3306;
    var baseUrl = Environment.GetEnvironmentVariable(PortalBaseUrlVariable) ?? "https://userportal.posprinteremulator.com";
    if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var baseUri) ||
        baseUri.Scheme != Uri.UriSchemeHttps ||
        !string.IsNullOrEmpty(baseUri.Query) ||
        !string.IsNullOrEmpty(baseUri.Fragment))
    {
        throw new InvalidOperationException($"{PortalBaseUrlVariable} must be a canonical HTTPS URL.");
    }
    var mailTransport = (Environment.GetEnvironmentVariable(PortalMailTransportVariable) ?? "php_mail").ToLowerInvariant();
    if (mailTransport is not "php_mail" and not "outbox")
    {
        throw new InvalidOperationException($"{PortalMailTransportVariable} must be php_mail or outbox.");
    }
    var mailFrom = Environment.GetEnvironmentVariable(PortalMailFromVariable) ?? "support@posprinteremulator.com";
    if (!System.Net.Mail.MailAddress.TryCreate(mailFrom, out _))
    {
        throw new InvalidOperationException("Customer Portal sender addresses are invalid.");
    }
    var buyBaseUrl = Environment.GetEnvironmentVariable(BuyBaseUrlVariable) ?? "https://buy.posprinteremulator.com";
    if (!Uri.TryCreate(buyBaseUrl, UriKind.Absolute, out var buyUri) ||
        buyUri.Scheme != Uri.UriSchemeHttps ||
        !string.IsNullOrEmpty(buyUri.Query) ||
        !string.IsNullOrEmpty(buyUri.Fragment))
    {
        throw new InvalidOperationException($"{BuyBaseUrlVariable} must be a canonical HTTPS URL.");
    }

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
            'portal' => [
                'base_url' => {PhpString(baseUri.GetLeftPart(UriPartial.Path).TrimEnd('/'))},
                'encryption_key' => {PhpString(encryptionKey)},
                'mail_transport' => {PhpString(mailTransport)},
                'mail_from' => {PhpString(mailFrom)},
                'support_url' => 'https://www.posprinteremulator.com/how-to-submit-a-support-request',
                'support_backend_url' => 'https://admin.posprinteremulator.com/api/v1/portal-support.php',
                'support_backend_token' => {PhpString(serviceToken)},
                'communications_worker_url' => 'https://admin.posprinteremulator.com/api/v1/communications-worker.php?max=5',
                'promotion_backend_url' => 'https://admin.posprinteremulator.com/api/v1/portal-promotion.php',
                'buy_base_url' => {PhpString(buyUri.GetLeftPart(UriPartial.Path).TrimEnd('/'))},
            ],
        ];
        """;
    UploadText(client, CombineRemote(privateDirectory, "config.php"), config);
    Console.WriteLine("Uploaded protected Customer Portal configuration. No database, encryption, or service credentials were displayed.");
    Console.WriteLine($"Reused or saved an encrypted current-user recovery copy at {recoveryPath}.");
}

static void ConfigureCustomerPortalFromAdmin(
    SftpClient client,
    string adminRemoteDirectory,
    string portalRemoteDirectory)
{
    var adminRoot = ResolveRemotePath(client, adminRemoteDirectory).TrimEnd('/');
    if (adminRoot.Length == 0)
    {
        adminRoot = "/";
    }

    var remoteConfigPath = CombineRemote(adminRoot, "private/config.php");
    if (!client.Exists(remoteConfigPath))
    {
        throw new FileNotFoundException(
            "The protected Admin Portal database configuration is unavailable.",
            remoteConfigPath);
    }

    var temporaryConfigPath = Path.Combine(
        Path.GetTempPath(),
        $"ppe-admin-config-{Guid.NewGuid():N}.php");
    try
    {
        using (var output = new FileStream(
                   temporaryConfigPath,
                   FileMode.CreateNew,
                   FileAccess.Write,
                   FileShare.None,
                   4096,
                   FileOptions.WriteThrough))
        {
            client.DownloadFile(remoteConfigPath, output);
        }

        var startInfo = new ProcessStartInfo
        {
            FileName = "php",
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true
        };
        startInfo.ArgumentList.Add("-r");
        startInfo.ArgumentList.Add(
            "$c=require $argv[1]; echo json_encode($c['database'], JSON_THROW_ON_ERROR);");
        startInfo.ArgumentList.Add(temporaryConfigPath);

        using var process = Process.Start(startInfo)
            ?? throw new InvalidOperationException("Could not start PHP to read the protected Admin configuration.");
        var databaseJson = process.StandardOutput.ReadToEnd();
        var error = process.StandardError.ReadToEnd();
        process.WaitForExit();
        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException(
                $"Could not read the protected Admin database configuration: {error.Trim()}");
        }

        using var database = JsonDocument.Parse(databaseJson);
        var root = database.RootElement;
        var values = new Dictionary<string, string?>
        {
            [DatabaseHostVariable] = root.GetProperty("host").GetString(),
            [DatabasePortVariable] = root.TryGetProperty("port", out var port)
                ? port.ToString()
                : "3306",
            [DatabaseUserVariable] = root.GetProperty("username").GetString(),
            [DatabasePasswordVariable] = root.GetProperty("password").GetString(),
            [DatabaseNameVariable] = root.GetProperty("name").GetString()
        };
        var previousValues = values.Keys.ToDictionary(
            name => name,
            Environment.GetEnvironmentVariable,
            StringComparer.Ordinal);
        try
        {
            foreach (var (name, value) in values)
            {
                Environment.SetEnvironmentVariable(name, value);
            }

            ConfigureCustomerPortal(client, portalRemoteDirectory);
        }
        finally
        {
            foreach (var (name, value) in previousValues)
            {
                Environment.SetEnvironmentVariable(name, value);
            }
        }
    }
    finally
    {
        if (File.Exists(temporaryConfigPath))
        {
            File.Delete(temporaryConfigPath);
        }
    }
}

static async Task MigrateCrmAsync(Uri migrationUri)
{
    var serviceToken = RecoverCrmServiceToken();
    await RunProtectedMigrationAsync(migrationUri, serviceToken, "CRM");
    Console.WriteLine("Protected customer CRM migration completed successfully.");
}

static async Task MigrateCommunicationsAsync(Uri migrationUri)
{
    var serviceToken = RecoverCrmServiceToken();
    await RunProtectedMigrationAsync(migrationUri, serviceToken, "communications");
    Console.WriteLine("Protected communications migration completed successfully.");
}

static async Task MigrateCustomerPortalAsync(Uri migrationUri)
{
    var serviceToken = RecoverCrmServiceToken();
    await RunProtectedMigrationAsync(migrationUri, serviceToken, "Customer Portal");
    Console.WriteLine("Protected Customer Portal migration completed successfully.");
}

static async Task MigrateSelfServiceCommerceAsync(Uri migrationUri)
{
    var serviceToken = RecoverCrmServiceToken();
    await RunProtectedMigrationAsync(migrationUri, serviceToken, "self-service commerce");
    Console.WriteLine("Protected self-service commerce migration completed successfully.");
}

static string RecoverCrmServiceToken()
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
    var protectedRecovery = File.ReadAllBytes(recoveryPath);
    var recovery = ProtectedData.Unprotect(protectedRecovery, null, DataProtectionScope.CurrentUser);
    using var document = JsonDocument.Parse(recovery);
    var serviceToken = document.RootElement.GetProperty("serviceToken").GetString();
    if (string.IsNullOrWhiteSpace(serviceToken))
    {
        throw new InvalidDataException("The protected CRM service token is unavailable.");
    }
    return serviceToken;
}

static async Task RunProtectedMigrationAsync(Uri migrationUri, string serviceToken, string name)
{
    using var request = new HttpRequestMessage(HttpMethod.Post, migrationUri);
    request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", serviceToken);
    request.Content = new StringContent("{}", System.Text.Encoding.UTF8, "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException($"{name} migration failed with HTTP {(int)response.StatusCode}.");
    }
    using var result = JsonDocument.Parse(body);
    if (!result.RootElement.TryGetProperty("ok", out var ok) || !ok.GetBoolean())
    {
        throw new InvalidDataException($"The {name} migration response was invalid.");
    }
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
