using MySqlConnector;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

const string HostVariable = "PPE_DB_HOST";
const string PortVariable = "PPE_DB_PORT";
const string UserVariable = "PPE_DB_USER";
const string PasswordVariable = "PPE_DB_PASSWORD";
const string DatabaseVariable = "PPE_DB_NAME";
const string AdminUserVariable = "PPE_ADMIN_USER";
const string AdminPasswordVariable = "PPE_ADMIN_PASSWORD";
const string EnvironmentVariable = "PPE_ENVIRONMENT";
const string StagingAllowedHostVariable = "PPE_STAGING_ALLOWED_HOST";
const string StagingResetConfirmationVariable = "PPE_STAGING_RESET_CONFIRM";
const string StagingEmailDomainVariable = "PPE_STAGING_EMAIL_DOMAIN";
const string StagingDatabaseLabelVariable = "PPE_STAGING_DATABASE_LABEL";
const string CertificationHostVariable = "PPE_CERT_DB_HOST";
const string CertificationPortVariable = "PPE_CERT_DB_PORT";
const string CertificationUserVariable = "PPE_CERT_DB_USER";
const string CertificationPasswordVariable = "PPE_CERT_DB_PASSWORD";
const string CertificationDatabaseVariable = "PPE_CERT_DB_NAME";

if (args.Length == 0 || args[0] is "-h" or "--help")
{
    Console.WriteLine("Usage:");
    Console.WriteLine("  database-tool inspect");
    Console.WriteLine("  database-tool validate-schema <schema-file>");
    Console.WriteLine("  database-tool apply <schema-file>");
    Console.WriteLine("  database-tool staging-rebuild <schema-file>");
    Console.WriteLine("  database-tool staging-seed");
    Console.WriteLine("  database-tool staging-rebuild-and-seed <schema-file>");
    Console.WriteLine("  database-tool staging-inspect");
    Console.WriteLine("  database-tool staging-customer-status <email>");
    Console.WriteLine("  database-tool staging-license-status <email>");
    Console.WriteLine("  database-tool staging-reset-checkout-rate <email>");
    Console.WriteLine("  database-tool remote-apply <https-setup-url>");
    Console.WriteLine("  database-tool smoke-test <https-telemetry-url> <https-setup-url>");
    Console.WriteLine();
    Console.WriteLine($"Connection settings are read from {HostVariable}, {PortVariable}, {UserVariable}, {PasswordVariable}, and (for apply) {DatabaseVariable}.");
    Console.WriteLine("Staging commands use the PPE_CERT_DB_HOST, PPE_CERT_DB_PORT, PPE_CERT_DB_USER, PPE_CERT_DB_PASSWORD, and PPE_CERT_DB_NAME variables.");
    Console.WriteLine($"Destructive staging commands additionally require {EnvironmentVariable}=staging,");
    Console.WriteLine(
        $"{StagingDatabaseLabelVariable}=<label containing sandbox or staging>, " +
        $"{StagingAllowedHostVariable}=<exact database host>, and " +
        $"{StagingResetConfirmationVariable}=RESET:<database name>.");
    return 0;
}

var command = args[0].ToLowerInvariant();
var isStagingCommand = command is
    "staging-rebuild" or
    "staging-seed" or
    "staging-rebuild-and-seed" or
    "staging-inspect" or
    "staging-customer-status" or
    "staging-license-status" or
    "staging-reset-checkout-rate";
if (command == "validate-schema")
{
    if (args.Length < 2)
    {
        throw new ArgumentException("The validate-schema command requires a schema file.");
    }

    var schemaPath = Path.GetFullPath(args[1]);
    var statements = SplitSqlStatements(await File.ReadAllTextAsync(schemaPath));
    if (statements.Length == 0)
    {
        throw new InvalidDataException($"No SQL statements were found in {schemaPath}.");
    }

    Console.WriteLine($"Schema validation passed: {statements.Length} SQL statements in {schemaPath}.");
    return 0;
}

