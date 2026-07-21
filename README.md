# POS Printer Emulator ESC/POS

POS Printer Emulator is a local Windows ESC/POS receipt emulator for testing point-of-sale printer output without a physical thermal printer. It listens for RAW printer traffic on TCP port `9100`, parses common Epson ESC/POS commands, and displays each receipt in a desktop HTML application.

![POS Printer Emulator logo](assets/branding/pos-printer-emulator-logo.png)

## Highlights

- RAW TCP/IP listener on `0.0.0.0:9100` with cut-command and idle-timeout job framing.
- Four-tier licensing in v0.3.25 provides total listener allowances of Trial 1, Lite 1, Pro 2, and Enterprise 15; managed listeners retain independent ports, profiles, state, buffers, counters, and Activity filtering.
- Annual Application Maintenance and Support in v0.3.26 keeps paid licenses permanent, includes one year of updates and assisted support with new purchases, and offers later annual renewals as optional one-time purchases rather than subscriptions.
- Receipt preview with persistent Light and Dark viewing modes.
- Trial Mode by default with five emulated print jobs per day, session-only jobs, a receipt watermark, and locked premium controls.
- Offline signed activation keys that immediately unlock unlimited jobs, persistent history, watermark-free receipts, exports, and premium features for Lite, Pro, and Enterprise without reinstalling.
- ESC/POS text modes, positioning, legacy and raster images, configured barcodes, standards-based QR rendering, feeds, cuts, and common code pages.
- Command diagnostics with byte offsets, hexadecimal values, and unsupported-command reporting.
- Stored Logo imports that map local PNG, JPEG, or WebP artwork to Epson NV graphic keys used by POS receipts.
- Maximum job-size protection and interrupted-connection recovery.
- Text and raw-data exports plus Print-to-PDF.
- Native C# desktop window hosting the HTML viewer through Microsoft WebView2, with no browser address bar.
- All-in-one Windows installer—customers do not separately install .NET, WebView2, Node.js, CMake, a database, or printer utilities.
- Service, firewall, health-check, uninstall, build, publish, and developer utility operations are implemented in C# without PowerShell.
- Automatic Windows Service registration, delayed startup, failure recovery, and private/domain firewall configuration.
- Guided Printer Setup Wizard that detects and installs the signed Epson TM-T88V Receipt5 driver, selects the first free port from 9100 upward, aligns adjusted Enterprise ports with emulator listeners, creates the Windows queue, verifies the connection, rolls back incomplete setup, and sends a test receipt.
- Privacy-safe license and usage reporting with a password- and authenticator-protected Admin Portal; receipt contents and raw printer data never leave the customer computer.
- Clean uninstall through Windows **Installed apps** or the Start Menu.

Feature upgrades and the `v0.MINOR.FEATURE` numbering sequence are tracked in [CHANGELOG.md](CHANGELOG.md).

> **Release status:** v0.3.34 is the current public release, released July 21, 2026. The next planned release is v0.3.35 Receipt Comparison and Automated Validation.

The public `posprinteremulator.com` marketing and download website is maintained in [`website`](website/README.md).

## Install on Windows

POS Printer Emulator supports 64-bit Windows 10 and Windows 11.

1. Download `POSPrinterEmulatorSetup-0.3.34-win-x64.exe` from the repository's Releases page.
2. Run the installer and approve the Windows administrator prompt.
3. Enter the customer or company name and email address that will be used for licensing.
4. Leave **Create a desktop shortcut** selected if desired.
5. Open **POS Printer Emulator** from the Start Menu or desktop shortcut.

Setup installs POS Printer Emulator and its desktop HTML component under Program Files, starts its background service, and creates a program-scoped RAW TCP firewall rule for private and domain networks. This covers the default port and Enterprise listener ports without exposing the local viewer or enabling public-network access. The viewer remains available at `http://127.0.0.1:5187` for diagnostics.

