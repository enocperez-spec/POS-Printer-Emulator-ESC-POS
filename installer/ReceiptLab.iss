#define MyAppName "POS Printer Emulator"
#define MyAppVersion "0.3.40"
#define MyAppPublisher "EPCOM Ltd."
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
WizardImageFile=..\assets\branding\pos-printer-emulator-icon.png
WizardSmallImageFile=..\assets\branding\pos-printer-emulator-icon.png
WizardImageBackColor=$FFFFFF
WizardSmallImageBackColor=$FFFFFF
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
SetupIconFile=..\assets\branding\pos-printer-emulator.ico
LicenseFile=EULA.txt

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[Files]
Source: "..\artifacts\win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "drivers\epson\*"; DestDir: "{app}\drivers\epson"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\artifacts\prerequisites\MicrosoftEdgeWebview2Setup.exe"; DestDir: "{tmp}"; Flags: ignoreversion deleteafterinstall

[Icons]
Name: "{autoprograms}\POS Printer Emulator"; Filename: "{app}\{#MyDesktopExeName}"; WorkingDir: "{app}"; IconFilename: "{app}\{#MyDesktopExeName}"
Name: "{autoprograms}\Uninstall POS Printer Emulator"; Filename: "{uninstallexe}"
Name: "{autodesktop}\POS Printer Emulator"; Filename: "{app}\{#MyDesktopExeName}"; WorkingDir: "{app}"; IconFilename: "{app}\{#MyDesktopExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyDesktopExeName}"; Description: "Open POS Printer Emulator"; WorkingDir: "{app}"; Flags: postinstall skipifsilent runasoriginaluser nowait

[Code]
var
  RegistrationPage: TInputQueryWizardPage;
  ExistingRegistrationFound: Boolean;
  SetupFailed: Boolean;

function ExtractJsonString(const Json: String; const PropertyName: String; var Value: String): Boolean;
var
  Key: String;
  I: Integer;
  CodePoint: Integer;
begin
  Result := False;
  Value := '';
  Key := '"' + PropertyName + '":';
  I := Pos(Key, Json);
  if I = 0 then
    exit;

  I := I + Length(Key);
  while (I <= Length(Json)) and (Json[I] = ' ') do
    I := I + 1;
  if (I > Length(Json)) or (Json[I] <> '"') then
    exit;

  I := I + 1;
  while I <= Length(Json) do
  begin
    if Json[I] = '"' then
    begin
      Result := True;
      exit;
    end;

    if Json[I] = '\' then
    begin
      I := I + 1;
      if I > Length(Json) then
        exit;

      if Json[I] = 'n' then
        Value := Value + #10
      else if Json[I] = 'r' then
        Value := Value + #13
      else if Json[I] = 't' then
        Value := Value + #9
      else if (Json[I] = 'u') and (I + 4 <= Length(Json)) then
      begin
        CodePoint := StrToIntDef('$' + Copy(Json, I + 1, 4), -1);
        if CodePoint < 0 then
          exit;
        Value := Value + Chr(CodePoint);
        I := I + 4;
      end
      else
        Value := Value + Json[I];
    end
    else
      Value := Value + Json[I];

    I := I + 1;
  end;
end;

procedure LoadExistingRegistration;
var
  Json: AnsiString;
  CustomerName: String;
  CustomerEmail: String;
  RegistrationPath: String;
begin
  ExistingRegistrationFound := False;
  RegistrationPath := ExpandConstant('{commonappdata}\POSPrinterEmulator\registration.json');
  if not LoadStringFromFile(RegistrationPath, Json) then
    exit;

  if not ExtractJsonString(String(Json), 'CustomerName', CustomerName) then
    exit;
  if not ExtractJsonString(String(Json), 'EmailAddress', CustomerEmail) then
    exit;

  if Trim(RegistrationPage.Values[0]) = '' then
    RegistrationPage.Values[0] := CustomerName;
  if Trim(RegistrationPage.Values[1]) = '' then
    RegistrationPage.Values[1] := CustomerEmail;

  ExistingRegistrationFound :=
    (Trim(RegistrationPage.Values[0]) <> '') and
    (Trim(RegistrationPage.Values[1]) <> '') and
    (Pos('@', RegistrationPage.Values[1]) > 1) and
    (Pos('"', RegistrationPage.Values[0]) = 0) and
    (Pos('"', RegistrationPage.Values[1]) = 0);

  if ExistingRegistrationFound then
    Log('Existing POS Printer Emulator registration found; the registration page will be skipped.')
  else
    Log('Existing registration was incomplete; available values were prefilled for confirmation.');
