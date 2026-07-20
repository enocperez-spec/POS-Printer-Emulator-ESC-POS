using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Configuration;
using POSPrinterEmulator.Licensing;
using System.Security.Cryptography;

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
        Assert.Equal(-1, status.Remaining);
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

    private sealed class TestEnvironment : IHostEnvironment
    {
        public string EnvironmentName { get; set; } = "Testing";
        public string ApplicationName { get; set; } = "ReceiptEmulator.Tests";
        public string ContentRootPath { get; set; } = Path.GetTempPath();
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }
}
