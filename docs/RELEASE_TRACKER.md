# POS Printer Emulator release tracker

This document is the single status view for completed, scheduled, and future POS Printer Emulator releases. Detailed notes for completed releases remain in [CHANGELOG.md](../CHANGELOG.md), and defect status is maintained in the [bug tracker](BUG_TRACKER.md).

## Tracking system

GitHub Issues and GitHub Projects are the official working trackers for features, releases, backlog items, and defects. This file remains the repository-owned release summary and must be updated when GitHub tracking state changes. MantisBT is not part of the project.

## Versioning policy

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`. The release after `v0.3.99` will be `v0.4.00`.

## Current release

**Current public release: v0.3.39 — released 2026-07-22**

**Current development: v0.3.40 — Simple Mode and Expert Mode**

**Next release after v0.3.40: v0.3.41 — Accessibility and keyboard usability**

**Future scheduled sequence: v0.3.40 through v0.3.49**

**Most recently completed: v0.3.39 — Guided update installation and restart**

### v0.3.32 — Updater installer-asset validation

**Status:** Released — 2026-07-21

**Purpose:** Prevent documentation-only GitHub releases from being presented as desktop installers and provide a real installer asset for the updater to download.

**Released scope:**

- Require a trusted Windows `.exe` release asset before enabling **Download and install**.
- Show a clear message when a newer release has no Windows installer instead of attempting to open the release webpage as an installer.
- Add regression tests for both installer and no-installer GitHub release responses.
- Build and publish `POSPrinterEmulatorSetup-0.3.32-win-x64.exe` with a SHA-256 checksum.

**Complete when:** An installed v0.3.26 or v0.3.31 customer sees a valid installer download for v0.3.32, while a documentation-only release cannot trigger an installer launch.

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
| v0.3.20 | Released | Reliable SQLite receipt history, verified migration, and safer release packaging |
| v0.3.21 | Released | Enterprise multiple printer listeners, isolated runtimes, and listener-aware Activity |
| v0.3.22 | Released | Test Receipt performance and reliable Clear All job deletion |
| v0.3.23 | Released | Enterprise activation and Printer Setup Wizard maintenance fixes |
| v0.3.24 | Released | Upgrade licensing and Printer Setup safeguards |
| v0.3.25 | Released | Four-tier licensing and upgrade paths |
| v0.3.26 | Released | Annual Application Maintenance and Support |
| v0.3.30 | Released | Security remediation (Phase 1) |
| v0.3.31 | Released | Secure development lifecycle (Phase 2) |
| v0.3.32 | Released | Updater installer-asset validation |
| v0.3.33 | Released | Enhanced support package, connection diagnostics, and in-app support requests |
| v0.3.34 | Released | Encrypted backup, EULA, and support policy |
| v0.3.35 | Released | Backup restore usability and compatibility |
| v0.3.36 | Released | Privacy-preserving geographic analytics dashboard |
| v0.3.37 | Released | Trial setup and onboarding improvements |
| v0.3.38 | Released | Trial onboarding clarity correction |
| v0.3.39 | Released | Guided update installation and restart |

## Scheduled releases

The scheduled order is customer-support driven: v0.3.25 establishes the four-tier commercial boundary and listener allowances; v0.3.26 adds maintenance without turning permanent licenses into subscriptions; v0.3.30-v0.3.32 complete security and updater work; v0.3.33 provides safe diagnostics; v0.3.34-v0.3.35 protect and clarify backups; v0.3.36 adds privacy-preserving adoption analytics; v0.3.37 introduces Trial onboarding; v0.3.38 corrects its visibility and listener clarity; v0.3.39 closes the in-application update lifecycle; v0.3.40-v0.3.47 improve everyday usability, recovery, organization, privacy, background awareness, international text compatibility, and restricted-network deployment; v0.3.48 delivers receipt comparison and automated validation; and v0.3.49 makes public update awareness available to every license and maintenance state.

### v0.3.15 — Capture, import, export, and replay

**Status:** Released

**Purpose:** Make real customer print streams reproducible without repeatedly connecting the original POS system. This is the foundation for later profile, comparison, and diagnostic work.

**Released scope:**

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

### v0.3.20 — Reliable SQLite receipt history

**Status:** Released

**Purpose:** Replace individual paid-history JSON files with a minimal transactional local database before multiple independently configured listeners share the same history system.

**Released scope:**

- Store Pro and Enterprise receipt history in one embedded SQLite database with no separate customer installation or database service.
- Keep Trial history session-only and preserve the existing 500-job paid-history limit.
- Use a versioned schema, WAL journaling, transactions, and listener-ready indexes.
- Copy and verify legacy JSON history into a rollback backup before importing it transactionally.
- Isolate damaged database rows so valid history remains available.
- Make delete, Clear All, retention, and Trial-to-paid activation remain consistent across restarts.
- Harden service-data permissions and verify the SQLite runtime, executable versions, notices, and safe service shutdown during release packaging.

**Complete when:** Existing paid history migrates without data loss, Trial creates no database, Pro and Enterprise history survives restart within the 500-job limit, damaged rows do not hide good data, and the all-in-one installer loads its bundled SQLite runtime.

### v0.3.21 — Enterprise multiple printer listeners

**Status:** Released — 2026-07-18

**Tracking issue:** [GitHub #5](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/5)

**Purpose:** Let one Enterprise installation emulate multiple receipt printers for different POS stations, departments, or printer roles while Trial and Pro retain the existing single listener.

**Implementation milestones:**

- [x] Add the persisted listener configuration model and Enterprise authorization boundary.
- [x] Run independent listener instances with isolated ports, profiles, printer states, buffers, counters, and failure handling.
- [x] Add Enterprise listener management, connection details, Activity filtering, port-conflict guidance, and upgrade messaging to the desktop UI.
- [x] Complete firewall automation, upgrade migration, concurrent-listener tests, installer QA, documentation, and release publication.

**Released scope:**

- Create, edit, start, stop, restart, and remove independently named listeners.
- Assign a unique TCP port, bind address, printer profile, and simulated printer state to each listener.
- Detect port conflicts before saving and explain how to correct them.
- Filter Activity by listener and show the destination listener on every job.
- Display per-listener active/total connection, queued/processing job, byte, completed, rejected, and failed counters.
- Use a private/domain, program-scoped RAW TCP firewall rule that automatically covers validated listener ports.
- Preserve a safe default listener at `0.0.0.0:9100` for existing installations.
- Prevent one failed listener from stopping other configured listeners.
- Restrict multiple-listener APIs and controls to Enterprise licenses; Trial and Pro continue using the default listener on port `9100`.

**Complete when:** At least two Enterprise listeners can receive simultaneous jobs on different ports, apply different profiles, remain independently controllable, and survive an application restart while Trial and Pro single-listener behavior remains unchanged.

### v0.3.22 — Receipt workflow regression fixes

**Status:** Released — 2026-07-18

**Purpose:** Restore fast Test Receipt feedback and reliable paid-history cleanup before the next feature release.

**Released scope:**

- Return and display the complete generated Test Receipt immediately while Activity refreshes in the background.
- Avoid a redundant receipt-detail fetch after the generated job is already loaded.
- Keep the SQLite clear transaction authoritative when obsolete legacy JSON cleanup encounters a stale, read-only, or locked file.
- Return plain-language API problem details for receipt-history deletion failures.
- Add locked-file regression coverage and end-to-end timing verification.

**Complete when:** Test Receipt appears without a multi-second delay, Clear All removes paid history without HTTP 500, deletion remains durable after restart, and related automated and end-to-end tests pass.

### v0.3.23 — Activation and Printer Setup Wizard fixes

**Status:** Released — 2026-07-19

**Purpose:** Correct two High-severity failures before resuming feature development: Enterprise activation returning HTTP 500 and Windows printer installation failing with an invalid WMI parameter.

**Released scope:**

- Keep valid Enterprise activation successful even when optional paid-history or listener storage needs recovery.
- Return safe activation validation results for malformed or truncated keys.
- Use unique temporary files and atomic replacement for license persistence.
- Replace WMI printer-queue creation with the native Windows `AddPrinter` API.
- Preserve automated TCP/IP port creation, Epson driver assignment, verification, rollback, and plain-language error details.
- Add regression coverage for activation/storage failures, listener startup resilience, and native printer configuration.
- Complete an installed Windows test with the Epson driver and a live `127.0.0.1:9100` listener.

**Complete when:** Valid Enterprise keys no longer produce HTTP 500, malformed keys fail safely, the Printer Setup Wizard creates the Windows queue without `Invalid parameter`, the wizard sends its Test Receipt, and all automated and packaging checks pass.

### v0.3.24 — Upgrade licensing and Printer Setup safeguards

**Status:** Released 2026-07-19

**Purpose:** Correct the v0.3.23 licensing regression and prevent Printer Setup from assigning one TCP/IP port number to multiple Windows printers.

**Planned release scope:**

- Preserve the existing registration and activation files as a matched pair before an upgrade changes the service or application files.
- Refresh and restore the preserved pair as one upgrade generation, then retain it until the updated service reports the expected paid license mode.
- Fall back to direct file overwrite when the hardened Windows data directory permits updating an existing license file but denies temporary-file replacement.
- Keep a failed two-file activation save from leaving an old key paired with new customer registration values.
- Retry persisted license loading after a temporary startup read failure.
- Provide a privacy-safe Activation Diagnostics download on the License page for Trial users without unlocking the paid Support section.
- Add automated persistence, restart, and startup-recovery regression tests.
- Scan Windows printer-to-port assignments and select the first available port beginning at 9100.
- Display an automatically adjusted port in the configuration summary before administrator approval.
- For Enterprise, create or reuse the matching emulator listener before adding a Windows queue on an automatically adjusted port; explain the Enterprise requirement before installing an additional Trial or Pro queue.
- Recheck the selected port before, during, and immediately after queue creation, rolling back safely if another printer claims it.
- Preserve idempotent reinstall of the same printer name and configuration.

**Completion verification:** All 105 automated tests pass. An installed Enterprise v0.3.23 system upgraded and completed a v0.3.24 maintenance reinstall without reactivation or re-entering registration. Trial-safe Activation Diagnostics passed authorization and privacy checks. With an existing Windows queue on 9100, the installed service selected 9101, created the matching Enterprise listener and an `EPSON TM-T88V Receipt5` queue, received its 112-byte ESC/POS test job, and then selected 9102 for the next printer. Setup retains conflict checks before, during, and after queue creation and rolls back incomplete state.

### v0.3.25 — Four-tier licensing and upgrade paths

**Status:** Released — 2026-07-19

**Purpose:** Introduce an affordable single-printer Lite License while giving Pro customers a two-printer workflow and reserving larger multi-printer environments for Enterprise, without removing the paid features current customers already use.

**Released scope:**

- Model **Trial**, **Lite**, **Pro**, and **Enterprise** consistently in activation keys, license validation, desktop status and feature gates, vendor tools, telemetry, purchasing, administration, documentation, and release reporting.
- Keep every new installation in Trial by default with five completed jobs per day, session-only Activity, the TRIAL watermark, locked paid features, and one total listener.
- Add a **Lite License** at a published one-time price of **$24.99**, with unlimited jobs, persistent local history, no watermark, exports, printer profiles, Stored Logos, Printer State, updates, support, capture/import/replay, and one total listener.
- Keep the **Pro License** entitled to the same paid features as Lite and add listener management for **two total listeners**. Pro and Enterprise prices remain server-controlled and are displayed on the Buy page.
- Keep the **Enterprise License** entitled to all paid features and the full multi-listener workflow for **up to 15 total listeners**, including per-listener ports, profiles, state, buffers, counters, lifecycle controls, and Activity filtering.
- Enforce total-listener allowances as **Trial 1 / Lite 1 / Pro 2 / Enterprise 15** in the local API, listener runtime, and Settings UI instead of relying only on hidden or disabled controls.
- Preserve valid existing Pro and Enterprise activation keys through the upgrade, and avoid deleting saved listener definitions when a lower active allowance temporarily prevents all of them from running.
- Show the active tier, allowance, usage, and upgrade path in plain language; activation must unlock the purchased tier immediately without reinstalling.
- Update the public pricing comparison and owner-facing pricing/license tools without hard-coding Pro or Enterprise amounts into the application or repository.
- Add unit, authorization, persistence, migration, listener-limit, downgrade/upgrade, telemetry, purchase, and rendered-interface regression coverage.

**Complete when:** Trial, Lite, Pro, and Enterprise keys validate as their exact tiers; all paid features work for every paid tier; one, one, two, and fifteen total listeners are enforced respectively after activation and restart; existing Pro and Enterprise customers retain their license and saved data; Lite is offered for $24.99; Pro and Enterprise amounts come from the Buy service; and automated plus installed-upgrade tests pass before the release is marked public.

**Completion verification:** All 113 desktop tests and all three PHP commerce contract suites pass. A real Lite key activated the local service and unlocked the paid feature set with a one-listener allowance. Trial, Lite, Pro, and Enterprise Settings plus desktop/mobile pricing rendered with the expected 1/1/2/15 allowances and no browser-console failures. The all-in-one v0.3.25 installer passed release packaging checks, and an installed v0.3.24 Enterprise system upgraded to v0.3.25 with the service running, the listener active, and the same customer, email, license ID, and Enterprise status intact.

### v0.3.26 — Annual Application Maintenance and Support

**Status:** Released — 2026-07-20

**Purpose:** Keep Lite, Pro, and Enterprise licenses permanent while providing a clear, optional way to fund ongoing application updates, upgrades, and assisted technical support.

**Released scope:**

- Keep every paid software license as a **one-time purchase**. POS Printer Emulator is not converted into a subscription, and the purchased application plus all features already unlocked continue working permanently.
- Include **one year of Application Maintenance and Support** with every new Lite, Pro, or Enterprise purchase, measured from the license purchase date.
- Cover application updates and upgrades, assisted technical support, and access to **Settings → Check for Updates** while maintenance is active.
- After coverage expires, keep the installed application, local receipt history, listener allowance, and every existing licensed feature working. Disable assisted support and update checking/downloads until maintenance is renewed.
- Keep local troubleshooting information, health checks, activation diagnostics, and privacy-safe log export available even when maintenance is expired so customers are never blocked from diagnosing activation or local operation.
- Offer optional, non-recurring, one-time annual renewals at **Lite $9.99**, **Pro $19.99**, and **Enterprise $59.99**. Do not create automatic billing or a PayPal subscription agreement.
- Add 12 months to the existing maintenance expiration date when a customer renews early. When coverage has already expired, begin the renewed 12-month period on the confirmed payment date.
- Restore update and assisted-support access immediately after a successful renewal. A renewed customer can obtain the current eligible release and every new release published during the renewed coverage period without replacing the permanent license.
- Grandfather every paid license issued before this release with maintenance through **2027-07-19**, preventing an existing customer from unexpectedly losing update or support access.
- Show active, expiring, expired, and renewed maintenance status plus the coverage end date in the application, purchase flow, renewal flow, Admin Portal, license records, and customer-facing documentation.
- Preserve offline receipt emulation when the entitlement service is temporarily unavailable, while using signed or cached maintenance proof to protect update downloads and assisted-support access.
- Add authorization, date-boundary, renewal, grandfathering, upgrade, downgrade, payment-verification, and installed-upgrade regression coverage.

**Complete when:** New paid purchases receive exactly 12 months of maintenance; existing paid licenses receive coverage through 2027-07-19; permanent licensed functionality remains usable after expiration; update checking/downloads and assisted support follow the maintenance state; local diagnostics remain available; early and lapsed renewals calculate their dates correctly; renewal payments are one-time transactions; and automated plus installed-upgrade tests pass before the release is marked public.

**Completion verification:** All 138 desktop tests, all PHP commerce/database/site contracts, 39-file PHP lint, TypeScript and JavaScript validation, 16-page SEO validation, release-manifest synchronization, bundled SQLite verification, and repository diff checks pass. Browser QA rendered the policy page and renewal checkout at desktop/mobile sizes, switched all three renewal tiers at $9.99/$19.99/$59.99, and showed no console errors. Isolated live-app QA confirmed that an expired Lite license retains its paid features, update checks return HTTP 403, diagnostics return HTTP 200 without an activation key, and a renewed Lite license immediately restores update access. The all-in-one v0.3.26 installer and SHA-256 checksum passed packaging checks. An installed v0.3.25 Enterprise system upgraded to v0.3.26 with installer exit code 0 while preserving its paid tier, customer registration, license ID, and listener allowance; maintenance loaded as Active with the grandfathered expiration present.

### v0.3.33 — Enhanced support package and connection diagnostics

**Status:** Released — 2026-07-21

**GitHub:** [Issue #20 — Enhanced support package and connection diagnostics](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/20)

**Purpose:** Help nontechnical customers identify common installation, Windows-printer, listener, and network problems without opening Windows administration tools, then create a privacy-reviewed package that support staff can use immediately.

**Planned scope:**

- Replace the single diagnostic-log download with a guided **Connection Diagnostics** workflow under Settings → Support.
- Check the Windows service, local viewer, database and storage access, configured listeners, bind addresses, port conflicts, listener health, recent connection activity, and print-job processing errors.
- Verify the Windows Print Spooler, installed printer queues, Standard TCP/IP port mappings, assigned Epson driver, Epson APD and Status API versions, and availability of the bundled repair package.
- Verify the POS Printer Emulator private/domain firewall rule and explain when the selected Windows network profile or bind address prevents another POS computer from connecting.
- Run a time-bounded local listener health probe without consuming a Trial print job or changing customer receipt history.
- Provide the exact configured listener IP address and port for the customer to enter in their POS software, without attempting to test or infer the behavior of an unknown POS implementation.
- Show every check as **Passed**, **Attention needed**, **Failed**, or **Skipped**, with a plain-language explanation and expandable technical details.
- Offer only reviewed corrective actions: restart the service or listener, retry a failed listener, recreate the application firewall rule, open the Printer Setup Wizard for queue or driver repair, and copy the correct POS connection details. Administrator approval is requested only when a selected repair requires it.
- Create a standard ZIP support package containing a text summary, machine-readable manifest, redacted application logs, application and Windows versions, listener/profile configuration summaries, diagnostic results, recent error metadata, printer/driver/firewall summaries, and a stable package identifier.
- Preview every file and data category before export. Exclude receipt text, raw receipt bytes, imported captures, activation keys, maintenance keys, payment information, email addresses, Windows user names, and full local file paths by default.
- Require explicit, separate consent before including registration details or selected receipt/capture evidence. Never include activation or maintenance keys.
- Add **Copy Support Summary**, **Save Support Package**, and **Submit a Support Request** actions. Package creation and local diagnostics remain available to Trial users and customers whose maintenance has expired; assisted-support service levels continue to follow the maintenance entitlement.
- Build the in-app **Submit a Support Request** workflow for Bug Report, Feature Request, License Issue, and Other Issue.
- Collect the subject, detailed description, optional reproduction steps, expected and actual behavior, and contact information; automatically add the application version, license tier, Windows version, and a redacted listener summary when available.
- Allow optional screenshots and attachments with file-type, size, quantity, and malware-safe storage limits. Never execute uploaded content or accept paths outside the files explicitly selected by the customer.
- For bug reports, offer diagnostic logs as an optional attachment. Show a preview of every file and data category, explain the redactions, and require explicit consent immediately before submission.
- Remove or mask activation and maintenance keys, credentials, receipt contents and raw bytes, email addresses outside the private contact field, Windows user names, full paths, and IP addresses before any data leaves the computer.
- Submit requests through an authenticated, rate-limited HTTPS backend that validates and redacts the request again, stores contact information privately, and creates the GitHub issue with the matching issue template and label. No GitHub credential is distributed with the desktop application.
- Keep personal contact information and private attachments out of public GitHub issue bodies. The public issue contains only reviewed, redacted technical content and a backend-generated support reference.
- Display confirmation, the support reference or GitHub issue number, a public issue link when available, and a **Copy reference** action.
- Save failed or offline submissions locally as protected drafts, allow retry without data loss, and let the user review or delete the draft before retrying.
- Keep the workflow offline-first. Do not upload a package automatically or transmit diagnostic data without a separate future feature and explicit customer consent.
- Add deterministic redaction, backend authorization, rate-limit, attachment-validation, consent, offline-draft, retry, corrupt-data, cancellation, timeout, all-license-tier, expired-maintenance, and installed-Windows diagnostic tests.

**Complete when:** A customer can run the diagnostic workflow without administrator tools, identify a deliberately broken service, listener, port, firewall, queue, or Epson-driver configuration with a useful corrective action, safely repair supported problems, and export a reviewed package that contains no receipt contents or secret licensing data unless the customer explicitly adds permitted evidence. A consented support request creates the correctly labeled GitHub issue through the backend without exposing GitHub credentials, private contact data, receipt content, IP addresses, or secrets; offline drafts survive restart and retry successfully. Trial and expired-maintenance installations retain local diagnostics and package export, while assisted-support service levels continue to follow the maintenance entitlement.

**Completion verification:** All 147 desktop tests, the production viewer build, desktop packaging build, PHP commerce/database/site contracts, PHP syntax checks, rendered Support UI checks, diagnostic-package preview, and ZIP download verification passed. The Trial tier retained local diagnostics and package export while assisted submission remained disabled without active maintenance. The self-contained v0.3.33 Windows installer and SHA-256 checksum were generated successfully.

### v0.3.34 — Encrypted backup, EULA, and support policy

**Status:** Released — 2026-07-21

**Purpose:** Give customers a safe, portable configuration recovery path while presenting the same product-use, licensing, compatibility, privacy, support, and liability terms before installation and purchase.

**Released scope:**

- Add **Settings → Backup & Restore** for Trial, Lite, Pro, and Enterprise installations.
- Create a portable `.ppebackup` package protected by a customer-supplied password using authenticated AES-256-GCM encryption and PBKDF2-SHA256 key derivation.
- Include printer listeners, custom printer profiles, the selected profile, stored logos, simulated printer states, and interface preferences.
- Allow paid licenses to include receipt history optionally; Trial backups remain configuration-only.
- Exclude activation keys, maintenance keys, registration details, credentials, logs, Windows printer queues, and Epson driver files.
- Inspect and validate a backup before making changes, then show its creation time, source version, included categories, item counts, exclusions, and compatibility warnings.
- Reject malformed, oversized, tampered, or incorrectly password-protected packages without changing the installation.
- Validate duplicate listener identities, names, and ports plus tier limits, profile limits, logo limits, and history limits before restore.
- Preserve listener definitions above the current license allowance while running only the number permitted by the active tier.
- Create a machine-protected safety snapshot before restore, keep the five newest safety snapshots, and roll back the running configuration if restoration fails.
- Restore the saved light/dark theme and collapsed-panel preferences immediately after a successful restore.
- Add an End User License Agreement covering Trial, Lite, Pro, and Enterprise editions.
- Require affirmative acceptance in the Windows installer before installation can continue.
- Publish the same agreement at the canonical `/eula` website route and link it from the homepage and sitemap.
- Identify EPCOM Ltd. as the Licensor and installer publisher and apply Georgia governing law and jurisdiction, subject to mandatory rights.
- Preserve Apache License 2.0 and other open-source rights for their covered components.
- Limit standard support to POS Printer Emulator and exclude third-party POS products, vendor-specific integration, and legacy systems.
- Require a separately approved quotation, order, or statement of work before any offered custom POS integration or development begins.
- Define fully updated 64-bit Windows 11 Pro as the only supported operating-system environment.
- State that active-maintenance requests may take up to six calendar months for an initial substantive response unless a separately signed SLA states otherwise, and that a response is not a promised diagnosis, correction, workaround, or resolution.

**Security and privacy:** Backup passwords and decrypted payloads are never written to application logs. Automatic safety snapshots are protected with Windows machine data protection in the restricted application-data directory. A 128 MB package limit and strict schema and count validation constrain untrusted imports.

**Completion verification:** All 151 automated desktop tests pass, including encrypted round-trip, incorrect-password, and tamper-detection coverage. A live local API exercise successfully created, inspected, and restored an encrypted package and returned a safety-snapshot reference. The release build and rendered desktop/mobile create-review-restore flow passed with no browser console errors. Website and installer terms match, installation requires acceptance, Windows and support-policy content is consistent, and automated release and SEO checks pass.

**Complete when:** A customer can create a password-protected backup, review it before restoring, recover supported configuration without transferring a license, and return automatically to the original configuration if restore fails; the website and installer present matching terms, installation requires acceptance, customer-facing support and Windows requirements are consistent, and the versioned installer plus checksum are published.

### v0.3.35 — Backup restore usability and compatibility

**Status:** Released — 2026-07-22

**Purpose:** Remove the confusing Windows ZIP behavior from encrypted backups and make restoration understandable without leaving the application.

**Released scope:**

- Use a dedicated Windows save filter and default extension so new backups retain the `.ppebackup` suffix.
- Accept both `.ppebackup` and legacy `.ppebackup.zip` names created by version 0.3.34 without asking customers to extract the encrypted package.
- Add an accessible question-mark tooltip beside **Restore from backup** with the complete choose, password, review, confirm, and restore sequence.
- Add a responsive illustrated website guide with screenshots for choosing a file, reviewing contents, and confirming a successful restore.
- Preserve all authenticated encryption, package-size limits, validation, safety-snapshot, rollback, tier-limit, and secret-exclusion protections from v0.3.34.

**Security and privacy:** Legacy filename compatibility does not bypass package authentication or schema validation. The application still decrypts only after password entry, never logs backup passwords or decrypted payloads, and never imports activation or maintenance credentials.

**Completion verification:** All 158 automated desktop tests pass, including valid and invalid backup filename cases. The complete create, choose, inspect, confirm, and restore workflow passed in desktop and narrow-window rendered QA with no browser console errors. The release build completed with zero warnings and errors, 18 canonical website pages passed SEO validation, and the live guide plus all four screenshot assets returned HTTP 200.

**Complete when:** Newly created backups keep the `.ppebackup` extension, existing `.ppebackup.zip` files restore without extraction, in-app guidance is available by pointer and keyboard, the illustrated guide is public, and the versioned installer plus checksum are published.

### v0.3.36 — Privacy-preserving geographic analytics dashboard

**Status:** Released — 2026-07-22

**Purpose:** Show approximate regional adoption while minimizing location data.

**Released scope:** Coarse country and U.S. state derivation for downloads and application telemetry; aggregate download starts; private world and U.S. maps; exact regional tables; date, metric, license, and version filters; local map assets; keyboard access; and matching privacy/EULA disclosures. Public IP addresses are processed transiently for lookup and are not stored in the product-analytics database.

**Complete when:** The dashboard reports download starts, installations, launches, and print jobs by approximate region, raw IP addresses are absent from analytics storage, all filters and accessible table/map controls work, and automated contract checks pass.

### v0.3.37 — Trial Setup and Onboarding Improvements

**Status:** Released — 2026-07-22

**Purpose:** Let a nontechnical Trial customer install, open, test, and connect the emulator without first understanding listeners, TCP/IP ports, or Windows printer configuration.

**Released scope:**

- Show a first-launch welcome screen with **Set Up Trial Printer**, **Print a Test Receipt**, listener status, and **Troubleshoot Connection**.
- Dynamically simplify the existing Printer Setup Wizard and label it **Trial Configuration Wizard** for Trial customers.
- Start one enabled default listener, reuse `127.0.0.1:9100` for same-computer setup, and safely move the single listener to the first free sequential port after a confirmed conflict.
- Make built-in Test Receipts unlimited, clearly labeled, session-only, excluded from paid history, and excluded from product usage reporting.
- Count only complete external POS jobs toward the five-job local-day Trial allowance.
- Continue accepting later external connections while retaining only the first ten rendered lines and an upgrade notice; irreversibly discard hidden bytes, parsed commands, and remaining receipt content.
- Keep local connection diagnostics available with reviewed restart, firewall, wizard, and repair actions.

**Security and privacy:** Over-limit content is redacted at ingestion, not merely hidden in the interface. Activating a paid license later cannot recover the discarded receipt bytes or lines. Test Receipts are not persisted or counted in telemetry.

**Complete when:** A fresh Trial installation reaches a visible Test Receipt in one click, configures its single Windows printer through the shared wizard, recovers from a port-9100 conflict with confirmation, processes five complete external jobs, accepts a sixth without a POS-side failure, and proves that content after line ten is unavailable from every API, view, export, history, or diagnostic path.

### v0.3.38 — Trial Onboarding Clarity Correction

**Status:** Released — 2026-07-22

**Purpose:** Correct the v0.3.37 onboarding gap so every Trial customer can find the setup instructions again and see exactly where their POS must send print jobs.

**Released scope:**

- Give the welcome experience a new versioned completion state so existing v0.3.37 Trial installations see the corrected guide once after updating.
- Add a permanent **Trial setup** action in the application header so the guide is never lost after dismissal.
- Present two explicit steps: run the Printer Setup Wizard, then configure the POS to send RAW TCP/ESC-POS data to the included listener.
- Show the included Trial listener under **Settings → Printer Listeners** with its name, status, profile, local endpoint, LAN IPv4 endpoint, port, and copyable instructions.
- Remove all create, edit, start, stop, restart, and delete controls for Trial while retaining server-side HTTP 403 enforcement for listener changes.
- Explain that a same-computer POS uses `127.0.0.1:<port>` and a remote POS uses the emulator computer's displayed LAN IPv4 address and the same port.

**Security and privacy:** Listener details remain local. The UI exposes only private-network connection information already needed to configure the customer's POS, and Trial listener mutation remains denied by the server rather than relying on hidden controls alone.

**Completion verification:** The production C# packager rebuilt the viewer, service, desktop shell, license tools, self-contained Windows runtime, and installer with zero build errors. All 166 desktop tests and Admin/database commerce tests passed. Live Trial API verification returned one included listener with a LAN IPv4 address and rejected a listener mutation with HTTP 403. The installer checksum was generated successfully; rendered Codex browser automation remained unavailable because its browser kernel could not create the required local assets.

**Complete when:** A fresh or upgraded Trial installation sees the corrected guide, can reopen it, can run the wizard from Step 1, sees one read-only listener in Step 2, can copy exact connection details, and cannot alter listener configuration through either the interface or API.

### v0.3.39 — Guided update installation and restart

**Status:** Released — 2026-07-22

**GitHub:** [v0.3.39 release](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.39)

**Purpose:** Make application updates reliable and understandable by closing the running application cleanly before the installer replaces its files, then returning the customer to the updated application.

**Released scope:**

- Download an available installer in the background while the application remains usable.
- Verify the completed download against the separately published release SHA-256 checksum before offering installation.
- Create an automatic pre-update configuration safety snapshot using the v0.3.34 backup foundation.
- Replace the current update action with a clear **Install and Restart** confirmation that explains the listener will be briefly unavailable.
- Provide a clear **Install now** confirmation and a safe defer choice without closing the application unexpectedly.
- Detect an active incoming print job, finish or preserve it safely, and stop accepting new jobs before shutdown.
- Save the selected receipt and workspace view state while preserving registration, activation, settings, profiles, stored logos, and local history through the existing upgrade-safe installer paths.
- Stop the listener and background service cleanly, launch a separate updater process, and exit every application process that could lock installed files.
- Wait for file locks to clear, run the installer with minimal prompts, preserve existing registration data, and relaunch POS Printer Emulator automatically.
- Show the installed version and a success confirmation after restart.
- Keep the existing installation available when preparation or installation fails and show plain-language recovery instructions after restart.

**Why this priority:** The external updater and controlled shutdown eliminate the remaining class of self-update file-lock failures while preventing unexpected listener downtime or loss of customer state.

**Completion verification:** The production viewer, service, desktop shell, update security library, external updater, license utilities, and C# build utility compile with zero warnings or errors. All 171 desktop tests, Admin/database commerce tests, PHP syntax validation, and 27-page SEO validation pass. The updater tests reject untrusted download hosts, missing checksums, and checksum mismatches, and verify listener stop/resume behavior. The 120,593,838-byte installer includes `POSPrinterEmulator.Updater.exe`; its independently recalculated SHA-256 value matches the generated checksum file (`34b1e74eb09909e7b9c58e9bd6d9ba7595497b6f596d841de13ee5433005ed63`).

**Complete when:** From Settings, a customer can download an update, confirm installation, see the listener drain and stop cleanly, complete installation without a self-lock, relaunch automatically on the new version, and retain registration, licensing, settings, stored data, and the previously selected receipt; cancel and preparation-failure paths leave the current installation usable.

### v0.3.40 — Simple Mode and Expert Mode

**Status:** Planned

**GitHub:** [Issue #30 — Simple Mode and Expert Mode](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/30)

**Purpose:** Give new customers a task-focused experience without removing the complete three-panel workspace used by experienced customers.

**Planned scope:**

- Add a persistent Simple Mode with clear task cards for printer setup, connection details, Test Receipt, latest receipt, capture import, diagnostics, and support.
- Keep the current Activity, Receipt Preview, and Inspector layout as Expert Mode.
- Add an always-available mode switch and remember the choice per Windows user.
- Show one plain-language health statement and the next recommended action instead of exposing implementation details first.
- Preserve the selected receipt, filters, panels, theme, and listener context when switching modes.
- Keep every license boundary enforced by the service API; Simple Mode must not unlock or conceal paid-only behavior incorrectly.

**License availability:** All license tiers.

**Security and privacy:** Do not expose receipt contents, activation material, or private endpoints beyond information already authorized for the current tier.

**Why this order:** It addresses the customer confusion already observed during onboarding while leaving the expert workflow intact.

**Complete when:** A new customer can set up, connect, test, review the latest receipt, and diagnose a problem from Simple Mode, then switch to Expert Mode and find the same state and data.

### v0.3.41 — Accessibility and keyboard usability

**Status:** Planned

**GitHub:** [Issue #31 — Accessibility and keyboard usability](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/31)

**Purpose:** Make every primary workflow usable with a keyboard, Windows assistive technology, display scaling, and high-contrast preferences.

**Planned scope:**

- Define a complete focus order, visible focus treatment, semantic landmarks, accessible names, and screen-reader status announcements.
- Add documented shortcuts for Test Receipt, Settings, search, job navigation, panel controls, help, and the mode switch.
- Support Windows text scaling, 200 percent display scaling, high contrast, reduced motion, and application zoom without clipped controls.
- Meet WCAG 2.2 AA contrast, focus, target-size, error, and help expectations for the WebView interface.
- Add automated accessibility checks plus keyboard-only, Narrator, scaling, and high-contrast acceptance tests.
- Caption every instructional video and provide equivalent text instructions.

**License availability:** All license tiers.

**Security and privacy:** Accessible names and announcements must never reveal hidden activation keys, masked receipt values, credentials, or other tier-restricted information.

**Why this order:** Accessibility improves usability for every customer and is less expensive to establish before more screens and controls are added.

**Complete when:** Primary setup, receipt, listener, export, update, backup, and support workflows pass keyboard-only, Narrator, 200 percent scaling, high-contrast, and automated accessibility verification.

### v0.3.42 — Automatic configuration restore points

**Status:** Planned

**GitHub:** [Issue #32 — Automatic configuration restore points](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/32)

**Purpose:** Protect customers from accidental configuration loss without requiring them to remember to create a manual backup.

**Planned scope:**

- Create encrypted restore points before listener, profile, stored-logo, configuration import, restore, repair, and update changes.
- Add optional scheduled local restore points with retention limits by count, age, and total storage.
- Show the creation time, reason, application version, included sections, and integrity state before restoration.
- Restore transactionally, create a safety point first, and roll back automatically when validation or restart fails.
- Provide clear storage usage and cleanup controls without deleting the only known-good restore point.
- Reuse the established encrypted backup format while keeping automatic files in the protected application-data directory.

**License availability:** Lite, Pro, and Enterprise. Trial receives automatic safety points for included setup changes without scheduled retention controls.

**Security and privacy:** Encrypt restore points, restrict Windows ACLs, redact secrets from metadata, never upload automatically, and exclude activation keys or receipt payloads unless a separately reviewed protected format explicitly supports them.

**Why this order:** It reduces the recovery risk before projects and additional privacy or encoding configuration make customer state more complex.

**Complete when:** A customer can recover the previous working configuration after a failed or accidental change with no partial state, secret exposure, or paid-license loss.

### v0.3.43 — Projects and testing sessions

**Status:** Planned

**GitHub:** [Issue #33 — Projects and testing sessions](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/33)

**Purpose:** Organize receipts and configuration by customer, store, POS migration, register, or support engagement.

**Planned scope:**

- Create named projects containing sessions, notes, tags, listener references, profiles, captures, validation baselines, and reports.
- Add a default project so existing installations migrate without requiring customer action.
- Provide project selection, recent projects, archive, duplicate, safe move or copy, export, and import workflows.
- Preserve project filters and the last-open session across restarts without changing active listener routing unexpectedly.
- Validate imported project schemas, integrity checksums, item limits, and safe paths before committing any data.
- Expose stable project identifiers and data boundaries that the later receipt-comparison release can reuse while keeping shared built-in profiles and stored logos understandable.

**License availability:** Pro and Enterprise.

**Security and privacy:** Projects remain local by default; exported packages require explicit content review and must not contain activation keys, credentials, unrelated receipts, or data from another project.

**Why this order:** The restore-point foundation in v0.3.42 makes project-level organization safer, and projects establish clean data boundaries for the later receipt-comparison release.

**Complete when:** A consultant can keep two customer projects isolated, switch between them safely, and export one project without leaking data or configuration from the other.

### v0.3.44 — Privacy-safe receipt masking

**Status:** Planned

**GitHub:** [Issue #34 — Privacy-safe receipt masking](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/34)

**Purpose:** Let customers demonstrate, screenshot, export, and share receipts without unnecessarily exposing sensitive customer information.

**Planned scope:**

- Add a reversible display-only Privacy View with built-in and customer-defined masking rules.
- Detect and mask likely names, email addresses, phone numbers, payment fragments, loyalty identifiers, transaction identifiers, IP addresses, and selected text patterns.
- Apply masking to screenshots, privacy-safe exports, comparison reports, and support attachments only when explicitly selected.
- Preserve the original authorized raw receipt locally and clearly distinguish masked output from original data.
- Preview every masked export, identify unmatched high-risk patterns, and let the customer cancel before saving or submitting.
- Add adversarial tests for split fields, alternate separators, encoded text, images, QR codes, barcodes, and rule bypasses.

**License availability:** Pro and Enterprise. Trial retains its existing irreversible post-allowance redaction behavior.

**Security and privacy:** Never claim perfect automatic detection, default to safer masking, prevent raw values from entering a masked artifact, and keep masking rules local unless the customer deliberately exports them.

**Why this order:** Project, support, and receipt exports increase the likelihood that receipt artifacts will be shared, so privacy controls should be established before later comparison reports.

**Complete when:** A privacy-safe screenshot, report, or support attachment contains none of the configured sensitive values while the authorized original receipt remains unchanged and access-controlled.

### v0.3.45 — System tray health and notifications

**Status:** Planned

**GitHub:** [Issue #35 — System tray health and notifications](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/35)

**Purpose:** Keep customers informed about important listener events without requiring the main application window to remain open.

**Planned scope:**

- Add a system-tray icon with healthy, warning, stopped, and faulted states.
- Provide quick actions for Open, Test Receipt, listener status, Diagnostics, and Exit while preserving the independently running Windows service.
- Add configurable local notifications for listener faults, port conflicts, rejected jobs, Trial limits, maintenance status, and available updates.
- Deduplicate repeated alerts, rate-limit noisy failures, expire stale notifications, and clear resolved conditions.
- Honor Windows Focus Assist and provide separate preferences for critical, warning, and informational events.
- Explain clearly whether closing the window hides the interface or stops any component.

**License availability:** All license tiers, with actions limited to each tier's existing permissions.

**Security and privacy:** Lock-screen notifications must not display receipt contents, registration data, activation keys, email addresses, credentials, or full private-network details.

**Why this order:** After core workflows and privacy controls are established, background awareness reduces missed faults and unnecessary support requests.

**Complete when:** A background listener fault produces one actionable privacy-safe notification, the tray shows the correct state, and both clear automatically after verified recovery.

### v0.3.46 — Character and code-page assistant

**Status:** Planned

**GitHub:** [Issue #36 — Character and code-page assistant](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/36)

**Purpose:** Help customers correct garbled symbols, accents, currency marks, and multilingual receipt text without manually researching Epson code-page tables.

**Planned scope:**

- Detect probable character-encoding mismatches and identify the affected byte ranges and ESC/POS code-page commands.
- Show side-by-side previews using compatible Epson and generic profile code pages.
- Explain when a POS changes encoding mid-job and distinguish an unsupported glyph from a wrong code page.
- Recommend the most likely profile or code-page correction and require an explicit preview before saving it.
- Add international golden fixtures covering common Western, Central European, Cyrillic, Greek, Turkish, Hebrew, Arabic, and symbol cases supported by the selected profile.
- Preserve original capture bytes and record assistant decisions as separate profile configuration.

**License availability:** Pro and Enterprise.

**Security and privacy:** Treat decoded receipt text as local customer data and exclude it from telemetry and diagnostics unless the customer explicitly consents to a reviewed, masked attachment.

**Why this order:** The profile, privacy, and project foundations make encoding recommendations safe to review, while deterministic encoding fixtures prepare reliable inputs for the later comparison release.

**Complete when:** Known mojibake fixtures produce the correct diagnosis and preview, saved recommendations render deterministically, and original capture bytes remain unchanged.

### v0.3.47 — Offline Enterprise update packages

**Status:** Planned

**GitHub:** [Issue #37 — Offline Enterprise update packages](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/37)

**Purpose:** Support secure application updates on restricted or air-gapped POS networks.

**Planned scope:**

- Publish a portable update package containing the installer, versioned manifest, architecture, checksums, trusted publisher signature, and release metadata.
- Import from approved removable media and verify every artifact before offering installation.
- Reject tampered, unsigned, downgraded, wrong-product, wrong-architecture, expired, and unsupported packages without changing the installed application.
- Reuse the v0.3.39 Install and Restart safety snapshot, active-job drain, controlled shutdown, rollback, relaunch, and confirmation flow.
- Provide a documented offline maintenance-entitlement refresh workflow without changing permanent-license ownership or creating a subscription requirement.
- Record privacy-safe audit evidence for package verification and installation results.

**License availability:** Enterprise.

**Security and privacy:** Require trusted signatures and hashes, prevent path traversal and package substitution, use least privilege, and never execute content until the complete package is verified.

**Why this order:** It depends on the guided updater, production signing, rollback, and entitlement foundations and therefore belongs after the earlier customer-experience work.

**Complete when:** An offline Enterprise computer installs a valid package and rejects tampered, unsigned, downgraded, incompatible, or unentitled packages without damaging the current installation.

### v0.3.48 — Receipt comparison and automated validation

**Status:** Planned

**GitHub:** [Issue #21 — Receipt comparison and automated validation](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/21)

**Purpose:** Turn the emulator into a repeatable compatibility-testing tool for POS changes, printer migrations, and regression testing.

**Planned scope:**

- Select any two jobs or compare a job against a named saved baseline.
- Compare raw bytes, normalized parsed commands, extracted text, warnings, and rendered receipt output.
- Highlight additions, removals, changed commands, layout changes, and image differences.
- Allow configurable comparison rules for values such as dates, times, check numbers, and transaction identifiers.
- Save validation suites made from capture files, projects, and printer profiles.
- Run a validation suite locally and produce clear pass, warning, or fail results.
- Export human-readable HTML/PDF results and machine-readable JSON results with the established privacy-masking controls.
- Add deterministic golden-output tests for the supported renderer and code-page behavior.
- Brand the installer welcome, completion, and header areas with the official product artwork while retaining the product icon on Setup, shortcuts, and uninstall entries.

**Why this order:** Projects, privacy masking, encoding diagnostics, and update recovery will already be established, giving comparison suites safer organization, exports, international fixtures, and rollback behavior.

**Complete when:** A known-good capture passes its baseline, an intentional command or layout change fails with a precise difference, ignored dynamic fields do not cause false failures, privacy-safe exports do not expose configured sensitive values, and the compiled installer consistently displays the official product branding at normal and high-DPI scaling.

### v0.3.49 — Update Notifications for All License Types

**Status:** Planned

**GitHub:** [Issue #40 — Update Notifications for All License Types](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/40)

**Purpose:** Ensure Trial, Lite, Pro, and Enterprise customers can see when a newer public desktop release exists, even when paid maintenance has expired, without weakening the rules that control in-app installation.

**Planned scope:**

- Separate the public release-notification check from the maintenance-gated installer-download workflow.
- Show the currently installed version, latest eligible public desktop version, number of releases behind, a short release summary, and a clear update-available indicator.
- Count eligible published desktop releases between the installed and current versions rather than treating semantic-version subtraction as a release count.
- Give Trial users a **Download Update** action that opens the official POS Printer Emulator download page for manual installation.
- Let Lite, Pro, and Enterprise customers with active maintenance continue into the guided in-app updater.
- Continue notifying paid customers whose maintenance has expired, while showing release details and a renewal option instead of allowing maintenance-gated installation.
- Cache the latest successful public check, handle offline startup quietly, rate-limit periodic requests, and keep receipt listening and processing non-blocking.
- Add accessible light- and dark-mode update indicators and screen-reader announcements.

**License and maintenance rules:** Trial receives notifications and an official manual-download link. Paid licenses with active maintenance receive notifications and the guided updater. Paid licenses with expired maintenance receive notifications, release details, and renewal guidance while their purchased version and existing features remain usable.

**Security and privacy:** Query only public release metadata over HTTPS, accept actions only for official website or trusted GitHub destinations, send no activation keys, receipt data, customer information, or printer configuration, and retain checksum verification for every in-app installer.

**Why this order:** Update awareness is useful to every customer, but it can build on the v0.3.39 guided-updater trust and restart foundation without destabilizing the already scheduled customer-experience releases.

**Complete when:** Every license tier receives accurate, non-blocking new-version notifications; the UI shows both versions, versions behind, and a concise summary; Trial opens the official download page; active-maintenance paid users can install in-app; expired-maintenance paid users cannot bypass renewal; and automated tests cover all license states, version counting, offline caching, malformed release data, and trusted-link enforcement.

### v0.3.30 — Security remediation (Phase 1)

**Status:** Released — 2026-07-21

**Purpose:** Resolve the actionable security findings from the completed deep review and harden the public Admin Portal, purchase site, and Windows application before adding more externally reachable functionality.

**Planned scope:**

- Rotate every exposed purchase and maintenance bearer token, remove tokens from source, history, backups, and logs, and load replacements from server-side secrets outside the web root.
- Separate purchase-site and maintenance credentials, reject the old credentials, and verify PayPal, activation, renewal, and Admin Portal workflows after rotation.
- Enforce HTTPS/TLS, secure cookies, CSRF protection, authorization checks, input validation, and login/API rate limits across the public website and Admin Portal.
- Encrypt activation keys and customer registration data at rest on Windows; redact credentials, keys, email addresses, and other personal data from logs.
- Enforce Trial/Lite/Pro/Enterprise feature and listener limits at every desktop API boundary, not only in the UI, and review local-service elevation and file permissions for least privilege.
- Verify update and installer downloads using trusted HTTPS, checksums, and publisher signatures; add dependency, secret-scanning, and package-integrity checks to the release build.
- Add regression tests for authentication, authorization, token rotation, rate limiting, secret redaction, license boundaries, update integrity, and installer permissions.

**Complete when:** The medium finding is remediated and verified, no critical/high findings remain, old exposed credentials are rejected, secrets are absent from tracked files and logs, website and desktop security tests pass, and signed update/install verification succeeds on a clean Windows installation.

### v0.3.31 — Secure development lifecycle (Phase 2)

**Status:** Released — 2026-07-21

**Purpose:** Make security a repeatable release requirement so future features for the website, Admin Portal, and desktop application do not reintroduce the issues addressed in Phase 1.

**Planned scope:**

- Add a security checklist and threat-model note to every feature, bug fix, and release entry.
- Require trust-boundary, data-flow, privilege, authentication, authorization, input-validation, and sensitive-data decisions before implementation.
- Run automated dependency, secret, static-analysis, package-signing, and HTTPS/security-header checks in CI for every merge and release candidate.
- Add API tests for CSRF, injection, rate limiting, object-level authorization, malformed uploads, and safe error responses.
- Add desktop tests for privilege escalation, local API access, encrypted storage, license bypass resistance, update authenticity, installer rollback, and log redaction.
- Keep validated security findings in GitHub Issues and the Admin Portal Dev Support tracker with severity, owner, remediation, and verification evidence.
- Require explicit security sign-off before publication; block releases with unresolved critical or high findings and document accepted lower-risk exceptions.
- Schedule a lightweight review each release and a deep security scan after major architecture, licensing, payment, storage, or networking changes.
- Use the repository [security release checklist](SECURITY_RELEASE_CHECKLIST.md), sign-off template, and automated `.github/workflows/security-baseline.yml` gates for every merge and release candidate.

**Complete when:** The checklist, CI gates, test suites, tracker workflow, and release sign-off are documented and exercised on at least one complete release after v0.3.30, with evidence linked from the release record.

## Future backlog

These items remain unnumbered until the order is approved. The priority below is the recommended implementation order after the scheduled v0.3.37-v0.3.40 releases.

### Release prerequisite — Windows 11 Pro support-policy alignment

**Why required:** The EULA identifies 64-bit Windows 11 Pro as the only supported operating-system environment, but existing public pages, application labels, documentation, structured data, test plans, and installer compatibility settings still contain Windows 10/11 claims. Those contradictions must be removed before the next public release or website publication.

**Proposed scope:**

- Replace customer-facing Windows 10/11 compatibility claims with a precise 64-bit Windows 11 Pro support statement across the homepage, download page, FAQ, SEO pages, application footer, README, documentation, structured data, and support materials.
- Decide and document whether the installer will block unsupported Windows versions or allow installation with an explicit unsupported-environment warning; do not imply that successful installation creates support eligibility.
- Align installer version checks, application diagnostics, support-package metadata, automated tests, and release validation with the approved Windows 11 Pro policy.
- Publish the maintenance-support disclosure consistently: an initial substantive response may take up to six calendar months unless a separately signed SLA states otherwise, and a response does not promise diagnosis, correction, workaround, or resolution.
- Publish the third-party POS limitation consistently: EPCOM Ltd. supports POS Printer Emulator only; POS-specific integration or development is outside standard support, may be unavailable, and requires a separately approved paid scope when offered.
- Add an automated content check that fails a release if obsolete Windows 10 support claims or conflicting response-time promises reappear in public product and support content.
- Test clean installation, upgrade, diagnostics, support-request creation, and uninstall on a fully updated 64-bit Windows 11 Pro machine.

**Complete when:** The EULA, website, application, installer, documentation, structured data, support policy, and automated release checks state one consistent support policy; no active customer-facing Windows 10 support claim remains; and Windows 11 Pro release validation is recorded.

### Priority 1 — Service-to-viewer authentication and installer repair

**Why first:** It closes the most important local security boundary and gives customers a supported recovery path before storage and licensing become more complex.

**Proposed scope:**

- Generate a unique per-installation credential for the desktop viewer and local service.
- Require authentication for state-changing localhost API operations and restrict allowed origins.
- Protect activation, deletion, import, replay, listener configuration, stored-logo, and printer-state operations.
- Add a Repair Installation workflow for the Windows service, firewall rules, WebView2, shortcuts, registration data, and local viewer health.
- Preserve customer settings, activation, imported logos, and receipt history during repair.
- Log repair actions and verify the repaired installation before reporting success.

### Priority 2 — Listener security and lifecycle hardening

**Tracker:** [BACKLOG-007](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/9)

**Why second:** v0.3.21 intentionally exposes configurable RAW TCP listeners to trusted private/domain networks. Connection resource limits and cancellation-safe management should be hardened before larger histories or additional network-facing features increase the service's workload.

**Proposed scope:**

- Add configurable per-listener and installation-wide active-connection limits.
- Limit connections per source, slow or idle clients, aggregate in-flight bytes, and queue memory without rejecting normal receipt traffic.
- Add rate-limited plain-language logs and diagnostics for refused or timed-out connections.
- Make create, update, delete, start, stop, and restart transitions finish or roll back safely when an HTTP request is cancelled.
- Make profile assignment/deletion atomic with listener updates and evaluate reconciled port-specific firewall rules against the current program-scoped rule.
- Add adversarial concurrency, cancellation, slow-client, and memory-pressure tests while preserving independent-listener fault isolation.

### Priority 3 — Advanced SQLite maintenance and retention

**Why third:** The transactional SQLite foundation and safe JSON migration are now part of v0.3.20. Customer-facing maintenance, larger-history controls, and recovery tools should follow after the multiple-listener runtime is hardened.

**Proposed scope:**

- Add paging, fast search, source/listener/profile filters, and reliable aggregate counts for larger histories.
- Add configurable retention by job count, storage size, or age, including fair per-listener limits so one busy printer cannot evict every other printer's history.
- Support individual deletion, Clear All, database health checks, and safe database repair.
- Add backup and restore with schema and integrity validation.
- Add reviewed cleanup of the rollback-safe legacy JSON backup after the customer confirms successful migration.

### Priority 4 — Production code-signing and deployment validation

**Why fourth:** Signed binaries and installers improve customer trust and tamper verification. It should move earlier if a production signing certificate is already available.

**Proposed scope:**

- Sign the desktop executable, service, installer, uninstaller, and other distributed executables with a trusted Windows code-signing certificate.
- Apply trusted timestamps so signatures remain valid after certificate expiration.
- Verify signatures and hashes as part of the build and release process.
- Publish installer checksums with GitHub releases and validate downloaded updates before launch.
- Test clean install, upgrade, repair, silent install, and uninstall on a fully updated supported 64-bit Windows 11 Pro environment.
- Document certificate custody, renewal, and emergency revocation procedures without storing private signing material in the repository.

### Priority 5 — Online license deactivation, revocation, and transfer

**Why fifth:** It improves commercial license control, but requires a highly reliable online service and clear offline behavior. The current signed offline activation remains functional while this is built.

**Admin Portal foundation completed:** Confirmed tier-replacement, Trial-upgrade, deactivation, reactivation, revocation, soft-deletion, purchase-license synchronization, optimistic concurrency, and audit-history controls are available in the protected License Manager. Because v0.3.23 validates signed keys offline, these portal controls intentionally do not claim to erase or remotely disable a key already stored on a customer computer.

**Proposed scope:**

- Let customers deactivate a licensed computer and transfer an available activation to a replacement computer.
- Add owner controls for revoking, restoring, and auditing issued licenses.
- Enforce configurable activation limits and transfer cooldowns.
- Cache signed authorization state locally with a defined offline grace period.
- Avoid disabling valid customers because of temporary network or server failures.
- Record privacy-minimized activation events, replace client-reported legacy paid status with signed entitlement proof, and show actionable license status in the desktop application.

### Priority 6 — PNG export and deterministic PDF generation

**Why sixth:** It is a valuable customer-facing feature, and the comparison release benefits from deterministic rendering first.

**Proposed scope:**

- Export the complete receipt as PNG at predictable thermal-printer dimensions.
- Generate consistent PDFs independent of the desktop window size, zoom level, or selected theme.
- Preserve receipt width, long-page layout, images, barcodes, QR codes, and watermark rules.
- Support individual and selected-job batch export with safe filenames.
- Add export metadata and deterministic-output tests.
- Keep premium export controls aligned with Trial, Lite, Pro, and Enterprise license rules.

### Priority 7 — Hardened Thermal adapter

**Why seventh:** It offers deeper compatibility but carries the greatest implementation and packaging risk. Capture, profiles, comparison baselines, and diagnostics should exist first so compatibility can be measured safely.

**Proposed scope:**

- Integrate the hardened Thermal renderer through an isolated local process so parser failures cannot terminate the listener service.
- Define a stable versioned JSON or C ABI contract with structured errors and original byte offsets.
- Add configurable printer profiles and parity for images, NV graphics, QR codes, barcodes, fonts, positioning, and code pages.
- Reject malformed input without crashes, hangs, excessive memory use, or unbounded output.
- Add golden receipt fixtures, differential tests against the managed parser, fuzz testing, and performance limits.
- Retain the managed parser as a controlled fallback during rollout.

### Priority 8 — Admin Portal License Manager tabs

**Tracker:** [BACKLOG-008](https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/12)

**Why eighth:** This is a contained usability enhancement to the completed License Manager foundation. The higher-risk security, listener, storage, signing, entitlement, export, and compatibility work remains ahead of it, but this item can be pulled forward when a short Admin Portal-focused release is useful.

**Proposed scope:**

- Add three tabs inside License Manager: **Issued Licenses**, **Trial Installations**, and **Recent License Activity**.
- Make Issued Licenses the default tab and keep manual key generation, search, status filters, deleted-license access, and license management actions in that view.
- Preserve each tab's filters, record counts, and scroll position while switching views.
- Support direct links plus browser Back and Forward navigation through a stable query parameter or URL fragment.
- Keep the self-reported registration warning visible in Trial Installations and the immutable-history disclosure visible in Recent License Activity.
- Implement keyboard-accessible tab semantics, visible focus states, screen-reader labels, responsive mobile behavior, and browser regression tests.
- Reuse the existing License Manager data and actions without creating another admin page or duplicate source of truth.

**Complete when:** The three sections render as accessible tabs, the active tab survives refresh and browser navigation, existing license confirmations work unchanged, filters and counts remain accurate, mobile layouts remain usable, and automated/browser tests cover tab switching and state preservation.

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
