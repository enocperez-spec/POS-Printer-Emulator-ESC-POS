# POS Printer Emulator feature history

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`; the next release after `v0.3.99` is `v0.4.00`.

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

- Added privacy-safe HTTPS reporting for installation registration, Trial/Full status, application launches, and emulated print-job counts.
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
- Full-Version deletion removes saved history files; Trial deletion removes session jobs.
- Added Epson `GS ( L` stored-graphics parsing without leaking binary parameters into receipt text.
- Added filtering for control-only initialization and cash-drawer connections.
- Added the vendor-only C# License Manager desktop interface.
- Launched the responsive `posprinteremulator.com` product, licensing, support, and installer-download website.

## v0.3.00

- Added Trial and Full versions with offline signed activation keys.
- Added installer registration for customer/company name and email.
- Added persistent Full-Version receipt history, exports, and in-app activation.

## v0.2.00

- Renamed the product to POS Printer Emulator and applied the supplied branding.
- Added the C# desktop HTML application with Light and Dark modes.
- Added the all-in-one Windows installer, service configuration, and clean uninstall.

## v0.1.00

- Initial local ESC/POS listener, parser, receipt viewer, and MVP build tooling.
