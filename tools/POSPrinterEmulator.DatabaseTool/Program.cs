using MySqlConnector;
using System.Net.Http.Json;
using System.Text.Json;

const string HostVariable = "PPE_DB_HOST";
const string PortVariable = "PPE_DB_PORT";
const string UserVariable = "PPE_DB_USER";
const string PasswordVariable = "PPE_DB_PASSWORD";
const string DatabaseVariable = "PPE_DB_NAME";
const string AdminUserVariable = "PPE_ADMIN_USER";
const string AdminPasswordVariable = "PPE_ADMIN_PASSWORD";

if (args.Length == 0 || args[0] is "-h" or "--help")
{
    Console.WriteLine("Usage:");
    Console.WriteLine("  database-tool inspect");
    Console.WriteLine("  database-tool apply <schema-file>");
    Console.WriteLine("  database-tool remote-apply <https-setup-url>");
    Console.WriteLine("  database-tool smoke-test <https-telemetry-url> <https-setup-url>");
    Console.WriteLine();
    Console.WriteLine($"Connection settings are read from {HostVariable}, {PortVariable}, {UserVariable}, {PasswordVariable}, and (for apply) {DatabaseVariable}.");
    return 0;
}

var command = args[0].ToLowerInvariant();
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
            appVersion = "0.3.12",
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
                appVersion = "0.3.12",
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
    Server = RequiredEnvironmentVariable(HostVariable),
    Port = uint.TryParse(Environment.GetEnvironmentVariable(PortVariable), out var port) ? port : 3306,
    UserID = RequiredEnvironmentVariable(UserVariable),
    Password = RequiredEnvironmentVariable(PasswordVariable),
    SslMode = MySqlSslMode.Required,
    ConnectionTimeout = 15,
    DefaultCommandTimeout = 30
};

if (command == "apply")
{
    builder.Database = RequiredEnvironmentVariable(DatabaseVariable);
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
    var statements = scriptText
        .Split(';', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries);
    foreach (var statement in statements)
    {
        await using var command = new MySqlCommand(statement, connection);
        await command.ExecuteNonQueryAsync();
    }

    Console.WriteLine($"Schema applied successfully ({statements.Length} statements). Database: {connection.Database}");
}

static string RequiredEnvironmentVariable(string name) =>
    Environment.GetEnvironmentVariable(name) is { Length: > 0 } value
        ? value
        : throw new InvalidOperationException($"Required environment variable {name} is not set.");