After installation, open **Settings → Printer Setup Wizard**. The wizard asks where the POS software runs, chooses `127.0.0.1:9100` automatically for a same-computer setup, verifies the Epson driver, and installs the Windows printer after one administrator confirmation. Customers do not need to open Windows printer settings, create a port, visit Epson's website, or select a driver manually.

Open **Settings → Printer State** to simulate Ready, Paper Low, Paper Out, Cover Open, Cutter Error, Offline, and custom error conditions. Connected POS clients receive Epson-compatible `DLE EOT` real-time responses and `GS a` Automatic Status Back notifications whenever the simulated state changes.

Lite, Pro, and Enterprise customers can open **Settings → Printer Profiles** to select the protected EPSON TM-T88V or Generic ESC/POS profile, duplicate and customize a profile, configure paper and command capabilities, and import or export `.ppeprofile` files. Trial installations can see the feature but cannot open it or call its local APIs.

The v0.3.25 listener model gives Trial and Lite one total listener, Pro two total listeners, and Enterprise up to 15 total listeners. Pro and Enterprise customers can open **Settings → Printer Listeners** to create and manage their allowed RAW TCP endpoints. Each listener has its own IPv4 bind address, unique port, printer profile, simulated state, optional job buffer, live counters, and start/stop/restart controls. Activity can be filtered by listener, and every saved job records the listener name and port that received it.

Open **Settings → Stored Logos** when a receipt references an image saved inside the physical printer rather than sending its pixels. Import a PNG, JPEG, or WebP image, enter the two-character Epson storage key shown by the command inspector (for example, `00`), and matching receipts will render that local logo automatically.

Lite, Pro, and Enterprise customers can use the **Import capture** button in Activity to open a raw `.bin` receipt or a portable `.ppecapture` package. Select any job and use **Capture** to export a checksum-protected package or **Replay** to run the exact saved bytes through the current parser and renderer. Imported and replayed jobs are labeled separately and remain local to the computer.

> The current installer is not code-signed, so Windows SmartScreen may show a warning. A trusted Windows code-signing certificate remains planned for a future production-hardening release.

## Connect a POS application

Configure the POS system as a RAW or network receipt printer using:

- **Host:** the Windows computer's local network IP address
- **Port:** `9100`
- **Protocol:** RAW TCP/IP

The diagnostic viewer remains local to the Windows computer at `http://127.0.0.1:5187`.

Pro and Enterprise installations can assign additional unique ports under **Settings → Printer Listeners**, within their two- and 15-listener totals. Configure each POS station with the host and port displayed for its assigned listener.

## Trial, Lite, Pro, and Enterprise versions

Every new installation begins in **Trial Mode**. Trial Mode permits five completed emulated print jobs per local calendar day. Trial jobs remain available only for the current service session, every receipt displays a visible trial watermark, and exports and premium controls are locked.

After purchase, open **License** in the application and enter the customer/company name, email address, and activation key. A valid Lite, Pro, or Enterprise key immediately enables:

- unlimited emulated print jobs;
- persistent print-job history of up to 500 jobs;
- watermark-free receipt previews;
- text and raw-data exports, Print/PDF, printer profiles, Stored Logos, Printer State, capture/import/replay, and all other permanent paid controls.

The paid feature set is the same across Lite, Pro, and Enterprise. The license level controls the total number of printer listeners:

- **Trial:** one listener, five completed jobs per day, session-only Activity, TRIAL watermark, and paid features locked.
- **Lite:** one listener and all paid features for a fixed one-time price of **$24.99**.
- **Pro:** two total listeners and all paid features; the current price is shown on the Buy page and managed through the Admin Portal.
- **Enterprise:** up to 15 total listeners and all paid features, including the full multi-listener workflow; the current price is shown on the Buy page and managed through the Admin Portal.

Activation is validated offline using a public-key signature. The customer does not reinstall the application or download another package. Activation keys are tied to the registered customer/company name and email address.

### Annual Application Maintenance and Support (v0.3.26)

