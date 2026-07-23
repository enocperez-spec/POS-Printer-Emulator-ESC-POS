using System.IO;
using System.Text;
using System.Text.Json;

namespace POSPrinterEmulator.Desktop;

internal sealed record WindowPlacementPreferences(
    int Left,
    int Top,
    int Right,
    int Bottom,
    string State)
{
    public bool IsMaximized =>
        State.Equals("Maximized", StringComparison.OrdinalIgnoreCase);
}

internal readonly record struct WindowWorkArea(
    int Left,
    int Top,
    int Right,
    int Bottom,
    bool IsPrimary = false);

internal static class WindowPlacementPreferencesStore
{
    private const int PreferredWidth = 1280;
    private const int PreferredHeight = 820;
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true
    };

    public static string DefaultPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "POSPrinterEmulator",
        "window-placement.json");

    public static WindowPlacementPreferences? Load(string path)
    {
        try
        {
            if (!File.Exists(path))
            {
                return null;
            }

            return JsonSerializer.Deserialize<WindowPlacementPreferences>(
                File.ReadAllText(path, Encoding.UTF8),
                JsonOptions);
        }
        catch (Exception exception) when (
            exception is IOException or UnauthorizedAccessException or JsonException)
        {
            return null;
        }
    }

    public static bool TrySave(string path, WindowPlacementPreferences preferences)
    {
        try
        {
            var directory = Path.GetDirectoryName(path);
            if (string.IsNullOrWhiteSpace(directory))
            {
                return false;
            }

            Directory.CreateDirectory(directory);
            var temporaryPath = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
            try
            {
                File.WriteAllText(
                    temporaryPath,
                    JsonSerializer.Serialize(preferences, JsonOptions),
                    new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
                File.Move(temporaryPath, path, overwrite: true);
                return true;
            }
            finally
            {
                if (File.Exists(temporaryPath))
                {
                    File.Delete(temporaryPath);
                }
            }
        }
        catch (Exception exception) when (
            exception is IOException or UnauthorizedAccessException)
        {
            return false;
        }
    }

    public static WindowPlacementPreferences Normalize(
        WindowPlacementPreferences preferences,
        IReadOnlyCollection<WindowWorkArea> availableWorkAreas)
    {
        var workAreas = availableWorkAreas
            .Where(IsValid)
            .ToArray();
        if (workAreas.Length == 0)
        {
            return preferences;
        }

        var requestedIsValid = IsValid(preferences);
        var requested = requestedIsValid
            ? new WindowWorkArea(
                preferences.Left,
                preferences.Top,
                preferences.Right,
                preferences.Bottom)
            : default;
        var bestWorkArea = requestedIsValid
            ? workAreas
                .Select(workArea => new
                {
                    WorkArea = workArea,
                    Intersection = IntersectionArea(requested, workArea)
                })
                .OrderByDescending(candidate => candidate.Intersection)
                .First()
            : null;

        if (bestWorkArea is null || bestWorkArea.Intersection == 0)
        {
            var fallback = workAreas.FirstOrDefault(workArea => workArea.IsPrimary);
            if (!IsValid(fallback))
            {
                fallback = workAreas[0];
            }

            var fallbackWidth = Math.Min(PreferredWidth, fallback.Right - fallback.Left);
            var fallbackHeight = Math.Min(PreferredHeight, fallback.Bottom - fallback.Top);
            var fallbackLeft = fallback.Left + (fallback.Right - fallback.Left - fallbackWidth) / 2;
            var fallbackTop = fallback.Top + (fallback.Bottom - fallback.Top - fallbackHeight) / 2;
            return new WindowPlacementPreferences(
                fallbackLeft,
                fallbackTop,
                fallbackLeft + fallbackWidth,
                fallbackTop + fallbackHeight,
                preferences.IsMaximized ? "Maximized" : "Normal");
        }

        var selected = bestWorkArea.WorkArea;
        var width = Math.Min(preferences.Right - preferences.Left, selected.Right - selected.Left);
        var height = Math.Min(preferences.Bottom - preferences.Top, selected.Bottom - selected.Top);
        var left = Math.Clamp(preferences.Left, selected.Left, selected.Right - width);
        var top = Math.Clamp(preferences.Top, selected.Top, selected.Bottom - height);
        return new WindowPlacementPreferences(
            left,
            top,
            left + width,
            top + height,
            preferences.IsMaximized ? "Maximized" : "Normal");
    }

    private static bool IsValid(WindowPlacementPreferences preferences) =>
        (long)preferences.Right - preferences.Left >= 320 &&
        (long)preferences.Bottom - preferences.Top >= 240;

    private static bool IsValid(WindowWorkArea workArea) =>
        workArea.Right > workArea.Left &&
        workArea.Bottom > workArea.Top;

    private static long IntersectionArea(WindowWorkArea first, WindowWorkArea second)
    {
        var width = Math.Max(
            0L,
            Math.Min((long)first.Right, second.Right) -
            Math.Max((long)first.Left, second.Left));
        var height = Math.Max(
            0L,
            Math.Min((long)first.Bottom, second.Bottom) -
            Math.Max((long)first.Top, second.Top));
        return width * height;
    }
}
