#define MyAppName "POS Printer Emulator"
#define MyAppVersion "0.2.0"
#define MyAppPublisher "POS Printer Emulator"
#define MyAppExeName "ReceiptEmulator.exe"
#define MyDesktopExeName "POSPrinterEmulator.Desktop.exe"
#define ServiceName "ReceiptLab"

[Setup]
AppId={{8F35B578-3D18-4B8D-9A4F-B8E2C7639275}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\POS Printer Emulator
DefaultGroupName=POS Printer Emulator
DisableProgramGroupPage=yes
DisableDirPage=auto
OutputDir=..\artifacts\installer
OutputBaseFilename=POSPrinterEmulatorSetup-{#MyAppVersion}-win-x64
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
Uninstallable=yes
UninstallDisplayName={#MyAppName}
UninstallDisplayIcon={app}\{#MyDesktopExeName}
CloseApplications=yes
RestartApplications=no
RestartIfNeededByRun=no
SetupMutex=POSPrinterEmulatorSetupMutex

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[Files]
Source: "..\artifacts\win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\artifacts\prerequisites\MicrosoftEdgeWebview2Setup.exe"; DestDir: "{tmp}"; Flags: ignoreversion deleteafterinstall

[Icons]
Name: "{autoprograms}\POS Printer Emulator"; Filename: "{app}\{#MyDesktopExeName}"; WorkingDir: "{app}"
Name: "{autoprograms}\Uninstall POS Printer Emulator"; Filename: "{uninstallexe}"
Name: "{autodesktop}\POS Printer Emulator"; Filename: "{app}\{#MyDesktopExeName}"; WorkingDir: "{app}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyDesktopExeName}"; Description: "Open POS Printer Emulator"; WorkingDir: "{app}"; Flags: postinstall skipifsilent runasoriginaluser nowait

[Code]
function IsWebView2Installed: Boolean;
var
  Version: String;
  ClientKey: String;
begin
  ClientKey := 'SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';
  Result :=
    (RegQueryStringValue(HKLM64, ClientKey, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0')) or
    (RegQueryStringValue(HKLM32, ClientKey, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0')) or
    (RegQueryStringValue(HKCU, ClientKey, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0'));
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop {#ServiceName}', '', SW_HIDE,
    ewWaitUntilTerminated, ResultCode);
  Sleep(2000);
  Result := '';
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  ErrorDetails: AnsiString;
begin
  if CurStep = ssPostInstall then
  begin
    if not IsWebView2Installed then
    begin
      WizardForm.StatusLabel.Caption := 'Installing the desktop HTML component...';
      if (not Exec(ExpandConstant('{tmp}\MicrosoftEdgeWebview2Setup.exe'), '/silent /install',
        '', SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
        ((ResultCode <> 0) and (ResultCode <> 3010)) then
        RaiseException('POS Printer Emulator could not install its desktop HTML component. Setup did not complete.');
    end;

    WizardForm.StatusLabel.Caption := 'Configuring the POS Printer Emulator background service...';
    if (not Exec(ExpandConstant('{app}\{#MyAppExeName}'), '--install-windows',
      ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
      (ResultCode <> 0) then
    begin
      Log(Format('POS Printer Emulator C# installer command failed with exit code %d.', [ResultCode]));
      if LoadStringFromFile(ExpandConstant('{app}\POSPrinterEmulator-setup-error.txt'), ErrorDetails) then
        RaiseException('POS Printer Emulator could not configure its Windows service:' + #13#10 + #13#10 + String(ErrorDetails))
      else
        RaiseException('POS Printer Emulator could not configure its Windows service. Setup did not complete.');
    end;
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  ResultCode: Integer;
begin
  if CurUninstallStep = usUninstall then
  begin
    if (not Exec(ExpandConstant('{app}\{#MyAppExeName}'), '--uninstall-windows',
      ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
      (ResultCode <> 0) then
    begin
      Log(Format('POS Printer Emulator C# uninstaller command failed with exit code %d.', [ResultCode]));
      RaiseException('POS Printer Emulator could not remove its Windows service and firewall rule.');
    end;
  end;
end;