if (command == "smoke-test")
{
    if (args.Length < 3 ||
        !Uri.TryCreate(args[1], UriKind.Absolute, out var telemetryUri) || telemetryUri.Scheme != Uri.UriSchemeHttps ||
        !Uri.TryCreate(args[2], UriKind.Absolute, out var cleanupUri) || cleanupUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The smoke-test command requires HTTPS telemetry and setup URLs.");
    }

    var installationId = Guid.NewGuid();
    using var httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
    try
    {
        using var registerResponse = await httpClient.PostAsJsonAsync(telemetryUri, new
        {
            action = "register",
            installationId,
            customerName = "Deployment Smoke Test",
            emailAddress = "smoke-test@posprinteremulator.com",
            appVersion = "0.3.26",
            licenseMode = "Trial",
            licenseId = (string?)null
        });
        registerResponse.EnsureSuccessStatusCode();
        using var registration = JsonDocument.Parse(await registerResponse.Content.ReadAsStringAsync());
        var token = registration.RootElement.GetProperty("token").GetString()
            ?? throw new InvalidDataException("The telemetry API did not return a token.");

        using var eventRequest = new HttpRequestMessage(HttpMethod.Post, telemetryUri)
        {
            Content = JsonContent.Create(new
            {
                action = "event",
                installationId,
                @event = "launch",
                count = 1,
                customerName = "Deployment Smoke Test",
                emailAddress = "smoke-test@posprinteremulator.com",
                appVersion = "0.3.26",
                licenseMode = "Trial",
                licenseId = (string?)null
            })
        };
        eventRequest.Headers.Add("X-Installation-Token", token);
        using var eventResponse = await httpClient.SendAsync(eventRequest);
        eventResponse.EnsureSuccessStatusCode();
        Console.WriteLine("Production telemetry registration and authenticated event reporting passed.");
    }
    finally
    {
        using var cleanupResponse = await httpClient.PostAsJsonAsync(cleanupUri, new
        {
            username = RequiredEnvironmentVariable(AdminUserVariable),
            password = RequiredEnvironmentVariable(AdminPasswordVariable),
            action = "cleanup-smoke-test",
            installationId
        });
        cleanupResponse.EnsureSuccessStatusCode();
    }
    return 0;
}

if (command == "remote-apply")
{
    if (args.Length < 2 || !Uri.TryCreate(args[1], UriKind.Absolute, out var setupUri) || setupUri.Scheme != Uri.UriSchemeHttps)
    {
        throw new ArgumentException("The remote-apply command requires an HTTPS setup URL.");
    }

    using var httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
    using var response = await httpClient.PostAsJsonAsync(setupUri, new
    {
        username = RequiredEnvironmentVariable(AdminUserVariable),
        password = RequiredEnvironmentVariable(AdminPasswordVariable)
    });
    var responseText = await response.Content.ReadAsStringAsync();
    if (!response.IsSuccessStatusCode)
    {
        throw new InvalidOperationException($"Remote schema setup failed with HTTP {(int)response.StatusCode}: {responseText}");
    }
    Console.WriteLine("Remote database schema applied successfully.");
    return 0;
}

var builder = new MySqlConnectionStringBuilder
{
    Server = RequiredEnvironmentVariable(isStagingCommand ? CertificationHostVariable : HostVariable),
    Port = uint.TryParse(
        ReadEnvironmentVariable(isStagingCommand ? CertificationPortVariable : PortVariable),
        out var port)
        ? port
        : 3306,
    UserID = RequiredEnvironmentVariable(isStagingCommand ? CertificationUserVariable : UserVariable),
    Password = RequiredEnvironmentVariable(isStagingCommand ? CertificationPasswordVariable : PasswordVariable),
    SslMode = MySqlSslMode.Required,
    ConnectionTimeout = 15,
    DefaultCommandTimeout = 30
};

if (command is
    "apply" or
    "staging-rebuild" or
    "staging-seed" or
    "staging-rebuild-and-seed" or
    "staging-inspect" or
    "staging-customer-status" or
    "staging-license-status" or
    "staging-reset-checkout-rate")
{
    builder.Database = RequiredEnvironmentVariable(
        isStagingCommand ? CertificationDatabaseVariable : DatabaseVariable);
}

if (isStagingCommand)
{
    RequireStagingSafety(builder);
}

await using var connection = new MySqlConnection(builder.ConnectionString);
await connection.OpenAsync();

