# POS Printer Emulator feature history

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`; the next release after `v0.3.99` is `v0.4.00`.

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
