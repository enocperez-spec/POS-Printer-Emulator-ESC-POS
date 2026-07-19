# POS Printer Emulator ESC/POS

POS Printer Emulator is a local Windows ESC/POS receipt emulator for testing point-of-sale printer output without a physical thermal printer. It listens for RAW printer traffic on TCP port `9100`, parses common Epson ESC/POS commands, and displays each receipt in a desktop HTML application.

![POS Printer Emulator logo](assets/branding/pos-printer-emulator-logo.png)

## Highlights

- RAW TCP/IP listener on `0.0.0.0:9100` with cut-command and idle-timeout job framing.
- Enterprise-only management for up to 16 isolated printer listeners with independent ports, profiles, state, buffers, counters, and Activity filtering.
- Receipt preview with persistent Light and Dark viewing modes.
- Trial Mode by default with five emulated print jobs per day, session-only jobs, a receipt watermark, and locked premium controls.
- Offline signed activation keys that immediately unlock unlimited jobs, persistent history, watermark-free receipts, exports, and premium features without reinstalling.
- ESC/POS text modes, positioning, legacy and raster images, configured barcodes, standards-based QR rendering, feeds, cuts, and common code pages.
- Command diagnostics with byte offsets, hexadecimal values, and unsupported-command reporting.
- Stored Logo imports that map local PNG, JPEG, or WebP artwork to Epson NV graphic keys used by POS receipts.
- Maximum job-size protection and interrupted-connection recovery.
- Text and raw-data exports plus Print-to-PDF.
- Native C# desktop window hosting the HTML viewer through Microsoft WebView2, with no browser address bar.
- All-in-one Windows installer—customers do not separately install .NET, WebView2, Node.js, CMake, a database, or printer utilities.
- Service, firewall, health-check, uninstall, build, publish, and developer utility operations are implemented in C# without PowerShell.
- Automatic Windows Service registration, delayed startup, failure recovery, and private/domain firewall configuration.
- Guided Printer Setup Wizard that detects and installs the signed Epson TM-T88V Receipt5 driver, creates the RAW TCP/IP port and Windows queue, verifies the connection, rolls back incomplete setup, and sends a test receipt.
- Privacy-safe license and usage reporting with a password- and authenticator-protected Admin Portal; receipt contents and raw printer data never leave the customer computer.
- Clean uninstall through Windows **Installed apps** or the Start Menu.

Feature upgrades and the `v0.MINOR.FEATURE` numbering sequence are tracked in [CHANGELOG.md](CHANGELOG.md).

The public `posprinteremulator.com` marketing and download website is maintained in [`website`](website/README.md).

## Install on Windows

POS Printer Emulator supports 64-bit Windows 10 and Windows 11.

1. Download `POSPrinterEmulatorSetup-0.3.21-win-x64.exe` from the repository's Releases page.
2. Run the installer and approve the Windows administrator prompt.
3. Enter the customer or company name and email address that will be used for licensing.
4. Leave **Create a desktop shortcut** selected if desired.
5. Open **POS Printer Emulator** from the Start Menu or desktop shortcut.

Setup installs POS Printer Emulator and its desktop HTML component under Program Files, starts its background service, and creates a program-scoped RAW TCP firewall rule for private and domain networks. This covers the default port and Enterprise listener ports without exposing the local viewer or enabling public-network access. The viewer remains available at `http://127.0.0.1:5187` for diagnostics.

After installation, open **Settings → Printer Setup Wizard**. The wizard asks where the POS software runs, chooses `127.0.0.1:9100` automatically for a same-computer setup, verifies the Epson driver, and installs the Windows printer after one administrator confirmation. Customers do not need to open Windows printer settings, create a port, visit Epson's website, or select a driver manually.

Open **Settings → Printer State** to simulate Ready, Paper Low, Paper Out, Cover Open, Cutter Error, Offline, and custom error conditions. Connected POS clients receive Epson-compatible `DLE EOT` real-time responses and `GS a` Automatic Status Back notifications whenever the simulated state changes.

Pro and Enterprise customers can open **Settings → Printer Profiles** to select the protected EPSON TM-T88V or Generic ESC/POS profile, duplicate and customize a profile, configure paper and command capabilities, and import or export `.ppeprofile` files. Trial installations can see the feature but cannot open it or call its local APIs.

