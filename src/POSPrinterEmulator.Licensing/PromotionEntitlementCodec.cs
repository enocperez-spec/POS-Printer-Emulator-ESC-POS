using System.Buffers.Binary;
using System.Security.Cryptography;

namespace POSPrinterEmulator.Licensing;

public enum PromotionSubjectType : byte
{
    License = 1,
    Installation = 2
}

public sealed record PromotionEntitlement(
    Guid PromotionId,
    PromotionSubjectType SubjectType,
    Guid SubjectId,
    DateTimeOffset IssuedAt,
    DateTimeOffset ExpiresAt,
    LicenseTier PreviousTier,
    LicenseTier GrantedTier);

public static class PromotionEntitlementCodec
{
    private const string Prefix = "PPEP1-";
    private const int PayloadLength = 52;
    private const int SignatureLength = 64;

    public static string Issue(
        string privateKeyPem,
        Guid promotionId,
        PromotionSubjectType subjectType,
        Guid subjectId,
        DateTimeOffset issuedAt,
        DateTimeOffset expiresAt,
        LicenseTier previousTier,
        LicenseTier grantedTier)
    {
        ValidateClaims(promotionId, subjectType, subjectId, issuedAt, expiresAt, previousTier, grantedTier);
        var payload = new byte[PayloadLength];
        payload[0] = 1;
        promotionId.TryWriteBytes(payload.AsSpan(1, 16));
        payload[17] = (byte)subjectType;
        subjectId.TryWriteBytes(payload.AsSpan(18, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(34, 8), issuedAt.ToUnixTimeSeconds());
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(42, 8), expiresAt.ToUnixTimeSeconds());
        payload[50] = (byte)previousTier;
        payload[51] = (byte)grantedTier;

        using var signer = ECDsa.Create();
        signer.ImportFromPem(privateKeyPem);
        var signature = signer.SignData(
            payload,
            HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        if (signature.Length != SignatureLength)
        {
            throw new CryptographicException("The promotion signature has an unexpected length.");
        }
        return Prefix + Base64UrlEncode(payload.Concat(signature).ToArray());
    }

    public static bool TryValidate(
        string token,
        out PromotionEntitlement? entitlement,
        out string error) =>
        TryValidateWithPublicKey(token, ActivationKeyCodec.PublicKeyPem, out entitlement, out error);

    public static bool TryValidateWithPublicKey(
        string token,
        string publicKeyPem,
        out PromotionEntitlement? entitlement,
        out string error)
    {
        entitlement = null;
        error = string.Empty;
        try
        {
            var compact = string.Concat(token.Where(character => !char.IsWhiteSpace(character)));
            if (!compact.StartsWith(Prefix, StringComparison.OrdinalIgnoreCase))
            {
                error = "The promotional access key format is not recognized.";
                return false;
            }
            var bytes = Base64UrlDecode(compact[Prefix.Length..]);
            if (bytes.Length != PayloadLength + SignatureLength || bytes[0] != 1)
            {
                error = "The promotional access key is incomplete or damaged.";
                return false;
            }
            using var verifier = ECDsa.Create();
            verifier.ImportFromPem(publicKeyPem);
            if (!verifier.VerifyData(
                    bytes.AsSpan(0, PayloadLength),
                    bytes.AsSpan(PayloadLength, SignatureLength),
                    HashAlgorithmName.SHA256,
                    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            {
                error = "The promotional access key signature is invalid.";
                return false;
            }

            var parsed = new PromotionEntitlement(
                new Guid(bytes.AsSpan(1, 16)),
                (PromotionSubjectType)bytes[17],
                new Guid(bytes.AsSpan(18, 16)),
                DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(bytes.AsSpan(34, 8))),
                DateTimeOffset.FromUnixTimeSeconds(BinaryPrimitives.ReadInt64BigEndian(bytes.AsSpan(42, 8))),
                (LicenseTier)bytes[50],
                (LicenseTier)bytes[51]);
            ValidateClaims(
                parsed.PromotionId,
                parsed.SubjectType,
                parsed.SubjectId,
                parsed.IssuedAt,
                parsed.ExpiresAt,
                parsed.PreviousTier,
                parsed.GrantedTier);
            entitlement = parsed;
            return true;
        }
        catch (Exception exception) when (exception is FormatException or CryptographicException or ArgumentException)
        {
            error = "The promotional access key could not be validated.";
            return false;
        }
    }

    private static void ValidateClaims(
        Guid promotionId,
        PromotionSubjectType subjectType,
        Guid subjectId,
        DateTimeOffset issuedAt,
        DateTimeOffset expiresAt,
        LicenseTier previousTier,
        LicenseTier grantedTier)
    {
        if (promotionId == Guid.Empty || subjectId == Guid.Empty ||
            subjectType is not PromotionSubjectType.License and not PromotionSubjectType.Installation ||
            expiresAt <= issuedAt || expiresAt - issuedAt > TimeSpan.FromDays(5).Add(TimeSpan.FromMinutes(5)) ||
            previousTier is not LicenseTier.Trial and not LicenseTier.Lite and not LicenseTier.Pro ||
            grantedTier is not LicenseTier.Lite and not LicenseTier.Pro and not LicenseTier.Enterprise ||
            TierRank(grantedTier) <= TierRank(previousTier))
        {
            throw new ArgumentException("The promotional entitlement claims are invalid.");
        }
    }

    private static int TierRank(LicenseTier tier) => tier switch
    {
        LicenseTier.Trial => 0,
        LicenseTier.Lite => 1,
        LicenseTier.Pro => 2,
        LicenseTier.Enterprise => 3,
        _ => -1
    };

    private static string Base64UrlEncode(ReadOnlySpan<byte> value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static byte[] Base64UrlDecode(string value)
    {
        var base64 = value.Replace('-', '+').Replace('_', '/');
        base64 += new string('=', (4 - base64.Length % 4) % 4);
        return Convert.FromBase64String(base64);
    }
}
