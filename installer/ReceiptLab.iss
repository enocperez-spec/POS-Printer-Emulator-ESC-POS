#define MyAppName "Receipt Lab"
#define MyAppVersion "0.1.1"
#define MyAppPublisher "Receipt Lab"
#define MyAppExeName "ReceiptEmulator.exe"
#define ServiceName "ReceiptLab"

[Setup]
AppId={{8F35B578-3D18-4B8D-9A4F-B8E2C7639275}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\Receipt Lab
DefaultGroupName=Receipt Lab
DisableProgramGroupPage=yes
DisableDirPage=auto
OutputDir=..\artifacts\installer
OutputBaseFilename=ReceiptLabSetup-{#MyAppVersion}-win-x64
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
Uninstallable=yes
UninstallDisplayName={#MyAppName}
UninstallDisplayIcon={app}\{#MyAppExeName}
CloseApplications=yes
RestartApplications=no
RestartIfNeededByRun=no
SetupMutex=ReceiptLabSetupMutex

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[Files]
Source: "..\artifacts\win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\Receipt Lab"; Filename: "http://127.0.0.1:5187"
Name: "{autoprograms}\Uninstall Receipt Lab"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Receipt Lab"; Filename: "http://127.0.0.1:5187"; Tasks: desktopicon

[Run]
Filename: "http://127.0.0.1:5187"; Description: "Open Receipt Lab"; Flags: shellexec postinstall skipifsilent runasoriginaluser nowait

[Code]
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
    WizardForm.StatusLabel.Caption := 'Configuring the Receipt Lab background service...';
    if (not Exec(ExpandConstant('{app}\{#MyAppExeName}'), '--install-windows',
      ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
      (ResultCode <> 0) then
    begin
      Log(Format('Receipt Lab C# installer command failed with exit code %d.', [ResultCode]));
      if LoadStringFromFile(ExpandConstant('{app}\ReceiptLab-setup-error.txt'), ErrorDetails) then
        RaiseException('Receipt Lab could not configure its Windows service:' + #13#10 + #13#10 + String(ErrorDetails))
      else
        RaiseException('Receipt Lab could not configure its Windows service. Setup did not complete.');
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
      Log(Format('Receipt Lab C# uninstaller command failed with exit code %d.', [ResultCode]));
      RaiseException('Receipt Lab could not remove its Windows service and firewall rule.');
    end;
  end;
end;
