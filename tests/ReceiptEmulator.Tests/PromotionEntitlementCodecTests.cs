using System.Security.Cryptography;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator.Tests;

public sealed class PromotionEntitlementCodecTests
{
    [Fact]
    public void SignedPromotionRoundTripsAndRejectsTampering()
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var promotionId = Guid.NewGuid();
        var subjectId = Guid.NewGuid();
        var issued = new DateTimeOffset(2026, 7, 23, 12, 0, 0, TimeSpan.Zero);
        var token = PromotionEntitlementCodec.Issue(
            key.ExportECPrivateKeyPem(),
            promotionId,
            PromotionSubjectType.Installation,
            subjectId,
            issued,
            issued.AddDays(5),
            LicenseTier.Trial,
            LicenseTier.Enterprise);

        Assert.True(PromotionEntitlementCodec.TryValidateWithPublicKey(
            token,
            key.ExportSubjectPublicKeyInfoPem(),
            out var entitlement,
            out _));
        Assert.Equal(promotionId, entitlement!.PromotionId);
        Assert.Equal(subjectId, entitlement.SubjectId);
        Assert.Equal(LicenseTier.Enterprise, entitlement.GrantedTier);

        // Change a byte-bearing Base64URL character. Mutating the final character can
        // change only unused padding bits and still decode to the identical signature.
        var tamperIndex = token.Length / 2;
        var replacement = token[tamperIndex] == 'A' ? 'B' : 'A';
        Assert.False(PromotionEntitlementCodec.TryValidateWithPublicKey(
            token[..tamperIndex] + replacement + token[(tamperIndex + 1)..],
            key.ExportSubjectPublicKeyInfoPem(),
            out _,
            out _));
    }

    [Fact]
    public void PromotionCannotExceedFiveDaysPlusClockTolerance()
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var issued = DateTimeOffset.UtcNow;
        Assert.Throws<ArgumentException>(() => PromotionEntitlementCodec.Issue(
            key.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            PromotionSubjectType.License,
            Guid.NewGuid(),
            issued,
            issued.AddDays(6),
            LicenseTier.Lite,
            LicenseTier.Pro));
    }
}
