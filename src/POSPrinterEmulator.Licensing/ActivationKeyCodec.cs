using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;

namespace POSPrinterEmulator.Licensing;

public enum LicenseTier : byte
{
    Trial = 0,
    Pro = 1,
    Enterprise = 2,
    Lite = 3
}

public sealed record ActivationLicense(
    Guid LicenseId,
    DateTimeOffset IssuedAt,
    LicenseTier Tier,
    DateTimeOffset? MaintenanceExpiresAt = null,
    byte KeyVersion = 1);

public static class ActivationKeyCodec
{
    private const string Prefix = "PPE1-";
    private const int LegacyPayloadLength = 57;
    private const int TieredPayloadLength = 58;
    private const int MaintenancePayloadLength = 66;
    private const int SignatureLength = 64;

    public const string PublicKeyPem = """
        -----BEGIN PUBLIC KEY-----
        MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAED3+Ri70tH3d3xwqo+BbNLpB/6PO7
        QHRHrhZ5k/2TgMcJ4r56fOz+LwHzBzrCHTbs+unRmS46TdHdldpIO0E9+A==
        -----END PUBLIC KEY-----
        """;

    public static string Issue(string privateKeyPem, string customerName, string emailAddress) =>
        Issue(privateKeyPem, customerName, emailAddress, LicenseTier.Pro);

    public static string Issue(
        string privateKeyPem,
        string customerName,
        string emailAddress,
        LicenseTier tier)
    {
        var issuedAt = DateTimeOffset.UtcNow;
        return Issue(
            privateKeyPem,
            customerName,
            emailAddress,
            tier,
            issuedAt,
            issuedAt.AddYears(1));
    }

    public static string Issue(
        string privateKeyPem,
        string customerName,
        string emailAddress,
        LicenseTier tier,
        DateTimeOffset issuedAt,
        DateTimeOffset maintenanceExpiresAt)
    {
        ValidateRegistration(customerName, emailAddress);
        if (tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise)
        {
            throw new ArgumentOutOfRangeException(nameof(tier), "Activation keys can only be issued for Lite, Pro, or Enterprise licenses.");
        }

        var payload = new byte[MaintenancePayloadLength];
        payload[0] = 3;
        Guid.NewGuid().TryWriteBytes(payload.AsSpan(1, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(17, 8), issuedAt.ToUnixTimeSeconds());
        RegistrationHashV3(CanonicalizeCustomerV3(customerName)).CopyTo(payload, 25);
        RegistrationHashV3(CanonicalizeEmailV3(emailAddress)).CopyTo(payload, 41);
        payload[57] = (byte)tier;
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(58, 8), maintenanceExpiresAt.ToUnixTimeSeconds());

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
            if (token.Length == 0)
            {
                error = "The activation key is incomplete or damaged.";
                return false;
            }

            var payloadLength = token[0] switch
            {
                1 when token.Length == LegacyPayloadLength + SignatureLength => LegacyPayloadLength,
                2 when token.Length == TieredPayloadLength + SignatureLength => TieredPayloadLength,
                3 when token.Length == MaintenancePayloadLength + SignatureLength => MaintenancePayloadLength,
                _ => 0
            };
            if (payloadLength == 0)
            {
                error = "The activation key is incomplete or damaged.";
                return false;
            }

            var payload = token.AsSpan(0, payloadLength);
            var asciiCustomerHash = RegistrationHashV3(CanonicalizeCustomerV3(customerName));
            var asciiEmailHash = RegistrationHashV3(CanonicalizeEmailV3(emailAddress));
            var asciiRegistrationMatches =
                CryptographicOperations.FixedTimeEquals(payload.Slice(25, 16), asciiCustomerHash) &&
                CryptographicOperations.FixedTimeEquals(payload.Slice(41, 16), asciiEmailHash);
            var legacyRegistrationMatches = token[0] is 1 or 2 &&
                CryptographicOperations.FixedTimeEquals(payload.Slice(25, 16), LegacyRegistrationHash(customerName)) &&
                CryptographicOperations.FixedTimeEquals(payload.Slice(41, 16), LegacyRegistrationHash(emailAddress.ToLowerInvariant()));
            if (!asciiRegistrationMatches && !legacyRegistrationMatches)
            {
                error = "This activation key was issued for a different customer name or email address.";
                return false;
            }

            using var verifier = ECDsa.Create();
            verifier.ImportFromPem(publicKeyPem);
            if (!verifier.VerifyData(
                    payload,
                    token.AsSpan(payloadLength, SignatureLength),
                    HashAlgorithmName.SHA256,
                    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            {
                error = "The activation key signature is invalid.";
                return false;
            }

            var licenseId = new Guid(payload.Slice(1, 16));
            var issuedAt = DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(payload.Slice(17, 8)));
            var tier = token[0] == 1 ? LicenseTier.Pro : (LicenseTier)payload[57];
            if (tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise)
            {
                error = "The activation key contains an unsupported license level.";
                return false;
            }

            DateTimeOffset? maintenanceExpiresAt = token[0] == 3
                ? DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(payload.Slice(58, 8)))
                : null;
            license = new ActivationLicense(licenseId, issuedAt, tier, maintenanceExpiresAt, token[0]);
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

    public static string CreateRegistrationDigest(string customerName, string emailAddress)
    {
        ValidateRegistration(customerName, emailAddress);
        var canonicalRegistration = $"{CanonicalizeCustomerV3(customerName)}\n{CanonicalizeEmailV3(emailAddress)}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonicalRegistration)))
            .ToLowerInvariant();
    }

    public static string CanonicalizeCustomer(string customerName)
    {
        if (string.IsNullOrWhiteSpace(customerName))
        {
            throw new ArgumentException("Customer or company name is required.", nameof(customerName));
        }
        return CanonicalizeCustomerV3(customerName);
    }

    public static string NormalizeCustomerName(string customerName)
    {
        if (string.IsNullOrWhiteSpace(customerName))
        {
            throw new ArgumentException("Customer or company name is required.", nameof(customerName));
        }
        return NormalizeCustomerWhitespace(customerName);
    }

    public static string CanonicalizeEmail(string emailAddress)
    {
        ValidateRegistration("Customer", emailAddress);
        return CanonicalizeEmailV3(emailAddress);
    }

    private static byte[] LegacyRegistrationHash(string value) =>
        SHA256.HashData(Encoding.UTF8.GetBytes(LegacyNormalize(value))).AsSpan(0, 16).ToArray();

    private static byte[] RegistrationHashV3(string canonicalValue) =>
        SHA256.HashData(Encoding.UTF8.GetBytes(canonicalValue)).AsSpan(0, 16).ToArray();

    private static string LegacyNormalize(string value) =>
        string.Join(' ', value.Trim().Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries)).ToUpperInvariant();

