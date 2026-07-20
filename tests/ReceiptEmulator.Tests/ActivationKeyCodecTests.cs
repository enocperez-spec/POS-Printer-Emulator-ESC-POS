using System.Security.Cryptography;
using System.Buffers.Binary;
using System.Text;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator.Tests;

public sealed class ActivationKeyCodecTests
{
    [Fact]
    public void LicenseTierBytesRemainBackwardCompatible()
    {
        Assert.Equal(0, (byte)LicenseTier.Trial);
        Assert.Equal(1, (byte)LicenseTier.Pro);
        Assert.Equal(2, (byte)LicenseTier.Enterprise);
        Assert.Equal(3, (byte)LicenseTier.Lite);
    }

    [Fact]
    public void IssuedKeyValidatesForMatchingRegistration()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Northwind Market", "owner@northwind.example");

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey, "Northwind   Market", "OWNER@NORTHWIND.EXAMPLE",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out var license, out var error);

        Assert.True(valid, error);
        Assert.NotNull(license);
        Assert.NotEqual(Guid.Empty, license.LicenseId);
        Assert.Equal(LicenseTier.Pro, license.Tier);
        Assert.NotNull(license.MaintenanceExpiresAt);
        Assert.Equal(license.IssuedAt.AddYears(1), license.MaintenanceExpiresAt);
    }

    [Fact]
    public void EnterpriseKeyPreservesEnterpriseTier()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Contoso Enterprise", "it@contoso.example", LicenseTier.Enterprise);

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey, "Contoso Enterprise", "it@contoso.example",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out var license, out var error);

        Assert.True(valid, error);
        Assert.Equal(LicenseTier.Enterprise, license?.Tier);
    }

    [Fact]
    public void LiteKeyPreservesLiteTier()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Contoso Lite", "lite@contoso.example", LicenseTier.Lite);

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey, "Contoso Lite", "lite@contoso.example",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out var license, out var error);

        Assert.True(valid, error);
        Assert.Equal(LicenseTier.Lite, license?.Tier);
    }

    [Fact]
    public void TrialActivationKeysCannotBeIssued()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);

        Assert.Throws<ArgumentOutOfRangeException>(() => ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Trial Customer", "trial@example.com", LicenseTier.Trial));
    }

    [Fact]
    public void LegacyPaidKeyValidatesAsPro()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = IssueLegacyKey(vendorKey, "Legacy Customer", "legacy@example.com");

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey, "Legacy Customer", "legacy@example.com",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out var license, out var error);

        Assert.True(valid, error);
        Assert.Equal(LicenseTier.Pro, license?.Tier);
        Assert.Null(license?.MaintenanceExpiresAt);
    }

    [Fact]
    public void VersionThreeKeyCarriesTheSignedMaintenanceExpiration()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_800_000_000);
        var expiresAt = issuedAt.AddYears(1);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Maintenance Customer",
            "maintenance@example.com",
            LicenseTier.Lite,
            issuedAt,
            expiresAt);

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey,
            "Maintenance Customer",
            "maintenance@example.com",
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var license,
            out var error);

        Assert.True(valid, error);
        Assert.Equal(issuedAt, license?.IssuedAt);
        Assert.Equal(expiresAt, license?.MaintenanceExpiresAt);
    }

    [Fact]
    public void VersionThreeKeyCanCarryAnAlreadyExpiredMaintenanceDateForTierReplacement()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_800_000_000);
        var expiresAt = issuedAt.AddYears(-1);

        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Maintenance Customer",
            "maintenance@example.com",
            LicenseTier.Pro,
            issuedAt,
            expiresAt);

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey,
            "Maintenance Customer",
            "maintenance@example.com",
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var license,
            out var error);

        Assert.True(valid, error);
        Assert.Equal(expiresAt, license?.MaintenanceExpiresAt);
    }

    [Fact]
    public void RegistrationDigestUsesTheCrossPlatformCanonicalFormat()
    {
        var digest = ActivationKeyCodec.CreateRegistrationDigest(
            "  Northwind\t Market  ",
            " OWNER@NORTHWIND.EXAMPLE ");

        Assert.Equal(
            "58bc64ba32a49be9f133b7a3c8e4ae7f45663760542c5c15747525fb66944c21",
            digest);
    }

    [Fact]
    public void RegistrationDigestPreservesNonAsciiCaseWithFixedAsciiCaseFolding()
    {
        var digest = ActivationKeyCodec.CreateRegistrationDigest(
            "  José\tCafé  ",
            " José@Example.COM ");

        Assert.Equal("JOSé CAFé", ActivationKeyCodec.CanonicalizeCustomer("  José\tCafé  "));
        Assert.Equal("José Café", ActivationKeyCodec.NormalizeCustomerName("  José\tCafé  "));
        Assert.Equal("josé@example.com", ActivationKeyCodec.CanonicalizeEmail(" José@Example.COM "));
        Assert.Equal(
            "3edccffc4c9e391af25c7d5c7b612cc192b2fb3872dac8588d83eb6ad075a47d",
            digest);
    }

    [Fact]
    public void VersionThreePayloadUsesTheFixedCrossPlatformRegistrationHashes()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_800_000_000);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "José Café",
            "José@Example.COM",
            LicenseTier.Lite,
            issuedAt,
            issuedAt.AddYears(1));
        var encoded = activationKey["PPE1-".Length..].Replace('-', '+').Replace('_', '/');
        encoded += new string('=', (4 - encoded.Length % 4) % 4);
        var token = Convert.FromBase64String(encoded);

        Assert.Equal("e0c8551396be02bc6377ac3d893048aa", Convert.ToHexString(token.AsSpan(25, 16)).ToLowerInvariant());
        Assert.Equal("b0a53cf19e34d05b57bced7365c6b00d", Convert.ToHexString(token.AsSpan(41, 16)).ToLowerInvariant());
    }

    [Fact]
    public void LegacyUnicodeRegistrationHashStillValidates()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = IssueLegacyKey(vendorKey, "José Café", "JOSÉ@example.com");

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey,
            "José Café",
            "JOSÉ@example.com",
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var license,
            out var error);

        Assert.True(valid, error);
        Assert.Equal(LicenseTier.Pro, license?.Tier);
    }

    [Fact]
    public void IssuedKeyRejectsDifferentEmailAddress()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Northwind Market", "owner@northwind.example");

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey, "Northwind Market", "other@northwind.example",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out _, out var error);

        Assert.False(valid);
        Assert.Contains("different customer name or email", error);
    }

    [Fact]
    public void IssuedKeyRejectsTampering()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Northwind Market", "owner@northwind.example");
        const int tamperIndex = 24;
        var replacement = activationKey[tamperIndex] == 'A' ? 'B' : 'A';

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            activationKey[..tamperIndex] + replacement + activationKey[(tamperIndex + 1)..],
            "Northwind Market", "owner@northwind.example",
            vendorKey.ExportSubjectPublicKeyInfoPem(), out _, out _);

        Assert.False(valid);
    }

    [Fact]
    public void EmptyPayloadIsRejectedWithoutThrowing()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);

        var valid = ActivationKeyCodec.TryValidateWithPublicKey(
            "PPE1-",
            "Northwind Market",
            "owner@northwind.example",
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var license,
            out var error);

        Assert.False(valid);
        Assert.Null(license);
        Assert.Contains("incomplete or damaged", error);
    }

    private static string IssueLegacyKey(ECDsa vendorKey, string customerName, string emailAddress)
    {
        var payload = new byte[57];
        payload[0] = 1;
        Guid.NewGuid().TryWriteBytes(payload.AsSpan(1, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(17, 8), DateTimeOffset.UtcNow.ToUnixTimeSeconds());

        static byte[] RegistrationHash(string value)
        {
            var normalized = string.Join(' ', value.Trim().Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries)).ToUpperInvariant();
            return SHA256.HashData(Encoding.UTF8.GetBytes(normalized)).AsSpan(0, 16).ToArray();
        }

        RegistrationHash(customerName).CopyTo(payload, 25);
        RegistrationHash(emailAddress.ToLowerInvariant()).CopyTo(payload, 41);
        var signature = vendorKey.SignData(payload, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        return "PPE1-" + Convert.ToBase64String(payload.Concat(signature).ToArray()).TrimEnd('=').Replace('+', '-').Replace('/', '_');
    }
}
