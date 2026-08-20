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
const string DeploymentProfileVariable = "PPE_DEPLOYMENT_PROFILE";
const string AdminBaseUrlVariable = "PPE_ADMIN_BASE_URL";
const string SupportBaseUrlVariable = "PPE_SUPPORT_BASE_URL";
const string PayPalClientIdVariable = "PPE_PAYPAL_CLIENT_ID";
const string PayPalSecretVariable = "PPE_PAYPAL_SECRET";
const string PayPalBaseUrlVariable = "PPE_PAYPAL_BASE_URL";
const string PayPalWebhookIdVariable = "PPE_PAYPAL_WEBHOOK_ID";
const string RolloutReadinessReportVariable = "PPE_ROLLOUT_READINESS_REPORT";
const string WebsiteBaseUrl = "https://www.posprinteremulator.com";

if (args.Length == 0 || args[0] is "-h" or "--help")
{
    Console.WriteLine("Usage:");
    Console.WriteLine("  website-publisher list [remote-directory]");
    Console.WriteLine("  website-publisher download <remote-file> <local-file>");
    Console.WriteLine("  website-publisher upload <local-file> <remote-file>");
    Console.WriteLine("  website-publisher upload-sandbox <local-file> <remote-file>");
    Console.WriteLine("  website-publisher delete-file <remote-file>");
    Console.WriteLine("  website-publisher delete-sandbox-file <remote-file>");
    Console.WriteLine("  website-publisher fetch-sandbox-release <github-release-url> <remote-file>");
    Console.WriteLine("  website-publisher publish <local-directory> [remote-directory]");
    Console.WriteLine("  website-publisher publish-sandbox <local-directory> <remote-directory>");
    Console.WriteLine("  website-publisher configure <schema-file> [remote-directory]");
    Console.WriteLine("  website-publisher configure-recovered <schema-file> [remote-directory]");
    Console.WriteLine("  website-publisher upload-schema <schema-file> [remote-directory]");
    Console.WriteLine("  website-publisher upload-protected <local-file> <private/remote-file> [remote-directory]");
    Console.WriteLine("  website-publisher download-protected <private/remote-file> <local-file> [remote-directory]");
    Console.WriteLine("  website-publisher configure-crm-secrets [remote-directory]");
    Console.WriteLine("  website-publisher configure-communications [remote-directory]");
    Console.WriteLine("  website-publisher set-communications-test-allowlist <email> [remote-directory]");
    Console.WriteLine("  website-publisher migrate-crm <https-migration-url>");
    Console.WriteLine("  website-publisher migrate-communications <https-migration-url>");
    Console.WriteLine("  website-publisher configure-customer-portal [remote-directory]");
    Console.WriteLine("  website-publisher configure-customer-portal-from-admin <admin-remote-directory> [portal-remote-directory]");
    Console.WriteLine("  website-publisher configure-purchase-integration <admin-remote-directory> <buy-remote-directory>");
    Console.WriteLine("  website-publisher reconcile-sandbox-paypal-reversal <https-admin-url> <event-id> <event-type> <refund|chargeback> <order-id|-> <capture-id|->");
    Console.WriteLine("  website-publisher migrate-customer-portal <https-migration-url>");
    Console.WriteLine("  website-publisher migrate-self-service-commerce <https-migration-url>");
    Console.WriteLine("  website-publisher migrate-schema-recovered <https-setup-url>");
    Console.WriteLine("  website-publisher seed-certification-recovered <https-setup-url>");
    Console.WriteLine("  website-publisher portal-diagnostics <https-diagnostics-url> <email>");
    Console.WriteLine("  website-publisher run-communications-worker <https-worker-url> [maximum]");
    Console.WriteLine("  website-publisher run-sandbox-communications-cron <admin-sandbox-remote-directory>");
    Console.WriteLine("  website-publisher diagnose-sandbox-communications-cron <admin-sandbox-remote-directory>");
    Console.WriteLine("  website-publisher queue-sandbox-communications-test <admin-sandbox-remote-directory> <customer-id> <template-key>");
    Console.WriteLine("  website-publisher inspect-sandbox-communications-message <admin-sandbox-remote-directory> <message-id>");
    Console.WriteLine("  website-publisher sync-sandbox-communication-template <https-sync-url> <template-key>");
    Console.WriteLine("  website-publisher sandbox-reset-checkout-rate <portal-remote-directory> <email>");
    Console.WriteLine("  website-publisher sandbox-set-maintenance-expiration <admin-sandbox-remote-directory> <license-id> <yyyy-MM-dd|yyyy-MM-ddTHH:mm:ss>");
    Console.WriteLine("  website-publisher sync-license-catalog [repository-root]");
    Console.WriteLine();
    Console.WriteLine($"Credentials are read from {HostVariable}, {UserVariable}, {PasswordVariable}, and {FingerprintVariable}.");
    Console.WriteLine($"Production mutations additionally require a passing report in {RolloutReadinessReportVariable}.");
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
if (args[0].Equals("reconcile-sandbox-paypal-reversal", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 7 || !Uri.TryCreate(args[1], UriKind.Absolute, out var adminUri) ||
        adminUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException(
            "The sandbox PayPal reconciliation command requires an HTTPS Admin URL and complete event identifiers.");
    }
    await ReconcileSandboxPayPalReversalAsync(
        adminUri,
        args[2],
        args[3],
        args[4],
        args[5] == "-" ? string.Empty : args[5],
        args[6] == "-" ? string.Empty : args[6]);
    return 0;
}
if (args[0].Equals("migrate-schema-recovered", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var setupUri) ||
        setupUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The migrate-schema-recovered command requires an HTTPS setup URL.");
    }
    await MigrateSchemaWithRecoveredAdminAsync(setupUri);
    return 0;
}
if (args[0].Equals("seed-certification-recovered", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var setupUri) ||
        setupUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The seed-certification-recovered command requires an HTTPS setup URL.");
    }
    await SeedCertificationWithRecoveredAdminAsync(setupUri);
    return 0;
}
if (args[0].Equals("portal-diagnostics", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 3 || !Uri.TryCreate(args[1], UriKind.Absolute, out var diagnosticsUri) ||
        diagnosticsUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The portal-diagnostics command requires an HTTPS diagnostics URL and email.");
    }
    await RunPortalDiagnosticsAsync(diagnosticsUri, args[2]);
    return 0;
}
if (args[0].Equals("sync-license-catalog", StringComparison.OrdinalIgnoreCase))
{
    SyncLicenseCatalog(args.Length > 1 ? args[1] : Directory.GetCurrentDirectory());
    return 0;
}
if (args[0].Equals("run-communications-worker", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var workerUri) ||
        workerUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The run-communications-worker command requires an HTTPS worker URL.");
    }
    var maximum = args.Length > 2 && int.TryParse(args[2], out var requestedMaximum)
        ? Math.Clamp(requestedMaximum, 1, 50)
        : 10;
    await RunCommunicationsWorkerAsync(workerUri, maximum);
    return 0;
}
if (args[0].Equals("sync-sandbox-communication-template", StringComparison.OrdinalIgnoreCase))
{
    if (args.Length < 3 || !Uri.TryCreate(args[1], UriKind.Absolute, out var synchronizationUri) ||
        synchronizationUri.Scheme != Uri.UriSchemeHttps ||
        !synchronizationUri.Host.Contains("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new ArgumentException(
            "The sync-sandbox-communication-template command requires an HTTPS sandbox URL and template key.");
    }
    await SynchronizeSandboxCommunicationTemplateAsync(synchronizationUri, args[2]);
    return 0;
}