Enterprise customers can open **Settings → Printer Listeners** to create and manage up to 16 independently named RAW TCP endpoints. Each listener has its own IPv4 bind address, unique port, printer profile, simulated state, optional job buffer, live counters, and start/stop/restart controls. Activity can be filtered by listener, and every saved job records the listener name and port that received it. Trial and Pro installations continue using the compatible, non-removable `0.0.0.0:9100` default listener.

Open **Settings → Stored Logos** when a receipt references an image saved inside the physical printer rather than sending its pixels. Import a PNG, JPEG, or WebP image, enter the two-character Epson storage key shown by the command inspector (for example, `00`), and matching receipts will render that local logo automatically.

Pro and Enterprise customers can use the **Import capture** button in Activity to open a raw `.bin` receipt or a portable `.ppecapture` package. Select any job and use **Capture** to export a checksum-protected package or **Replay** to run the exact saved bytes through the current parser and renderer. Imported and replayed jobs are labeled separately and remain local to the computer.

> The current development installer is not code-signed, so Windows SmartScreen may show a warning. Production releases should be signed with a trusted Windows code-signing certificate.

## Connect a POS application

Configure the POS system as a RAW or network receipt printer using:

- **Host:** the Windows computer's local network IP address
- **Port:** `9100`
- **Protocol:** RAW TCP/IP

The diagnostic viewer remains local to the Windows computer at `http://127.0.0.1:5187`.

Enterprise installations can assign additional unique ports under **Settings → Printer Listeners**. Configure each POS station with the host and port displayed for its assigned listener.

## Trial, Pro, and Enterprise versions

Every new installation begins in **Trial Mode**. Trial Mode permits five completed emulated print jobs per local calendar day. Trial jobs remain available only for the current service session, every receipt displays a visible trial watermark, and exports and premium controls are locked.

After purchase, open **License** in the application and enter the customer/company name, email address, and activation key. A valid key immediately enables the **Pro Version** with:

- unlimited emulated print jobs;
- persistent print-job history of up to 500 jobs;
- watermark-free receipt previews;
- text and raw-data exports, Print/PDF, and all premium controls.

An **Enterprise Version** includes every Pro feature plus multiple isolated printer listeners, per-listener profiles and printer state, optional buffering, live counters, independent lifecycle controls, and listener-based Activity filtering. Multiple-listener controls and APIs remain unavailable to Trial and Pro licenses.

Activation is validated offline using a public-key signature. The customer does not reinstall the application or download another package. Activation keys are tied to the registered customer/company name and email address.

## License and usage dashboard

Version 0.3.21 reports installation registration, Trial, Pro, or Enterprise status, application version, launch counts, emulated print-job counts, and last-seen time to the canonical HTTPS telemetry API at `www.posprinteremulator.com`. Failed usage reports are retained in memory and retried while the application remains running. Receipt text, raw ESC/POS payloads, barcodes, QR-code contents, imported logos, capture packages, printer profiles, listener configuration, and rendered receipt images are never uploaded.

The protected Admin Portal is hosted at `https://admin.posprinteremulator.com/`. Password sign-in is followed by a six-digit authenticator-app challenge. First-time enrollment presents a locally rendered QR code; its TOTP secret and the activation-key signing key remain in the web host's blocked `private` directory. The Admin Portal includes the usage dashboard, Purchase Pricing, and a web License Manager for issuing signed customer keys and reviewing issued licenses. The application reports in the background; an unavailable internet connection never blocks receipt emulation.

The MariaDB schema is stored in `database/schema.sql`. The C# utilities under `tools/POSPrinterEmulator.DatabaseTool` and `tools/POSPrinterEmulator.WebsitePublisher` provision the schema, verify the production API, publish the site, and upload protected server configuration. All database, SFTP, and dashboard credentials are supplied through temporary environment variables and must never be committed to Git.

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

