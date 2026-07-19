using System.Security.Cryptography;
using System.Buffers.Binary;
using System.Text;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator.Tests;

public sealed class ActivationKeyCodecTests
{
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