switch (command)
{
    case "inspect":
        await InspectAsync(connection);
        break;
    case "apply":
        if (args.Length < 2)
        {
            throw new ArgumentException("The apply command requires a schema file.");
        }

        await ApplyAsync(connection, Path.GetFullPath(args[1]));
        break;
    case "staging-rebuild":
        if (args.Length < 2)
        {
            throw new ArgumentException("The staging-rebuild command requires a schema file.");
        }

        await RebuildStagingAsync(connection, Path.GetFullPath(args[1]));
        break;
    case "staging-seed":
        await SeedStagingAsync(connection);
        break;
    case "staging-rebuild-and-seed":
        if (args.Length < 2)
        {
            throw new ArgumentException("The staging-rebuild-and-seed command requires a schema file.");
        }

        await RebuildStagingAsync(connection, Path.GetFullPath(args[1]));
        await SeedStagingAsync(connection);
        break;
    case "staging-inspect":
        await InspectStagingSchemaAsync(connection);
        break;
    case "staging-customer-status":
        if (args.Length < 2 || string.IsNullOrWhiteSpace(args[1]))
        {
            throw new ArgumentException("The staging-customer-status command requires an email address.");
        }

        await ShowStagingCustomerStatusAsync(connection, args[1]);
        break;
    case "staging-license-status":
        if (args.Length < 2 || string.IsNullOrWhiteSpace(args[1]))
        {
            throw new ArgumentException("The staging-license-status command requires an email address.");
        }

        await ShowStagingLicenseStatusAsync(connection, args[1]);
        break;
    case "staging-reset-checkout-rate":
        if (args.Length < 2 || string.IsNullOrWhiteSpace(args[1]))
        {
            throw new ArgumentException("The staging-reset-checkout-rate command requires an email address.");
        }

        await ResetStagingCheckoutRateAsync(connection, args[1]);
        break;
    default:
        throw new ArgumentException($"Unknown command: {args[0]}");
}

return 0;

static async Task InspectAsync(MySqlConnection connection)
{
    await using var versionCommand = new MySqlCommand("SELECT VERSION()", connection);
    Console.WriteLine($"Server version: {await versionCommand.ExecuteScalarAsync()}");
    Console.WriteLine("Accessible databases:");

    await using var command = new MySqlCommand("SHOW DATABASES", connection);
    await using var reader = await command.ExecuteReaderAsync();
    while (await reader.ReadAsync())
    {
        var name = reader.GetString(0);
        if (!name.Equals("information_schema", StringComparison.OrdinalIgnoreCase))
        {
            Console.WriteLine($"  {name}");
        }
    }
}

static async Task ApplyAsync(MySqlConnection connection, string schemaPath)
{
    if (!File.Exists(schemaPath))
    {
        throw new FileNotFoundException("Schema file was not found.", schemaPath);
    }

    var scriptText = await File.ReadAllTextAsync(schemaPath);
    var statements = SplitSqlStatements(scriptText);
    foreach (var statement in statements)
    {
        await using var command = new MySqlCommand(statement, connection);
        await command.ExecuteNonQueryAsync();
    }

    Console.WriteLine($"Schema applied successfully ({statements.Length} statements). Database: {connection.Database}");
}

static async Task ShowStagingCustomerStatusAsync(MySqlConnection connection, string email)
{
    const string sql =
        """
        SELECT customer_id, display_name, email_verified_at, status
        FROM customers
        WHERE canonical_email = @email
        ORDER BY updated_at DESC
        """;

    await using var command = new MySqlCommand(sql, connection);
    command.Parameters.AddWithValue("@email", email.Trim().ToLowerInvariant());
    await using var reader = await command.ExecuteReaderAsync();

    var matches = 0;
    while (await reader.ReadAsync())
    {
        matches++;
        var customerIdOrdinal = reader.GetOrdinal("customer_id");
        var displayNameOrdinal = reader.GetOrdinal("display_name");
        var verifiedAtOrdinal = reader.GetOrdinal("email_verified_at");
        var statusOrdinal = reader.GetOrdinal("status");
        var customerId = reader.GetString(customerIdOrdinal);
        var displayName = reader.GetString(displayNameOrdinal);
        var verifiedAt = reader.IsDBNull(verifiedAtOrdinal)
            ? "not verified"
            : $"{reader.GetDateTime(verifiedAtOrdinal):O} UTC";
        var status = reader.GetString(statusOrdinal);
        var maskedCustomerId = customerId.Length > 8 ? $"{customerId[..8]}…" : customerId;
        Console.WriteLine(
            $"Customer {maskedCustomerId}: {displayName}; email {verifiedAt}; account {status}.");
    }

    Console.WriteLine($"Matching staging customer accounts: {matches}.");
}

