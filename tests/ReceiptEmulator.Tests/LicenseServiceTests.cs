using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Configuration;
using POSPrinterEmulator.Licensing;
using System.Security.Cryptography;
using System.Buffers.Binary;
using System.Text;
using System.Text.Json;

namespace ReceiptEmulator.Tests;

public sealed class LicenseServiceTests
{
    [Fact]
    public void NewInstallationStartsInTrialAndStopsAfterFiveJobs()
    {
        var service = new LicenseService(new TestEnvironment());

        Assert.Equal("Trial", service.GetStatus().Mode);
        for (var count = 0; count < LicenseService.TrialDailyLimit; count++)
        {
            Assert.True(service.TryConsume(out _));
        }

        Assert.False(service.TryConsume(out var status));
        Assert.Equal(0, status.Remaining);
        Assert.True(status.Features.Watermark);
        Assert.False(status.Features.History);
        Assert.False(status.HasProAccess);
        Assert.False(status.IsEnterprise);
        Assert.False(status.Features.StoredLogos);
        Assert.False(status.Features.PrinterState);
        Assert.False(status.Features.PrinterProfiles);
        Assert.False(status.Features.Updates);
        Assert.False(status.Features.Support);
        Assert.False(status.Features.MultipleListeners);
        Assert.False(service.HasEnterpriseAccess);
        Assert.False(status.IsPaid);
        Assert.False(service.HasPaidAccess);
        Assert.False(service.HasMaintenanceAccess);
        Assert.False(status.Maintenance.IsApplicable);
        Assert.Equal("NotApplicable", status.Maintenance.State);
        Assert.Equal(1, status.MaximumListeners);
    }

    [Theory]
    [InlineData(LicenseTier.Lite, 1, false)]
    [InlineData(LicenseTier.Pro, 2, true)]
    [InlineData(LicenseTier.Enterprise, 15, true)]
    public void PaidTierUnlocksCurrentPaidFeaturesAndItsListenerAllowance(
        LicenseTier tier,
        int maximumListeners,
        bool multipleListeners)
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var service = new LicenseService(new TestEnvironment(), configuration);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Tier Customer", "tier@example.com", tier);

        var status = service.Activate("Tier Customer", "tier@example.com", activationKey);

