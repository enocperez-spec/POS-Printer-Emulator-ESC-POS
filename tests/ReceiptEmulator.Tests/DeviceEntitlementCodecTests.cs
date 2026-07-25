using System.Security.Cryptography;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator.Tests;

public sealed class DeviceEntitlementCodecTests
{
    [Fact]
    public void SignedEntitlementRoundTripsAndIsBoundToItsComputer()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var licenseId = Guid.NewGuid();
        var customerId = Guid.NewGuid();
        var installationId = Guid.NewGuid();
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var expiresAt = issuedAt.AddYears(1);
        var token = DeviceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            licenseId,
            customerId,
            installationId,
            LicenseTier.Enterprise,
            issuedAt,
            expiresAt,
            revision: 7);

        var valid = DeviceEntitlementCodec.TryValidateWithPublicKey(
            token,
            installationId,
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out var error);

        Assert.True(valid, error);
        Assert.Equal(licenseId, entitlement?.LicenseId);
        Assert.Equal(customerId, entitlement?.CustomerId);
        Assert.Equal(installationId, entitlement?.InstallationId);
        Assert.Equal(LicenseTier.Enterprise, entitlement?.Tier);
        Assert.Equal(issuedAt.AddHours(24), entitlement?.ValidUntil);
        Assert.Equal(expiresAt, entitlement?.MaintenanceExpiresAt);
        Assert.Equal(7, entitlement?.Revision);
    }

    [Fact]
    public void EntitlementCannotBeUsedByAnotherComputer()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var token = DeviceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            Guid.NewGuid(),
            Guid.NewGuid(),
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1),
            revision: 1);

        var valid = DeviceEntitlementCodec.TryValidateWithPublicKey(
            token,
            Guid.NewGuid(),
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out var error);

        Assert.False(valid);
        Assert.Null(entitlement);
        Assert.Contains("different computer", error, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void TamperedEntitlementIsRejected()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issuedAt = DateTimeOffset.FromUnixTimeSeconds(1_810_000_000);
        var token = DeviceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            Guid.NewGuid(),
            Guid.NewGuid(),
            LicenseTier.Lite,
            issuedAt,
            issuedAt.AddYears(1),
            revision: 1);
        const int tamperIndex = 30;
        var replacement = token[tamperIndex] == 'A' ? 'B' : 'A';

        var valid = DeviceEntitlementCodec.TryValidateWithPublicKey(
            token[..tamperIndex] + replacement + token[(tamperIndex + 1)..],
            Guid.NewGuid(),
            vendorKey.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out _);

        Assert.False(valid);
        Assert.Null(entitlement);
    }
}