static async Task ShowStagingLicenseStatusAsync(MySqlConnection connection, string email)
{
    const string customerSql =
        """
        SELECT customer_id, display_name, email_verified_at, status
        FROM customers
        WHERE canonical_email = @email
        ORDER BY updated_at DESC
        """;

    var customers = new List<(string Id, string Name, bool Verified, string Status)>();
    await using (var customerCommand = new MySqlCommand(customerSql, connection))
    {
        customerCommand.Parameters.AddWithValue("@email", email.Trim().ToLowerInvariant());
        await using var customerReader = await customerCommand.ExecuteReaderAsync();
        while (await customerReader.ReadAsync())
        {
            customers.Add((
                customerReader.GetString("customer_id"),
                customerReader.GetString("display_name"),
                !customerReader.IsDBNull(customerReader.GetOrdinal("email_verified_at")),
                customerReader.GetString("status")));
        }
    }

    var activeCustomers = customers.Where(customer => customer.Status == "Active").ToArray();
    Console.WriteLine($"Matching staging customer accounts: {customers.Count}; active: {activeCustomers.Length}.");
    if (activeCustomers.Length != 1)
    {
        throw new InvalidOperationException(
            $"Expected exactly one active staging customer for the supplied email; found {activeCustomers.Length}.");
    }

    var customer = activeCustomers[0];
    Console.WriteLine($"Customer: {customer.Name}; email verified: {(customer.Verified ? "yes" : "no")}.");

    const string licenseSql =
        """
        SELECT license_id, license_tier, control_state, maintenance_expires_at,
               entitlement_revision, updated_at
        FROM issued_licenses
        WHERE customer_id = @customer_id AND control_state <> 'Deleted'
        ORDER BY issued_at DESC
        """;
    await using (var licenseCommand = new MySqlCommand(licenseSql, connection))
    {
        licenseCommand.Parameters.AddWithValue("@customer_id", customer.Id);
        await using var licenseReader = await licenseCommand.ExecuteReaderAsync();
        var licenseCount = 0;
        while (await licenseReader.ReadAsync())
        {
            licenseCount++;
            Console.WriteLine(
                $"License {MaskIdentifier(licenseReader.GetString("license_id"))}: " +
                $"{licenseReader.GetString("license_tier")}; " +
                $"state {licenseReader.GetString("control_state")}; " +
                $"maintenance {FormatNullableDate(licenseReader, "maintenance_expires_at")}; " +
                $"revision {licenseReader.GetUInt64("entitlement_revision")}; " +
                $"updated {GetDateTime(licenseReader, "updated_at"):O} UTC.");
        }
        Console.WriteLine($"Non-deleted licenses: {licenseCount}.");
    }

    const string installationSql =
        """
        SELECT installation_uuid, device_label, app_version, license_mode, license_id,
               maintenance_status, maintenance_expires_at, portal_deactivated_at,
               license_last_sync_at, license_last_sync_status, license_last_sync_error, last_seen_at
        FROM installations
        WHERE customer_id = @customer_id
        ORDER BY last_seen_at DESC
        """;
    await using (var installationCommand = new MySqlCommand(installationSql, connection))
    {
        installationCommand.Parameters.AddWithValue("@customer_id", customer.Id);
        await using var installationReader = await installationCommand.ExecuteReaderAsync();
        var installationCount = 0;
        while (await installationReader.ReadAsync())
        {
            installationCount++;
            Console.WriteLine(
                $"Computer {installationReader.GetString("device_label")} " +
                $"({MaskIdentifier(installationReader.GetString("installation_uuid"))}): " +
                $"app {installationReader.GetString("app_version")}; " +
                $"mode {installationReader.GetString("license_mode")}; " +
                $"license {FormatNullableIdentifier(installationReader, "license_id")}; " +
                $"maintenance {installationReader.GetString("maintenance_status")} " +
                $"through {FormatNullableDate(installationReader, "maintenance_expires_at")}; " +
                $"sync {FormatNullableString(installationReader, "license_last_sync_status")} at " +
                $"{FormatNullableDate(installationReader, "license_last_sync_at")}; " +
                $"last seen {GetDateTime(installationReader, "last_seen_at"):O} UTC; " +
                $"deactivated {(installationReader.IsDBNull(installationReader.GetOrdinal("portal_deactivated_at")) ? "no" : "yes")}.");
        }
        Console.WriteLine($"Registered computers: {installationCount}.");
    }

    const string purchaseSql =
        """
        SELECT purchase_status, order_type, license_tier, amount, currency, paid_at, updated_at
        FROM customer_purchases
        WHERE customer_id = @customer_id
        ORDER BY paid_at DESC, updated_at DESC
        LIMIT 10
        """;
    await using (var purchaseCommand = new MySqlCommand(purchaseSql, connection))
    {
        purchaseCommand.Parameters.AddWithValue("@customer_id", customer.Id);
        await using var purchaseReader = await purchaseCommand.ExecuteReaderAsync();
        var purchaseCount = 0;
        while (await purchaseReader.ReadAsync())
        {
            purchaseCount++;
            Console.WriteLine(
                $"Purchase: {purchaseReader.GetString("order_type")} " +
                $"{purchaseReader.GetString("license_tier")}; " +
                $"status {purchaseReader.GetString("purchase_status")}; " +
                $"{purchaseReader.GetDecimal(purchaseReader.GetOrdinal("amount")):0.00} {purchaseReader.GetString("currency")}; " +
                $"paid {FormatNullableDate(purchaseReader, "paid_at")}; " +
                $"updated {GetDateTime(purchaseReader, "updated_at"):O} UTC.");
        }
        Console.WriteLine($"Recent purchases returned: {purchaseCount}.");
    }
}