Lite, Pro, and Enterprise remain permanent, one-time-purchase licenses. Each new paid license includes one year of Application Maintenance and Support covering application updates and upgrades, assisted technical support, and access to **Settings → Check for Updates**. The annual plan is not a software subscription and does not use automatic recurring billing.

When maintenance expires, the purchased application, currently installed version, licensed features, receipt history, and listener allowance continue working permanently. Update checking/downloads and assisted support pause until the customer chooses to renew. Local troubleshooting information, health checks, activation diagnostics, and privacy-safe log export remain available without active maintenance.

Optional one-year renewals are **Lite $9.99**, **Pro $19.99**, and **Enterprise $59.99**. An early renewal adds 12 months to the existing expiration date; a renewal after expiration begins on the confirmed payment date and immediately restores eligible update and assisted-support access. Paid customers licensed before v0.3.26 are grandfathered with maintenance through **2027-07-19**.

## License and usage dashboard

Version 0.3.26 reports installation registration, Trial, Lite, Pro, or Enterprise status, maintenance status and coverage date, application version, launch counts, emulated print-job counts, and last-seen time to the canonical HTTPS telemetry API at `www.posprinteremulator.com`. Failed usage reports are retained in memory and retried while the application remains running. Receipt text, raw ESC/POS payloads, barcodes, QR-code contents, imported logos, capture packages, printer profiles, listener configuration, and rendered receipt images are never uploaded.

The protected Admin Portal is hosted at `https://admin.posprinteremulator.com/`. Password sign-in is followed by a six-digit authenticator-app challenge. First-time enrollment presents a locally rendered QR code; its TOTP secret and the activation-key signing key remain in the web host's blocked `private` directory. The Admin Portal includes the usage dashboard, Purchase Pricing, and a web License Manager for issuing signed customer keys and reviewing issued licenses. The application reports in the background; an unavailable internet connection never blocks receipt emulation.

Production operators should follow the [integration-token rotation runbook](docs/SECURITY_TOKEN_ROTATION.md) whenever purchase-site or Admin Portal credentials are created, rotated, or suspected of exposure.

The MariaDB schema is stored in `database/schema.sql`. The C# utilities under `tools/POSPrinterEmulator.DatabaseTool` and `tools/POSPrinterEmulator.WebsitePublisher` provision the schema, verify the production API, publish the site, and upload protected server configuration. All database, SFTP, and dashboard credentials are supplied through temporary environment variables and must never be committed to Git.

For v0.3.33 support requests, copy `admin-website/private/support.example.php` to the protected server-only `support.php` and supply a fine-grained GitHub token restricted to **Issues: Read and write** for the POS Printer Emulator repository. The token must never be included in the desktop application, source repository, public GitHub issue, deployment log, or browser response. The Admin Portal stores customer contact information, redacted diagnostics, and validated attachments privately; the public GitHub issue receives only the redacted technical report and support reference.

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

Output for the current release: `artifacts\installer\POSPrinterEmulatorSetup-0.3.34-win-x64.exe`

The C# build utility compiles the viewer, builds the application, runs the automated tests, publishes the self-contained runtime, packages the installer, and sends sample ESC/POS traffic. The `artifacts` directory is excluded from Git source history. Creating an installer does not change the public website or its download links.

The public version is recorded separately in `website/release.json`. After the candidate has passed release testing and the release is approved, promote `ProductInfo.Version` to the website labels and download links explicitly:

```console
dotnet run --project tools/ReceiptLab.Build -- sync-release
dotnet run --project tools/ReceiptLab.Build -- sync-release --check
```

