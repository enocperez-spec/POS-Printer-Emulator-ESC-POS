using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Microsoft.Win32.SafeHandles;

namespace ReceiptEmulator;

[SupportedOSPlatform("windows")]
internal static class WindowsServiceManager
{
    private const uint ScManagerConnect = 0x0001;
    private const uint ScManagerCreateService = 0x0002;
    private const uint ServiceAllAccess = 0x000F01FF;
    private const uint DeleteAccess = 0x00010000;
    private const uint ServiceWin32OwnProcess = 0x00000010;
    private const uint ServiceAutoStart = 0x00000002;
    private const uint ServiceErrorNormal = 0x00000001;
    private const uint ServiceConfigDescription = 1;
    private const uint ServiceConfigFailureActions = 2;
    private const uint ServiceConfigDelayedAutoStartInfo = 3;
    private const uint ServiceConfigFailureActionsFlag = 4;
    private const int ScActionRestart = 1;

    public static void Create(string serviceName, string displayName, string description, string executablePath)
    {
        using var manager = OpenManager(ScManagerConnect | ScManagerCreateService);
        using var service = CreateService(
            manager,
            serviceName,
            displayName,
            ServiceAllAccess,
            ServiceWin32OwnProcess,
            ServiceAutoStart,
            ServiceErrorNormal,
            $"\"{executablePath}\"",
            null,
            IntPtr.Zero,
            null,
            @"NT AUTHORITY\LocalService",
            null);

        if (service.IsInvalid)
        {
            throw CreateWin32Exception("Windows could not create the POS Printer Emulator service");
        }

        ConfigureDescription(service, description);
        ConfigureDelayedStart(service);
        ConfigureFailureRecovery(service);
    }

    public static void Delete(string serviceName)
    {
        using var manager = OpenManager(ScManagerConnect);
        using var service = OpenService(manager, serviceName, DeleteAccess);
        if (service.IsInvalid)
        {
            var error = Marshal.GetLastWin32Error();
            if (error == 1060)
            {
                return;
            }

            throw new Win32Exception(error, "Windows could not open the POS Printer Emulator service for deletion");
        }

        if (!DeleteService(service))
        {
            throw CreateWin32Exception("Windows could not delete the POS Printer Emulator service");
        }
    }

    private static SafeServiceHandle OpenManager(uint access)
    {
        var manager = OpenSCManager(null, null, access);
        if (manager.IsInvalid)
        {
            throw CreateWin32Exception("Windows could not open the Service Control Manager");
        }

        return manager;
    }

    private static void ConfigureDescription(SafeServiceHandle service, string description)
    {
        var descriptionPointer = Marshal.StringToHGlobalUni(description);
        try
        {
            var configuration = new ServiceDescription { Description = descriptionPointer };
            if (!ChangeServiceDescription(service, ServiceConfigDescription, ref configuration))
            {
                throw CreateWin32Exception("Windows could not set the POS Printer Emulator service description");
            }
        }
        finally
        {
            Marshal.FreeHGlobal(descriptionPointer);
        }
    }

    private static void ConfigureDelayedStart(SafeServiceHandle service)
    {
        var configuration = new ServiceDelayedAutoStartInfo { DelayedAutoStart = true };
        if (!ChangeServiceDelayedStart(service, ServiceConfigDelayedAutoStartInfo, ref configuration))
        {
            throw CreateWin32Exception("Windows could not enable delayed startup for POS Printer Emulator");
        }
    }

    private static void ConfigureFailureRecovery(SafeServiceHandle service)
    {
        var actionSize = Marshal.SizeOf<ServiceAction>();
        var actionsPointer = Marshal.AllocHGlobal(actionSize * 3);
        try
        {
            var actions = new[]
            {
                new ServiceAction { Type = ScActionRestart, DelayMilliseconds = 5_000 },
                new ServiceAction { Type = ScActionRestart, DelayMilliseconds = 15_000 },
                new ServiceAction { Type = ScActionRestart, DelayMilliseconds = 60_000 }
            };

            for (var index = 0; index < actions.Length; index++)
            {
                Marshal.StructureToPtr(actions[index], IntPtr.Add(actionsPointer, index * actionSize), false);
            }

            var failureActions = new ServiceFailureActions
            {
                ResetPeriodSeconds = 86_400,
                ActionCount = (uint)actions.Length,
                Actions = actionsPointer
            };

            if (!ChangeServiceFailureActions(service, ServiceConfigFailureActions, ref failureActions))
            {
                throw CreateWin32Exception("Windows could not configure POS Printer Emulator service recovery");
            }

            var failureFlag = new ServiceFailureActionsFlag { Enabled = true };
            if (!ChangeServiceFailureFlag(service, ServiceConfigFailureActionsFlag, ref failureFlag))
            {
                throw CreateWin32Exception("Windows could not enable POS Printer Emulator failure recovery");
            }
        }
        finally
        {
            Marshal.FreeHGlobal(actionsPointer);
        }
    }

    private static Win32Exception CreateWin32Exception(string message)
    {
        var error = Marshal.GetLastWin32Error();
        return new Win32Exception(error, $"{message} (Windows error {error})");
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceDescription { public IntPtr Description; }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceDelayedAutoStartInfo
    {
        [MarshalAs(UnmanagedType.Bool)] public bool DelayedAutoStart;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceAction
    {
        public int Type;
        public uint DelayMilliseconds;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceFailureActions
    {
        public uint ResetPeriodSeconds;
        public IntPtr RebootMessage;
        public IntPtr Command;
        public uint ActionCount;
        public IntPtr Actions;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceFailureActionsFlag
    {
        [MarshalAs(UnmanagedType.Bool)] public bool Enabled;
    }

    private sealed class SafeServiceHandle : SafeHandleZeroOrMinusOneIsInvalid
    {
        private SafeServiceHandle() : base(ownsHandle: true) { }
        protected override bool ReleaseHandle() => CloseServiceHandle(handle);
    }

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern SafeServiceHandle OpenSCManager(string? machineName, string? databaseName, uint desiredAccess);

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern SafeServiceHandle CreateService(
        SafeServiceHandle serviceManager,
        string serviceName,
        string displayName,
        uint desiredAccess,
        uint serviceType,
        uint startType,
        uint errorControl,
        string binaryPathName,
        string? loadOrderGroup,
        IntPtr tagId,
        string? dependencies,
        string? serviceStartName,
        string? password);

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern SafeServiceHandle OpenService(SafeServiceHandle serviceManager, string serviceName, uint desiredAccess);

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DeleteService(SafeServiceHandle service);

    [DllImport("advapi32.dll", EntryPoint = "ChangeServiceConfig2W", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ChangeServiceDescription(SafeServiceHandle service, uint infoLevel, ref ServiceDescription info);

    [DllImport("advapi32.dll", EntryPoint = "ChangeServiceConfig2W", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ChangeServiceDelayedStart(SafeServiceHandle service, uint infoLevel, ref ServiceDelayedAutoStartInfo info);

    [DllImport("advapi32.dll", EntryPoint = "ChangeServiceConfig2W", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ChangeServiceFailureActions(SafeServiceHandle service, uint infoLevel, ref ServiceFailureActions info);

    [DllImport("advapi32.dll", EntryPoint = "ChangeServiceConfig2W", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ChangeServiceFailureFlag(SafeServiceHandle service, uint infoLevel, ref ServiceFailureActionsFlag info);

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseServiceHandle(IntPtr serviceHandle);
}