static string MaskIdentifier(string value) =>
    value.Length <= 8 ? value : $"{value[..8]}…";

static string FormatNullableIdentifier(MySqlDataReader reader, string column) =>
    reader.IsDBNull(reader.GetOrdinal(column)) ? "none" : MaskIdentifier(reader.GetString(column));

static string FormatNullableString(MySqlDataReader reader, string column) =>
    reader.IsDBNull(reader.GetOrdinal(column)) ? "none" : reader.GetString(column);

static string FormatNullableDate(MySqlDataReader reader, string column) =>
    reader.IsDBNull(reader.GetOrdinal(column)) ? "none" : $"{GetDateTime(reader, column):O} UTC";

static DateTime GetDateTime(MySqlDataReader reader, string column) =>
    reader.GetDateTime(reader.GetOrdinal(column));

static async Task ResetStagingCheckoutRateAsync(MySqlConnection connection, string email)
{
    const string findCustomerSql =
        """
        SELECT customer_id
        FROM customers
        WHERE canonical_email = @email AND status = 'Active'
        ORDER BY updated_at DESC
        LIMIT 2
        """;

    var customerIds = new List<string>();
    await using (var findCustomer = new MySqlCommand(findCustomerSql, connection))
    {
        findCustomer.Parameters.AddWithValue("@email", email.Trim().ToLowerInvariant());
        await using var reader = await findCustomer.ExecuteReaderAsync();
        while (await reader.ReadAsync())
        {
            customerIds.Add(reader.GetString(0));
        }
    }

    if (customerIds.Count != 1)
    {
        throw new InvalidOperationException(
            $"Expected exactly one active staging customer for the supplied email; found {customerIds.Count}.");
    }

    var bucketHash = SHA256.HashData(Encoding.UTF8.GetBytes($"checkout|{customerIds[0]}"));
    await using var delete = new MySqlCommand(
        "DELETE FROM portal_rate_limits WHERE bucket_hash = @bucket_hash",
        connection);
    delete.Parameters.Add("@bucket_hash", MySqlDbType.Binary, 32).Value = bucketHash;
    var deleted = await delete.ExecuteNonQueryAsync();
    Console.WriteLine($"Staging checkout throttle reset completed. Matching rate-limit rows removed: {deleted}.");
}


static async Task InspectStagingSchemaAsync(MySqlConnection connection)
{
    Console.WriteLine($"Staging database connection passed: {connection.Database}");
    var requiredColumns = new Dictionary<string, string[]>
    {
        ["installations"] =
        [
            "installation_uuid", "device_fingerprint_hash", "token_hash", "customer_id",
            "maintenance_status", "maintenance_expires_at", "portal_deactivated_at"
        ],
        ["customers"] =
        [
            "customer_id", "display_name", "canonical_email", "email_verified_at", "status"
        ],
        ["portal_computer_link_requests"] =
        [
            "link_id", "installation_id", "request_token_hash", "user_code_hash",
            "journey_correlation_id", "status", "expires_at"
        ],
        ["license_activation_events"] =
        [
            "installation_id", "link_id", "event_type", "outcome", "activation_method",
            "journey_correlation_id"
        ],
    };

    foreach (var (table, columns) in requiredColumns)
    {
        await using var command = new MySqlCommand(
            """
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@table
            """,
            connection);
        command.Parameters.AddWithValue("@table", table);
        var present = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        await using var reader = await command.ExecuteReaderAsync();
        while (await reader.ReadAsync()) present.Add(reader.GetString(0));
        var missing = columns.Where(column => !present.Contains(column)).ToArray();
        Console.WriteLine(missing.Length == 0
            ? $"{table}: PASS ({present.Count} columns)"
            : $"{table}: MISSING {string.Join(", ", missing)}");
    }
}

