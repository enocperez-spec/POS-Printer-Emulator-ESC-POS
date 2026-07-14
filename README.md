# POS Printer Emulator

POS Printer Emulator is a local Windows ESC/POS receipt emulator for testing point-of-sale printer output without a physical thermal printer. It listens for RAW printer traffic on TCP port `9100`, parses common Epson ESC/POS commands, and displays each receipt in a desktop HTML application.

![POS Printer Emulator logo](assets/branding/pos-printer-emulator-logo.png)

## Highlights

- RAW TCP/IP listener on `0.0.0.0:9100` with cut-command and idle-timeout job framing.
- Receipt preview with persistent Light and Dark viewing modes.
- ESC/POS text, alignment, emphasis, underline, character sizing, feeds, cuts, basic barcodes, QR command tracking, and common code pages.
- Command diagnostics with byte offsets, hexadecimal values, and unsupported-command reporting.
- Maximum job-size protection and interrupted-connection recovery.
- Text and raw-data exports plus Print-to-PDF.
- Native C# desktop window hosting the HTML viewer through Microsoft WebView2, with no browser address bar.
- All-in-one Windows installer—customers do not separately install .NET, WebView2, Node.js, CMake, a database, or printer utilities.
- Service, firewall, health-check, uninstall, build, publish, and developer utility operations are implemented in C# without PowerShell.
- Automatic Windows Service registration, delayed startup, failure recovery, and private/domain firewall configuration.
- Clean uninstall through Windows **Installed apps** or the Start Menu.

## Install on Windows

POS Printer Emulator supports 64-bit Windows 10 and Windows 11.

1. Download `POSPrinterEmulatorSetup-0.2.0-win-x64.exe` from the repository's Releases page.
2. Run the installer and approve the Windows administrator prompt.
3. Leave **Create a desktop shortcut** selected if desired.
4. Open **POS Printer Emulator** from the Start Menu or desktop shortcut.

Setup installs POS Printer Emulator and its desktop HTML component under Program Files, starts its background service, and permits inbound TCP `9100` traffic on private and domain networks. Public-network access is intentionally not enabled. The local viewer remains available at `http://127.0.0.1:5187` for diagnostics.

> The current development installer is not code-signed, so Windows SmartScreen may show a warning. Production releases should be signed with a trusted Windows code-signing certificate.

## Connect a POS application

Configure the POS system as a RAW or network receipt printer using:

- **Host:** the Windows computer's local network IP address
- **Port:** `9100`
- **Protocol:** RAW TCP/IP

The diagnostic viewer remains local to the Windows computer at `http://127.0.0.1:5187`.

## Uninstall

Open Windows **Settings → Apps → Installed apps**, find **POS Printer Emulator**, and select **Uninstall**. You can also use **Uninstall POS Printer Emulator** from the Start Menu.

Uninstall removes the Windows Service, firewall rules, service-owned application data, shortcuts, and installed application files.

## Development requirements

- .NET SDK 8 or newer
- Node.js 22 and pnpm
- Inno Setup 6 for compiling the Windows installer
- Git and GitHub CLI for source control and releases

CMake is not required by this project.

## Run locally

```console
dotnet run --project tools/ReceiptLab.Build -- build
dotnet run --project src\ReceiptEmulator.App
```

Open `http://127.0.0.1:5187` and select **Test receipt**, or send a sample job from another terminal:

```console
dotnet run --project tools/ReceiptLab.Build -- send-sample
```

## Test

```console
dotnet run --project tools/ReceiptLab.Build -- test
```

## Build

Create a self-contained Windows application bundle:

```console
dotnet run --project tools/ReceiptLab.Build -- publish
```

Output: `artifacts\win-x64`

Create the complete customer installer:

```console
dotnet run --project tools/ReceiptLab.Build -- installer
```

Output: `artifacts\installer\POSPrinterEmulatorSetup-0.2.0-win-x64.exe`

The C# build utility compiles the viewer, builds the application, runs the automated tests, publishes the self-contained runtime, packages the installer, and sends sample ESC/POS traffic. The `artifacts` directory is excluded from Git source history.

The packaged executable also provides Windows installer commands used by Inno Setup:

```console
ReceiptEmulator.exe --install-windows
ReceiptEmulator.exe --uninstall-windows
ReceiptEmulator.exe --health-check
```

The install and uninstall commands require administrator privileges. They are intended to be called by Setup rather than run manually.

## Publish a GitHub release

After authenticating GitHub CLI and pushing the repository, publish the installer with:

```console
gh auth login
gh release create v0.2.0 artifacts/installer/POSPrinterEmulatorSetup-0.2.0-win-x64.exe --title "POS Printer Emulator 0.2.0" --notes "Desktop HTML application and new POS Printer Emulator branding."
```

## Configuration

Development settings are stored in `src/ReceiptEmulator.App/appsettings.json`:

- `Printer:Port`: RAW listener port; default `9100`.
- `Printer:BindAddress`: listener address; default `0.0.0.0`.
- `Printer:IdleJobTimeoutMilliseconds`: completes a no-cut job after inactivity.
- `Printer:MaximumJobBytes`: rejects oversized jobs.
- `Viewer:Url`: local viewer binding; default `http://127.0.0.1:5187`.

## Current MVP limitations

The current build uses session-only receipt storage and a persisted five-completed-jobs-per-day trial counter. Licensing activation, SQLite licensed history, hardened Thermal rendering, PNG export, and production code-signing remain planned work.

See [the architecture notes](docs/architecture.md) for implementation details and the production roadmap.

## License

Licensed under the [Apache License 2.0](LICENSE).
