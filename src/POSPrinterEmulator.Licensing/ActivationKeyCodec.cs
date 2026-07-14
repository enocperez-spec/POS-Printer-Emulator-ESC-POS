using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;

namespace POSPrinterEmulator.Licensing;

public sealed record ActivationLicense(Guid LicenseId, DateTimeOffset IssuedAt);

public static class ActivationKeyCodec
{
    private const string Prefix = "PPE1-";
    private const int PayloadLength = 57;
    private const int SignatureLength = 64;

    public const string PublicKeyPem = """
        -----BEGIN PUBLIC KEY-----
        MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAED3+Ri70tH3d3xwqo+BbNLpB/6PO7
        QHRHrhZ5k/2TgMcJ4r56fOz+LwHzBzrCHTbs+unRmS46TdHdldpIO0E9+A==
        -----END PUBLIC KEY-----
        """;

    public static string Issue(string privateKeyPem, string customerName, string emailAddress)
    {
        ValidateRegistration(customerName, emailAddress);
        var payload = new byte[PayloadLength];
        payload[0] = 1;
        Guid.NewGuid().TryWriteBytes(payload.AsSpan(1, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(17, 8), DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        RegistrationHash(customerName).CopyTo(payload, 25);
        RegistrationHash(emailAddress.ToLowerInvariant()).CopyTo(payload, 41);

        using var signer = ECDsa.Create();
        signer.ImportFromPem(privateKeyPem);
        var signature = signer.SignData(
            payload,
            HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        if (signature.Length != SignatureLength)
        {
            throw new CryptographicException("The activation signature has an unexpected length.");
        }

        var token = new byte[payload.Length + signature.Length];
        payload.CopyTo(token, 0);
        signature.CopyTo(token, payload.Length);
        return Prefix + Base64UrlEncode(token);
    }

    public static bool TryValidate(
        string activationKey,
        string customerName,
        string emailAddress,
        out ActivationLicense? license,
        out string error) =>
        TryValidateWithPublicKey(activationKey, customerName, emailAddress, PublicKeyPem, out license, out error);

    public static bool TryValidateWithPublicKey(
        string activationKey,
        string customerName,
        string emailAddress,
        string publicKeyPem,
        out ActivationLicense? license,
        out string error)
    {
        license = null;
        error = string.Empty;
        try
        {
            ValidateRegistration(customerName, emailAddress);
            var compactKey = string.Concat(activationKey.Where(character => !char.IsWhiteSpace(character)));
            if (!compactKey.StartsWith(Prefix, StringComparison.OrdinalIgnoreCase))
            {
                error = "The activation key format is not recognized.";
                return false;
            }

            var token = Base64UrlDecode(compactKey[Prefix.Length..]);
            if (token.Length != PayloadLength + SignatureLength || token[0] != 1)
            {
                error = "The activation key is incomplete or damaged.";
                return false;
            }

            var payload = token.AsSpan(0, PayloadLength);
            if (!CryptographicOperations.FixedTimeEquals(payload.Slice(25, 16), RegistrationHash(customerName)) ||
                !CryptographicOperations.FixedTimeEquals(payload.Slice(41, 16), RegistrationHash(emailAddress.ToLowerInvariant())))
            {
                error = "This activation key was issued for a different customer name or email address.";
                return false;
            }

            using var verifier = ECDsa.Create();
            verifier.ImportFromPem(publicKeyPem);
            if (!verifier.VerifyData(
                    payload,
                    token.AsSpan(PayloadLength, SignatureLength),
                    HashAlgorithmName.SHA256,
                    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            {
                error = "The activation key signature is invalid.";
                return false;
            }

            var licenseId = new Guid(payload.Slice(1, 16));
            var issuedAt = DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(payload.Slice(17, 8)));
            license = new ActivationLicense(licenseId, issuedAt);
            return true;
        }
        catch (Exception exception) when (exception is FormatException or CryptographicException or ArgumentException)
        {
            error = "The activation key could not be validated.";
            return false;
        }
    }

    public static void ValidateRegistration(string customerName, string emailAddress)
    {
        if (string.IsNullOrWhiteSpace(customerName))
        {
            throw new ArgumentException("Customer or company name is required.", nameof(customerName));
        }

        var email = emailAddress.Trim();
        if (string.IsNullOrWhiteSpace(email) || !email.Contains('@') || email.StartsWith('@') || email.EndsWith('@'))
        {
            throw new ArgumentException("A valid email address is required.", nameof(emailAddress));
        }
    }

    private static byte[] RegistrationHash(string value) =>
        SHA256.HashData(Encoding.UTF8.GetBytes(Normalize(value))).AsSpan(0, 16).ToArray();

    private static string Normalize(string value) =>
        string.Join(' ', value.Trim().Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries)).ToUpperInvariant();

    private static string Base64UrlEncode(ReadOnlySpan<byte> value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static byte[] Base64UrlDecode(string value)
    {
        var base64 = value.Replace('-', '+').Replace('_', '/');
        base64 += new string('=', (4 - base64.Length % 4) % 4);
        return Convert.FromBase64String(base64);
    }
}