Do not run `sync-release` merely to package or test an unreleased candidate.

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
gh release create v0.3.34 artifacts/installer/POSPrinterEmulatorSetup-0.3.34-win-x64.exe artifacts/installer/POSPrinterEmulatorSetup-0.3.34-win-x64.exe.sha256 --title "POS Printer Emulator 0.3.34" --notes-file artifacts/release-notes-v0.3.34.md
```

## Issue customer activation keys

The vendor private key is intentionally stored outside this Git repository and must never be included in the application or installer. Back it up securely before selling licenses. Issue a key with the exact registration details supplied by the customer:

```console
dotnet run --project tools/POSPrinterEmulator.LicenseTool -- issue --private-key "..\License Keys\vendor-private-key.pem" --customer "Customer or Company Name" --email "customer@example.com"
```

Send the printed `PPE1-...` value to the customer. The corresponding public key is embedded in the application and can validate the key without internet access.

The protected Admin Portal License Manager unifies manual and purchase-issued keys, can issue paid upgrades for registered Trial installations, and provides confirmation-gated tier replacement, deactivation, reactivation, revocation, deletion, and audit history. Paid-tier changes always generate a new signed key; the customer must enter that replacement key because the tier is cryptographically embedded in the original key. Portal lifecycle controls do not remotely erase a key already stored by v0.3.23—the outage-safe online entitlement and transfer workflow remains tracked in `BACKLOG-004`. Until that release adds signed entitlement proof, telemetry for a legacy paid license ID that is not yet in the central ledger remains client-reported for dashboard compatibility; this reporting does not unlock desktop features.

For unattended installation, provide the required registration fields:

```console
POSPrinterEmulatorSetup-0.3.25-win-x64.exe /VERYSILENT /CustomerName="Company Name" /CustomerEmail="customer@example.com"
```

## Configuration

Development settings are stored in `src/ReceiptEmulator.App/appsettings.json`:

- `Printer:Port`: RAW listener port; default `9100`.
- `Printer:BindAddress`: listener address; default `0.0.0.0`.
- `Printer:IdleJobTimeoutMilliseconds`: completes a no-cut job after inactivity.
- `Printer:MaximumJobBytes`: rejects oversized jobs.
- `Viewer:Url`: local viewer binding; default `http://127.0.0.1:5187`.

The `Printer` section supplies the compatible default listener for every installation. In v0.3.25, Pro and Enterprise listener changes are validated against their total allowances and stored locally in SQLite through **Settings → Printer Listeners**.

## Configuration backup and restore

The v0.3.34 interface is available under **Settings → Backup & Restore**. Enter and confirm a password of at least 10 characters, optionally include local receipt history on a paid license, and save the generated `.ppebackup` file somewhere safe. The password cannot be recovered.

To restore, select the `.ppebackup` file and enter its password. The application verifies the package and displays its contents, exclusions, counts, and compatibility warnings before enabling restore. A Windows-protected safety snapshot is created first, and the running configuration is rolled back automatically if restoration fails.

Portable backups include listeners, custom profiles, the selected profile, stored logos, simulated printer states, interface preferences, and optional paid receipt history. Activation and maintenance keys, customer registration, credentials, logs, Windows printer queues, Epson drivers, and other machine-level components are never exported. Restoring a backup does not transfer the software license.

## Current MVP limitations

Version 0.3.25 stores Lite, Pro, and Enterprise history plus paid listener configuration in one local SQLite database with a 500-job history limit, while Trial remains session-only. Existing JSON and earlier SQLite history migrate transactionally. Customer-facing database maintenance, receipt comparison, online revocation/transfer, hardened Thermal rendering, PNG export, and production code-signing remain planned work.

## Release roadmap

The permanent status list for every completed, scheduled, and future release is maintained in the [release tracker](docs/RELEASE_TRACKER.md). Reported and resolved defects are maintained separately in the [bug tracker](docs/BUG_TRACKER.md). Detailed completed-release notes are recorded in [CHANGELOG.md](CHANGELOG.md).

