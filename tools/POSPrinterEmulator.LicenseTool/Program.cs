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
        var activationKey = ActivationKeyCodec.Issue(File.ReadAllText(privateKeyPath), customer, email);
        Console.WriteLine(activationKey);
    }
    else
    {
        Console.WriteLine("""
            POS Printer Emulator license utility

            Generate the vendor key pair once:
              dotnet run --project tools/POSPrinterEmulator.LicenseTool -- generate-keys --output "C:\secure\license-keys"

            Issue a customer activation key:
              dotnet run --project tools/POSPrinterEmulator.LicenseTool -- issue --private-key "C:\secure\license-keys\vendor-private-key.pem" --customer "Company Name" --email "customer@example.com"
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