static void RequireStagingSafety(MySqlConnectionStringBuilder builder)
{
    var environment = RequiredEnvironmentVariable(EnvironmentVariable);
    if (!environment.Equals("staging", StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            $"Destructive database commands require {EnvironmentVariable}=staging.");
    }

    var database = builder.Database.Trim();
    var databaseLabel = RequiredEnvironmentVariable(StagingDatabaseLabelVariable).Trim();
    if (!System.Text.RegularExpressions.Regex.IsMatch(
            databaseLabel,
            @"(^|[_-])(staging|sandbox)([_-]|$)",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase))
    {
        throw new InvalidOperationException(
            $"{StagingDatabaseLabelVariable} must contain a distinct 'staging' or 'sandbox' segment.");
    }

    var allowedHost = RequiredEnvironmentVariable(StagingAllowedHostVariable);
    if (!builder.Server.Equals(allowedHost, StringComparison.OrdinalIgnoreCase))
    {
        throw new InvalidOperationException(
            $"The configured database host does not match {StagingAllowedHostVariable}.");
    }

    var confirmation = RequiredEnvironmentVariable(StagingResetConfirmationVariable);
    if (!confirmation.Equals($"RESET:{database}", StringComparison.Ordinal))
    {
        throw new InvalidOperationException(
            $"{StagingResetConfirmationVariable} must exactly equal RESET:{database}.");
    }
}

static async Task RebuildStagingAsync(MySqlConnection connection, string schemaPath)
{
    if (!File.Exists(schemaPath))
    {
        throw new FileNotFoundException("Schema file was not found.", schemaPath);
    }

    var tables = new List<string>();
    await using (var tableCommand = new MySqlCommand(
        """
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        """,
        connection))
    await using (var reader = await tableCommand.ExecuteReaderAsync())
    {
        while (await reader.ReadAsync())
        {
            tables.Add(reader.GetString(0));
        }
    }

    await using (var disableForeignKeys = new MySqlCommand("SET FOREIGN_KEY_CHECKS=0", connection))
    {
        await disableForeignKeys.ExecuteNonQueryAsync();
    }

    try
    {
        foreach (var table in tables)
        {
            var escapedTable = table.Replace("`", "``", StringComparison.Ordinal);
            await using var drop = new MySqlCommand($"DROP TABLE `{escapedTable}`", connection);
            await drop.ExecuteNonQueryAsync();
        }
    }
    finally
    {
        await using var enableForeignKeys = new MySqlCommand("SET FOREIGN_KEY_CHECKS=1", connection);
        await enableForeignKeys.ExecuteNonQueryAsync();
    }

    await ApplyAsync(connection, schemaPath);
    Console.WriteLine($"Staging database rebuilt after dropping {tables.Count} tables.");
}

