using System.Buffers.Binary;
using System.Security.Cryptography;

namespace POSPrinterEmulator.Licensing;

public sealed record DeviceEntitlement(
    Guid LicenseId,
    Guid CustomerId,
    Guid InstallationId,
    DateTimeOffset IssuedAt,
    DateTimeOffset ValidUntil,
    DateTimeOffset MaintenanceExpiresAt,
    LicenseTier Tier,
    long Revision);

public static class DeviceEntitlementCodec
{
    private const string Prefix = "PPED1-";
    private const int PayloadLength = 82;
    private const int SignatureLength = 64;

    public static string Issue(
        string privateKeyPem,
        Guid licenseId,
        Guid customerId,
        Guid installationId,
        LicenseTier tier,
        DateTimeOffset issuedAt,
        DateTimeOffset maintenanceExpiresAt,
        long revision)
        => Issue(
            privateKeyPem,
            licenseId,
            customerId,
            installationId,
            tier,
            issuedAt,
            issuedAt.AddHours(24),
            maintenanceExpiresAt,
            revision);

    public static string Issue(
        string privateKeyPem,
        Guid licenseId,
        Guid customerId,
        Guid installationId,
        LicenseTier tier,
        DateTimeOffset issuedAt,
        DateTimeOffset validUntil,
        DateTimeOffset maintenanceExpiresAt,
        long revision)
    {
        if (licenseId == Guid.Empty || customerId == Guid.Empty || installationId == Guid.Empty)
        {
            throw new ArgumentException("License, customer, and installation identifiers are required.");
        }
        if (tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise)
        {
            throw new ArgumentOutOfRangeException(nameof(tier));
        }
        if (revision < 1 || validUntil <= issuedAt)
        {
            throw new ArgumentOutOfRangeException(nameof(revision));
        }

        var payload = new byte[PayloadLength];
        payload[0] = 2;
        licenseId.TryWriteBytes(payload.AsSpan(1, 16));
        customerId.TryWriteBytes(payload.AsSpan(17, 16));
        installationId.TryWriteBytes(payload.AsSpan(33, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(49, 8), issuedAt.ToUnixTimeSeconds());
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(57, 8), validUntil.ToUnixTimeSeconds());
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(65, 8), maintenanceExpiresAt.ToUnixTimeSeconds());
        payload[73] = (byte)tier;
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(74, 8), revision);

        using var signer = ECDsa.Create();
        signer.ImportFromPem(privateKeyPem);
        var signature = signer.SignData(
            payload,
            HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        return Prefix + Base64UrlEncode(payload.Concat(signature).ToArray());
    }

    public static bool TryValidateWithPublicKey(
        string token,
        Guid expectedInstallationId,
        string publicKeyPem,
        out DeviceEntitlement? entitlement,
        out string error)
    {
        entitlement = null;
        error = string.Empty;
        try
        {
            var compact = string.Concat((token ?? string.Empty).Where(character => !char.IsWhiteSpace(character)));
            if (!compact.StartsWith(Prefix, StringComparison.Ordinal))
            {
                error = "The device entitlement format is not recognized.";
                return false;
            }
            var decoded = Base64UrlDecode(compact[Prefix.Length..]);
            if (decoded.Length != PayloadLength + SignatureLength || decoded[0] != 2)
            {
                error = "The device entitlement is incomplete or damaged.";
                return false;
            }
            using var verifier = ECDsa.Create();
            verifier.ImportFromPem(publicKeyPem);
            if (!verifier.VerifyData(
                    decoded.AsSpan(0, PayloadLength),
                    decoded.AsSpan(PayloadLength, SignatureLength),
                    HashAlgorithmName.SHA256,
                    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            {
                error = "The device entitlement signature is invalid.";
                return false;
            }

            var licenseId = new Guid(decoded.AsSpan(1, 16));
            var customerId = new Guid(decoded.AsSpan(17, 16));
            var installationId = new Guid(decoded.AsSpan(33, 16));
            var issuedAt = DateTimeOffset.FromUnixTimeSeconds(
                BinaryPrimitives.ReadInt64BigEndian(decoded.AsSpan(49, 8)));
            var validUntil = DateTimeOffset.FromUnixTimeSeconds(
                BinaryPrimitives.ReadInt64BigEndian(decoded.AsSpan(57, 8)));
            var tier = (LicenseTier)decoded[73];
            var revision = BinaryPrimitives.ReadInt64BigEndian(decoded.AsSpan(74, 8));
            if (installationId != expectedInstallationId)
            {
                error = "This entitlement belongs to a different computer.";
                return false;
            }
            if (licenseId == Guid.Empty || customerId == Guid.Empty ||
                tier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise ||
                revision < 1 ||
                validUntil <= issuedAt)
            {
                error = "The device entitlement contains unsupported claims.";
                return false;
            }
            entitlement = new DeviceEntitlement(
                licenseId,
                customerId,
                installationId,
                issuedAt,
                validUntil,
                DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(decoded.AsSpan(65, 8))),
                tier,
                revision);
            return true;
        }
        catch (Exception exception) when (exception is FormatException or CryptographicException or ArgumentException)
        {
            error = "The device entitlement could not be validated.";
            return false;
        }
    }

    private static string Base64UrlEncode(byte[] value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static byte[] Base64UrlDecode(string value)
    {
        var normalized = value.Replace('-', '+').Replace('_', '/');
        normalized = normalized.PadRight(normalized.Length + ((4 - normalized.Length % 4) % 4), '=');
        return Convert.FromBase64String(normalized);
    }
}
