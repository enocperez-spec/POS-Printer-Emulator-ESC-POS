using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class ConfigurationBackupServiceTests
{
    [Fact]
    public void EncryptedPackageRoundTripsWithoutLicenseOrRegistrationData()
    {
        var payload = Payload();

        var package = BackupPackageCodec.Create(payload, "correct horse battery staple");
        var restored = BackupPackageCodec.Read(package, "correct horse battery staple");

        Assert.Equal("POS Printer Emulator Backup", restored.Format);
        Assert.Equal("dark", restored.Preferences.Theme);
        Assert.Single(restored.PrinterListeners);
        Assert.DoesNotContain("activation", Convert.ToHexString(package), StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("customer@example.com", Convert.ToHexString(package), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void WrongPasswordFailsWithoutReturningPartialData()
    {
        var package = BackupPackageCodec.Create(Payload(), "correct horse battery staple");

        var exception = Assert.Throws<InvalidDataException>(() =>
            BackupPackageCodec.Read(package, "wrong password value"));

        Assert.Contains("incorrect", exception.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void TamperedPackageFailsAuthentication()
    {
        var package = BackupPackageCodec.Create(Payload(), "correct horse battery staple");
        package[^1] ^= 0x40;

        Assert.Throws<InvalidDataException>(() =>
            BackupPackageCodec.Read(package, "correct horse battery staple"));
    }

    [Fact]
    public void BackupPasswordRequiresAtLeastTenCharacters()
    {
        var exception = Assert.Throws<ArgumentException>(() =>
            BackupPackageCodec.Create(Payload(), "short"));

        Assert.Contains("10 to 256", exception.Message, StringComparison.Ordinal);
    }

    private static ConfigurationBackupPayload Payload()
    {
        var now = DateTimeOffset.Parse("2026-07-21T15:00:00Z");
        return new(
            "POS Printer Emulator Backup",
            1,
            "0.3.33",
            now,
            false,
            new("dark", false, true),
            new(PrinterProfileService.EpsonTmT88VId, []),
            [new(
                PrinterListenerDefaults.DefaultId,
                PrinterListenerDefaults.DefaultName,
                PrinterListenerDefaults.DefaultBindAddress,
                PrinterListenerDefaults.DefaultPort,
                PrinterProfileService.EpsonTmT88VId,
                true,
                PrinterListenerDefaults.DefaultIdleJobTimeoutMilliseconds,
                PrinterListenerDefaults.DefaultMaximumJobBytes,
                new(),
                now,
                now)],
            new Dictionary<string, PrinterStateSnapshot>(),
            [],
            []);
    }
}
