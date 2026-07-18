# POS Printer Emulator release tracker

This document is the single status view for completed, scheduled, and future POS Printer Emulator releases. Detailed notes for completed releases remain in [CHANGELOG.md](../CHANGELOG.md), and defect status is maintained in the [bug tracker](BUG_TRACKER.md).

## Tracking system

GitHub Issues and GitHub Projects are the official working trackers for features, releases, backlog items, and defects. This file remains the repository-owned release summary and must be updated when GitHub tracking state changes. MantisBT is not part of the project.

## Versioning policy

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`. The release after `v0.3.99` will be `v0.4.00`.

## Current release

**Current public release: v0.3.19**

## Completed releases

| Version | Status | Release theme |
| --- | --- | --- |
| v0.1.00 | Released | Initial local ESC/POS listener, parser, receipt viewer, and MVP tooling |
| v0.2.00 | Released | POS Printer Emulator branding, desktop HTML application, installer, and uninstall |
| v0.3.00 | Released | Trial/Pro/Enterprise licensing, registration, persistent history, and activation |
| v0.3.01 | Released | Collapsible panels, job deletion, License Manager, and public website |
| v0.3.02 | Released | Updated built-in Test Receipt |
| v0.3.03 | Released | Settings, application updates, and support diagnostics |
| v0.3.04 | Released | Privacy-safe telemetry, owner dashboard, and deployment tools |
| v0.3.05 | Released | Direct Settings dialog, unified web administration, activation-key generation, and 2FA |
| v0.3.06 | Released | Update installer file-lock correction |
| v0.3.07 | Released | Registration reuse and prefill during upgrades |
| v0.3.08 | Released | Branded Windows application and installer icons |
| v0.3.09 | Released | Test Receipt logo and ESC/POS raster-image rendering |
| v0.3.10 | Released | Automated Printer Setup Wizard and bundled Epson driver installation |
| v0.3.11 | Released | Printer-state simulation and Epson status responses |
| v0.3.12 | Released | Expanded ESC/POS images, QR, barcode, text-mode, and positioning compatibility |
| v0.3.13 | Released | Imported Stored Logos and Epson NV graphic substitution |
| v0.3.14 | Released | Reliable dashboard print-job telemetry and canonical endpoint reporting |
| v0.3.15 | Released | Portable capture packages, safe import, export, and replay |
| v0.3.16 | Released | In-place Text, Raw, and Capture download correction |
| v0.3.17 | Released | Trial, Pro, and Enterprise license tiers and Pro feature gates |
| v0.3.18 | Released | Admin Portal branding and separate Pro and Enterprise purchase pricing |
| v0.3.19 | Released | Pro and Enterprise printer profiles, custom configuration, and profile-aware processing |

## Scheduled releases

The scheduled order is dependency-driven: licensing tiers establish the commercial feature boundary; profiles then define printer behavior; multiple listeners reuse profiles; comparison uses deterministic captures and profiles; enhanced diagnostics can report across the complete system.

### v0.3.15 — Capture, import, export, and replay

**Status:** Released

**Purpose:** Make real customer print streams reproducible without repeatedly connecting the original POS system. This is the foundation for later profile, comparison, and diagnostic work.

**Planned scope:**

- Capture the original ESC/POS bytes, connection metadata, received time, processing result, parsed-command summary, and renderer version for each job.
- Import `.bin` files and supported POS Printer Emulator capture packages through the desktop interface.
- Export a selected job as raw `.bin` data or as a portable capture package containing a manifest and integrity checksum.
- Replay an imported or saved capture through the normal parser and renderer without counting it as a live Trial print job.
- Clearly label live, imported, and replayed jobs in Activity.
- Validate file size and format, reject unsafe paths or malformed packages, and avoid executing imported content.
- Keep all capture data local unless the customer deliberately exports it.

**Complete when:** A supplied binary receipt can be imported, rendered, exported, re-imported, and replayed with identical bytes and output; malformed files fail safely with useful logs.

### v0.3.16 — In-place receipt export correction

**Status:** Released

**Purpose:** Correct the v0.3.15 desktop export failure without delaying a customer-facing fix until the larger printer-profile feature was complete.

**Released scope:**

- Download Text, Raw, and Capture files without navigating the WebView away from the selected receipt.
- Open a native Windows Save dialog from the desktop application for receipt exports.
- Keep an already-loaded viewer visible if WebView2 reports `ConnectionAborted` for an attachment navigation.
- Show download progress and plain-language failures while retaining the established export formats.
- Record and release BUG-005 with production builds, automated tests, endpoint checks, and rendered-viewer verification.

**Complete when:** All three export actions save their expected attachment types, the receipt and viewer URL remain unchanged, and the installed desktop application no longer replaces the viewer with a startup error.

### v0.3.17 — License tiers and Pro feature gates

**Status:** Released

**Purpose:** Establish a durable three-level licensing model before Enterprise-only functionality is introduced.

**Released scope:**

- Model Trial, Pro, and Enterprise licenses throughout the service, viewer, telemetry, database, and administration tools.
- Treat existing paid activation keys as Pro so current customers upgrade without receiving replacement keys.
- Issue new tier-aware Pro or Enterprise keys from the vendor License Manager and Admin site.
- Lock Stored Logos, Printer State, Check for Updates, and Support for Trial users in the Settings interface.
- Enforce the same rules on every corresponding local API, including read, write, delete, diagnostics, and update-check operations.
- Continue selling Pro keys through the existing PayPal purchase flow while reserving Enterprise keys for owner-managed issuance.

**Complete when:** Trial users see all four settings as locked and receive HTTP 403 from their APIs, while Pro and Enterprise keys immediately unlock them and legacy paid keys still validate as Pro.

### v0.3.18 — Admin Portal and tier-aware purchase pricing

**Status:** Released

**Purpose:** Give the business one clearly named administration area and let it price and sell Pro and Enterprise licenses independently.

**Released scope:**

- Brand `admin.posprinteremulator.com` consistently as the POS Printer Emulator Admin Portal.
- Add separate Pro and Enterprise price controls to Purchase Pricing.
- Display both license choices and their server-controlled prices on the purchase website.
- Carry the selected tier through PayPal order creation, payment verification, order approval, activation-key issuance, and email delivery.
- Preserve all existing orders as Pro orders during the automatic purchase-database migration.
- Prevent website deployments from overwriting server-owned credentials, purchase records, pricing settings, and signing keys.

**Complete when:** The Admin Portal shows two independently saved prices, the purchase page changes tiers and amounts without reload, and an approved order receives the same tier of activation key that the customer purchased.

### v0.3.19 — Printer profiles

**Status:** Released

**Tracking issue:** [GitHub #4](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/4)

**Purpose:** Represent differences between printer models explicitly instead of assuming every incoming job behaves like one TM-T88V configuration.

**Planned scope:**

- Add built-in profiles for the tested Epson TM-T88V configuration and a generic ESC/POS printer.
- Configure paper width, printable dots, default and supported code pages, fonts, cutter, cash drawer, image limits, barcode/QR capabilities, two-color output, and status behavior.
- Allow customers to duplicate and customize profiles while protecting built-in defaults.
- Add profile selection and management under Settings.
- Record the selected profile with captured and saved jobs so replay remains deterministic.
- Show profile-related unsupported commands and capability mismatches in plain language.
- Export and import custom profile definitions with schema-version validation.
- Restrict the Settings section and all profile APIs to Pro and Enterprise licenses; Trial receives an upgrade lock and HTTP 403.

**Complete when:** The same captured job can be replayed against two profiles and the viewer consistently shows the expected rendering and capability differences.

### v0.3.20 — Enterprise multiple printer listeners

**Status:** In progress

**Tracking issue:** [GitHub #5](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/5)

**Purpose:** Let one Enterprise installation emulate multiple receipt printers for different POS stations, departments, or printer roles while Trial and Pro retain the existing single listener.

**Implementation milestones:**

- [x] Establish a lightweight SQLite paid-history foundation with WAL, schema versioning, transactional writes, listener-ready indexes, 500-job retention, verified JSON migration, and a rollback backup. Trial remains session-only.
- [ ] Add the persisted listener configuration model and Enterprise authorization boundary.
- [ ] Run independent listener instances with isolated ports, profiles, printer states, buffers, counters, and failure handling.
- [ ] Add Enterprise listener management, connection details, Activity filtering, port-conflict guidance, and upgrade messaging to the desktop UI.
- [ ] Complete firewall automation, upgrade migration, concurrent-listener tests, installer QA, documentation, and release publication.

**Planned scope:**

- Create, edit, start, stop, and remove independently named listeners.
- Assign a unique TCP port, bind address, printer profile, and simulated printer state to each listener.
- Detect port conflicts before saving and explain how to correct them.
- Filter Activity by listener and show the destination listener on every job.
- Display per-listener connection, job, byte, warning, and error counters.
- Extend firewall and setup automation for the configured listener ports.
- Preserve a safe default listener at `0.0.0.0:9100` for existing installations.
- Prevent one failed listener from stopping other configured listeners.
- Restrict multiple-listener APIs and controls to Enterprise licenses; Trial and Pro continue using the default listener on port `9100`.

**Complete when:** At least two Enterprise listeners can receive simultaneous jobs on different ports, apply different profiles, remain independently controllable, and survive an application restart while Trial and Pro single-listener behavior remains unchanged.

### v0.3.21 — Receipt comparison and automated validation

**Status:** Planned

**Purpose:** Turn the emulator into a repeatable compatibility-testing tool for POS changes, printer migrations, and regression testing.

**Planned scope:**

- Select any two jobs or compare a job against a named saved baseline.
- Compare raw bytes, normalized parsed commands, extracted text, warnings, and rendered receipt output.
- Highlight additions, removals, changed commands, layout changes, and image differences.
- Allow configurable comparison rules for values such as dates, times, check numbers, and transaction identifiers.
- Save validation suites made from capture files and printer profiles.
- Run a validation suite locally and produce clear pass, warning, or fail results.
- Export human-readable HTML/PDF results and machine-readable JSON results.
- Add deterministic golden-output tests for the supported renderer behavior.

**Complete when:** A known-good capture passes its baseline, an intentional command or layout change fails with a precise difference, and ignored dynamic fields do not cause false failures.

### v0.3.22 — Enhanced support package and connection diagnostics

**Status:** Planned

**Purpose:** Reduce support time by guiding nontechnical customers through connection checks and producing a privacy-aware package when assistance is required.

**Planned scope:**

- Add guided checks for service status, listener state, bind address, port availability, Windows firewall rules, printer queues, Epson driver state, and viewer health.
- Test local and remote connectivity with plain-language results and corrective suggestions.
- Report each configured listener and profile without including receipt contents by default.
- Build a redacted support package containing logs, versions, configuration summaries, health results, and recent error metadata.
- Preview exactly what will be included before saving a support package.
- Require explicit consent before including raw receipt captures, receipt text, registration fields, or activation information.
- Add one-click copy for a support summary and a stable package identifier.
- Include repair links or actions for problems the application can safely correct.

**Complete when:** A customer can diagnose common service, port, firewall, and driver problems without opening Windows administration tools and can produce a reviewed, redacted package for support.

### v0.3.23 — Guided update installation and restart

**Status:** Planned

**GitHub:** [Issue #3 — Guided update installation and restart](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/3)

**Purpose:** Make application updates reliable and understandable by closing the running application cleanly before the installer replaces its files, then returning the customer to the updated application.

**Planned scope:**

- Download an available installer in the background while the application remains usable.
- Verify the completed download against the release checksum and trusted publisher signature before offering installation.
- Replace the current update action with a clear **Install and Restart** confirmation that explains the listener will be briefly unavailable.
- Provide **Install and Restart**, **Install Later**, and **Cancel** choices without closing the application unexpectedly.
- Detect an active incoming print job, finish or preserve it safely, and stop accepting new jobs before shutdown.
- Save the selected receipt, window state, settings, registration, activation, profiles, stored logos, and local history.
- Stop the listener and background service cleanly, launch a separate updater process, and exit every application process that could lock installed files.
- Wait for file locks to clear, run the installer with minimal prompts, preserve existing registration data, and relaunch POS Printer Emulator automatically.
- Show the installed version and a success confirmation after restart.
- Keep the existing version recoverable when installation fails, record update diagnostics in the support log, and show plain-language recovery instructions.
- Add an optional automatic-download preference while always requiring confirmation before closing and installing.

**Why this priority:** The external updater and controlled shutdown eliminate the remaining class of self-update file-lock failures while preventing unexpected listener downtime or loss of customer state.

**Complete when:** From Settings, a customer can download an update, choose Install and Restart, see the listener stop cleanly, complete the installation with no locked-file error, relaunch automatically on the new version, and retain registration, licensing, settings, stored data, and the previously selected receipt; cancel and failure paths leave the current installation usable.

## Future backlog

These items remain unnumbered until the order is approved. The priority below is the recommended implementation order after v0.3.23.

### Priority 1 — Service-to-viewer authentication and installer repair

**Why first:** It closes the most important local security boundary and gives customers a supported recovery path before storage and licensing become more complex.

**Proposed scope:**

- Generate a unique per-installation credential for the desktop viewer and local service.
- Require authentication for state-changing localhost API operations and restrict allowed origins.
- Protect activation, deletion, import, replay, listener configuration, stored-logo, and printer-state operations.
- Add a Repair Installation workflow for the Windows service, firewall rules, WebView2, shortcuts, registration data, and local viewer health.
- Preserve customer settings, activation, imported logos, and receipt history during repair.
- Log repair actions and verify the repaired installation before reporting success.

### Priority 2 — Advanced SQLite maintenance and retention

**Why second:** The transactional SQLite foundation and safe JSON migration are now part of v0.3.20. Customer-facing maintenance, larger-history controls, and recovery tools should follow after the multiple-listener data model stabilizes.

**Proposed scope:**

- Add paging, fast search, source/listener/profile filters, and reliable aggregate counts for larger histories.
- Add configurable retention by job count, storage size, or age.
- Support individual deletion, Clear All, database health checks, and safe database repair.
- Add backup and restore with schema and integrity validation.
- Add reviewed cleanup of the rollback-safe legacy JSON backup after the customer confirms successful migration.

### Priority 3 — Production code-signing and deployment validation

**Why third:** Signed binaries and installers improve customer trust and tamper verification. It should move earlier if a production signing certificate is already available.

**Proposed scope:**

- Sign the desktop executable, service, installer, uninstaller, and other distributed executables with a trusted Windows code-signing certificate.
- Apply trusted timestamps so signatures remain valid after certificate expiration.
- Verify signatures and hashes as part of the build and release process.
- Publish installer checksums with GitHub releases and validate downloaded updates before launch.
- Test clean install, upgrade, repair, silent install, and uninstall on supported Windows 10/11 environments.
- Document certificate custody, renewal, and emergency revocation procedures without storing private signing material in the repository.

### Priority 4 — Online license deactivation, revocation, and transfer

**Why fourth:** It improves commercial license control, but requires a highly reliable online service and clear offline behavior. The current signed offline activation remains functional while this is built.

**Proposed scope:**

- Let customers deactivate a licensed computer and transfer an available activation to a replacement computer.
- Add owner controls for revoking, restoring, and auditing issued licenses.
- Enforce configurable activation limits and transfer cooldowns.
- Cache signed authorization state locally with a defined offline grace period.
- Avoid disabling valid customers because of temporary network or server failures.
- Record privacy-minimized activation events and show actionable license status in the desktop application.

### Priority 5 — PNG export and deterministic PDF generation

**Why fifth:** It is a valuable customer-facing feature, and the comparison release benefits from deterministic rendering first.

**Proposed scope:**

- Export the complete receipt as PNG at predictable thermal-printer dimensions.
- Generate consistent PDFs independent of the desktop window size, zoom level, or selected theme.
- Preserve receipt width, long-page layout, images, barcodes, QR codes, and watermark rules.
- Support individual and selected-job batch export with safe filenames.
- Add export metadata and deterministic-output tests.
- Keep premium export controls aligned with Trial, Pro, and Enterprise license rules.

### Priority 6 — Hardened Thermal adapter

**Why sixth:** It offers deeper compatibility but carries the greatest implementation and packaging risk. Capture, profiles, comparison baselines, and diagnostics should exist first so compatibility can be measured safely.

**Proposed scope:**

- Integrate the hardened Thermal renderer through an isolated local process so parser failures cannot terminate the listener service.
- Define a stable versioned JSON or C ABI contract with structured errors and original byte offsets.
- Add configurable printer profiles and parity for images, NV graphics, QR codes, barcodes, fonts, positioning, and code pages.
- Reject malformed input without crashes, hangs, excessive memory use, or unbounded output.
- Add golden receipt fixtures, differential tests against the managed parser, fuzz testing, and performance limits.
- Retain the managed parser as a controlled fallback during rollout.

## Release completion checklist

A release is marked **Released** only after all applicable items are complete:

- [ ] Implement the planned feature or correction.
- [ ] Link all included bug IDs and update their status in `docs/BUG_TRACKER.md`.
- [ ] Update the matching Release Tracker and Bug Tracker records in the protected Admin **Dev Support** page.
- [ ] Add or update automated tests and complete local verification.
- [ ] Update application, desktop, installer, and vendor-tool version values.
- [ ] Add detailed release notes to `CHANGELOG.md`.
- [ ] Move the release from **Planned** to **Released** in this tracker and identify the next release.
- [ ] Build and test the all-in-one Windows installer and upgrade path.
- [ ] Commit and push the source to GitHub.
- [ ] Publish the tagged GitHub Release with the versioned installer.
- [ ] Update the `www.posprinteremulator.com` download link and displayed version.
- [ ] Confirm the application's Check for Updates flow detects and launches the new release.
- [ ] Smoke-test the public download, installation, listener, receipt preview, and uninstall.