static async Task SeedStagingAsync(MySqlConnection connection)
{
    var emailDomain = ReadEnvironmentVariable(StagingEmailDomainVariable)?.Trim()
        ?? "example.invalid";
    if (!System.Text.RegularExpressions.Regex.IsMatch(
            emailDomain,
            @"^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"))
    {
        throw new InvalidOperationException(
            $"{StagingEmailDomainVariable} must be a valid test email domain.");
    }

    var scenarios = new[]
    {
        new StagingScenario("trial", "Trial Certification", null, null),
        new StagingScenario("lite", "Lite Certification", "Lite", DateTime.UtcNow.AddYears(1)),
        new StagingScenario("pro", "Pro Certification", "Pro", DateTime.UtcNow.AddYears(1)),
        new StagingScenario("enterprise", "Enterprise Certification", "Enterprise", DateTime.UtcNow.AddYears(1)),
        new StagingScenario("expired", "Expired Maintenance Certification", "Lite", DateTime.UtcNow.AddDays(-1)),
    };

    await using var transaction = await connection.BeginTransactionAsync();
    try
    {
        for (var index = 0; index < scenarios.Length; index++)
        {
            var scenario = scenarios[index];
            var suffix = index + 1;
            var customerId = $"00000000-0000-4000-8000-{suffix:000000000000}";
            var installationUuid = $"10000000-0000-4000-8000-{suffix:000000000000}";
            var licenseId = scenario.LicenseTier is null
                ? null
                : $"20000000-0000-4000-8000-{suffix:000000000000}";
            var bindingId = scenario.LicenseTier is null
                ? null
                : $"30000000-0000-4000-8000-{suffix:000000000000}";
            var email = $"cert-{scenario.Key}@{emailDomain}".ToLowerInvariant();

            await using (var customer = new MySqlCommand(
                """
                INSERT INTO customers(
                    customer_id,display_name,company_name,canonical_email,email_hash,email_verified_at,status
                ) VALUES(
                    @customer_id,@display_name,'POS Printer Emulator Certification',@email,
                    UNHEX(SHA2(@email,256)),UTC_TIMESTAMP(6),'Active'
                )
                ON DUPLICATE KEY UPDATE
                    display_name=VALUES(display_name),
                    company_name=VALUES(company_name),
                    canonical_email=VALUES(canonical_email),
                    email_hash=VALUES(email_hash),
                    email_verified_at=VALUES(email_verified_at),
                    status='Active'
                """,
                connection,
                transaction))
            {
                customer.Parameters.AddWithValue("@customer_id", customerId);
                customer.Parameters.AddWithValue("@display_name", scenario.DisplayName);
                customer.Parameters.AddWithValue("@email", email);
                await customer.ExecuteNonQueryAsync();
            }

            if (licenseId is not null && scenario.LicenseTier is not null)
            {
                await using var license = new MySqlCommand(
                    """
                    INSERT INTO issued_licenses(
                        license_id,customer_id,customer_name,email_address,license_tier,issued_at,
                        created_by,control_state,license_source,source_reference,maintenance_expires_at
                    ) VALUES(
                        @license_id,@customer_id,@display_name,@email,@license_tier,UTC_TIMESTAMP(6),
                        'certification-seed','Enabled','Manual',@source_reference,@maintenance_expires_at
                    )
                    ON DUPLICATE KEY UPDATE
                        customer_id=VALUES(customer_id),
                        customer_name=VALUES(customer_name),
                        email_address=VALUES(email_address),
                        license_tier=VALUES(license_tier),
                        control_state='Enabled',
                        maintenance_expires_at=VALUES(maintenance_expires_at)
                    """,
                    connection,
                    transaction);
                license.Parameters.AddWithValue("@license_id", licenseId);
                license.Parameters.AddWithValue("@customer_id", customerId);
                license.Parameters.AddWithValue("@display_name", scenario.DisplayName);
                license.Parameters.AddWithValue("@email", email);
                license.Parameters.AddWithValue("@license_tier", scenario.LicenseTier);
                license.Parameters.AddWithValue("@source_reference", $"certification:{scenario.Key}");
                license.Parameters.AddWithValue(
                    "@maintenance_expires_at",
                    scenario.MaintenanceExpiresAt ?? (object)DBNull.Value);
                await license.ExecuteNonQueryAsync();
            }

            await using (var installation = new MySqlCommand(
                """
                INSERT INTO installations(
                    installation_uuid,customer_id,token_hash,customer_name,email_address,app_version,
                    windows_version,license_mode,license_id,maintenance_status,maintenance_expires_at,
                    country_code,region_code,last_launch_at,launch_count
                ) VALUES(
                    @installation_uuid,@customer_id,UNHEX(SHA2(@installation_uuid,256)),
                    @display_name,@email,'0.3.55','Windows 11 Pro',
                    @license_mode,@license_id,@maintenance_status,@maintenance_expires_at,
                    'US','GA',UTC_TIMESTAMP(6),1
                )
                ON DUPLICATE KEY UPDATE
                    customer_id=VALUES(customer_id),
                    customer_name=VALUES(customer_name),
                    email_address=VALUES(email_address),
                    app_version=VALUES(app_version),
                    windows_version=VALUES(windows_version),
                    license_mode=VALUES(license_mode),
                    license_id=VALUES(license_id),
                    maintenance_status=VALUES(maintenance_status),
                    maintenance_expires_at=VALUES(maintenance_expires_at),
                    portal_deactivated_at=NULL
                """,
                connection,
                transaction))
            {
                installation.Parameters.AddWithValue("@installation_uuid", installationUuid);
                installation.Parameters.AddWithValue("@customer_id", customerId);
                installation.Parameters.AddWithValue("@display_name", scenario.DisplayName);
                installation.Parameters.AddWithValue("@email", email);
                installation.Parameters.AddWithValue("@license_mode", scenario.LicenseTier ?? "Trial");
                installation.Parameters.AddWithValue("@license_id", licenseId ?? (object)DBNull.Value);
                installation.Parameters.AddWithValue(
                    "@maintenance_status",
                    scenario.LicenseTier is null
                        ? "NotApplicable"
                        : scenario.MaintenanceExpiresAt > DateTime.UtcNow ? "Active" : "Expired");
                installation.Parameters.AddWithValue(
                    "@maintenance_expires_at",
                    scenario.MaintenanceExpiresAt ?? (object)DBNull.Value);
                await installation.ExecuteNonQueryAsync();
            }

            if (licenseId is not null && bindingId is not null)
            {
                await using var binding = new MySqlCommand(
                    """
                    INSERT INTO license_device_bindings(
                        binding_id,license_id,customer_id,installation_id,binding_state,
                        activation_method,activated_at
                    )
                    SELECT
                        @binding_id,@license_id,@customer_id,id,'Active','AdminRecovery',UTC_TIMESTAMP(6)
                    FROM installations WHERE installation_uuid=@installation_uuid
                    ON DUPLICATE KEY UPDATE
                        license_id=VALUES(license_id),
                        customer_id=VALUES(customer_id),
                        installation_id=VALUES(installation_id),
                        binding_state='Active',
                        activated_at=VALUES(activated_at),
                        deactivated_at=NULL
                    """,
                    connection,
                    transaction);
                binding.Parameters.AddWithValue("@binding_id", bindingId);
                binding.Parameters.AddWithValue("@license_id", licenseId);
                binding.Parameters.AddWithValue("@customer_id", customerId);
                binding.Parameters.AddWithValue("@installation_uuid", installationUuid);
                await binding.ExecuteNonQueryAsync();
            }
        }

        await transaction.CommitAsync();
        Console.WriteLine(
            $"Seeded {scenarios.Length} isolated certification scenarios in database {connection.Database}.");
    }
    catch
    {
        await transaction.RollbackAsync();
        throw;
    }
}

