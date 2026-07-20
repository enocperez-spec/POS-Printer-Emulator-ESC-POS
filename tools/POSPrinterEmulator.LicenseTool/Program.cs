using System.Security.Cryptography;
using POSPrinterEmulator.Licensing;

try
{
    var command = args.FirstOrDefault()?.ToLowerInvariant();
    if (command == "generate-keys")
    {
        var output = GetRequiredOption(args, "--output");
        Directory.CreateDirectory(output);
        var privatePath = Path.Combine(output, "vendor-private-key.pem");
        var publicPath = Path.Combine(output, "vendor-public-key.pem");
        if (File.Exists(privatePath) || File.Exists(publicPath))
        {
            throw new InvalidOperationException("A vendor key already exists in that folder. Existing keys were not overwritten.");
        }

        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        File.WriteAllText(privatePath, key.ExportECPrivateKeyPem());
        File.WriteAllText(publicPath, key.ExportSubjectPublicKeyInfoPem());
        Console.WriteLine($"Private key: {privatePath}");
        Console.WriteLine($"Public key:  {publicPath}");
        Console.WriteLine("Back up the private key securely. Never commit it to GitHub or include it in the installer.");
    }
    else if (command == "issue")
    {
        var privateKeyPath = GetRequiredOption(args, "--private-key");
        var customer = GetRequiredOption(args, "--customer");
        var email = GetRequiredOption(args, "--email");
        var tier = Enum.Parse<LicenseTier>(GetOption(args, "--tier") ?? nameof(LicenseTier.Pro), ignoreCase: true);
        var privateKey = File.ReadAllText(privateKeyPath);
        var maintenanceExpiration = GetOption(args, "--maintenance-expires");
        var activationKey = maintenanceExpiration is null
            ? ActivationKeyCodec.Issue(privateKey, customer, email, tier)
            : ActivationKeyCodec.Issue(
                privateKey,
                customer,
                email,
                tier,
                DateTimeOffset.UtcNow,
                DateTimeOffset.Parse(
                    maintenanceExpiration,
                    System.Globalization.CultureInfo.InvariantCulture,
                    System.Globalization.DateTimeStyles.AssumeUniversal |
                    System.Globalization.DateTimeStyles.AdjustToUniversal));
        Console.WriteLine(activationKey);
    }
    else if (command == "verify")
    {
        var activationKey = GetRequiredOption(args, "--key");
        var customer = GetRequiredOption(args, "--customer");
        var email = GetRequiredOption(args, "--email");
        if (!ActivationKeyCodec.TryValidate(activationKey, customer, email, out var license, out var error))
        {
            throw new InvalidOperationException(error);
        }
        var maintenance = license!.MaintenanceExpiresAt is { } expiresAt
            ? $"; maintenance through: {expiresAt:u}"
            : "; maintenance: legacy coverage applies";
        Console.WriteLine($"Valid {license.Tier} activation key. License ID: {license.LicenseId}; issued: {license.IssuedAt:u}{maintenance}");
    }
    else
    {
        Console.WriteLine("""
            POS Printer Emulator license utility

            Generate the vendor key pair once:
              dotnet run --project tools/POSPrinterEmulator.LicenseTool -- generate-keys --output "C:\secure\license-keys"

            Issue a customer activation key:
              dotnet run --project tools/POSPrinterEmulator.LicenseTool -- issue --private-key "C:\secure\license-keys\vendor-private-key.pem" --customer "Company Name" --email "customer@example.com" --tier Lite

            Preserve a specific maintenance date when replacing an existing permanent license:
              add --maintenance-expires "2027-07-19T23:59:59Z"

            Paid license levels:
              Lite        All paid features with one printer listener
              Pro         All paid features with up to two printer listeners
              Enterprise  All enterprise features with up to fifteen printer listeners

            Every newly issued paid key includes one year of Application Maintenance and Support.
            The paid application license remains permanent when maintenance expires.

            Verify any activation key against the public key built into the application:
              dotnet run --project tools/POSPrinterEmulator.LicenseTool -- verify --key "PPE1-..." --customer "Company Name" --email "customer@example.com"
            """);
    }

    return 0;
}
catch (Exception exception)
{
    Console.Error.WriteLine(exception.Message);
    return 1;
}

static string GetRequiredOption(string[] arguments, string name)
{
    var index = Array.FindIndex(arguments, argument => string.Equals(argument, name, StringComparison.OrdinalIgnoreCase));
    if (index < 0 || index + 1 >= arguments.Length || string.IsNullOrWhiteSpace(arguments[index + 1]))
    {
        throw new ArgumentException($"{name} is required.");
    }

    return arguments[index + 1];
}

static string? GetOption(string[] arguments, string name)
{
    var index = Array.FindIndex(arguments, argument => string.Equals(argument, name, StringComparison.OrdinalIgnoreCase));
    return index >= 0 && index + 1 < arguments.Length && !string.IsNullOrWhiteSpace(arguments[index + 1])
        ? arguments[index + 1]
        : null;
}