end;

function EnsureDataDirectoryAccess: Boolean;
var
  DataPath: String;
  ResultCode: Integer;
begin
  Result := True;
  DataPath := ExpandConstant('{commonappdata}\POSPrinterEmulator');
  if not DirExists(DataPath) then
    exit;

  if (not Exec(ExpandConstant('{sys}\takeown.exe'),
      Format('/F "%s" /A /R /D Y', [DataPath]), '', SW_HIDE,
      ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
  begin
    Log(Format('Could not take ownership of the application-data directory (exit code %d).', [ResultCode]));
    Result := False;
    exit;
  end;

  if (not Exec(ExpandConstant('{sys}\icacls.exe'),
      Format('"%s" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F *S-1-5-19:(OI)(CI)M', [DataPath]),
      '', SW_HIDE, ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
  begin
    Log(Format('Could not normalize the application-data permissions (exit code %d).', [ResultCode]));
    Result := False;
    exit;
  end;

  if (not Exec(ExpandConstant('{sys}\icacls.exe'),
      Format('"%s\*" /reset /T /C', [DataPath]), '', SW_HIDE,
      ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
  begin
    Log(Format('Could not reset child application-data permissions (exit code %d).', [ResultCode]));
    Result := False;
    exit;
  end;

  if (not Exec(ExpandConstant('{sys}\icacls.exe'),
      Format('"%s\*" /inheritance:e /T /C', [DataPath]), '', SW_HIDE,
      ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
  begin
    Log(Format('Could not enable child application-data inheritance (exit code %d).', [ResultCode]));
    Result := False;
  end;
end;

procedure InitializeWizard;
begin
  SetupFailed := False;
  RegistrationPage := CreateInputQueryPage(wpSelectDir,
    'Register POS Printer Emulator',
    'Enter the customer information for this installation.',
    'The customer or company name and email address will be tied to the activation key.');
  RegistrationPage.Add('Customer or company name:', False);
  RegistrationPage.Add('Email address:', False);
  RegistrationPage.Values[0] := ExpandConstant('{param:CustomerName|}');
  RegistrationPage.Values[1] := ExpandConstant('{param:CustomerEmail|}');
  EnsureDataDirectoryAccess;
  LoadExistingRegistration;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := ExistingRegistrationFound and (PageID = RegistrationPage.ID);
end;

function RegistrationIsValid(ShowMessage: Boolean): Boolean;
begin
  Result :=
    (Trim(RegistrationPage.Values[0]) <> '') and
    (Trim(RegistrationPage.Values[1]) <> '') and
    (Pos('@', RegistrationPage.Values[1]) > 1) and
    (Pos('"', RegistrationPage.Values[0]) = 0) and
    (Pos('"', RegistrationPage.Values[1]) = 0);
  if (not Result) and ShowMessage then
    MsgBox('Enter a customer or company name and a valid email address. Double quotes are not allowed.',
      mbError, MB_OK);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if CurPageID = RegistrationPage.ID then
    Result := RegistrationIsValid(True);
end;

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

function ServiceIsStoppedOrMissing: Boolean;
var
  ResultCode: Integer;
  Output: TExecOutput;
  I: Integer;
begin
  Result := False;
  if not ExecAndCaptureOutput(ExpandConstant('{sys}\sc.exe'), 'query {#ServiceName}', '', SW_HIDE,
    ewWaitUntilTerminated, ResultCode, Output) then
    exit;

  if ResultCode = 1060 then
  begin
    Result := True;
    exit;
  end;

  if (ResultCode <> 0) or Output.Error then
    exit;

  for I := 0 to GetArrayLength(Output.StdOut) - 1 do
  begin
    { The service state value is numeric and does not depend on the Windows display language. }
    if Pos(': 1 ', Output.StdOut[I] + ' ') > 0 then
    begin
      Result := True;
      exit;
    end;
  end;
end;

function WaitForServiceToStop: Boolean;
var
  Attempt: Integer;
begin
  Result := False;
  for Attempt := 1 to 120 do
  begin
    if ServiceIsStoppedOrMissing then
    begin
      Result := True;
      exit;
    end;

    Sleep(250);
  end;
end;

function RestorePreservedUpgradeState: Boolean; forward;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
  RegistrationPath: String;
  LicensePath: String;
  MaintenancePath: String;
begin
  if not RegistrationIsValid(False) then
  begin
    Result := 'Customer or company name and a valid email address are required. For silent setup, use /CustomerName and /CustomerEmail.';
    exit;
  end;

  Exec(ExpandConstant('{sys}\sc.exe'), 'stop {#ServiceName}', '', SW_HIDE,
    ewWaitUntilTerminated, ResultCode);
  if not WaitForServiceToStop then
  begin
    Result := 'POS Printer Emulator is still shutting down. Close the application, wait a few seconds, and run setup again.';
    exit;
  end;

  if not EnsureDataDirectoryAccess then
  begin
    Result := 'Setup could not access the existing POS Printer Emulator license data. Run setup as an administrator and try again.';
    exit;
  end;

  RegistrationPath := ExpandConstant('{commonappdata}\POSPrinterEmulator\registration.json');
  LicensePath := ExpandConstant('{commonappdata}\POSPrinterEmulator\license.json');
  MaintenancePath := ExpandConstant('{commonappdata}\POSPrinterEmulator\maintenance.json');
  if FileExists(RegistrationPath + '.upgrade-backup') or
     FileExists(LicensePath + '.upgrade-backup') or
     FileExists(MaintenancePath + '.upgrade-backup') then
  begin
    if not RestorePreservedUpgradeState then
    begin
      Result := 'Setup could not restore the protected registration, license, and maintenance entitlement from the earlier update attempt. The recovery files were retained; run setup as an administrator and try again.';
      exit;
    end;

    RegistrationPage.Values[0] := '';
    RegistrationPage.Values[1] := '';
    ExistingRegistrationFound := False;
    LoadExistingRegistration;
    if not RegistrationIsValid(False) then
    begin
      Result := 'Setup restored the protected upgrade files, but the preserved customer registration is incomplete. The recovery files were retained.';
      exit;
    end;
    Log('Restored the prior upgrade backup generation and retained it for this retry.');
  end
  else
  begin
    if FileExists(RegistrationPath) then
    begin
      if not CopyFile(RegistrationPath, RegistrationPath + '.upgrade-backup', True) then
      begin
        Result := 'Setup could not preserve the existing customer registration. Close POS Printer Emulator and run setup again.';
        exit;
      end;
      Log('Preserved the existing customer registration for this upgrade.');
    end;
    if FileExists(LicensePath) then
    begin
      if not CopyFile(LicensePath, LicensePath + '.upgrade-backup', True) then
      begin
        Result := 'Setup could not preserve the existing activation license. Close POS Printer Emulator and run setup again.';
        exit;
      end;
      Log('Preserved the existing activation license for this upgrade.');
    end;
    if FileExists(MaintenancePath) then
    begin
      if not CopyFile(MaintenancePath, MaintenancePath + '.upgrade-backup', True) then
      begin
        Result := 'Setup could not preserve the existing maintenance entitlement. Close POS Printer Emulator and run setup again.';
        exit;
      end;
      Log('Preserved the existing maintenance entitlement for this upgrade.');
    end;
  end;

  Result := '';
end;

function RestorePreservedUpgradeFile(const FilePath: String): Boolean;
var
  BackupPath: String;
begin
  Result := True;
  BackupPath := FilePath + '.upgrade-backup';
  if not FileExists(BackupPath) then
    exit;

  if FileExists(FilePath) and (not DeleteFile(FilePath)) then
  begin
    Log('Could not remove the current upgrade file before restoring its preserved copy: ' + FilePath);
    Result := False;
    exit;
  end;

  if not CopyFile(BackupPath, FilePath, True) then
  begin
    Log('Could not restore the preserved upgrade file: ' + FilePath);
    Result := False;
    exit;
  end;

  Log('Restored the preserved upgrade file: ' + FilePath);
end;

function RestorePreservedUpgradeState: Boolean;
var
  DataPath: String;
begin
  DataPath := ExpandConstant('{commonappdata}\POSPrinterEmulator');
  Result :=
    RestorePreservedUpgradeFile(DataPath + '\registration.json') and
    RestorePreservedUpgradeFile(DataPath + '\license.json') and
    RestorePreservedUpgradeFile(DataPath + '\maintenance.json');
end;

function CompletePreservedUpgradeState: Boolean;
var
  DataPath: String;
begin
  DataPath := ExpandConstant('{commonappdata}\POSPrinterEmulator');
  Result := True;
  if FileExists(DataPath + '\registration.json.upgrade-backup') and
     (not DeleteFile(DataPath + '\registration.json.upgrade-backup')) then
  begin
    Log('Could not remove the completed registration upgrade backup.');
    Result := False;
  end;
  if FileExists(DataPath + '\license.json.upgrade-backup') and
     (not DeleteFile(DataPath + '\license.json.upgrade-backup')) then
  begin
    Log('Could not remove the completed activation upgrade backup.');
    Result := False;
  end;
  if FileExists(DataPath + '\maintenance.json.upgrade-backup') and
     (not DeleteFile(DataPath + '\maintenance.json.upgrade-backup')) then
  begin
    Log('Could not remove the completed maintenance upgrade backup.');
    Result := False;
  end;
end;

procedure FailAndRestorePreservedUpgradeState(const FailureMessage: String);
begin
  SetupFailed := True;
  if not RestorePreservedUpgradeState then
    RaiseException(FailureMessage + #13#10 + #13#10 +
      'Setup also could not restore the protected registration, activation, and maintenance files. The recovery copies were retained.')
  else
    RaiseException(FailureMessage);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  ErrorDetails: AnsiString;
  InstallArguments: String;
  FailureMessage: String;
begin
  if CurStep = ssPostInstall then
  begin
    if not RestorePreservedUpgradeState then
    begin
      SetupFailed := True;
      RaiseException('POS Printer Emulator could not restore the existing registration, license, and maintenance entitlement. The preserved copies were not removed.');
    end;

    if not IsWebView2Installed then
    begin
      WizardForm.StatusLabel.Caption := 'Installing the desktop HTML component...';
      if (not Exec(ExpandConstant('{tmp}\MicrosoftEdgeWebview2Setup.exe'), '/silent /install',
        '', SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
        ((ResultCode <> 0) and (ResultCode <> 3010)) then
        FailAndRestorePreservedUpgradeState(
          'POS Printer Emulator could not install its desktop HTML component. Setup did not complete.');
    end;

    WizardForm.StatusLabel.Caption := 'Configuring the POS Printer Emulator background service...';
    InstallArguments := Format('--install-windows --upgrade-state-restored --customer-name "%s" --email "%s"', [Trim(RegistrationPage.Values[0]), Trim(RegistrationPage.Values[1])]);
    if (not Exec(ExpandConstant('{app}\{#MyAppExeName}'), InstallArguments,
      ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode)) or
      (ResultCode <> 0) then
    begin
      Log(Format('POS Printer Emulator C# installer command failed with exit code %d.', [ResultCode]));
      if LoadStringFromFile(ExpandConstant('{app}\POSPrinterEmulator-setup-error.txt'), ErrorDetails) then
        FailureMessage := 'POS Printer Emulator could not configure its Windows service:' + #13#10 + #13#10 + String(ErrorDetails)
      else
        FailureMessage := 'POS Printer Emulator could not configure its Windows service. Setup did not complete.';
      FailAndRestorePreservedUpgradeState(FailureMessage);
    end;

    if not CompletePreservedUpgradeState then
    begin
      SetupFailed := True;
      RaiseException('POS Printer Emulator was updated, but setup could not remove its protected recovery files. Run setup again as an administrator.');
    end;
  end;
end;

function GetCustomSetupExitCode: Integer;
begin
  if SetupFailed then
    Result := 1
  else
    Result := 0;
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
