# POS Printer Emulator feature history

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`; the next release after `v0.3.99` is `v0.4.00`.

For the current release status, scheduled versions, future backlog, and release-completion checklist, see the [release tracker](docs/RELEASE_TRACKER.md). Reported, fixed, and released defects are indexed in the [bug tracker](docs/BUG_TRACKER.md).

## v0.3.22 — 2026-07-18

- Restored near-instant Test Receipt display by returning the complete generated receipt from the sample endpoint and selecting it immediately while Activity refreshes in the background.
- Fixed **Delete All Print Jobs** returning HTTP 500 when a stale, read-only, or locked legacy JSON history file remained after the SQLite history was cleared.
- Kept paid-history deletion durable by treating obsolete legacy-file cleanup as best effort after the authoritative SQLite transaction succeeds.
- Added plain-language local API problem details for unexpected receipt-history deletion failures.
- Added regression coverage for locked legacy history and verified Test Receipt and Clear All end to end at 280 ms and 285 ms respectively.

## v0.3.21 — 2026-07-18

- Added Enterprise-only management for up to 16 independently named RAW TCP printer listeners while Trial and Pro retain one compatible default listener.
- Added create, edit, start, stop, restart, and delete controls under **Settings → Printer Listeners**, with connection details, live status, buffer settings, per-listener counters, and clear Enterprise upgrade guidance.
- Added isolated listener runtimes with independent bind addresses, ports, printer profiles, simulated printer state, job buffers, connection tracking, and failure handling so one listener cannot stop the others.
- Added transactional SQLite schema v2 persistence for listener configuration and immutable listener ID, name, and port snapshots on receipt history, legacy JSON, and capture packages.
- Added Activity filtering by printer listener and displayed the destination listener and endpoint in receipt summaries and Job Details.
- Added pre-save IPv4, reserved-port, duplicate-name, duplicate-port, profile, buffer, and maximum-job-size validation with plain-language conflict errors.
- Preserved `0.0.0.0:9100` as the non-removable default listener and migrated existing installations without losing v0.3.20 receipt history.
- Enforced the Enterprise boundary in both the local API and Settings UI; Trial and Pro multiple-listener requests are rejected without creating listener configuration storage.
- Changed Windows setup to a private/domain, program-scoped RAW TCP firewall rule so newly configured listener ports work without separate customer firewall steps.
- Fixed `BUG-006`, which could raise an unhandled double-disposal error after the Windows host had already stopped all listener runtimes during application or service shutdown.
- Added simultaneous-listener, routing, isolation, restart, persistence, migration, port-conflict, authorization, buffer, capture, and receipt-history regression coverage.

## v0.3.20

- Replaced Pro and Enterprise JSON receipt history with one embedded SQLite database while keeping Trial history session-only.
- Added a versioned receipt schema, WAL journaling, transactional writes, listener-ready indexes, and the existing 500-job paid-history limit without requiring a separate database installation.
- Added a verified, idempotent migration from legacy JSON history with a rollback backup retained on the customer computer.
- Isolated damaged database rows so valid receipt history continues loading, and made delete and Clear All operations remain durable across restarts.
- Kept the in-memory and persisted 500-job limits aligned when a Trial installation activates Pro or Enterprise.
- Hardened ProgramData permissions for the Windows service, administrators, and the operating system.
- Added automatic SQLite runtime and executable-version verification to release builds, safer service shutdown during upgrades, and installed third-party notices.

## v0.3.19

- Added Pro and Enterprise Printer Profiles under Settings while keeping the feature locked for Trial installations in both the UI and local APIs.
- Added protected built-in profiles for EPSON TM-T88V Receipt5 and a conservative Generic ESC/POS 80 mm printer.
- Added custom-profile creation, editing, duplication, deletion, active selection, and persistent local storage.
- Added configurable paper width, printable dots, raster-image limits, default and supported code pages, Font A/B columns, cutter, cash drawer, image, NV-graphics, barcode, QR, two-color, DLE EOT, and Automatic Status Back capabilities.
- Added schema-versioned `.ppeprofile` import and export with size and data validation.
- Recorded profile identity and dimensions with saved jobs and portable capture packages.
- Made replay use the currently selected profile while retaining the source capture profile for comparison and traceability.
- Added profile-specific unsupported-command warnings, image-limit warnings, status-protocol behavior, default-code-page decoding, and paper-width-aware receipt previews.
- Added the selected profile to Job Details and privacy-safe support diagnostics.

## v0.3.18

- Renamed the protected administration website to the POS Printer Emulator Admin Portal throughout its interface and documentation.
- Added independent Pro and Enterprise price controls to Purchase Pricing.
- Added Pro and Enterprise license selection to the purchase page with live price updates.
- Preserved the purchased tier through PayPal order creation, approval, activation-key generation, and customer email delivery.
- Added a backward-compatible purchase-database migration that treats existing orders as Pro orders.
- Prevented website publishing from overwriting server-owned private configuration, purchase data, pricing settings, and signing keys.

## v0.3.17

- Added explicit Trial, Pro, and Enterprise license levels across the desktop application, activation-key format, telemetry, database schema, and owner dashboard.
- Renamed the former paid-edition wording to Pro License while preserving every existing paid activation key as a valid Pro key.
- Restricted Stored Logos, Printer State, Check for Updates, and Support to Pro and Enterprise licenses in both the Settings UI and protected local APIs.
- Added Pro lock badges for restricted Trial settings and stopped Trial installations from loading those resources or running automatic update checks.
- Added Pro and Enterprise selection to the desktop and web License Managers; normal website purchases continue to issue Pro keys.
- Added an idempotent database migration that converts legacy `Full` telemetry records to `Pro` and records issued-license tiers.

## v0.3.16

- Fixed BUG-005 so Text, Raw, and Capture exports download without navigating the desktop WebView away from the selected receipt.
- Added a native Windows Save dialog for receipt exports in the desktop application.
- Kept the loaded viewer visible when WebView2 reports an attachment-navigation `ConnectionAborted` event after startup.
- Added in-progress feedback and plain-language download errors while preserving the existing `.txt`, `.bin`, and `.ppecapture` formats.

## v0.3.15

- Added portable `.ppecapture` export packages containing the exact ESC/POS payload, capture metadata, renderer version, parsed-command summary, and a SHA-256 integrity checksum.
- Added Pro import for raw `.bin` receipts and validated POS Printer Emulator capture packages directly from Activity.
- Added safe replay through the normal parser and renderer without consuming a Trial print-job allowance or reporting replayed jobs as live usage.
- Added Live, Imported, and Replayed origin badges plus original source, time, parent-job, imported-file, and renderer details.
- Added strict archive-entry, schema, file-size, payload-length, and checksum validation so malformed or unsafe packages fail without being extracted or executed.
- Preserved compatibility with existing saved job history while recording the new capture metadata for future jobs.

## v0.3.14

- Fixed aggregate usage reporting so completed emulated print jobs reach the dashboard through the canonical HTTPS telemetry endpoint.
- Retained and retried pending print-job totals when the telemetry service is temporarily unavailable instead of dropping the count.
- Changed the website canonical redirect to preserve POST methods where the hosting platform permits it.

## v0.3.13

- Added a Stored Logos library under Settings for importing PNG, JPEG, or WebP images and assigning them to two-character Epson NV graphic keys.
- Receipt previews now substitute an imported logo whenever a POS job requests the matching printer-resident image key.
- Added local, persistent C# storage with replace and confirmed-delete controls; imported logo files remain on the customer computer.
- Corrected recognized `GS ( L` NV print commands so missing printer-resident image data is informational instead of an unsupported-command warning.
- Added regression coverage for stored NV graphics and the QR command order used by the supplied customer receipt.

## v0.3.12

- Expanded ESC/POS compatibility for configured QR codes and barcode dimensions, symbology, and human-readable text placement.
- Added legacy `ESC *` bit-image conversion so older POS logo commands render in the receipt preview.
- Added text Font A/B, reverse, red/black, 90-degree rotation, upside-down, horizontal-tab, and absolute/relative positioning support.
- Replaced the QR placeholder with a standards-based QR renderer while preserving compatibility with existing saved receipts.
- Improved command diagnostics with decoded barcode types, QR settings, positioning values, and image dimensions.

## v0.3.11

- Added a Printer State panel under Settings with Ready, Paper Low, Paper Out, Cover Open, Cutter Error, and Offline scenarios.
- Added custom simulation controls for recoverable, unrecoverable, and automatically recoverable errors plus cash-drawer state.
- Added Epson `DLE EOT` real-time printer, offline-cause, error-cause, and paper-sensor status responses.
- Added Epson `GS a` Automatic Status Back with immediate state-change notifications to connected POS clients.
- Added `DLE ENQ` recovery handling, status-response counters, support diagnostics, and protocol-level automated tests.

## v0.3.10

- Added a six-step Printer Setup Wizard under Settings for same-computer and network POS configurations.
- Added automatic Epson TM-T88V Receipt5 driver detection with exact APD, driver, and Status API version reporting.
- Added administrator-approved C# installation of Epson components, a RAW Standard TCP/IP port, and the Windows printer queue without PowerShell.
- Added connection and Windows printer verification, incomplete-install rollback, plain-language failure results, technical logging, retry, and raw Test Receipt printing.
- Packaged the official signed Epson APD 5.13 TM-T88V driver components in the all-in-one installer.

## v0.3.09

- Added the monochrome POS Printer Emulator logo to the built-in Test Receipt as a real ESC/POS raster image.
- Added parsing and receipt-preview rendering for standard `GS v 0` raster image commands sent by POS systems.

## v0.3.08

- Added the branded POS Printer Emulator icon to the desktop executable, Windows shortcuts, taskbar, uninstaller, and setup program.
- Added a multi-resolution Windows icon with transparent corners for clear display on light and dark desktops.

## v0.3.07

- Existing installations now reuse their saved customer or company name and email address during upgrades.
- The installer skips the registration page when both saved values are valid, and prefills any available values when confirmation is still needed.

## v0.3.06

- Fixed update installation failures caused by reusing a locked temporary installer file; every download now uses a unique staging directory and is fully closed before launch.

## v0.3.05

- Changed the Windows application Settings button to open the Settings dialog directly without an intermediate dropdown menu.
- Moved the owner dashboard and License Manager to `admin.posprinteremulator.com` behind a shared secure sign-in.
- Added web-based customer activation-key generation with protected server-side signing and issued-license history.
- Added authenticator-app two-factor authentication with local QR enrollment and six-digit TOTP verification.

## v0.3.04

- Added privacy-safe HTTPS reporting for installation registration, Trial/Pro/Enterprise status, application launches, and emulated print-job counts.
- Added a MariaDB schema for installations and daily aggregate usage without storing receipt text or raw ESC/POS data.
- Added a password-protected License & Usage owner dashboard with date ranges, usage charts, license distribution, search, and license filtering.
- Added C# database provisioning, production telemetry smoke testing, protected configuration upload, and website publishing tools.
- Updated the privacy notice and public download to the v0.3.04 all-in-one installer.

## v0.3.03

- Added a Settings menu with License, Check for Updates, and Support sections.
- Moved license status and activation management from the application header into Settings.
- Added manual and automatic GitHub Releases update checks with new-version notifications and desktop installer launch.
- Added downloadable support diagnostics with rolling application logs and system status information.

## v0.3.02

- Updated the built-in Test Receipt with the Atlanta address, Check label, and E. Perez server name.

## v0.3.01

- Added collapse and restore controls for the left Activity panel and right receipt inspector.
- Added deletion for an individual receipt job and a confirmed Clear All action.
- Pro deletion removes saved history files; Trial deletion removes session jobs.
- Added Epson `GS ( L` stored-graphics parsing without leaking binary parameters into receipt text.
- Added filtering for control-only initialization and cash-drawer connections.
- Added the vendor-only C# License Manager desktop interface.
- Launched the responsive `posprinteremulator.com` product, licensing, support, and installer-download website.

## v0.3.00

- Added Trial, Pro, and Enterprise versions with offline signed activation keys.
- Added installer registration for customer/company name and email.
- Added persistent Pro receipt history, exports, and in-app activation.

## v0.2.00

- Renamed the product to POS Printer Emulator and applied the supplied branding.
- Added the C# desktop HTML application with Light and Dark modes.
- Added the all-in-one Windows installer, service configuration, and clean uninstall.

## v0.1.00

- Initial local ESC/POS listener, parser, receipt viewer, and MVP build tooling.