    private static string CanonicalizeCustomerV3(string value)
    {
        var normalized = NormalizeCustomerWhitespace(value);
        var result = new StringBuilder(normalized.Length);
        foreach (var character in normalized)
        {
            result.Append(character is >= 'a' and <= 'z' ? (char)(character - 32) : character);
        }
        return result.ToString();
    }

    private static string NormalizeCustomerWhitespace(string value)
    {
        var result = new StringBuilder(value.Length);
        var pendingSpace = false;
        foreach (var character in value)
        {
            if (IsAsciiWhitespace(character))
            {
                pendingSpace = result.Length > 0;
                continue;
            }
            if (pendingSpace)
            {
                result.Append(' ');
                pendingSpace = false;
            }
            result.Append(character);
        }
        return result.ToString();
    }

    private static string CanonicalizeEmailV3(string value)
    {
        var start = 0;
        var end = value.Length;
        while (start < end && IsAsciiWhitespace(value[start])) start++;
        while (end > start && IsAsciiWhitespace(value[end - 1])) end--;
        var result = new StringBuilder(end - start);
        for (var index = start; index < end; index++)
        {
            var character = value[index];
            result.Append(character is >= 'A' and <= 'Z' ? (char)(character + 32) : character);
        }
        return result.ToString();
    }

    private static bool IsAsciiWhitespace(char character) =>
        character is ' ' or '\t' or '\r' or '\n' or '\v' or '\f';

    private static string Base64UrlEncode(ReadOnlySpan<byte> value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static byte[] Base64UrlDecode(string value)
    {
        var base64 = value.Replace('-', '+').Replace('_', '/');
        base64 += new string('=', (4 - base64.Length % 4) % 4);
        return Convert.FromBase64String(base64);
    }
}
