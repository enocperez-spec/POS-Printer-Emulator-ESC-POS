using System.Security.Cryptography;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator.Tests;

public sealed class MaintenanceEntitlementCodecTests
{
    [Fact]
    public void SignedEntitlementRoundTripsItsLicenseTierAndDates()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var licenseId = Guid.NewGuid();
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var expiresAt = issuedAt.AddYears(1);
        var token = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            licenseId,
            LicenseTier.Enterprise,
            issuedAt,
            expiresAt);

        var valid = MaintenanceEntitlementCodec.TryValidateWithPublicKey(
            token,
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out var error);

        Assert.True(valid, error);
        Assert.Equal(licenseId, entitlement?.LicenseId);
        Assert.Equal(LicenseTier.Enterprise, entitlement?.Tier);
        Assert.Equal(issuedAt, entitlement?.IssuedAt);
        Assert.Equal(expiresAt, entitlement?.MaintenanceExpiresAt);
    }

    [Fact]
    public void TamperedEntitlementIsRejected()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var token = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1));
        const int tamperIndex = 30;
        var replacement = token[tamperIndex] == 'A' ? 'B' : 'A';

        var valid = MaintenanceEntitlementCodec.TryValidateWithPublicKey(
            token[..tamperIndex] + replacement + token[(tamperIndex + 1)..],
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out _);

        Assert.False(valid);
        Assert.Null(entitlement);
    }

    [Fact]
    public void EntitlementCannotBeIssuedForATrialLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);

        Assert.Throws<ArgumentOutOfRangeException>(() => MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            LicenseTier.Trial,
            issuedAt,
            issuedAt.AddYears(1)));
    }
}