var productionMutationCommands = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
{
    "upload",
    "delete-file",
    "publish",
    "configure",
    "configure-recovered",
    "upload-schema",
    "upload-protected",
    "configure-crm-secrets",
    "configure-communications",
    "set-communications-test-allowlist",
    "configure-customer-portal",
    "configure-customer-portal-from-admin",
    "configure-purchase-integration"
};
if (productionMutationCommands.Contains(args[0]) &&
    DeploymentProfile().Equals("production", StringComparison.OrdinalIgnoreCase))
{
    RequireProductionReadiness();
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
        case "download":
            if (args.Length < 3)
            {
                throw new ArgumentException("The download command requires a remote file and local destination.");
            }
            DownloadFile(client, args[1], Path.GetFullPath(args[2]));
            break;
        case "upload":
            if (args.Length < 3)
            {
                throw new ArgumentException("The upload command requires a local file and remote destination.");
            }
            UploadFile(client, Path.GetFullPath(args[1]), args[2]);
            break;
        case "upload-sandbox":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The upload-sandbox command requires a local file and sandbox remote destination.");
            }
            UploadSandboxFile(client, Path.GetFullPath(args[1]), args[2]);
            break;
        case "delete-file":
            if (args.Length < 2)
            {
                throw new ArgumentException("The delete-file command requires one remote file.");
            }
            DeleteRemoteFile(client, args[1]);
            break;
        case "delete-sandbox-file":
            if (args.Length < 2)
            {
                throw new ArgumentException("The delete-sandbox-file command requires one sandbox remote file.");
            }
            DeleteSandboxFile(client, args[1]);
            break;
        case "fetch-sandbox-release":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The fetch-sandbox-release command requires a GitHub release URL and sandbox remote file.");
            }
            FetchSandboxRelease(
                client,
                host,
                username,
                password,
                expectedFingerprint,
                args[1],
                args[2]);
            break;
        case "sandbox-reset-checkout-rate":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The sandbox-reset-checkout-rate command requires a sandbox portal directory and email.");
            }
            ResetSandboxCheckoutRate(
                client,
                host,
                username,
                password,
                expectedFingerprint,
                args[1],
                args[2]);
            break;
        case "sandbox-set-maintenance-expiration":
            if (args.Length < 4)
            {
                throw new ArgumentException(
                    "The sandbox-set-maintenance-expiration command requires the Admin sandbox directory, license ID, and UTC date or timestamp.");
            }
            SetSandboxMaintenanceExpiration(
                host,
                username,
                password,
                expectedFingerprint,
                args[1],
                args[2],
                args[3]);
            break;
        case "run-sandbox-communications-cron":
            if (args.Length < 2)
            {
                throw new ArgumentException(
                    "The run-sandbox-communications-cron command requires the Admin sandbox remote directory.");
            }
            RunSandboxCommunicationsCron(
                host,
                username,
                password,
                expectedFingerprint,
                args[1]);
            break;
        case "diagnose-sandbox-communications-cron":
            if (args.Length < 2)
            {
                throw new ArgumentException(
                    "The diagnose-sandbox-communications-cron command requires the Admin sandbox remote directory.");
            }
            DiagnoseSandboxCommunicationsCron(
                host,
                username,
                password,
                expectedFingerprint,
                args[1]);
            break;
        case "queue-sandbox-communications-test":
            if (args.Length < 4)
            {
                throw new ArgumentException(
                    "The queue-sandbox-communications-test command requires the Admin sandbox directory, customer ID, and template key.");
            }
            QueueSandboxCommunicationsTest(
                host,
                username,
                password,
                expectedFingerprint,
                args[1],
                args[2],
                args[3]);
            break;
        case "inspect-sandbox-communications-message":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The inspect-sandbox-communications-message command requires the Admin sandbox directory and message ID.");
            }
            InspectSandboxCommunicationsMessage(
                host,
                username,
                password,
                expectedFingerprint,
                args[1],
                args[2]);
            break;
        case "publish":
            if (args.Length < 2)
            {
                throw new ArgumentException("The publish command requires a local source directory.");
            }

            var localDirectory = Path.GetFullPath(args[1]);
            var remoteDirectory = args.Length > 2 ? args[2] : ".";
            Publish(client, localDirectory, remoteDirectory, false);
            UploadWebmasterVerification(client, remoteDirectory);
            SubmitIndexNow(localDirectory);
            break;
        case "publish-sandbox":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The publish-sandbox command requires local and remote directories.");
            }
            Publish(client, Path.GetFullPath(args[1]), args[2], true);
            break;
        case "configure":
            if (args.Length < 2)
            {
                throw new ArgumentException("The configure command requires a schema file.");
            }

            Configure(client, Path.GetFullPath(args[1]), args.Length > 2 ? args[2] : ".");
            break;
        case "configure-recovered":
            if (args.Length < 2)
            {
                throw new ArgumentException("The configure-recovered command requires a schema file.");
            }
            if (DeploymentProfile().Equals("production", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    "Recovered configuration is restricted to a named non-production deployment profile.");
            }
            var recoveredAdmin = RecoverAdminCredentials();
            Environment.SetEnvironmentVariable(AdminUserVariable, recoveredAdmin.Username);
            Environment.SetEnvironmentVariable(AdminPasswordVariable, recoveredAdmin.Password);
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
        case "set-communications-test-allowlist":
            if (args.Length < 2)
            {
                throw new ArgumentException("The set-communications-test-allowlist command requires an email address.");
            }
            SetCommunicationsTestAllowlist(client, args[1], args.Length > 2 ? args[2] : ".");
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
        case "configure-purchase-integration":
            if (args.Length < 3)
            {
                throw new ArgumentException(
                    "The configure-purchase-integration command requires Admin and Buy remote directories.");
            }
            ConfigurePurchaseIntegration(client, args[1], args[2]);
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

static string DeploymentProfile()
{
    var profile = (Environment.GetEnvironmentVariable(DeploymentProfileVariable) ?? "production")
        .Trim()
        .ToLowerInvariant();
    if (!System.Text.RegularExpressions.Regex.IsMatch(profile, "^[a-z0-9][a-z0-9_-]{0,31}$"))
    {
        throw new InvalidOperationException(
            $"{DeploymentProfileVariable} must contain only letters, numbers, underscores, or hyphens.");
    }
    return profile;
}

static string DeploymentRecoveryPath(string fileName)
{
    var directory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "deployment-secrets");
    Directory.CreateDirectory(directory);
    return Path.Combine(directory, $"{DeploymentProfile()}-{fileName}");
}

static void RequireProductionReadiness()
{
    var reportPath = Environment.GetEnvironmentVariable(RolloutReadinessReportVariable);
    if (string.IsNullOrWhiteSpace(reportPath))
    {
        throw new InvalidOperationException(
            $"Production changes are blocked until {RolloutReadinessReportVariable} points to a passing rollout-verify report.json.");
    }
    reportPath = Path.GetFullPath(reportPath);
    if (!File.Exists(reportPath))
    {
        throw new FileNotFoundException("The production-readiness report was not found.", reportPath);
    }

    using var document = JsonDocument.Parse(File.ReadAllText(reportPath));
    var root = document.RootElement;
    var status = root.GetProperty("Status").GetString();
    var environment = root.GetProperty("Environment").GetString();
    var commit = root.GetProperty("RepositoryCommit").GetString();
    var completedAt = root.GetProperty("CompletedAtUtc").GetDateTimeOffset();
    if (!string.Equals(status, "Passed", StringComparison.Ordinal) ||
        !string.Equals(environment, "production-readiness", StringComparison.Ordinal))
    {
        throw new InvalidDataException(
            "The supplied report is not a passing production-readiness report.");
    }
    if (DateTimeOffset.UtcNow - completedAt > TimeSpan.FromHours(24) ||
        completedAt > DateTimeOffset.UtcNow.AddMinutes(5))
    {
        throw new InvalidDataException(
            "The production-readiness report is stale or has an invalid completion time.");
    }
    var currentCommit = RunGitValue("rev-parse", "HEAD");
    if (string.IsNullOrWhiteSpace(commit) ||
        !string.Equals(commit, currentCommit, StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidDataException(
            "The production-readiness report is bound to a different Git commit.");
    }
    var readinessPassed = root.GetProperty("Checks")
        .EnumerateArray()
        .Any(check =>
            check.GetProperty("Name").GetString() == "Sandbox rollout production readiness" &&
            check.GetProperty("Status").GetString() == "Passed");
    if (!readinessPassed)
    {
        throw new InvalidDataException(
            "The production-readiness report does not contain a passing rollout check.");
    }
    Console.WriteLine(
        $"Accepted production-readiness report for commit {currentCommit} completed {completedAt:O}.");
}

static string RunGitValue(params string[] arguments)
{
    using var process = new Process
    {
        StartInfo = new ProcessStartInfo("git")
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true
        }
    };
    process.StartInfo.WorkingDirectory = Directory.GetCurrentDirectory();
    foreach (var argument in arguments)
    {
        process.StartInfo.ArgumentList.Add(argument);
    }
    process.Start();
    var output = process.StandardOutput.ReadToEnd();
    process.WaitForExit();
    if (process.ExitCode != 0)
    {
        throw new InvalidOperationException("Git repository state could not be determined.");
    }
    return output.Trim();
}

static void SyncLicenseCatalog(string repositoryRoot)
{
    var root = Path.GetFullPath(repositoryRoot);
    var source = Path.Combine(root, "website", "license-catalog.json");
    if (!File.Exists(source))
    {
        throw new FileNotFoundException("The canonical website/license-catalog.json file was not found.", source);
    }

    using var catalog = JsonDocument.Parse(File.ReadAllBytes(source));
    var rootElement = catalog.RootElement;
    if (!rootElement.TryGetProperty("sourceOfTruth", out var sourceOfTruth) ||
        !sourceOfTruth.TryGetProperty("applicationVersion", out var version) ||
        string.IsNullOrWhiteSpace(version.GetString()) ||
        !rootElement.TryGetProperty("licenses", out var licenses) ||
        licenses.ValueKind != JsonValueKind.Object ||
        !rootElement.TryGetProperty("features", out var matrix) ||
        matrix.ValueKind != JsonValueKind.Array)
    {
        throw new InvalidDataException("The canonical license catalog is missing required sourceOfTruth.applicationVersion, licenses, or features data.");
    }

    var bytes = File.ReadAllBytes(source);
    var destinations = new[]
    {
        Path.Combine(root, "buy-website", "assets", "license-catalog.json"),
        Path.Combine(root, "customer-portal", "assets", "license-catalog.json")
    };

    foreach (var destination in destinations)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        File.WriteAllBytes(destination, bytes);
        Console.WriteLine($"Synchronized {Path.GetRelativePath(root, destination)} (catalog {version.GetString()}).");
    }
}

static void ListDirectory(SftpClient client, string remoteDirectory)
{
    var resolvedDirectory = ResolveRemotePath(client, remoteDirectory);
    Console.WriteLine($"Remote directory: {resolvedDirectory}");
    foreach (var entry in client.ListDirectory(resolvedDirectory).Where(item => item.Name is not "." and not ".."))
    {
        Console.WriteLine($"{(entry.IsDirectory ? "directory" : "file"),-9} {entry.Length,12:N0}  {entry.Name}");
    }
}

static void Publish(
    SftpClient client,
    string localDirectory,
    string remoteDirectory,
    bool sandbox)
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
        .Where(path => !IsGeneratedReleaseDownload(localDirectory, path))
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

        using var input = OpenDeploymentContent(localFile, sandbox);
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

    if (File.Exists(Path.Combine(localDirectory, "sitemap.xml")))
    {
        PublishExtensionlessHtmlAliases(client, localDirectory, remoteRoot, sandbox);
    }

    Console.WriteLine($"Published {files.Length} files ({skippedFiles} already current, {uploadedBytes:N0} bytes transferred) to {remoteRoot}.");
}