- **Released in v0.3.15 — Capture, import, export, and replay:** Save complete ESC/POS sessions, import captured `.bin` jobs, export portable capture packages, and replay jobs through the emulator for troubleshooting and testing.
- **Released in v0.3.16 — In-place receipt export correction:** Download Text, Raw, and Capture files through the desktop application without leaving the selected receipt or displaying a WebView startup error.
- **Released in v0.3.17 — License tiers and Pro feature gates:** Add Trial, Pro, and Enterprise licensing, preserve legacy paid keys as Pro, and restrict Stored Logos, Printer State, Updates, and Support to paid licenses.
- **Released in v0.3.18 — Admin Portal and tier-aware purchase pricing:** Brand the protected administration site as the Admin Portal and manage separate Pro and Enterprise purchase prices and fulfillment.
- **Released in v0.3.19 — Printer profiles:** Add Pro and Enterprise printer profiles with selectable models, custom import/export, paper and code-page configuration, capability warnings, and profile-aware capture/replay.
- **Released in v0.3.20 — Reliable SQLite receipt history:** Store Pro and Enterprise history transactionally in an embedded database, migrate legacy JSON safely, preserve a rollback backup, and keep Trial history session-only.
- **Released in v0.3.21 — Enterprise multiple printer listeners:** Add persisted listener configuration, isolated runtimes, Enterprise UI/API gates, routing, filtering, live counters, dynamic-port firewall support, and independent fault handling.
- **Released in v0.3.22 — Receipt workflow regression fixes:** Restore near-instant Test Receipt display and reliable Clear All deletion when obsolete legacy history files are locked.
- **Released in v0.3.23 — Activation and Printer Setup Wizard fixes:** Prevent valid Enterprise activation from failing when optional paid storage cannot initialize, and create Windows printer queues through the native printer API without the WMI `Invalid parameter` failure.
- **Released in v0.3.24 — Upgrade licensing and Printer Setup safeguards:** Preserve paid licensing during updates, recover from hardened-folder persistence failures, provide Trial-safe activation diagnostics, and allocate unique Windows printer ports sequentially from 9100.
- **Released in v0.3.25 — Four-tier licensing and listener allowances:** Add Lite at $24.99, make paid features available to Lite/Pro/Enterprise, and enforce total listener caps of 1/1/2/15 for Trial/Lite/Pro/Enterprise.
- **Released in v0.3.26 — Annual Application Maintenance and Support:** Keeps licenses permanent while adding the included first year, optional one-time annual renewals, maintenance-aware updates and assisted support, grandfathered coverage through 2027-07-19, and always-available local diagnostics.
- **Released in v0.3.33 — Enhanced support package and connection diagnostics:** Adds guided service, listener, port, firewall, Windows queue, Epson driver, and storage checks; safe repair actions; privacy-reviewed support packages; and an in-app Support Request form that securely creates redacted GitHub issues through the backend. The release does not attempt to test unknown POS software implementations or store GitHub credentials in the desktop application.
- **Released in v0.3.34 — Encrypted configuration backup and restore:** Create password-protected `.ppebackup` packages, review their contents before restore, exclude license and registration secrets, optionally include paid receipt history, and recover automatically if restoration fails.
- **Next in v0.3.35 — Receipt comparison and automated validation:** Compare rendered receipts, raw bytes, and parsed commands, highlight differences, and support repeatable pass/fail validation.
- **v0.3.36 — Guided update installation and restart:** Download and verify updates in the background, create a pre-update safety snapshot, confirm an Install and Restart action, close the application safely, run an external updater, and relaunch after installation.
- **Release prerequisite — Windows 11 Pro support-policy alignment:** Remove conflicting Windows 10/11 support claims from the website, application, installer metadata, documentation, structured data, and release checks; identify 64-bit Windows 11 Pro as the only supported environment; and publish the maintenance-response and third-party POS support limitations consistently.

Following these feature releases, planned production work includes service-to-viewer authentication and installer repair, advanced SQLite maintenance and retention controls, online license transfer and revocation, hardened thermal rendering, PNG export, deterministic PDF generation, and production code-signing.

See [the architecture notes](docs/architecture.md) for implementation details and the production roadmap.

## License

Licensed under the [Apache License 2.0](LICENSE).
