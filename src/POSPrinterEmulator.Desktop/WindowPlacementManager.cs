using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;

namespace POSPrinterEmulator.Desktop;

internal static class WindowPlacementManager
{
    private const uint SwShownormal = 1;
    private const uint SwShowmaximized = 3;
    private const uint MonitorInfoPrimary = 1;

    public static WindowState Apply(Window window)
    {
        var saved = WindowPlacementPreferencesStore.Load(
            WindowPlacementPreferencesStore.DefaultPath);
        if (saved is null)
        {
            window.WindowState = WindowState.Maximized;
            return WindowState.Maximized;
        }

        var workAreas = EnumerateWorkAreas();
        if (workAreas.Count == 0)
        {
            window.WindowState = WindowState.Maximized;
            return WindowState.Maximized;
        }

        var normalized = WindowPlacementPreferencesStore.Normalize(saved, workAreas);
        var placement = new NativeWindowPlacement
        {
            Length = Marshal.SizeOf<NativeWindowPlacement>(),
            ShowCommand = normalized.IsMaximized ? SwShowmaximized : SwShownormal,
            NormalPosition = new NativeRect
            {
                Left = normalized.Left,
                Top = normalized.Top,
                Right = normalized.Right,
                Bottom = normalized.Bottom
            }
        };

        var handle = new WindowInteropHelper(window).Handle;
        if (handle == IntPtr.Zero || !SetWindowPlacement(handle, ref placement))
        {
            window.WindowState = WindowState.Maximized;
            return WindowState.Maximized;
        }

        return normalized.IsMaximized
            ? WindowState.Maximized
            : WindowState.Normal;
    }

    public static void Save(Window window, WindowState lastNonMinimizedState)
    {
        var handle = new WindowInteropHelper(window).Handle;
        if (handle == IntPtr.Zero)
        {
            return;
        }

        var placement = new NativeWindowPlacement
        {
            Length = Marshal.SizeOf<NativeWindowPlacement>()
        };
        if (!GetWindowPlacement(handle, ref placement))
        {
            return;
        }

        var normal = placement.NormalPosition;
        var preferences = new WindowPlacementPreferences(
            normal.Left,
            normal.Top,
            normal.Right,
            normal.Bottom,
            lastNonMinimizedState == WindowState.Maximized ? "Maximized" : "Normal");
        var workAreas = EnumerateWorkAreas();
        var normalized = workAreas.Count == 0
            ? preferences
            : WindowPlacementPreferencesStore.Normalize(preferences, workAreas);
        WindowPlacementPreferencesStore.TrySave(
            WindowPlacementPreferencesStore.DefaultPath,
            normalized);
    }

    private static IReadOnlyCollection<WindowWorkArea> EnumerateWorkAreas()
    {
        var workAreas = new List<WindowWorkArea>();
        MonitorEnumProc callback = (monitor, _, _, _) =>
        {
            var info = new NativeMonitorInfo
            {
                Size = Marshal.SizeOf<NativeMonitorInfo>()
            };
            if (GetMonitorInfo(monitor, ref info))
            {
                workAreas.Add(new WindowWorkArea(
                    info.WorkArea.Left,
                    info.WorkArea.Top,
                    info.WorkArea.Right,
                    info.WorkArea.Bottom,
                    (info.Flags & MonitorInfoPrimary) != 0));
            }

            return true;
        };
        EnumDisplayMonitors(IntPtr.Zero, IntPtr.Zero, callback, IntPtr.Zero);
        GC.KeepAlive(callback);
        return workAreas;
    }

    private delegate bool MonitorEnumProc(
        IntPtr monitor,
        IntPtr deviceContext,
        IntPtr monitorRectangle,
        IntPtr data);

    [StructLayout(LayoutKind.Sequential)]
    private struct NativePoint
    {
        public int X;
        public int Y;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeRect
    {
        public int Left;
        public int Top;
        public int Right;
        public int Bottom;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeWindowPlacement
    {
        public int Length;
        public int Flags;
        public uint ShowCommand;
        public NativePoint MinimumPosition;
        public NativePoint MaximumPosition;
        public NativeRect NormalPosition;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeMonitorInfo
    {
        public int Size;
        public NativeRect Monitor;
        public NativeRect WorkArea;
        public uint Flags;
    }

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetWindowPlacement(
        IntPtr window,
        ref NativeWindowPlacement placement);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetWindowPlacement(
        IntPtr window,
        ref NativeWindowPlacement placement);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool EnumDisplayMonitors(
        IntPtr deviceContext,
        IntPtr clipRectangle,
        MonitorEnumProc callback,
        IntPtr data);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetMonitorInfo(
        IntPtr monitor,
        ref NativeMonitorInfo monitorInfo);
}