        Assert.Equal(tier.ToString(), status.Mode);
        Assert.True(status.IsPaid);
        Assert.True(status.HasProAccess);
        Assert.True(service.HasPaidAccess);
        Assert.Equal(maximumListeners, status.MaximumListeners);
        Assert.Equal(multipleListeners, status.Features.MultipleListeners);
        Assert.True(status.Features.History);
        Assert.True(status.Features.Exports);
        Assert.True(status.Features.PremiumFeatures);
        Assert.False(status.Features.Watermark);
        Assert.True(status.Features.StoredLogos);
        Assert.True(status.Features.PrinterState);
        Assert.True(status.Features.PrinterProfiles);
        Assert.True(status.Features.Updates);
        Assert.True(status.Features.Support);
        Assert.True(status.Maintenance.IsActive);
        Assert.NotNull(status.Maintenance.ExpiresAt);
        Assert.Equal(-1, status.Remaining);
    }

    [Fact]
    public void VersionTwoPaidLicenseIsGrandfatheredThroughJulyNineteenth2027()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var now = new DateTimeOffset(2027, 7, 19, 20, 0, 0, TimeSpan.Zero);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = IssueVersionTwoKey(
            vendorKey,
            "Grandfathered Customer",
            "grandfathered@example.com",
            LicenseTier.Pro,
            new DateTimeOffset(2026, 7, 19, 12, 0, 0, TimeSpan.Zero));

        var active = service.Activate(
            "Grandfathered Customer",
            "grandfathered@example.com",
            activationKey);

        Assert.True(active.Maintenance.IsActive);
        Assert.True(active.Maintenance.IsGrandfathered);
        Assert.Equal(LicenseService.GrandfatheredMaintenanceExpiresAt, active.Maintenance.ExpiresAt);
        Assert.True(active.Features.Updates);
        Assert.True(active.Features.Support);

        now = new DateTimeOffset(2027, 7, 20, 0, 0, 0, TimeSpan.Zero);
        var expired = service.GetStatus();

        Assert.False(expired.Maintenance.IsActive);
        Assert.Equal("Expired", expired.Maintenance.State);
        Assert.False(expired.Features.Updates);
        Assert.False(expired.Features.Support);
        Assert.True(expired.Features.History);
        Assert.True(expired.Features.Exports);
        Assert.True(expired.Features.PremiumFeatures);
        Assert.Equal(2, expired.MaximumListeners);
        Assert.True(service.HasPaidAccess);
    }

    [Fact]
    public void ExpiredMaintenanceDoesNotDisablePermanentPaidFeatures()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var service = new LicenseService(
            new TestEnvironment(),
            configuration,
            () => issuedAt.AddYears(2));
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Permanent Customer",
            "permanent@example.com",
            LicenseTier.Lite,
            issuedAt,
            issuedAt.AddYears(1));

        var status = service.Activate("Permanent Customer", "permanent@example.com", activationKey);

        Assert.True(status.IsPaid);
        Assert.False(status.Maintenance.IsActive);
        Assert.False(service.HasMaintenanceAccess);
        Assert.False(status.Features.Updates);
        Assert.False(status.Features.Support);
        Assert.True(status.Features.History);
        Assert.True(status.Features.Exports);
        Assert.True(status.Features.PremiumFeatures);
        Assert.True(status.Features.StoredLogos);
        Assert.True(status.Features.PrinterState);
        Assert.True(status.Features.PrinterProfiles);
        Assert.False(status.Features.Watermark);
    }

    [Fact]
    public void SignedRenewalRestoresMaintenanceWithoutChangingThePermanentLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Renewal Customer",
            "renewal@example.com",
            LicenseTier.Enterprise,
            issuedAt,
            issuedAt.AddYears(1));
        var activated = service.Activate("Renewal Customer", "renewal@example.com", activationKey);
        var renewedThrough = now.AddYears(1);
        var renewalToken = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            activated.LicenseId!.Value,
            LicenseTier.Enterprise,
            now,
            renewedThrough);

        var renewed = service.InstallMaintenanceEntitlement(renewalToken);
        var reloaded = new LicenseService(new TestEnvironment(), configuration, () => now).GetStatus();

        Assert.True(renewed.Maintenance.IsActive);
        Assert.Equal(renewedThrough, renewed.Maintenance.ExpiresAt);
        Assert.True(renewed.Features.Updates);
        Assert.True(renewed.Features.Support);
        Assert.Equal("Enterprise", renewed.Mode);
        Assert.Equal(15, renewed.MaximumListeners);
        Assert.Equal(activated.LicenseId, renewed.LicenseId);
        Assert.Equal(renewed.Maintenance, reloaded.Maintenance);
        Assert.True(File.Exists(Path.Combine(root, "maintenance.json")));

        var reapplied = service.InstallMaintenanceEntitlement(renewalToken);
        Assert.Equal(renewed.Maintenance, reapplied.Maintenance);
    }

    [Fact]
    public void RenewalMustMatchTheActiveLicenseIdAndTier()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Bound Customer",
            "bound@example.com",
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1));
        service.Activate("Bound Customer", "bound@example.com", activationKey);
        var wrongLicense = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            LicenseTier.Pro,
            now,
            now.AddYears(1));
        var wrongTier = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            service.GetStatus().LicenseId!.Value,
            LicenseTier.Enterprise,
            now,
            now.AddYears(1));

        Assert.Contains("different license", Assert.Throws<InvalidOperationException>(() =>
            service.InstallMaintenanceEntitlement(wrongLicense)).Message);
        Assert.Contains("different license", Assert.Throws<InvalidOperationException>(() =>
            service.InstallMaintenanceEntitlement(wrongTier)).Message);
        Assert.False(File.Exists(Path.Combine(root, "maintenance.json")));
    }

    [Fact]
    public void RenewalCannotShortenExistingMaintenanceCoverage()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddMonths(1);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Coverage Customer",
            "coverage@example.com",
            LicenseTier.Lite,
            issuedAt,
            issuedAt.AddYears(1));
        var license = service.Activate("Coverage Customer", "coverage@example.com", activationKey);
        var shorterToken = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            license.LicenseId!.Value,
            LicenseTier.Lite,
            now,
            issuedAt.AddMonths(6));

        var exception = Assert.Throws<InvalidOperationException>(() =>
            service.InstallMaintenanceEntitlement(shorterToken));

        Assert.Contains("does not extend", exception.Message);
        Assert.Equal(issuedAt.AddYears(1), service.GetStatus().Maintenance.ExpiresAt);
    }

    [Fact]
    public void MaintenanceFileFromTheOriginalTokenOnlySchemaStillLoads()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var now = issuedAt.AddYears(2);
        var firstRun = new LicenseService(new TestEnvironment(), configuration, () => now);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Schema Customer",
            "schema@example.com",
            LicenseTier.Pro,
            issuedAt,
            issuedAt.AddYears(1));
        var activated = firstRun.Activate("Schema Customer", "schema@example.com", activationKey);
        var token = MaintenanceEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            activated.LicenseId!.Value,
            LicenseTier.Pro,
            now.AddMinutes(-1),
            now.AddYears(1));
        File.WriteAllText(
            Path.Combine(root, "maintenance.json"),
            JsonSerializer.Serialize(new { EntitlementToken = token, InstalledAt = now }));

        var reloaded = new LicenseService(new TestEnvironment(), configuration, () => now).GetStatus();

        Assert.True(reloaded.Maintenance.IsActive);
        Assert.Equal(now.AddYears(1), reloaded.Maintenance.ExpiresAt);
    }

    [Fact]
    public void MaintenanceStatusFromAnOldLicenseDoesNotDisableAReplacementLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var now = new DateTimeOffset(2026, 8, 1, 12, 0, 0, TimeSpan.Zero);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var firstKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Replacement Customer",
            "replacement@example.com",
            LicenseTier.Pro,
            now.AddDays(-10),
            now.AddYears(1));
        var first = service.Activate("Replacement Customer", "replacement@example.com", firstKey);
        service.RecordMaintenanceUnavailable("revoked", null);
        Assert.False(service.GetStatus().Maintenance.IsActive);

        var replacementKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Replacement Customer",
            "replacement@example.com",
            LicenseTier.Enterprise,
            now,
            now.AddYears(1));
        var replacement = service.Activate(
            "Replacement Customer",
            "replacement@example.com",
            replacementKey);

        Assert.NotEqual(first.LicenseId, replacement.LicenseId);
        Assert.Equal("Enterprise", replacement.Mode);
        Assert.True(replacement.Maintenance.IsActive);
        Assert.Equal("Active", replacement.Maintenance.State);
        Assert.True(replacement.Features.Updates);
        Assert.False(File.Exists(Path.Combine(root, "maintenance.json")));
    }

    [Fact]
    public void ExistingFileIsOverwrittenWhenAtomicReplacementIsDenied()
    {
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        var path = Path.Combine(root, "license.json");
        File.WriteAllText(path, "old");

        LicenseService.WriteJsonWithFallback(
            path,
            "new",
            (_, _) => throw new UnauthorizedAccessException("Temporary-file replacement is denied."));

        Assert.Equal("new", File.ReadAllText(path));
    }

    [Fact]
    public void PaidLicenseRemainsActivatedAfterServiceReload()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Upgrade Customer",
            "upgrade@example.com",
            LicenseTier.Enterprise);

        var firstRun = new LicenseService(new TestEnvironment(), configuration);
        var activated = firstRun.Activate("Upgrade Customer", "upgrade@example.com", activationKey);
        var afterUpgrade = new LicenseService(new TestEnvironment(), configuration);

        Assert.True(activated.IsEnterprise);
        Assert.True(afterUpgrade.GetStatus().IsEnterprise);
        Assert.True(afterUpgrade.HasProAccess);
    }

    [Fact]
    public void VersionThreeUnicodeRegistrationRemainsValidAfterReload()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var issuedAt = new DateTimeOffset(2026, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "José   Café",
            "JOSÉ@EXAMPLE.COM",
            LicenseTier.Lite,
            issuedAt,
            issuedAt.AddYears(1));

        new LicenseService(new TestEnvironment(), configuration).Activate(
            "José   Café",
            "JOSÉ@EXAMPLE.COM",
            activationKey);
        var reloaded = new LicenseService(new TestEnvironment(), configuration).GetStatus();

        Assert.Equal("Lite", reloaded.Mode);
        Assert.Equal("José Café", reloaded.CustomerName);
        Assert.Equal("josÉ@example.com", reloaded.EmailAddress);
        Assert.True(reloaded.IsPaid);
    }

    [Fact]
    public void TierReplacementWithPastMaintenanceStillActivatesPermanentFeatures()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var now = new DateTimeOffset(2028, 7, 20, 12, 0, 0, TimeSpan.Zero);
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        var replacementKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Tier Replacement",
            "replacement@example.com",
            LicenseTier.Enterprise,
            now,
            now.AddYears(-1));

        var status = service.Activate("Tier Replacement", "replacement@example.com", replacementKey);

        Assert.Equal("Enterprise", status.Mode);
        Assert.True(status.IsPaid);
        Assert.True(status.Features.History);
        Assert.True(status.Features.PremiumFeatures);
        Assert.Equal(15, status.MaximumListeners);
        Assert.False(status.Maintenance.IsActive);
        Assert.False(status.Features.Updates);
        Assert.False(status.Features.Support);
    }

    [Fact]
    public void ServiceRetriesLicenseLoadAfterATransientStartupMiss()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var serviceStartedBeforeFilesWereReadable = new LicenseService(new TestEnvironment(), configuration);
        var writer = new LicenseService(new TestEnvironment(), configuration);
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Recovered Customer",
            "recovered@example.com",
            LicenseTier.Pro);

        writer.Activate("Recovered Customer", "recovered@example.com", activationKey);

        Assert.True(serviceStartedBeforeFilesWereReadable.GetStatus().HasProAccess);
    }

    [Fact]
    public void InstallerRegistrationRepairsMissingRegistrationBesideExistingLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var publicKey = vendorKey.ExportSubjectPublicKeyInfoPem();
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = publicKey
        }).Build();
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Recovered Registration",
            "registration@example.com",
            LicenseTier.Enterprise);
        new LicenseService(new TestEnvironment(), configuration).Activate(
            "Recovered Registration",
            "registration@example.com",
            activationKey);
        File.Delete(Path.Combine(root, "registration.json"));

        LicenseService.RegisterInstallation(
            root,
            "Recovered Registration",
            "registration@example.com");

        Assert.Equal(
            "Enterprise",
            LicenseService.GetRequiredPersistedLicenseMode(root, publicKey));
        Assert.True(new LicenseService(new TestEnvironment(), configuration).GetStatus().IsEnterprise);
    }

    [Fact]
    public void PersistedUpgradeLicenseRequiresItsMatchingRegistration()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var publicKey = vendorKey.ExportSubjectPublicKeyInfoPem();
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = publicKey
        }).Build();
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Upgrade Pair",
            "pair@example.com",
            LicenseTier.Pro);
        new LicenseService(new TestEnvironment(), configuration).Activate(
            "Upgrade Pair",
            "pair@example.com",
            activationKey);
        File.Delete(Path.Combine(root, "registration.json"));

        var exception = Assert.Throws<InvalidOperationException>(() =>
            LicenseService.GetRequiredPersistedLicenseMode(root, publicKey));

        Assert.Contains("missing its customer registration", exception.Message);
    }

    [Fact]
    public void MissingRegistrationCanOnlyBeRepairedWithIdentityMatchingThePersistedLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var publicKey = vendorKey.ExportSubjectPublicKeyInfoPem();
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = publicKey
        }).Build();
        var activationKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Recovery Customer",
            "recovery@example.com",
            LicenseTier.Enterprise);
        new LicenseService(new TestEnvironment(), configuration).Activate(
            "Recovery Customer",
            "recovery@example.com",
            activationKey);
        var registrationPath = Path.Combine(root, "registration.json");
        File.Delete(registrationPath);

        Assert.Throws<InvalidOperationException>(() =>
            LicenseService.ValidatePersistedLicenseForRegistration(
                root,
                publicKey,
                "Wrong Customer",
                "wrong@example.com"));
        Assert.False(File.Exists(registrationPath));

        Assert.Equal(
            "Enterprise",
            LicenseService.ValidatePersistedLicenseForRegistration(
                root,
                publicKey,
                "Recovery Customer",
                "recovery@example.com"));
    }

    [Fact]
    public void ActivationStopsBeforeWritingWhenAnExistingSnapshotCannotBeRead()
    {
        if (!OperatingSystem.IsWindows()) return;

        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();
        var service = new LicenseService(new TestEnvironment(), configuration);
        var originalKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Snapshot Customer",
            "snapshot@example.com",
            LicenseTier.Pro);
        service.Activate("Snapshot Customer", "snapshot@example.com", originalKey);
        var registrationPath = Path.Combine(root, "registration.json");
        var activationPath = Path.Combine(root, "license.json");
        var originalRegistration = File.ReadAllBytes(registrationPath);
        var originalActivation = File.ReadAllBytes(activationPath);
        var replacementKey = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            "Snapshot Customer",
            "snapshot@example.com",
            LicenseTier.Enterprise);

        using (new FileStream(activationPath, FileMode.Open, FileAccess.Read, FileShare.None))
        {
            Assert.ThrowsAny<IOException>(() =>
                service.Activate("Snapshot Customer", "snapshot@example.com", replacementKey));
        }

        Assert.Equal(originalRegistration, File.ReadAllBytes(registrationPath));
        Assert.Equal(originalActivation, File.ReadAllBytes(activationPath));
    }

    [Fact]
    public void SnapshotTreatsAFileNotFoundErrorAsAnAbsentFile()
    {
        var snapshot = LicenseService.ReadSnapshot(
            "missing-license.json",
            _ => throw new FileNotFoundException());

        Assert.False(snapshot.Exists);
        Assert.Null(snapshot.Content);
    }

    [Fact]
    public void SnapshotDoesNotTreatAccessDeniedAsAnAbsentFile()
    {
        Assert.Throws<UnauthorizedAccessException>(() =>
            LicenseService.ReadSnapshot(
                "protected-license.json",
                _ => throw new UnauthorizedAccessException("Access denied.")));
    }

    [Fact]
    public void TrialPromotionUnlocksEnterpriseAndRestoresTrialAfterExpiration()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var root = NewRoot();
        var configuration = Configuration(root, vendorKey);
        var now = new DateTimeOffset(2026, 7, 23, 12, 0, 0, TimeSpan.Zero);
        var installationId = Guid.NewGuid();
        var service = new LicenseService(new TestEnvironment(), configuration, () => now);
        service.BindInstallationId(installationId);
        var token = PromotionEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            PromotionSubjectType.Installation,
            installationId,
            now,
            now.AddDays(5),
            LicenseTier.Trial,
            LicenseTier.Enterprise);

        var promoted = service.InstallPromotionEntitlement(token);

        Assert.Equal("Enterprise", promoted.Mode);
        Assert.True(promoted.Promotion.IsActive);
        Assert.Equal(now, promoted.Promotion.StartsAt);
        Assert.True(promoted.Features.History);
        Assert.Equal(15, promoted.MaximumListeners);

        now = now.AddDays(5).AddMinutes(1);
        var restored = service.GetStatus();
        Assert.Equal("Trial", restored.Mode);
        Assert.False(restored.Promotion.IsActive);
        Assert.Equal("Expired", restored.Promotion.State);
        Assert.False(restored.Features.History);
        Assert.Equal(1, restored.MaximumListeners);
    }

    [Fact]
    public void PromotionPausesWhenTheSystemClockMovesBackward()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var initial = new DateTimeOffset(2026, 7, 23, 12, 0, 0, TimeSpan.Zero);
        var now = initial;
        var installationId = Guid.NewGuid();
        var service = new LicenseService(new TestEnvironment(), Configuration(NewRoot(), vendorKey), () => now);
        service.BindInstallationId(installationId);
        service.InstallPromotionEntitlement(PromotionEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            PromotionSubjectType.Installation,
            installationId,
            initial,
            initial.AddDays(5),
            LicenseTier.Trial,
            LicenseTier.Pro));

        now = initial.AddDays(1);
        Assert.True(service.GetStatus().Promotion.IsActive);
        now = initial;

        var rolledBack = service.GetStatus();

        Assert.Equal("Trial", rolledBack.Mode);
        Assert.Equal("ClockRollback", rolledBack.Promotion.State);
        Assert.False(rolledBack.Promotion.IsActive);
    }

    [Fact]
    public void PromotionIsBoundToTheSelectedPermanentLicense()
    {
        using var vendorKey = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var now = new DateTimeOffset(2026, 7, 23, 12, 0, 0, TimeSpan.Zero);
        var service = new LicenseService(new TestEnvironment(), Configuration(NewRoot(), vendorKey), () => now);
        var activation = ActivationKeyCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(), "Promo Customer", "promo@example.com", LicenseTier.Lite, now, now.AddYears(1));
        service.Activate("Promo Customer", "promo@example.com", activation);
        var token = PromotionEntitlementCodec.Issue(
            vendorKey.ExportECPrivateKeyPem(),
            Guid.NewGuid(),
            PromotionSubjectType.License,
            Guid.NewGuid(),
            now,
            now.AddDays(5),
            LicenseTier.Lite,
            LicenseTier.Enterprise);

        Assert.Contains("different license", Assert.Throws<InvalidOperationException>(() =>
            service.InstallPromotionEntitlement(token)).Message);
    }

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }

    private static string NewRoot() =>
        Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.Tests", Guid.NewGuid().ToString("N"));

    private static IConfiguration Configuration(string root, ECDsa vendorKey) =>
        new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Data:Root"] = root,
            ["Licensing:PublicKeyPem"] = vendorKey.ExportSubjectPublicKeyInfoPem()
        }).Build();

    private static string IssueVersionTwoKey(
        ECDsa vendorKey,
        string customerName,
        string emailAddress,
        LicenseTier tier,
        DateTimeOffset issuedAt)
    {
        var payload = new byte[58];
        payload[0] = 2;
        Guid.NewGuid().TryWriteBytes(payload.AsSpan(1, 16));
        BinaryPrimitives.WriteInt64BigEndian(payload.AsSpan(17, 8), issuedAt.ToUnixTimeSeconds());

        static byte[] RegistrationHash(string value)
        {
            var normalized = string.Join(' ', value.Trim().Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries)).ToUpperInvariant();
            return SHA256.HashData(Encoding.UTF8.GetBytes(normalized)).AsSpan(0, 16).ToArray();
        }

        RegistrationHash(customerName).CopyTo(payload, 25);
        RegistrationHash(emailAddress.ToLowerInvariant()).CopyTo(payload, 41);
        payload[57] = (byte)tier;
        var signature = vendorKey.SignData(
            payload,
            HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        return "PPE1-" + Convert.ToBase64String(payload.Concat(signature).ToArray())
            .TrimEnd('=').Replace('+', '-').Replace('/', '_');
    }
}
