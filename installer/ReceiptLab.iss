#define MyAppName "POS Printer Emulator"
#define MyAppVersion "0.3.13"
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
SetupIconFile=..\assets\branding\pos-printer-emulator.ico

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

procedure InitializeWizard;
begin
  RegistrationPage := CreateInputQueryPage(wpSelectDir,
    'Register POS Printer Emulator',
    'Enter the customer information for this installation.',
    'The customer or company name and email address will be tied to the activation key.');
  RegistrationPage.Add('Customer or company name:', False);
  RegistrationPage.Add('Email address:', False);
  RegistrationPage.Values[0] := ExpandConstant('{param:CustomerName|}');
  RegistrationPage.Values[1] := ExpandConstant('{param:CustomerEmail|}');
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

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  if not RegistrationIsValid(False) then
  begin
    Result := 'Customer or company name and a valid email address are required. For silent setup, use /CustomerName and /CustomerEmail.';
    exit;
  end;

  Exec(ExpandConstant('{sys}\sc.exe'), 'stop {#ServiceName}', '', SW_HIDE,
    ewWaitUntilTerminated, ResultCode);
  Sleep(2000);
  Result := '';
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  ErrorDetails: AnsiString;
  InstallArguments: String;
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
    InstallArguments := Format('--install-windows --customer-name "%s" --email "%s"', [Trim(RegistrationPage.Values[0]), Trim(RegistrationPage.Values[1])]);
    if (not Exec(ExpandConstant('{app}\{#MyAppExeName}'), InstallArguments,
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