Output: `artifacts\installer\POSPrinterEmulatorSetup-0.3.21-win-x64.exe`

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
gh release create v0.3.21 artifacts/installer/POSPrinterEmulatorSetup-0.3.21-win-x64.exe --title "POS Printer Emulator 0.3.21" --notes "Adds Enterprise multiple printer listeners with isolated runtimes, persisted configuration, Activity filtering, live counters, and independent fault handling."
```

## Issue customer activation keys

The vendor private key is intentionally stored outside this Git repository and must never be included in the application or installer. Back it up securely before selling licenses. Issue a key with the exact registration details supplied by the customer:

```console
dotnet run --project tools/POSPrinterEmulator.LicenseTool -- issue --private-key "..\License Keys\vendor-private-key.pem" --customer "Customer or Company Name" --email "customer@example.com"
```

Send the printed `PPE1-...` value to the customer. The corresponding public key is embedded in the application and can validate the key without internet access.

For unattended installation, provide the required registration fields:

```console
POSPrinterEmulatorSetup-0.3.21-win-x64.exe /VERYSILENT /CustomerName="Company Name" /CustomerEmail="customer@example.com"
```

## Configuration

Development settings are stored in `src/ReceiptEmulator.App/appsettings.json`:

- `Printer:Port`: RAW listener port; default `9100`.
- `Printer:BindAddress`: listener address; default `0.0.0.0`.
- `Printer:IdleJobTimeoutMilliseconds`: completes a no-cut job after inactivity.
- `Printer:MaximumJobBytes`: rejects oversized jobs.
- `Viewer:Url`: local viewer binding; default `http://127.0.0.1:5187`.

The `Printer` section supplies the compatible default listener for Trial and Pro installations and the initial Enterprise listener. Enterprise listener changes are validated and stored locally in SQLite through **Settings → Printer Listeners**.

## Current MVP limitations

Version 0.3.21 stores Pro and Enterprise history plus Enterprise listener configuration in one local SQLite database with a 500-job history limit, while Trial remains session-only. Existing JSON and v0.3.20 SQLite history migrate transactionally. Customer-facing database maintenance, receipt comparison, online revocation/transfer, hardened Thermal rendering, PNG export, and production code-signing remain planned work.

## Release roadmap

The permanent status list for every completed, scheduled, and future release is maintained in the [release tracker](docs/RELEASE_TRACKER.md). Reported and resolved defects are maintained separately in the [bug tracker](docs/BUG_TRACKER.md). Detailed completed-release notes are recorded in [CHANGELOG.md](CHANGELOG.md).

- **Released in v0.3.15 — Capture, import, export, and replay:** Save complete ESC/POS sessions, import captured `.bin` jobs, export portable capture packages, and replay jobs through the emulator for troubleshooting and testing.
- **Released in v0.3.16 — In-place receipt export correction:** Download Text, Raw, and Capture files through the desktop application without leaving the selected receipt or displaying a WebView startup error.
- **Released in v0.3.17 — License tiers and Pro feature gates:** Add Trial, Pro, and Enterprise licensing, preserve legacy paid keys as Pro, and restrict Stored Logos, Printer State, Updates, and Support to paid licenses.
- **Released in v0.3.18 — Admin Portal and tier-aware purchase pricing:** Brand the protected administration site as the Admin Portal and manage separate Pro and Enterprise purchase prices and fulfillment.
- **Released in v0.3.19 — Printer profiles:** Add Pro and Enterprise printer profiles with selectable models, custom import/export, paper and code-page configuration, capability warnings, and profile-aware capture/replay.
- **Released in v0.3.20 — Reliable SQLite receipt history:** Store Pro and Enterprise history transactionally in an embedded database, migrate legacy JSON safely, preserve a rollback backup, and keep Trial history session-only.
- **Released in v0.3.21 — Enterprise multiple printer listeners:** Add persisted listener configuration, isolated runtimes, Enterprise UI/API gates, routing, filtering, live counters, dynamic-port firewall support, and independent fault handling.
- **v0.3.22 — Receipt comparison and automated validation:** Compare rendered receipts, raw bytes, and parsed commands, highlight differences, and support repeatable pass/fail validation.
- **v0.3.23 — Enhanced support package and connection diagnostics:** Add guided network tests, listener and firewall checks, redacted diagnostic bundles, and clearer customer-facing connection results.
- **v0.3.24 — Guided update installation and restart:** Download and verify updates in the background, confirm an Install and Restart action, close the application safely, run an external updater, and relaunch after installation.

Following these feature releases, planned production work includes service-to-viewer authentication and installer repair, advanced SQLite maintenance and retention controls, online license transfer and revocation, hardened thermal rendering, PNG export, deterministic PDF generation, and production code-signing.

See [the architecture notes](docs/architecture.md) for implementation details and the production roadmap.

## License

Licensed under the [Apache License 2.0](LICENSE).
