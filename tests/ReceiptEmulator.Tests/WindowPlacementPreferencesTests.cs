using POSPrinterEmulator.Desktop;

namespace ReceiptEmulator.Tests;

public sealed class WindowPlacementPreferencesTests
{
    [Fact]
    public void LoadReturnsNullWhenPreferencesDoNotExist()
    {
        var path = Path.Combine(Path.GetTempPath(), Guid.NewGuid().ToString("N"), "placement.json");

        Assert.Null(WindowPlacementPreferencesStore.Load(path));
    }

    [Fact]
    public void SaveAndLoadRoundTripWindowPlacement()
    {
        var directory = Directory.CreateTempSubdirectory("ppe-window-placement-");
        try
        {
            var path = Path.Combine(directory.FullName, "placement.json");
            var expected = new WindowPlacementPreferences(100, 80, 1380, 900, "Maximized");

            Assert.True(WindowPlacementPreferencesStore.TrySave(path, expected));

            Assert.Equal(expected, WindowPlacementPreferencesStore.Load(path));
        }
        finally
        {
            directory.Delete(recursive: true);
        }
    }

    [Fact]
    public void LoadIgnoresDamagedPreferences()
    {
        var directory = Directory.CreateTempSubdirectory("ppe-window-placement-");
        try
        {
            var path = Path.Combine(directory.FullName, "placement.json");
            File.WriteAllText(path, "{not-json");

            Assert.Null(WindowPlacementPreferencesStore.Load(path));
        }
        finally
        {
            directory.Delete(recursive: true);
        }
    }

    [Fact]
    public void NormalizeKeepsRestoredWindowInsideDesktopWorkArea()
    {
        var normalized = WindowPlacementPreferencesStore.Normalize(
            new WindowPlacementPreferences(-50, -40, 1600, 900, "Normal"),
            [new WindowWorkArea(0, 0, 1200, 700, IsPrimary: true)]);

        Assert.Equal(new WindowPlacementPreferences(0, 0, 1200, 700, "Normal"), normalized);
    }

    [Fact]
    public void NormalizeUsesPrimaryWorkAreaWhenSavedMonitorIsMissing()
    {
        var normalized = WindowPlacementPreferencesStore.Normalize(
            new WindowPlacementPreferences(5000, 5000, 6280, 5820, "Maximized"),
            [new WindowWorkArea(0, 0, 1920, 1040, IsPrimary: true)]);

        Assert.Equal(new WindowPlacementPreferences(320, 110, 1600, 930, "Maximized"), normalized);
    }

    [Fact]
    public void NormalizePreservesPlacementOnAnAvailableSecondaryMonitor()
    {
        var expected = new WindowPlacementPreferences(2100, 100, 3100, 800, "Normal");

        var normalized = WindowPlacementPreferencesStore.Normalize(
            expected,
            [
                new WindowWorkArea(0, 0, 1920, 1040, IsPrimary: true),
                new WindowWorkArea(1920, 0, 3840, 1040)
            ]);

        Assert.Equal(expected, normalized);
    }
}
