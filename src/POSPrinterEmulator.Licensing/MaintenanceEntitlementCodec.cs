using System.Buffers.Binary;
using System.Security.Cryptography;

namespace POSPrinterEmulator.Licensing;

public sealed record MaintenanceEntitlement(
    Guid LicenseId,
    DateTimeOffset IssuedAt,
    DateTimeOffset MaintenanceExpiresAt,
    LicenseTier Tier);

public static class MaintenanceEntitlementCodec
{
    private const string Prefix = "PPEM1-";
    private const int PayloadLength = 34;
    private const int SignatureLength = 64;

    public static string Issue(
        string privateKeyPem,
        Guid licenseId,
        LicenseTier tier,
        DateTimeOffset maintenanceExpiresAt) =>
        Issue(privateKeyPem, licenseId, tier, DateTimeOffset.UtcNow, maintenanceExpiresAt);

    public static string Issue(
        string privateKeyPem,
        Guid licenseId,
        LicenseTier tier,
        DateTimeOffset issuedAt,
        DateTimeOffset maintenanceExpiresAt)
    {
        if (licenseId == Guid.Empty)
        {
            throw new ArgumentException("A maintenance entitlement requires a license ID.", nameof(licenseId));
        }
        if (tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise)
        {
            throw new ArgumentOutOfRangeException(nameof(tier), "Maintenance can only be issued for Lite, Pro, or Enterprise licenses.");
        }
        if (maintenanceExpiresAt <= issuedAt)
        {
            throw new ArgumentOutOfRangeException(
                nameof(maintenanceExpiresAt),
                "The maintenance expiration must be later than the entitlement issue time.");
        }

        var payload = new byte[PayloadLength];
        payload[0] = 1;
        licenseId.TryWriteBytes(payload.AsSpan(1, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(17, 8), issuedAt.ToUnixTimeSeconds());
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(25, 8), maintenanceExpiresAt.ToUnixTimeSeconds());
        payload[33] = (byte)tier;

        using var signer = ECDsa.Create();
        signer.ImportFromPem(privateKeyPem);
        var signature = signer.SignData(
            payload,
            HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        if (signature.Length != SignatureLength)
        {
            throw new CryptographicException("The maintenance signature has an unexpected length.");
        }

        var token = new byte[PayloadLength + SignatureLength];
        payload.CopyTo(token, 0);
        signature.CopyTo(token, PayloadLength);
        return Prefix + Base64UrlEncode(token);
    }

    public static bool TryValidate(
        string entitlementToken,
        out MaintenanceEntitlement? entitlement,
        out string error) =>
        TryValidateWithPublicKey(
            entitlementToken,
            ActivationKeyCodec.PublicKeyPem,
            out entitlement,
            out error);

    public static bool TryValidateWithPublicKey(
        string entitlementToken,
        string publicKeyPem,
        out MaintenanceEntitlement? entitlement,
        out string error)
    {
        entitlement = null;
        error = string.Empty;
        try
        {
            var compactToken = string.Concat(entitlementToken.Where(character => !char.IsWhiteSpace(character)));
            if (!compactToken.StartsWith(Prefix, StringComparison.OrdinalIgnoreCase))
            {
                error = "The maintenance renewal key format is not recognized.";
                return false;
            }

            var token = Base64UrlDecode(compactToken[Prefix.Length..]);
            if (token.Length != PayloadLength + SignatureLength || token[0] != 1)
            {
                error = "The maintenance renewal key is incomplete or damaged.";
                return false;
            }

            using var verifier = ECDsa.Create();
            verifier.ImportFromPem(publicKeyPem);
            if (!verifier.VerifyData(
                    token.AsSpan(0, PayloadLength),
                    token.AsSpan(PayloadLength, SignatureLength),
                    HashAlgorithmName.SHA256,
                    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            {
                error = "The maintenance renewal key signature is invalid.";
                return false;
            }

            var licenseId = new Guid(token.AsSpan(1, 16));
            var issuedAt = DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(token.AsSpan(17, 8)));
            var maintenanceExpiresAt = DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(token.AsSpan(25, 8)));
            var tier = (LicenseTier)token[33];
            if (licenseId == Guid.Empty ||
                tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise ||
                maintenanceExpiresAt <= issuedAt)
            {
                error = "The maintenance renewal key contains invalid entitlement data.";
                return false;
            }

            entitlement = new MaintenanceEntitlement(licenseId, issuedAt, maintenanceExpiresAt, tier);
            return true;
        }
        catch (Exception exception) when (exception is FormatException or CryptographicException or ArgumentException)
        {
            error = "The maintenance renewal key could not be validated.";
            return false;
        }
    }

    private static string Base64UrlEncode(ReadOnlySpan<byte> value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static byte[] Base64UrlDecode(string value)
    {
        var base64 = value.Replace('-', '+').Replace('_', '/');
        base64 += new string('=', (4 - base64.Length % 4) % 4);
        return Convert.FromBase64String(base64);
    }
}