static string[] SplitSqlStatements(string scriptText)
{
    var statements = new List<string>();
    var buffer = new StringBuilder();
    char? quote = null;
    var escaped = false;
    var lineComment = false;
    var blockComment = false;

    for (var index = 0; index < scriptText.Length; index++)
    {
        var character = scriptText[index];
        var next = index + 1 < scriptText.Length ? scriptText[index + 1] : '\0';

        if (lineComment)
        {
            if (character == '\n')
            {
                lineComment = false;
                buffer.Append(character);
            }
            continue;
        }

        if (blockComment)
        {
            if (character == '*' && next == '/')
            {
                blockComment = false;
                index++;
            }
            continue;
        }

        if (quote is not null)
        {
            buffer.Append(character);
            if (escaped)
            {
                escaped = false;
            }
            else if (character == '\\')
            {
                escaped = true;
            }
            else if (character == quote)
            {
                if (next == quote)
                {
                    buffer.Append(next);
                    index++;
                }
                else
                {
                    quote = null;
                }
            }
            continue;
        }

        if (character is '\'' or '"' or '`')
        {
            quote = character;
            buffer.Append(character);
        }
        else if (character == '#' || (character == '-' && next == '-' &&
                 (index + 2 >= scriptText.Length || char.IsWhiteSpace(scriptText[index + 2]))))
        {
            lineComment = true;
            if (character == '-')
            {
                index++;
            }
        }
        else if (character == '/' && next == '*')
        {
            blockComment = true;
            index++;
        }
        else if (character == ';')
        {
            var statement = buffer.ToString().Trim();
            if (statement.Length > 0)
            {
                statements.Add(statement);
            }
            buffer.Clear();
        }
        else
        {
            buffer.Append(character);
        }
    }

    if (quote is not null || blockComment)
    {
        throw new InvalidDataException("The schema contains an incomplete SQL statement.");
    }

    var trailingStatement = buffer.ToString().Trim();
    if (trailingStatement.Length > 0)
    {
        statements.Add(trailingStatement);
    }

    return statements.ToArray();
}

static string? ReadEnvironmentVariable(string name)
{
    var processValue = Environment.GetEnvironmentVariable(name);
    if (!string.IsNullOrWhiteSpace(processValue) || !OperatingSystem.IsWindows())
    {
        return processValue;
    }

    return Environment.GetEnvironmentVariable(name, EnvironmentVariableTarget.User);
}

static string RequiredEnvironmentVariable(string name) =>
    ReadEnvironmentVariable(name) is { Length: > 0 } value
        ? value
        : throw new InvalidOperationException($"Required environment variable {name} is not set.");

sealed record StagingScenario(
    string Key,
    string DisplayName,
    string? LicenseTier,
    DateTime? MaintenanceExpiresAt);