static void PublishExtensionlessHtmlAliases(
    SftpClient client,
    string localDirectory,
    string remoteRoot,
    bool sandbox)
{
    foreach (var localFile in Directory.EnumerateFiles(localDirectory, "*.html", SearchOption.TopDirectoryOnly)
                 .Where(path => !Path.GetFileName(path).Equals("index.html", StringComparison.OrdinalIgnoreCase))
                 .Order(StringComparer.OrdinalIgnoreCase))
    {
        var aliasName = Path.GetFileNameWithoutExtension(localFile);
        var remoteAlias = CombineRemote(remoteRoot, aliasName);
        using var input = OpenDeploymentContent(localFile, sandbox);
        var matches = client.Exists(remoteAlias) &&
                      client.GetAttributes(remoteAlias).Size == input.Length &&
                      RemotePrefixMatches(client, remoteAlias, input, input.Length);
        if (matches)
        {
            Console.WriteLine($"Verified  {aliasName} (extensionless alias, {input.Length:N0} bytes)");
            continue;
        }
        input.Position = 0;
        client.UploadFile(input, remoteAlias, true);
        if (client.GetAttributes(remoteAlias).Size != input.Length)
        {
            throw new IOException($"Size verification failed for extensionless alias {aliasName}.");
        }
        Console.WriteLine($"Uploaded  {aliasName} (extensionless alias, {input.Length:N0} bytes)");
    }
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
            'environment' => {PhpString(DeploymentProfile())},
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
    if (!DeploymentProfile().Equals("production", StringComparison.OrdinalIgnoreCase))
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException(
                "Non-production Admin credential recovery uses Windows data protection.");
        }
        var recovery = JsonSerializer.SerializeToUtf8Bytes(new
        {
            version = 1,
            createdAtUtc = DateTimeOffset.UtcNow,
            profile = DeploymentProfile(),
            username = RequiredEnvironmentVariable(AdminUserVariable),
            password = RequiredEnvironmentVariable(AdminPasswordVariable)
        });
        File.WriteAllBytes(
            DeploymentRecoveryPath("admin-access.secrets.bin"),
            ProtectedData.Protect(recovery, null, DataProtectionScope.CurrentUser));
    }
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
    var recoveryPath = DeploymentRecoveryPath("crm-v0.3.42.secrets.bin");
    File.WriteAllBytes(recoveryPath, protectedRecovery);
    var metadataPath = DeploymentRecoveryPath("crm-v0.3.42.metadata.json");
    File.WriteAllText(metadataPath, JsonSerializer.Serialize(new
    {
        version = 1,
        createdAtUtc = DateTimeOffset.UtcNow,
        purpose = "Admin CRM service authentication and protected legacy-record migration",
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

    var mode = (Environment.GetEnvironmentVariable(BrevoModeVariable) ?? "test").Trim().ToLowerInvariant();
    if (mode is not "test" and not "live" and not "disabled")
    {
        throw new InvalidOperationException($"{BrevoModeVariable} must be disabled, test, or live.");
    }
    var apiKey = (Environment.GetEnvironmentVariable(BrevoApiKeyVariable) ?? string.Empty).Trim();
    if (mode != "disabled" &&
        (apiKey.Length is < 32 or > 256 || apiKey.Any(char.IsWhiteSpace)))
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

    var recoveryPath = DeploymentRecoveryPath("communications-v0.3.45.secrets.bin");
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
                'service_sender_email' => 'info@buy.posprinteremulator.com',
                'sales_sender_email' => 'sales@buy.posprinteremulator.com',
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
    if (mode != "disabled")
    {
        webhookId = ConfigureBrevoWebhook(apiKey, webhookToken, webhookId);
    }
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

        $scheduledInvocation = getenv('PPE_COMMUNICATIONS_CRON') === '1';
        if (PHP_SAPI !== 'cli' && !$scheduledInvocation) {
            http_response_code(404);
            exit;
        }
        $statusPath = __DIR__ . '/communications-cron-status.json';
        $status = [
            'version' => 1,
            'started_at_utc' => gmdate('c'),
            'completed_at_utc' => null,
            'completed' => false,
            'outcomes' => [],
            'error_class' => null,
        ];
        $writeStatus = static function () use (&$status, $statusPath): void {
            $encoded = json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            file_put_contents($statusPath, $encoded . PHP_EOL, LOCK_EX);
        };
        try {
            $writeStatus();
            require __DIR__ . '/../includes/bootstrap.php';
            require __DIR__ . '/../includes/communications.php';
            $pdo = database();
            communication_schedule_lifecycle($pdo);
            for ($index = 0; $index < 50; $index++) {
                $result = communication_worker_process_one($pdo);
                $outcome = (string)($result['status'] ?? 'unknown');
                $status['outcomes'][$outcome] = ($status['outcomes'][$outcome] ?? 0) + 1;
                if ($outcome === 'idle') break;
            }
            $status['completed'] = true;
            $status['completed_at_utc'] = gmdate('c');
            $writeStatus();
        } catch (Throwable $exception) {
            $status['completed_at_utc'] = gmdate('c');
            $status['error_class'] = get_class($exception);
            try {
                $writeStatus();
            } catch (Throwable) {
                // Preserve the original failure for the scheduler exit code.
            }
            error_log('POS Printer Emulator communications cron failed: ' . get_class($exception));
            exit(1);
        }
        """;
    UploadText(client, CombineRemote(privateDirectory, "communications-cron.php"), cron);
    var cronLauncher = """
        #!/bin/sh
        set -eu
        umask 077
        private_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
        printf '{"version":1,"launched_at_utc":"%s"}\n' \
          "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
          > "$private_dir/communications-cron-launch.json"
        set +e
        PPE_COMMUNICATIONS_CRON=1 \
          /usr/local/bin/php8.4 -f "$private_dir/communications-cron.php" >/dev/null 2>/dev/null
        exit_code=$?
        set -e
        printf '{"version":1,"launched_at_utc":"%s","php_exit_code":%s}\n' \
          "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$exit_code" \
          > "$private_dir/communications-cron-launch.json"
        exit "$exit_code"
        """;
    UploadText(
        client,
        CombineRemote(privateDirectory, "communications-cron.sh"),
        cronLauncher);

    Console.WriteLine("Uploaded protected Brevo communications configuration. Provider credentials were not displayed.");
    Console.WriteLine($"Saved encrypted webhook recovery metadata to {recoveryPath}.");
}

static long ConfigureBrevoWebhook(string apiKey, string webhookToken, long? existingId)
{
    var adminBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(AdminBaseUrlVariable) ??
        "https://admin.posprinteremulator.com",
        AdminBaseUrlVariable);
    var endpoint = existingId is > 0
        ? new Uri($"https://api.brevo.com/v3/webhooks/{existingId.Value}")
        : new Uri("https://api.brevo.com/v3/webhooks");
    var payload = JsonSerializer.Serialize(new
    {
        description = "POS Printer Emulator transactional delivery events",
        url = adminBaseUrl + "/api/v1/brevo-webhook.php",
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
    var recoveryPath = DeploymentRecoveryPath("customer-portal-v0.3.43.secrets.bin");
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
    var adminBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(AdminBaseUrlVariable) ??
        "https://admin.posprinteremulator.com",
        AdminBaseUrlVariable);
    var supportBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(SupportBaseUrlVariable) ??
        "https://www.posprinteremulator.com",
        SupportBaseUrlVariable);

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
                'support_url' => {PhpString(supportBaseUrl + "/how-to-submit-a-support-request")},
                'support_backend_url' => {PhpString(adminBaseUrl + "/api/v1/portal-support.php")},
                'support_backend_token' => {PhpString(serviceToken)},
                'communications_worker_url' => {PhpString(adminBaseUrl + "/api/v1/communications-worker.php?max=5")},
                'promotion_backend_url' => {PhpString(adminBaseUrl + "/api/v1/portal-promotion.php")},
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

static bool IsGeneratedReleaseDownload(string localRoot, string path)
{
    var relative = Path.GetRelativePath(localRoot, path).Replace('\\', '/');
    if (!relative.StartsWith("downloads/", StringComparison.OrdinalIgnoreCase))
    {
        return false;
    }

    return relative.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) ||
           relative.EndsWith(".exe.sha256", StringComparison.OrdinalIgnoreCase);
}

static void ConfigurePurchaseIntegration(
    SftpClient client,
    string adminRemoteDirectory,
    string buyRemoteDirectory)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException(
            "Purchase integration recovery uses Windows data protection.");
    }
    if (!CanonicalHttpsUrl(
            Environment.GetEnvironmentVariable(PayPalBaseUrlVariable) ??
            "https://api-m.paypal.com",
            PayPalBaseUrlVariable).Equals("https://api-m.sandbox.paypal.com", StringComparison.OrdinalIgnoreCase) &&
        DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException("Sandbox deployments must use the PayPal Sandbox API host.");
    }

    var adminBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(AdminBaseUrlVariable) ??
        "https://admin.posprinteremulator.com",
        AdminBaseUrlVariable);
    var buyBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(BuyBaseUrlVariable) ??
        "https://buy.posprinteremulator.com",
        BuyBaseUrlVariable);
    var paypalBaseUrl = CanonicalHttpsUrl(
        Environment.GetEnvironmentVariable(PayPalBaseUrlVariable) ??
        "https://api-m.paypal.com",
        PayPalBaseUrlVariable);
    var paypalClientId = RequiredEnvironmentVariable(PayPalClientIdVariable);
    var paypalSecret = RequiredEnvironmentVariable(PayPalSecretVariable);
    var paypalWebhookId = RequiredEnvironmentVariable(PayPalWebhookIdVariable);
    var adminToken = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32))
        .TrimEnd('=').Replace('+', '-').Replace('/', '_');
    var maintenanceToken = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32))
        .TrimEnd('=').Replace('+', '-').Replace('/', '_');

    var adminRoot = ResolveRemotePath(client, adminRemoteDirectory).TrimEnd('/');
    if (adminRoot.Length == 0) adminRoot = "/";
    var buyRoot = ResolveRemotePath(client, buyRemoteDirectory).TrimEnd('/');
    if (buyRoot.Length == 0) buyRoot = "/";
    var createdDirectories = new HashSet<string>(StringComparer.Ordinal);
    EnsureDirectory(client, CombineRemote(adminRoot, "private"), createdDirectories);
    EnsureDirectory(client, CombineRemote(buyRoot, "private"), createdDirectories);

    var adminConfig = $"""
        <?php
        declare(strict_types=1);

        return [
            'base_url' => {PhpString(buyBaseUrl)},
            'admin_token' => {PhpString(adminToken)},
            'maintenance_token' => {PhpString(maintenanceToken)},
        ];
        """;
    var buyConfig = $"""
        <?php
        declare(strict_types=1);

        return [
            'app_url' => {PhpString(buyBaseUrl)},
            'environment' => {PhpString(DeploymentProfile())},
            'license' => [
                'product_name' => 'POS Printer Emulator',
                'lite_price' => '24.99',
                'pro_price' => '39.99',
                'enterprise_price' => '199.99',
                'currency' => 'USD',
            ],
            'maintenance' => [
                'lite_price' => '9.99',
                'pro_price' => '19.99',
                'enterprise_price' => '59.99',
                'base_url' => {PhpString(adminBaseUrl)},
                'api_token' => {PhpString(maintenanceToken)},
            ],
            'paypal' => [
                'client_id' => {PhpString(paypalClientId)},
                'secret' => {PhpString(paypalSecret)},
                'webhook_id' => {PhpString(paypalWebhookId)},
                'base_url' => {PhpString(paypalBaseUrl)},
            ],
            'admin_api_token' => {PhpString(adminToken)},
            'mail' => [
                'from_email' => 'sales@buy.posprinteremulator.com',
                'from_name' => 'POS Printer Emulator Sandbox',
                'reply_to' => '',
            ],
        ];
        """;

    UploadText(client, CombineRemote(adminRoot, "private/purchase-site.php"), adminConfig);
    UploadText(client, CombineRemote(buyRoot, "private/config.php"), buyConfig);
    var recovery = JsonSerializer.SerializeToUtf8Bytes(new
    {
        version = 1,
        createdAtUtc = DateTimeOffset.UtcNow,
        profile = DeploymentProfile(),
        adminToken,
        maintenanceToken
    });
    File.WriteAllBytes(
        DeploymentRecoveryPath("purchase-integration.secrets.bin"),
        ProtectedData.Protect(recovery, null, DataProtectionScope.CurrentUser));
    Console.WriteLine("Uploaded matching protected Admin/Buy sandbox integration configuration.");
    Console.WriteLine("PayPal and service credentials were not displayed.");
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

static async Task ReconcileSandboxPayPalReversalAsync(
    Uri adminUri,
    string eventId,
    string eventType,
    string reversalType,
    string providerOrderId,
    string providerCaptureId)
{
    if (!adminUri.Host.Contains("sandbox", StringComparison.OrdinalIgnoreCase) &&
        !adminUri.Host.Contains("staging", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "PayPal event replay is restricted to a sandbox or staging Admin hostname.");
    }
    if (!DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase) &&
        !DeploymentProfile().Equals("staging", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "PayPal event replay requires a sandbox or staging deployment profile.");
    }
    var token = RecoverPurchaseMaintenanceToken();
    using var request = new HttpRequestMessage(
        HttpMethod.Post,
        new Uri(adminUri, "/api/v1/portal-commerce.php"));
    request.Headers.Add("X-PPE-Admin-Token", token);
    request.Content = new StringContent(
        JsonSerializer.Serialize(new
        {
            action = "record-provider-reversal",
            eventId,
            eventType,
            reversalType,
            providerOrderId,
            providerCaptureId,
            reason = "Verified PayPal Sandbox event replayed by the release-certification operator."
        }),
        System.Text.Encoding.UTF8,
        "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException(
            $"Sandbox PayPal reconciliation failed with HTTP {(int)response.StatusCode}.");
    }
    using var result = JsonDocument.Parse(body);
    var root = result.RootElement;
    Console.WriteLine(JsonSerializer.Serialize(new
    {
        ok = root.TryGetProperty("ok", out var ok) && ok.GetBoolean(),
        state = root.TryGetProperty("state", out var state) ? state.GetString() : null,
        idempotent = root.TryGetProperty("idempotent", out var idempotent) && idempotent.GetBoolean(),
        reviewRequired = root.TryGetProperty("reviewRequired", out var review) && review.GetBoolean()
    }));
}

static string RecoverPurchaseMaintenanceToken()
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException(
            "Purchase-integration recovery uses Windows data protection.");
    }
    var recoveryPath = DeploymentRecoveryPath("purchase-integration.secrets.bin");
    if (!File.Exists(recoveryPath))
    {
        throw new FileNotFoundException(
            "The protected purchase-integration recovery file is unavailable.",
            recoveryPath);
    }
    var recovery = ProtectedData.Unprotect(
        File.ReadAllBytes(recoveryPath),
        null,
        DataProtectionScope.CurrentUser);
    using var document = JsonDocument.Parse(recovery);
    var token = document.RootElement.GetProperty("maintenanceToken").GetString();
    if (string.IsNullOrWhiteSpace(token) || token.Length < 32)
    {
        throw new InvalidDataException(
            "The protected purchase-integration maintenance token is unavailable.");
    }
    return token;
}

static async Task MigrateSchemaWithRecoveredAdminAsync(Uri setupUri)
    => await RunRecoveredAdminSetupAsync(setupUri, null, "schema migration");

static async Task SeedCertificationWithRecoveredAdminAsync(Uri setupUri)
    => await RunRecoveredAdminSetupAsync(setupUri, "seed-certification", "certification seed");

static async Task RunRecoveredAdminSetupAsync(Uri setupUri, string? action, string operation)
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("Admin credential recovery uses Windows data protection.");
    }
    var profile = DeploymentProfile();
    if (profile.Equals("production", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "Recovered schema migration is restricted to a named non-production deployment profile.");
    }
    if (!setupUri.Host.Contains("sandbox", StringComparison.OrdinalIgnoreCase) &&
        !setupUri.Host.Contains("staging", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "Recovered schema migration requires a sandbox or staging hostname.");
    }

    var credentials = RecoverAdminCredentials();

    using var request = new HttpRequestMessage(HttpMethod.Post, setupUri);
    request.Content = new StringContent(
        JsonSerializer.Serialize(new
        {
            username = credentials.Username,
            password = credentials.Password,
            action
        }),
        System.Text.Encoding.UTF8,
        "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromMinutes(3) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        var detail = string.Empty;
        try
        {
            using var error = JsonDocument.Parse(body);
            if (error.RootElement.TryGetProperty("detail", out var detailElement) &&
                detailElement.ValueKind == JsonValueKind.String)
            {
                detail = detailElement.GetString() ?? string.Empty;
            }
        }
        catch (JsonException)
        {
            // The sandbox endpoint did not return a structured diagnostic.
        }
        throw new InvalidOperationException(
            $"Sandbox {operation} failed with HTTP {(int)response.StatusCode}" +
            (detail.Length > 0 ? $": {detail}" : "."));
    }
    using var result = JsonDocument.Parse(body);
    if (!result.RootElement.TryGetProperty("ok", out var ok) || !ok.GetBoolean())
    {
        throw new InvalidDataException($"The sandbox {operation} response was invalid.");
    }
    var statements = result.RootElement.TryGetProperty("statements", out var count)
        ? count.GetInt32()
        : 0;
    Console.WriteLine($"Sandbox {operation} completed successfully ({statements} statements).");
}

static (string Username, string Password) RecoverAdminCredentials()
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("Admin credential recovery uses Windows data protection.");
    }
    var recoveryPath = DeploymentRecoveryPath("admin-access.secrets.bin");
    if (!File.Exists(recoveryPath))
    {
        throw new FileNotFoundException(
            "The protected non-production Admin credential recovery file is unavailable.",
            recoveryPath);
    }
    var recovered = ProtectedData.Unprotect(
        File.ReadAllBytes(recoveryPath),
        null,
        DataProtectionScope.CurrentUser);
    using var document = JsonDocument.Parse(recovered);
    var username = document.RootElement.GetProperty("username").GetString();
    var password = document.RootElement.GetProperty("password").GetString();
    if (string.IsNullOrWhiteSpace(username) || string.IsNullOrWhiteSpace(password))
    {
        throw new InvalidDataException("The protected Admin credentials are incomplete.");
    }
    return (username, password);
}

static string RecoverCrmServiceToken()
{
    if (!OperatingSystem.IsWindows())
    {
        throw new PlatformNotSupportedException("CRM deployment-secret recovery uses Windows data protection.");
    }
    var recoveryPath = DeploymentRecoveryPath("crm-v0.3.42.secrets.bin");
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

static async Task RunPortalDiagnosticsAsync(Uri diagnosticsUri, string email)
{
    var serviceToken = RecoverCrmServiceToken();
    using var request = new HttpRequestMessage(HttpMethod.Post, diagnosticsUri);
    request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", serviceToken);
    request.Content = new StringContent(
        JsonSerializer.Serialize(new { email }),
        System.Text.Encoding.UTF8,
        "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException(
            $"Portal diagnostics failed with HTTP {(int)response.StatusCode}: {body}");
    }
    using var result = JsonDocument.Parse(body);
    Console.WriteLine(JsonSerializer.Serialize(result.RootElement, new JsonSerializerOptions { WriteIndented = true }));
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
        var detail = "";
        try
        {
            using var errorDocument = JsonDocument.Parse(body);
            if (errorDocument.RootElement.TryGetProperty("detail", out var detailElement) &&
                detailElement.ValueKind == JsonValueKind.String)
            {
                detail = detailElement.GetString() ?? "";
            }
        }
        catch (JsonException)
        {
            // The protected endpoint did not return a structured diagnostic.
        }
        throw new InvalidOperationException(
            $"{name} migration failed with HTTP {(int)response.StatusCode}" +
            (detail.Length > 0 ? $": {detail}" : "."));
    }
    using var result = JsonDocument.Parse(body);
    if (!result.RootElement.TryGetProperty("ok", out var ok) || !ok.GetBoolean())
    {
        throw new InvalidDataException($"The {name} migration response was invalid.");
    }
    if (result.RootElement.TryGetProperty("trialGuidance", out var trialGuidance) &&
        trialGuidance.ValueKind == JsonValueKind.String)
    {
        Console.WriteLine($"Trial guidance workflow: {trialGuidance.GetString()}.");
    }
    if (result.RootElement.TryGetProperty("senderPolicy", out var senderPolicy) &&
        senderPolicy.ValueKind == JsonValueKind.Object)
    {
        var updated = senderPolicy.TryGetProperty("updated", out var updatedElement) &&
            updatedElement.TryGetInt32(out var updatedCount) ? updatedCount : 0;
        var verified = senderPolicy.TryGetProperty("verified", out var verifiedElement) &&
            verifiedElement.TryGetInt32(out var verifiedCount) ? verifiedCount : 0;
        Console.WriteLine($"Template sender policy: {verified} verified, {updated} updated.");
    }
    if (result.RootElement.TryGetProperty("processed", out var processed) &&
        processed.TryGetInt32(out var processedCount) &&
        result.RootElement.TryGetProperty("summary", out var summary) &&
        summary.ValueKind == JsonValueKind.Object)
    {
        var mapped = summary.TryGetProperty("mapped", out var mappedElement) && mappedElement.TryGetInt32(out var mappedCount)
            ? mappedCount : 0;
        var failed = summary.TryGetProperty("failed", out var failedElement) && failedElement.TryGetInt32(out var failedCount)
            ? failedCount : 0;
        Console.WriteLine($"Protected workflow processed {processedCount} item(s): {mapped} mapped, {failed} failed.");
        if (result.RootElement.TryGetProperty("results", out var results) &&
            results.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in results.EnumerateArray())
            {
                var templateKey = item.TryGetProperty("template_key", out var keyElement)
                    ? keyElement.GetString() ?? "unknown" : "unknown";
                var status = item.TryGetProperty("status", out var statusElement)
                    ? statusElement.GetString() ?? "unknown" : "unknown";
                var error = item.TryGetProperty("error", out var errorElement) &&
                    errorElement.ValueKind == JsonValueKind.String
                    ? errorElement.GetString() : null;
                Console.WriteLine(error is { Length: > 0 }
                    ? $"  {templateKey}: {status} — {error}"
                    : $"  {templateKey}: {status}");
            }
        }
    }
}

static void DownloadFile(SftpClient client, string remoteFile, string localPath)
{
    var resolvedFile = ResolveRemotePath(client, remoteFile);
    if (!client.Exists(resolvedFile))
    {
        throw new FileNotFoundException("The remote file was not found.", resolvedFile);
    }
    var parent = Path.GetDirectoryName(localPath);
    if (!string.IsNullOrWhiteSpace(parent))
    {
        Directory.CreateDirectory(parent);
    }
    using var output = File.Create(localPath);
    client.DownloadFile(resolvedFile, output);
    var remoteSize = client.GetAttributes(resolvedFile).Size;
    if (output.Length != remoteSize)
    {
        throw new IOException($"Size verification failed for {resolvedFile}.");
    }
    Console.WriteLine($"Downloaded and size-verified {resolvedFile} ({remoteSize:N0} bytes).");
}

static void UploadFile(SftpClient client, string localPath, string remoteFile)
{
    if (!File.Exists(localPath))
    {
        throw new FileNotFoundException("The local file was not found.", localPath);
    }
    var resolvedFile = ResolveRemotePath(client, remoteFile);
    var separator = resolvedFile.LastIndexOf('/');
    if (separator > 0)
    {
        EnsureDirectory(client, resolvedFile[..separator], new HashSet<string>(StringComparer.Ordinal));
    }
    using var input = File.OpenRead(localPath);
    client.UploadFile(input, resolvedFile, true);
    var remoteLength = client.GetAttributes(resolvedFile).Size;
    if (remoteLength != input.Length)
    {
        throw new IOException($"Upload size verification failed for {resolvedFile}.");
    }
    Console.WriteLine($"Uploaded and size-verified {resolvedFile} ({input.Length:N0} bytes).");
}

static async Task RunCommunicationsWorkerAsync(Uri workerUri, int maximum)
{
    var serviceToken = RecoverCrmServiceToken();
    var builder = new UriBuilder(workerUri);
    var existingQuery = builder.Query.TrimStart('?');
    var maximumQuery = "max=" + maximum.ToString(System.Globalization.CultureInfo.InvariantCulture);
    builder.Query = string.IsNullOrWhiteSpace(existingQuery)
        ? maximumQuery
        : existingQuery + "&" + maximumQuery;
    using var request = new HttpRequestMessage(HttpMethod.Post, builder.Uri);
    request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", serviceToken);
    request.Content = new StringContent("{}", System.Text.Encoding.UTF8, "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(90) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException(
            $"Communications worker failed with HTTP {(int)response.StatusCode}: {body}");
    }
    using var result = JsonDocument.Parse(body);
    var processed = result.RootElement.TryGetProperty("processed", out var count)
        ? count.GetInt32()
        : 0;
    Console.WriteLine($"Communications worker completed successfully ({processed} messages processed).");
}

static async Task SynchronizeSandboxCommunicationTemplateAsync(
    Uri synchronizationUri,
    string templateKey)
{
    if (!System.Text.RegularExpressions.Regex.IsMatch(templateKey, "^[a-z0-9_]{3,64}$"))
    {
        throw new ArgumentException("The communication template key is invalid.", nameof(templateKey));
    }
    var serviceToken = RecoverCrmServiceToken();
    using var request = new HttpRequestMessage(HttpMethod.Post, synchronizationUri);
    request.Headers.Authorization =
        new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", serviceToken);
    request.Content = new StringContent(
        JsonSerializer.Serialize(new { template_key = templateKey, send_test = true }),
        System.Text.Encoding.UTF8,
        "application/json");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(90) };
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException(
            $"Communication template synchronization failed with HTTP {(int)response.StatusCode}: {body}");
    }
    using var result = JsonDocument.Parse(body);
    var synchronizedKey = result.RootElement.GetProperty("template_key").GetString();
    var templateId = result.RootElement.GetProperty("template_id").GetInt64();
    var testSent = result.RootElement.GetProperty("test_sent").GetBoolean();
    Console.WriteLine(
        $"Sandbox communication template {synchronizedKey} synchronized and verified " +
        $"(Brevo ID {templateId}, test sent: {testSent}).");
}

static void UploadSandboxFile(SftpClient client, string localPath, string remoteFile)
{
    if (!File.Exists(localPath))
    {
        throw new FileNotFoundException("The local file was not found.", localPath);
    }
    if (!remoteFile.Contains("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "upload-sandbox requires a remote path containing the sandbox marker.");
    }
    var resolvedFile = ResolveRemotePath(client, remoteFile);
    var separator = resolvedFile.LastIndexOf('/');
    if (separator > 0)
    {
        EnsureDirectory(client, resolvedFile[..separator], new HashSet<string>(StringComparer.Ordinal));
    }
    using var input = OpenDeploymentContent(localPath, true);
    client.UploadFile(input, resolvedFile, true);
    var remoteLength = client.GetAttributes(resolvedFile).Size;
    if (remoteLength != input.Length)
    {
        throw new IOException($"Sandbox upload size verification failed for {resolvedFile}.");
    }
    Console.WriteLine($"Sandbox-transformed and size-verified {resolvedFile} ({input.Length:N0} bytes).");
}

static void DeleteRemoteFile(SftpClient client, string remoteFile)
{
    var resolvedFile = ResolveRemotePath(client, remoteFile);
    if (resolvedFile is "/" or "." || resolvedFile.EndsWith('/'))
    {
        throw new ArgumentException("A specific remote file is required.");
    }
    if (!client.Exists(resolvedFile))
    {
        Console.WriteLine($"Remote file is already absent: {resolvedFile}");
        return;
    }
    var attributes = client.GetAttributes(resolvedFile);
    if (attributes.IsDirectory)
    {
        throw new InvalidOperationException("delete-file does not remove directories.");
    }
    client.DeleteFile(resolvedFile);
    if (client.Exists(resolvedFile))
    {
        throw new IOException($"Remote file deletion could not be verified for {resolvedFile}.");
    }
    Console.WriteLine($"Deleted and verified remote file {resolvedFile}.");
}

static void DeleteSandboxFile(SftpClient client, string remoteFile)
{
    if (!remoteFile.Contains("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "delete-sandbox-file requires a remote path containing the sandbox marker.");
    }
    DeleteRemoteFile(client, remoteFile);
}

static void SetCommunicationsTestAllowlist(SftpClient client, string email, string remoteDirectory)
{
    email = email.Trim().ToLowerInvariant();
    if (!System.Net.Mail.MailAddress.TryCreate(email, out _))
    {
        throw new ArgumentException("The communications test recipient is invalid.");
    }
    var remoteRoot = ResolveRemotePath(client, remoteDirectory).TrimEnd('/');
    if (remoteRoot.Length == 0) remoteRoot = "/";
    var remotePath = CombineRemote(remoteRoot, "private/communications.php");
    if (!client.Exists(remotePath))
    {
        throw new FileNotFoundException("The protected communications configuration is unavailable.");
    }
    string config;
    using (var input = client.OpenRead(remotePath))
    using (var reader = new StreamReader(input, System.Text.Encoding.UTF8, true, leaveOpen: false))
    {
        config = reader.ReadToEnd();
    }
    var pattern =
        @"'test_allowlist'\s*=>\s*(?:\[[^\]]*\]|array\s*\([^)]*\))";
    if (!System.Text.RegularExpressions.Regex.IsMatch(
        config,
        pattern,
        System.Text.RegularExpressions.RegexOptions.CultureInvariant))
    {
        throw new InvalidDataException("The protected communications allowlist setting could not be located.");
    }
    var updated = System.Text.RegularExpressions.Regex.Replace(
        config,
        pattern,
        "'test_allowlist' => [" + PhpString(email) + "]",
        System.Text.RegularExpressions.RegexOptions.CultureInvariant);
    UploadText(client, remotePath, updated);
    if (client.GetAttributes(remotePath).Size != System.Text.Encoding.UTF8.GetByteCount(updated))
    {
        throw new IOException("The protected communications allowlist update could not be verified.");
    }
    Console.WriteLine("Updated and size-verified the protected communications test allowlist.");
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

static string CanonicalHttpsUrl(string value, string variableName)
{
    if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) ||
        uri.Scheme != Uri.UriSchemeHttps ||
        !string.IsNullOrEmpty(uri.Query) ||
        !string.IsNullOrEmpty(uri.Fragment))
    {
        throw new InvalidOperationException($"{variableName} must be a canonical HTTPS URL.");
    }
    return uri.GetLeftPart(UriPartial.Path).TrimEnd('/');
}

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

static void FetchSandboxRelease(
    SftpClient sftp,
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string releaseUrl,
    string remoteFile)
{
    if (!Uri.TryCreate(releaseUrl, UriKind.Absolute, out var uri) ||
        uri.Scheme != Uri.UriSchemeHttps ||
        !uri.Host.Equals("github.com", StringComparison.OrdinalIgnoreCase) ||
        !uri.AbsolutePath.StartsWith(
            "/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/download/",
            StringComparison.Ordinal))
    {
        throw new ArgumentException(
            "Only HTTPS release assets from the POS Printer Emulator GitHub repository are allowed.",
            nameof(releaseUrl));
    }
    if (!System.Text.RegularExpressions.Regex.IsMatch(
            remoteFile,
            @"^/sandbox_posprinteremulator/downloads/[A-Za-z0-9._-]+$"))
    {
        throw new ArgumentException(
            "The remote release file must stay inside the public sandbox downloads directory.",
            nameof(remoteFile));
    }

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" + Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var shellRelativeFile = remoteFile.TrimStart('/');
        var command = ssh.RunCommand(
            $"umask 077; curl --fail --location --silent --show-error --output '{shellRelativeFile}' '{uri.AbsoluteUri}'");
        if (command.ExitStatus != 0)
        {
            throw new InvalidOperationException(
                $"The sandbox host could not fetch the GitHub release asset (exit {command.ExitStatus}).");
        }
        var checksumFile = remoteFile + ".sha256";
        if (!sftp.Exists(checksumFile))
        {
            throw new FileNotFoundException(
                "The sandbox release checksum must be uploaded before fetching the installer.",
                checksumFile);
        }
        var lastSlash = remoteFile.LastIndexOf('/');
        var shellDirectory = remoteFile[..lastSlash].TrimStart('/');
        var checksumName = remoteFile[(lastSlash + 1)..] + ".sha256";
        var verification = ssh.RunCommand(
            $"cd '{shellDirectory}' && sed 's/\r$//' '{checksumName}' | sha256sum --check -");
        if (verification.ExitStatus != 0)
        {
            var diagnostic = (verification.Error + " " + verification.Result)
                .Replace("\r", " ", StringComparison.Ordinal)
                .Replace("\n", " ", StringComparison.Ordinal)
                .Trim();
            throw new InvalidDataException(
                "The sandbox release checksum verification failed" +
                (diagnostic.Length > 0 ? $": {diagnostic}" : "."));
        }
    }
    finally
    {
        ssh.Disconnect();
    }

    var size = sftp.GetAttributes(remoteFile).Size;
    if (size <= 0)
    {
        throw new IOException("The fetched sandbox release asset is empty.");
    }
    Console.WriteLine(
        $"Sandbox host fetched and checksum-verified {Path.GetFileName(remoteFile)} ({size:N0} bytes).");
}

static void RunSandboxCommunicationsCron(
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory)
{
    var normalizedDirectory = remoteDirectory.Trim().Trim('/');
    if (!normalizedDirectory.Equals(
            "admin_sandbox_posprinteremulator",
            StringComparison.Ordinal))
    {
        throw new InvalidOperationException(
            "The communications cron diagnostic is restricted to the Admin sandbox directory.");
    }
    if (!DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "The communications cron diagnostic requires PPE_DEPLOYMENT_PROFILE=sandbox.");
    }

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" +
                     Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        const string diagnosticScript = """
            <?php
            declare(strict_types=1);
            require 'includes/bootstrap.php';
            require 'includes/communications.php';
            $pdo = database();
            $snapshot = static function (PDO $pdo): array {
                $row = $pdo->query(
                    "SELECT
                        SUM(state='Pending') AS pending_count,
                        SUM(state='Deferred') AS deferred_count,
                        SUM(state IN ('Pending','Deferred') AND available_at<=UTC_TIMESTAMP(6)) AS due_count,
                        SUM(state='Sent') AS sent_count
                     FROM communication_outbox"
                )->fetch();
                return [
                    'pending' => (int)($row['pending_count'] ?? 0),
                    'deferred' => (int)($row['deferred_count'] ?? 0),
                    'due' => (int)($row['due_count'] ?? 0),
                    'sent' => (int)($row['sent_count'] ?? 0),
                ];
            };
            $before = $snapshot($pdo);
            $outcomes = [];
            for ($index = 0; $index < 50; $index++) {
                $result = communication_worker_process_one($pdo);
                $status = (string)($result['status'] ?? 'unknown');
                $outcomes[$status] = ($outcomes[$status] ?? 0) + 1;
                if ($status === 'idle') break;
            }
            echo json_encode([
                'before' => $before,
                'outcomes' => $outcomes,
                'after' => $snapshot($pdo),
            ], JSON_THROW_ON_ERROR);
            """;
        var encodedScript = Convert.ToBase64String(
            System.Text.Encoding.UTF8.GetBytes(diagnosticScript));
        var command = ssh.RunCommand(
            $"cd '{normalizedDirectory}' && " +
            "test -f private/communications-cron.php && " +
            $"printf '%s' '{encodedScript}' | base64 -d | /usr/bin/php8.4");
        if (command.ExitStatus != 0)
        {
            throw new InvalidOperationException(
                $"The sandbox communications cron failed with exit status {command.ExitStatus}.");
        }
        var jsonOffset = command.Result.IndexOf(
            "{\"before\"",
            StringComparison.Ordinal);
        if (jsonOffset < 0)
        {
            throw new InvalidDataException(
                "The sandbox communications cron did not return its privacy-safe diagnostic result.");
        }
        using var result = JsonDocument.Parse(command.Result[jsonOffset..]);
        Console.WriteLine(
            "Sandbox communications cron completed successfully through the verified SSH host: " +
            JsonSerializer.Serialize(result.RootElement));
    }
    finally
    {
        ssh.Disconnect();
    }
}

static void QueueSandboxCommunicationsTest(
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory,
    string customerId,
    string templateKey)
{
    var normalizedDirectory = remoteDirectory.Trim().Trim('/');
    if (!normalizedDirectory.Equals(
            "admin_sandbox_posprinteremulator",
            StringComparison.Ordinal) ||
        !DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "Communications test enqueue is restricted to the Admin sandbox directory.");
    }
    if (!System.Text.RegularExpressions.Regex.IsMatch(
            customerId,
            @"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase) ||
        !System.Text.RegularExpressions.Regex.IsMatch(
            templateKey,
            @"^[a-z][a-z0-9_]{1,63}$"))
    {
        throw new ArgumentException("The customer ID or communication template key is invalid.");
    }

    var php = $$"""
        <?php
        declare(strict_types=1);
        require 'includes/bootstrap.php';
        require 'includes/communications.php';
        $pdo = database();
        $customerId = '{{customerId.ToLowerInvariant()}}';
        $templateKey = '{{templateKey}}';
        $customer = $pdo->prepare(
            'SELECT display_name FROM customers WHERE customer_id=:id LIMIT 1'
        );
        $customer->execute(['id' => $customerId]);
        $name = $customer->fetchColumn();
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('Certification customer was not found.');
        }
        $messageId = communication_enqueue(
            $pdo,
            $customerId,
            $templateKey,
            communication_test_parameters($templateKey, $name),
            'cron-certification:' . $templateKey . ':' . $customerId . ':' . gmdate('YmdHis'),
            null,
            false
        );
        echo json_encode(['message_id' => $messageId], JSON_THROW_ON_ERROR);
        """;
    var encoded = Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(php));

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" +
                     Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var command = ssh.RunCommand(
            $"cd '{normalizedDirectory}' && " +
            $"printf '%s' '{encoded}' | base64 -d | /usr/bin/php8.4");
        if (command.ExitStatus != 0)
        {
            throw new InvalidOperationException(
                $"The sandbox communications test could not be queued (exit {command.ExitStatus}).");
        }
        var jsonOffset = command.Result.IndexOf("{\"message_id\"", StringComparison.Ordinal);
        if (jsonOffset < 0)
        {
            throw new InvalidDataException(
                "The sandbox communications test did not return a message identifier.");
        }
        using var result = JsonDocument.Parse(command.Result[jsonOffset..]);
        Console.WriteLine(
            "Queued sandbox communications test for automatic Cron delivery: " +
            result.RootElement.GetProperty("message_id").GetString());
    }
    finally
    {
        ssh.Disconnect();
    }
}

static void DiagnoseSandboxCommunicationsCron(
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory)
{
    var normalizedDirectory = remoteDirectory.Trim().Trim('/');
    if (!normalizedDirectory.Equals(
            "admin_sandbox_posprinteremulator",
            StringComparison.Ordinal) ||
        !DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "Communications Cron diagnostics are restricted to the Admin sandbox directory.");
    }

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" +
                     Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var command = ssh.RunCommand(
            $"root=$(pwd); target=$(readlink -f '{normalizedDirectory}/private/communications-cron.php'); " +
            "phpbin=$(command -v php8.4); " +
            "/usr/local/bin/php8.4 -l \"$target\" >/dev/null 2>/dev/null; syntax=$?; " +
            "printf '{\"working_directory\":\"%s\",\"script_path\":\"%s\",\"php_path\":\"%s\",\"script_readable\":%s,\"syntax_exit_code\":%s}\\n' " +
            "\"$root\" \"$target\" \"$phpbin\" \"$(test -r \"$target\" && printf true || printf false)\" \"$syntax\"");
        if (command.ExitStatus != 0)
        {
            throw new InvalidOperationException(
                $"The sandbox Cron path diagnostic failed (exit {command.ExitStatus}).");
        }
        using var result = JsonDocument.Parse(command.Result.Trim());
        Console.WriteLine(JsonSerializer.Serialize(result.RootElement));
    }
    finally
    {
        ssh.Disconnect();
    }
}

static void InspectSandboxCommunicationsMessage(
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory,
    string messageId)
{
    var normalizedDirectory = remoteDirectory.Trim().Trim('/');
    if (!normalizedDirectory.Equals(
            "admin_sandbox_posprinteremulator",
            StringComparison.Ordinal) ||
        !DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase) ||
        !System.Text.RegularExpressions.Regex.IsMatch(
            messageId,
            @"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase))
    {
        throw new InvalidOperationException(
            "Communications message inspection is restricted to a valid Admin sandbox record.");
    }

    var php = $$"""
        <?php
        declare(strict_types=1);
        require 'includes/bootstrap.php';
        $pdo = database();
        $statement = $pdo->prepare(
            "SELECT state,attempts,
                    provider_message_id IS NOT NULL AS provider_id_present
             FROM communication_outbox WHERE message_id=:id LIMIT 1"
        );
        $statement->execute(['id' => '{{messageId.ToLowerInvariant()}}']);
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('Certification message was not found.');
        $events = $pdo->prepare(
            'SELECT COUNT(*) FROM communication_delivery_events WHERE message_id=:id'
        );
        $events->execute(['id' => '{{messageId.ToLowerInvariant()}}']);
        echo json_encode([
            'state' => (string)$row['state'],
            'attempt_count' => (int)$row['attempts'],
            'provider_id_present' => (bool)$row['provider_id_present'],
            'delivery_event_count' => (int)$events->fetchColumn(),
        ], JSON_THROW_ON_ERROR);
        """;
    var encoded = Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(php));

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" +
                     Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var command = ssh.RunCommand(
            $"cd '{normalizedDirectory}' && " +
            $"printf '%s' '{encoded}' | base64 -d | /usr/local/bin/php8.4");
        if (command.ExitStatus != 0)
        {
            throw new InvalidOperationException(
                $"The sandbox message inspection failed (exit {command.ExitStatus}).");
        }
        var jsonOffset = command.Result.IndexOf("{\"state\"", StringComparison.Ordinal);
        if (jsonOffset < 0)
        {
            throw new InvalidDataException(
                "The sandbox message inspection returned no privacy-safe result.");
        }
        using var result = JsonDocument.Parse(command.Result[jsonOffset..]);
        Console.WriteLine(JsonSerializer.Serialize(result.RootElement));
    }
    finally
    {
        ssh.Disconnect();
    }
}

static void SetSandboxMaintenanceExpiration(
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory,
    string licenseId,
    string expirationDate)
{
    var normalizedDirectory = remoteDirectory.Trim().Trim('/');
    if (!normalizedDirectory.Equals("admin_sandbox_posprinteremulator", StringComparison.Ordinal) ||
        !DeploymentProfile().Equals("sandbox", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "Maintenance date certification is restricted to the Admin sandbox directory.");
    }
    if (!Guid.TryParse(licenseId, out var parsedLicenseId) ||
        !DateTime.TryParseExact(
            expirationDate,
            ["yyyy-MM-dd", "yyyy-MM-dd'T'HH:mm:ss"],
            System.Globalization.CultureInfo.InvariantCulture,
            System.Globalization.DateTimeStyles.AssumeUniversal |
            System.Globalization.DateTimeStyles.AdjustToUniversal,
            out var parsedExpiration))
    {
        throw new ArgumentException(
            "A license UUID and yyyy-MM-dd or yyyy-MM-ddTHH:mm:ss UTC expiration are required.");
    }

    var earliest = DateTime.UtcNow.Date.AddDays(-7);
    var latest = DateTime.UtcNow.Date.AddYears(2).AddDays(1).AddTicks(-1);
    if (parsedExpiration < earliest || parsedExpiration > latest)
    {
        throw new InvalidOperationException(
            $"Certification maintenance dates must be between {earliest:yyyy-MM-dd} and {latest:yyyy-MM-dd}.");
    }

    var expectedDatabaseHost = RequiredEnvironmentVariable("PPE_CERT_DB_HOST").Trim();
    var expectedDatabaseName = RequiredEnvironmentVariable("PPE_CERT_DB_NAME").Trim();
    var stagingDatabaseLabel = RequiredEnvironmentVariable("PPE_STAGING_DATABASE_LABEL").Trim();
    var stagingAllowedHost = RequiredEnvironmentVariable("PPE_STAGING_ALLOWED_HOST").Trim();
    if (!RequiredEnvironmentVariable("PPE_ENVIRONMENT").Equals(
            "staging",
            StringComparison.OrdinalIgnoreCase) ||
        !System.Text.RegularExpressions.Regex.IsMatch(
            stagingDatabaseLabel,
            @"(^|[_-])(staging|sandbox)([_-]|$)",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase) ||
        !stagingAllowedHost.Equals(expectedDatabaseHost, StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            "The certification database safety variables do not identify the approved sandbox.");
    }

    var php = $$"""
        <?php
        declare(strict_types=1);
        require 'includes/bootstrap.php';
        $licenseId = '{{parsedLicenseId:D}}';
        $newExpiration = '{{(expirationDate.Length == 10 ? parsedExpiration.Date.AddDays(1).AddSeconds(-1) : parsedExpiration):yyyy-MM-dd HH:mm:ss}}.000000';
        $expectedDatabaseHost = {{PhpString(expectedDatabaseHost)}};
        $expectedDatabaseName = {{PhpString(expectedDatabaseName)}};
        $pdo = null;
        try {
            $config = private_config();
            $configuredDatabaseHost = trim((string)($config['database']['host'] ?? ''));
            if (!hash_equals(strtolower($expectedDatabaseHost), strtolower($configuredDatabaseHost))) {
                throw new RuntimeException('The remote database host does not match the approved sandbox host.');
            }
            $pdo = database();
            $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!hash_equals($expectedDatabaseName, $databaseName)) {
                throw new RuntimeException('The selected database does not match the approved sandbox database.');
            }
            $pdo->beginTransaction();
            $find = $pdo->prepare(
                "SELECT control_state,maintenance_expires_at
                 FROM issued_licenses WHERE license_id=:license_id FOR UPDATE"
            );
            $find->execute(['license_id' => $licenseId]);
            $license = $find->fetch();
            if (!is_array($license) || (string)$license['control_state'] !== 'Enabled') {
                throw new RuntimeException('An enabled sandbox license was not found.');
            }
            $previousExpiration = $license['maintenance_expires_at'];
            $update = $pdo->prepare(
                "UPDATE issued_licenses
                 SET maintenance_expires_at=:expiration,maintenance_revoked_at=NULL,
                     row_version=row_version+1,entitlement_revision=entitlement_revision+1
                 WHERE license_id=:license_id"
            );
            $update->execute(['expiration' => $newExpiration, 'license_id' => $licenseId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The sandbox maintenance date was not updated.');
            }
            $installations = $pdo->prepare(
                "UPDATE installations
                 SET maintenance_status=IF(:expiration>=UTC_TIMESTAMP(6),'Active','Expired'),
                     maintenance_expires_at=:expiration_2
                 WHERE license_id=:license_id AND portal_deactivated_at IS NULL"
            );
            $installations->execute([
                'expiration' => $newExpiration,
                'expiration_2' => $newExpiration,
                'license_id' => $licenseId,
            ]);
            $audit = $pdo->prepare(
                "INSERT INTO license_maintenance_events
                    (license_id,event_type,previous_expires_at,new_expires_at,source_reference,
                     reason,performed_by,admin_ip)
                 VALUES
                    (:license_id,'CERTIFICATION_EXPIRATION_SET',:previous_expiration,:new_expiration,
                     :source_reference,
                     'Temporary sandbox date used for the Maintenance and Support release gate.',
                     'certification-tool',NULL)"
            );
            $audit->execute([
                'license_id' => $licenseId,
                'previous_expiration' => $previousExpiration,
                'new_expiration' => $newExpiration,
                'source_reference' => 'certification-date:' . gmdate('YmdHisv'),
            ]);
            $pdo->commit();
            echo json_encode([
                'licenseId' => $licenseId,
                'previousExpiration' => $previousExpiration,
                'newExpiration' => $newExpiration,
                'linkedInstallations' => $installations->rowCount(),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo 'CERTIFICATION_ERROR:' . $exception->getMessage();
            exit(3);
        }
        """;
    var encodedScript = Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(php));

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" +
                     Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var command = ssh.RunCommand(
            $"cd '{normalizedDirectory}' && " +
            $"printf '%s' '{encodedScript}' | base64 -d | " +
            $"/usr/bin/php8.4 -d display_errors=stderr -d display_startup_errors=1");
        if (command.ExitStatus != 0)
        {
            var diagnostic = (command.Error + " " + command.Result)
                .Replace("\r", " ", StringComparison.Ordinal)
                .Replace("\n", " ", StringComparison.Ordinal)
                .Trim();
            throw new InvalidOperationException(
                $"The sandbox maintenance date change failed (exit {command.ExitStatus})" +
                (diagnostic.Length > 0 ? $": {diagnostic}" : "."));
        }
        var jsonOffset = command.Result.IndexOf("{\"licenseId\"", StringComparison.Ordinal);
        if (jsonOffset < 0)
        {
            throw new InvalidDataException(
                "The sandbox maintenance date change returned no verification result.");
        }
        using var result = JsonDocument.Parse(command.Result[jsonOffset..]);
        Console.WriteLine(JsonSerializer.Serialize(result.RootElement));
    }
    finally
    {
        ssh.Disconnect();
    }
}

static void ResetSandboxCheckoutRate(
    SftpClient sftp,
    string host,
    string username,
    string password,
    string expectedFingerprint,
    string remoteDirectory,
    string email)
{
    var normalizedDirectory = remoteDirectory.Trim().TrimStart('/');
    if (!System.Text.RegularExpressions.Regex.IsMatch(
            normalizedDirectory,
            @"^[A-Za-z0-9_-]*sandbox[A-Za-z0-9_-]*$",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase) ||
        !normalizedDirectory.Contains("userportal", StringComparison.OrdinalIgnoreCase))
    {
        throw new ArgumentException(
            "The remote directory must be a sandbox Customer Portal webspace directory.",
            nameof(remoteDirectory));
    }

    var resetAll = email.Trim().Equals("--all", StringComparison.OrdinalIgnoreCase);
    var normalizedEmail = resetAll ? string.Empty : email.Trim().ToLowerInvariant();
    if (!resetAll &&
        (!System.Net.Mail.MailAddress.TryCreate(normalizedEmail, out var address) ||
         !address.Address.Equals(normalizedEmail, StringComparison.OrdinalIgnoreCase)))
    {
        throw new ArgumentException(
            "A valid customer email address or --all is required.",
            nameof(email));
    }

    var encodedEmail = Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(normalizedEmail));
    var php =
        """
        require 'includes/bootstrap.php';
        $config = portal_config();
        $baseUrl = isset($config['portal']['base_url']) ? (string)$config['portal']['base_url'] : '';
        if (stripos($baseUrl, 'sandbox') === false) {
            fwrite(STDERR, 'Refusing to modify a non-sandbox portal.');
            exit(2);
        }
        $resetAll = __RESET_ALL__;
        $email = strtolower(trim(base64_decode('__EMAIL__', true) ?: ''));
        $pdo = portal_database();
        if ($resetAll) {
            $find = $pdo->prepare(
                "SELECT customer_id FROM customers
                 WHERE status='Active'
                 ORDER BY updated_at DESC LIMIT 500"
            );
            $find->execute();
        } else {
            $find = $pdo->prepare(
                "SELECT customer_id FROM customers
                 WHERE canonical_email=:email AND status='Active'
                 ORDER BY updated_at DESC LIMIT 5"
            );
            $find->execute(['email' => $email]);
        }
        $ids = $find->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) < 1 || count($ids) > 500) {
            fwrite(STDERR, 'Expected between one and 500 active sandbox customer records; found ' . count($ids) . '.');
            exit(3);
        }
        $delete = $pdo->prepare('DELETE FROM portal_rate_limits WHERE bucket_hash=:bucket_hash');
        $removed = 0;
        foreach ($ids as $customerId) {
            $bucketHash = hash('sha256', 'checkout|' . (string)$customerId, true);
            $delete->bindValue('bucket_hash', $bucketHash, PDO::PARAM_LOB);
            $delete->execute();
            $removed += $delete->rowCount();
        }
        $intentCount = 0;
        $latest = null;
        $inspect = $pdo->prepare(
            "SELECT state,order_type,target_tier,prepared_at
             FROM portal_checkout_intents
             WHERE customer_id=:customer_id
             ORDER BY prepared_at DESC LIMIT 1"
        );
        foreach ($ids as $customerId) {
            $inspect->execute(['customer_id' => $customerId]);
            $candidate = $inspect->fetch(PDO::FETCH_ASSOC);
            if (is_array($candidate)) {
                $intentCount++;
                if ($latest === null || (string)$candidate['prepared_at'] > (string)$latest['prepared_at']) {
                    $latest = $candidate;
                }
            }
        }
        echo 'Sandbox checkout throttle reset completed. Rows removed: ' . $removed .
             '. Customer records: ' . count($ids) .
             '. Records with checkout activity: ' . $intentCount .
             '. Checkout host: ' . (string)($config['portal']['buy_base_url'] ?? 'not configured') . '.';
        if (is_array($latest)) {
            echo ' Latest checkout: ' . (string)$latest['state'] . ' ' .
                 (string)$latest['order_type'] . ' ' . (string)$latest['target_tier'] .
                 ' at ' . (string)$latest['prepared_at'] . ' UTC.';
        }
        """
        .Replace("__RESET_ALL__", resetAll ? "true" : "false", StringComparison.Ordinal)
        .Replace("__EMAIL__", encodedEmail, StringComparison.Ordinal);
    var scriptName = $".ppe-cert-reset-checkout-{Guid.NewGuid():N}.php";
    var scriptRemotePath = CombineRemote(
        ResolveRemotePath(sftp, remoteDirectory).TrimEnd('/'),
        scriptName);
    using (var scriptStream = new MemoryStream(
               System.Text.Encoding.UTF8.GetBytes("<?php\n" + php),
               writable: false))
    {
        sftp.UploadFile(scriptStream, scriptRemotePath, true);
    }

    using var ssh = new SshClient(host, 22, username, password);
    ssh.HostKeyReceived += (_, eventArgs) =>
    {
        var actual = "SHA256:" + Convert.ToBase64String(SHA256.HashData(eventArgs.HostKey)).TrimEnd('=');
        eventArgs.CanTrust = CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(actual),
            System.Text.Encoding.ASCII.GetBytes(expectedFingerprint));
    };
    ssh.Connect();
    try
    {
        var command = ssh.RunCommand(
            $"cd '{normalizedDirectory}' && " +
            "for php_bin in php8.4-cli php8.3-cli php8.2-cli php8.1-cli php8.0-cli php7.4-cli " +
            "php8.4 php8.3 php8.2 php8.1 php8.0 php7.4 php; do " +
            "if command -v \"$php_bin\" >/dev/null 2>&1; then " +
            $"\"$php_bin\" -d display_errors=1 -d log_errors=0 -f '{scriptName}'; exit $?; fi; done; exit 127");
        if (command.ExitStatus != 0)
        {
            var diagnostic = (command.Error + " " + command.Result)
                .Replace("\r", " ", StringComparison.Ordinal)
                .Replace("\n", " ", StringComparison.Ordinal)
                .Trim();
            throw new InvalidOperationException(
                $"The sandbox checkout throttle reset failed (exit {command.ExitStatus})" +
                (diagnostic.Length > 0 ? $": {diagnostic}" : "."));
        }
        Console.WriteLine(command.Result.Trim());
    }
    finally
    {
        ssh.Disconnect();
        if (sftp.Exists(scriptRemotePath))
        {
            sftp.DeleteFile(scriptRemotePath);
        }
    }
}

static Stream OpenDeploymentContent(string localFile, bool sandbox)
{
    if (!sandbox)
    {
        return File.OpenRead(localFile);
    }

    var textExtensions = new HashSet<string>(
        [".php", ".html", ".htm", ".js", ".css", ".json", ".xml", ".txt", ".webmanifest"],
        StringComparer.OrdinalIgnoreCase);
    if (!textExtensions.Contains(Path.GetExtension(localFile)) &&
        !Path.GetFileName(localFile).Equals(".htaccess", StringComparison.OrdinalIgnoreCase))
    {
        return File.OpenRead(localFile);
    }

    var content = File.ReadAllText(localFile);
    foreach (var (production, staging) in new[]
             {
                 ("https://userportal.posprinteremulator.com", "https://userportal-sandbox.posprinteremulator.com"),
                 ("https://admin.posprinteremulator.com", "https://admin-sandbox.posprinteremulator.com"),
                 ("https://buy.posprinteremulator.com", "https://buy-sandbox.posprinteremulator.com"),
                 ("https://support.posprinteremulator.com", "https://support-sandbox.posprinteremulator.com"),
                 ("https://www.posprinteremulator.com", "https://sandbox.posprinteremulator.com"),
                 ("https://posprinteremulator.com", "https://sandbox.posprinteremulator.com"),
                 (@"userportal\.posprinteremulator\.com", @"userportal-sandbox\.posprinteremulator\.com"),
                 (@"admin\.posprinteremulator\.com", @"admin-sandbox\.posprinteremulator\.com"),
                 (@"buy\.posprinteremulator\.com", @"buy-sandbox\.posprinteremulator\.com"),
                 (@"support\.posprinteremulator\.com", @"support-sandbox\.posprinteremulator\.com"),
                 (@"www\.posprinteremulator\.com", @"sandbox\.posprinteremulator\.com")
             })
    {
        content = content.Replace(production, staging, StringComparison.OrdinalIgnoreCase);
    }
    return new MemoryStream(System.Text.Encoding.UTF8.GetBytes(content), writable: false);
}

static bool RemotePrefixMatches(SftpClient client, string remoteFile, Stream localFile, long length)
    => RemoteMatchingPrefixLength(client, remoteFile, localFile, length) == length;

static long RemoteMatchingPrefixLength(SftpClient client, string remoteFile, Stream localFile, long length)
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
