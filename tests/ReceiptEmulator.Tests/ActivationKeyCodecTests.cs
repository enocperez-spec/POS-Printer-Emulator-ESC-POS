using System.Security.Cryptography;
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
}
