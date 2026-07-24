# POS Printer Emulator feature history

Feature releases use `v0.MINOR.FEATURE`, with a two-digit feature number. The feature number advances from `01` through `99`; the next release after `v0.3.99` is `v0.4.00`.

For the current release status, scheduled versions, future backlog, and release-completion checklist, see the [release tracker](docs/RELEASE_TRACKER.md). Reported, fixed, and released defects are indexed in the [bug tracker](docs/BUG_TRACKER.md).

## v0.3.48 — 2026-07-23

- Displays the complete application version persistently at the bottom-left of Settings, regardless of the selected section.
- Reads the displayed version from the running application build instead of hard-coding it in the viewer.
- Separates the one-click Five-Day Promotional Trial from permanent-license activation so evaluation customers are not prompted for an activation key.
- Corrects the promotional verification action to open the live Customer Portal.
- Selects **Create a desktop shortcut** by default in the Windows installer while still allowing customers to opt out.

## v0.3.47 — 2026-07-23

- Replaces manual promotional-key entry with a one-click, server-authorized Five-Day Promotional Trial.
- Lets eligible verified customers evaluate Lite, Pro, or Enterprise from the License screen.
- Authenticates requests with the existing protected installation identity and never places signing credentials in the desktop application.
- Activates the returned signed entitlement automatically without exposing or requiring a promotional key.
- Shows the evaluated edition, activation and expiration date and time, a live days-hours-minutes countdown, and a direct purchase action.
- Stores permanent server-side customer, account, license, and installation claims so reinstalling, deleting local files, changing editions, or using another device cannot create a second trial.
- Uses idempotent request identifiers and encrypted server-side entitlement recovery so network retries cannot consume the promotion without delivering it.
- Preserves the prior permanent tier and restores it automatically when the five consecutive days end.

## v0.3.46 — 2026-07-23

- Launches the desktop application maximized on first use without covering the Windows taskbar.
- Remembers each Windows user's last restored size, position, and maximized or normal state.
- Restores saved windows within the available monitor work area and safely recenters the window if a previously used monitor is disconnected.
- Never persists a minimized startup state, so minimizing the application does not make it appear missing at the next launch.
- Preserves standard Windows maximize, restore, resize, minimize, and multi-monitor behavior.

## v0.3.45 — 2026-07-23

- Adds protected Brevo REST delivery, authenticated webhooks, and a durable priority outbox without exposing provider credentials to browsers or desktop binaries.
- Separates required service messages from consented marketing and rechecks verified ownership, consent, suppressions, recipient hash, and account status immediately before delivery.
- Adds a configurable 300-message provider limit, 290-message automated cap, 50 reserved service slots, quiet hours, frequency caps, bounded retries, dead-letter review, and safe next-window deferral.
- Connects Customer Portal verification and recovery, purchase and activation notices, support confirmation, onboarding, Trial guidance, inactivity follow-up, release announcements, and maintenance reminders to the same policy-aware queue.
- Adds an Admin Portal Communications workspace with service and marketing pause controls, template mappings, controlled test sends, quota visibility, delivery events, aggregate lifecycle segments, and privacy-safe export.
- Adds consented, aggregate lifecycle telemetry while excluding receipt text, raw print data, activation keys, credentials, logs, private listener addresses, and customer email fields from template parameters and analytics.
- Requires recent administrator two-factor verification for communications mutations and export.
- Ships with delivery and marketing paused; provider credentials and approved Brevo template IDs remain protected deployment configuration.
- Passes all PHP suites, 175 desktop tests, publisher compilation, installer packaging, version synchronization, and secret scanning.

## v0.3.44 — 2026-07-23

- Begins the secure self-service commercial workflow for verified Customer Portal accounts.
- Adds server-owned checkout intents for optional annual maintenance renewals and permanent license upgrades.
- Adds one-time promotional eligibility records, prior-entitlement snapshots, five-day expiration, and restoration audit events.
- Keeps PayPal credentials, product prices, activation-key signing, and entitlement transitions on protected backend services.
- Requires reauthentication for checkout and promotion actions and binds every action to the authenticated customer.
- Adds signed, license-or-installation-bound five-day promotion keys that unlock the selected tier locally, resist clock rollback, and restore the permanent tier automatically.
- Adds an Admin-confirmed, reason-required exception workflow for one additional promotion.
- Passes desktop, PHP, idempotent-commerce, and cross-runtime entitlement contract tests.

## v0.3.43 — 2026-07-23

- Adds the Secure Customer Portal foundation for verified Trial, Lite, Pro, and Enterprise customers.
- Adds generic-response email enrollment and password recovery with one-time hashed tokens.
- Adds Argon2id passwords, protected sessions, rotation and revocation, lockouts, persistent rate limits, optional authenticator MFA, and one-time recovery codes.
- Adds customer-owned views for masked licenses, maintenance, installations, purchases, downloads, support history, consent, and privacy-safe account activity.
- Adds reauthenticated computer deactivation with ownership checks, audit evidence, and cooldown protection.
- Adds private support submissions and replies with a server-to-server Admin Portal handoff so GitHub credentials never reach the customer browser.
- Adds account preferences, append-only consent evidence, privacy-safe JSON export, and account closure that preserves permanent license and transaction evidence.

## Upcoming security releases

## v0.3.42 — 2026-07-22

- Adds canonical customer records that connect registrations, installations, paid licenses, purchases, maintenance, support references, and aggregate lifecycle activity without uploading receipt contents.
- Adds an Admin Portal Customers workspace with masked list data, detailed ownership records, maintenance, application-version, activity, consent, support, and duplicate-review filters.
- Adds independently confirmed email ownership, append-only consent evidence, suppression records, permanent merge history, capability-controlled CSV export, and administrative audit events.
- Adds exact-ID server-to-server customer access with bearer-token authentication, persistent rate limiting, generic not-found responses, and no full activation keys.
- Protects recoverable activation keys using AES-256-GCM with a deployment-only key, stores only fingerprints and masked endings for routine lookup, and retains encrypted recovery material outside the repository.
- Migrates existing records without changing license tiers, maintenance dates, activation keys, listener allowances, or desktop feature entitlements.
- Passes all 171 desktop tests, the production viewer build, PHP CRM and commerce contract tests, production migration validation, and secret scanning.
- Moves active development to v0.3.43 — Secure Customer Portal MVP.

## v0.3.41 — 2026-07-22

- Fixes BUG-016 by replacing the stretched square installer artwork with a dedicated `164:314` wizard banner that preserves the official logo proportions.
- Keeps the square icon for the compact wizard header and adds packaging validation that rejects an invalid or incorrectly proportioned banner before compilation.
- Moves active development to v0.3.42 — Customer identity, consent, and CRM foundation.

## v0.3.40 — 2026-07-22

- Adds a persistent **Simple Mode** with plain-language health, the next recommended action, and a numbered setup, connection, and verification workflow.
- Displays the active listener name, address, port, and protocol with copy controls so customers can configure a POS without opening advanced settings.
- Shows the most recent receipt through the production receipt renderer and provides direct Test Receipt, capture import, diagnostics, support, and Printer Setup Wizard actions.
- Preserves the existing Activity, Receipt Preview, and Inspector workspace as **Expert Mode**, with an always-available switch that remembers each Windows user's choice.
- Preserves the selected receipt and the existing search, listener filter, panel, zoom, inspector, and theme state while changing modes.
- Retains all server-enforced Trial, Lite, Pro, and Enterprise feature boundaries; Simple Mode does not grant access to restricted operations.
- Adds responsive layouts, visible keyboard focus, semantic regions, and accessible pressed-state announcements for the mode control.
- Moves active development to v0.3.41 — Accessibility and keyboard usability.

## v0.3.39 — 2026-07-22

- Adds a guided in-application update flow with visible download progress and an explicit install-and-restart confirmation.
- Requires the official GitHub installer and matching SHA-256 release checksum before an update can be opened.
- Creates an encrypted safety snapshot and waits for active receipt connections and jobs to finish before stopping listeners.
- Adds a separate self-contained updater that closes file-locking processes, runs setup with minimal prompts, and automatically relaunches the desktop application.
- Preserves the selected receipt, search, printer filter, inspector tab, zoom, theme, and collapsed-panel preferences across restart.
- Shows a plain-language success or recovery result after the updated application relaunches, and keeps listeners running when preparation or administrator approval is canceled.
- Moves active development to v0.3.40 — Simple Mode and Expert Mode.

## v0.3.38 — 2026-07-22

- Corrects the v0.3.37 first-launch experience with a versioned welcome guide that existing Trial customers see once after updating and can reopen at any time from **Trial setup** in the top bar.
- Replaces the general onboarding checklist with a clear two-step flow: **1. Run the Printer Setup Wizard** and **2. Configure the POS to use the included listener**.
- Shows the included Trial listener under **Settings → Printer Listeners** with its name, running state, local address, LAN IPv4 address, port, protocol, profile, and copyable connection instructions.
- Keeps the Trial listener read-only: create, edit, start, stop, restart, and delete controls are absent, and the server continues to reject listener changes for Trial licenses.
- Replaces the network computer name with an actual LAN IPv4 address so a remote POS can use the displayed connection target directly.
- Moves Receipt Comparison and Automated Validation to v0.3.39 and Guided Update Installation and Restart to v0.3.40.

## v0.3.37 — 2026-07-22

- Adds a first-launch Trial welcome screen with **Set Up Trial Printer**, **Print a Test Receipt**, live listener status, and **Troubleshoot Connection** actions.
- Renames and simplifies the existing wizard to **Trial Configuration Wizard** for Trial customers while retaining one shared implementation for every license tier.
- Makes built-in Test Receipts unlimited, labels them clearly, excludes them from the five-job Trial allowance and usage reporting, and keeps them out of persistent paid history.
- Keeps accepting external POS connections after the five complete daily Trial jobs are used, shows only the first ten rendered lines, and permanently discards the hidden raw bytes, commands, and remaining receipt content.
- Adds an explicit Trial-limit receipt notice, Activity status, upgrade guidance, and a header counter showing complete Trial POS jobs remaining for the computer's local day.
- Preserves the single Trial listener, detects a faulted or assigned port 9100, selects the next available port, requires confirmation, and safely persists the replacement single-listener port without unlocking multi-listener management.
- Moves Receipt Comparison and Automated Validation to v0.3.38 and Guided Update Installation and Restart to v0.3.39.

## v0.3.36 — 2026-07-22

- Adds privacy-preserving country and U.S. state analytics for installations, download starts, application launches, and emulated print jobs.
- Adds accessible world and United States maps with exact regional tables and date, metric, license, and version filters in the private Admin Portal.
- Routes public installer actions through a fixed same-site download endpoint that records aggregate starts without storing raw IP addresses.
- Adds coarse-geography database migrations, periodic location refresh, privacy/EULA disclosures, local permissive map assets, and automated analytics contract tests.
- Moves Receipt Comparison and Automated Validation to v0.3.37 and Guided Update Installation and Restart to v0.3.38.

## v0.3.35 — 2026-07-22

- Fixes the Windows desktop save dialog so configuration backups keep the native `.ppebackup` extension instead of being renamed to `.ppebackup.zip`.
- Accepts legacy `.ppebackup.zip` files created by version 0.3.34 so customers do not need to extract or rebuild an existing backup.
- Adds an accessible question-mark tooltip beside **Restore from backup** with the complete restore sequence and legacy-file guidance.
- Publishes a responsive, illustrated backup and restore guide on the product website with four verified in-app screenshots.
- Passed all 158 automated desktop tests, a clean warning-free release build, desktop/mobile restore-flow QA, 18-page SEO validation, and live website asset verification.
- Moves Receipt Comparison to v0.3.36 and Guided Update Installation to v0.3.37.

## v0.3.34 — 2026-07-21

- Adds **Settings → Backup & Restore** to every license tier with portable password-encrypted `.ppebackup` packages.
- Backs up printer listeners, custom printer profiles and selection, stored logos, simulated printer states, and interface preferences; paid licenses may optionally include local receipt history.
- Excludes activation and maintenance keys, customer registration, credentials, application logs, Windows printer queues, and Epson driver files so a backup cannot transfer a software license or machine identity.
- Verifies the package password, integrity, schema, contents, limits, and compatibility before enabling restore and shows all included and excluded categories to the customer.
- Creates a Windows-protected safety snapshot before restore, keeps the five newest snapshots, and automatically rolls back the running configuration if restoration fails.
- Preserves listener definitions beyond the current tier allowance while activating only the number permitted by the installed Trial, Lite, Pro, or Enterprise license.
- Passed all 151 automated desktop tests, a clean warning-free release build, encrypted create/inspect/restore API verification, and desktop/mobile rendered UI validation with no browser console errors.
- Adds an End User License Agreement covering Trial, Lite, Pro, and Enterprise editions and requires affirmative acceptance before Windows installation can continue.
- Publishes the same agreement at `/eula`, links it from the homepage, and adds it to the canonical sitemap and automated SEO validation.
- Identifies EPCOM Ltd. as the Licensor and installer publisher and applies Georgia law and Georgia state and federal court jurisdiction, subject to mandatory rights.
- Preserves the rights granted by Apache License 2.0 and other open-source licenses for their covered components.
- Defines POS Printer Emulator-only support, excludes third-party and legacy POS systems from standard support, and requires a separately approved paid scope for custom integration or development when offered.
- Defines fully updated 64-bit Windows 11 Pro as the only supported operating-system environment and treats Windows 10 and other editions as unsupported.
- States that an active-maintenance support request may take up to six calendar months for an initial substantive response unless a separately signed SLA provides otherwise; a response does not promise diagnosis, correction, workaround, or resolution.
- Establishes the encrypted backup foundation refined by the v0.3.35 restore-usability maintenance release.

## v0.3.33 — 2026-07-21

- Adds guided Connection Diagnostics for the local service and viewer, storage, printer listeners, configured ports, local health probes, recent job errors, Windows services, printer queues, Epson driver components, and the private/domain firewall rule. The diagnostics display emulator connection details but do not attempt to test unknown POS software.
- Adds reviewed listener restart, Printer Setup Wizard, installation-repair guidance, and administrator-approved firewall repair actions.
- Adds a previewed, redacted ZIP support package and Copy Support Summary workflow available locally on every license tier without uploading receipt contents, raw bytes, license secrets, contact details, or network addresses.
- Replaces Contact Technical Support with a structured in-app Support Request for bug reports, feature requests, license issues, and other issues, including consented redacted logs and validated private attachments.
- Routes authenticated requests through the Admin Portal, keeps GitHub credentials server-side, rate-limits submissions, stores contact information and attachments privately, and creates a redacted labeled GitHub issue with a customer reference.
- Protects offline support-request drafts with Windows data protection and allows retry or deletion without re-entering the form.

## v0.3.32 — Updater installer-asset validation

- Fixed the desktop updater rejecting a release because it received a GitHub release webpage instead of a Windows installer asset.
- Releases without a Windows `.exe` installer are now reported clearly and are not offered as installable desktop updates.
- Added regression coverage for installer and no-installer release responses.
- Published the signed/self-contained Windows installer as `POSPrinterEmulatorSetup-0.3.32-win-x64.exe`.

- **v0.3.30 — Security remediation (Phase 1):** Rotate exposed website credentials, harden website and desktop boundaries, protect secrets and logs, verify signed updates/installers, and clear all critical/high security blockers.
- **v0.3.31 — Secure development lifecycle (Phase 2, released 2026-07-21):** Make threat modeling, automated security checks, security regression tests, tracker evidence, and release sign-off part of every future feature release.

## v0.3.26 — 2026-07-20

- Keeps every Lite, Pro, and Enterprise license permanent while adding an independent annual Application Maintenance and Support entitlement.
- Includes one year of updates and assisted technical support with every new paid license. Existing paid licenses are grandfathered through July 19, 2027.
- Adds backward-compatible v3 activation keys carrying the included maintenance date and signed renewal entitlements tied to the existing license ID and tier.
- Keeps paid receipt-emulation features working after maintenance expires while locking update checks, update downloads, and assisted support behind an optional renewal. Local diagnostics remain available.
- Adds one-time, non-subscription renewal checkout at $9.99 for Lite, $19.99 for Pro, and $59.99 for Enterprise, with server-controlled pricing and verified PayPal capture.
- Adds Admin Portal maintenance status, history, pricing, extension, and revocation workflows with confirmation and audit records.
- Preserves maintenance entitlement data during in-place Windows upgrades and exposes a privacy-minimized signed entitlement refresh flow without transmitting activation keys.
- Fixes **BUG-013** so always-available support diagnostics still download when the optional Stored Logos directory has not been created or was removed.
- Passed all 138 desktop tests, the complete PHP commerce/database/site contract suite, PHP/JavaScript/TypeScript validation, desktop/mobile browser QA, expired-and-renewed entitlement checks, and an installed v0.3.25 Enterprise-to-v0.3.26 upgrade that preserved the paid tier, registration, license ID, listener allowance, and active grandfathered maintenance coverage.

## v0.3.25 — 2026-07-19

- Released four license levels across the desktop application, activation keys, telemetry, purchase workflow, Admin Portal, database, and documentation: Trial, Lite, Pro, and Enterprise.
- Added Lite at a fixed one-time price of $24.99 with the common paid feature set and one total printer listener.
- Gave Pro the same paid features with two total listeners and Enterprise all paid features with up to 15 total listeners. Pro and Enterprise prices remain server-controlled and are displayed on the Buy page.
- Trial remains limited to five completed jobs per day, session-only Activity, a visible watermark, locked paid features, and one total listener.
- Preserved existing paid activation keys as Pro, added safe replacement-key upgrades without reinstalling, and retained listener definitions that exceed a temporarily lower allowance without running or deleting them.
- Fixed protected-schema deployment so semicolons inside release and backlog descriptions are not mistaken for SQL statement boundaries.
- Passed all 113 automated desktop tests, all PHP commerce contracts, PHP and JavaScript syntax checks, 15-page SEO validation, real Lite activation against the local service, rendered Trial/Lite/Pro/Enterprise Settings plus desktop/mobile pricing checks, and an installed v0.3.24 Enterprise-to-v0.3.25 upgrade that preserved registration and activation.
- After the v0.3.30-v0.3.32 security and updater releases, customer support diagnostics shipped in v0.3.33; encrypted configuration backup and restore followed in v0.3.34; v0.3.35 refined backup restore usability; receipt comparison and guided update installation moved to v0.3.36 and v0.3.37.

## v0.3.24 — 2026-07-19

- Fixed **BUG-011**, which could leave an updated Pro or Enterprise installation in Trial and then fail while saving the activation key inside the hardened application-data folder.
- Added a compatibility-safe direct-write fallback when Windows permits overwriting the existing license file but denies temporary-file creation or replacement.
- Preserved registration and activation files as one upgrade pair, restored them before service startup, and removed the temporary upgrade backups only after a successful health check.
- Retained the last known-good upgrade pair across interrupted installer retries and restored it on every setup failure instead of replacing it with damaged live state.
- Validated installer-entered customer information against any surviving paid activation key before writing registration data.
- Repaired ownership and inherited access on existing application-data files before restoring upgrade state, including recovery from an interrupted update that left hardened files inaccessible to the service.
- Made unattended installer failures return a nonzero process exit code so deployment and update tooling can detect an incomplete installation.
- Retried persisted license loading after a temporary startup read failure instead of remaining in Trial until the service restarted.
- Added privacy-safe Activation Diagnostics directly to the Trial License page while keeping the full Support section restricted to Pro and Enterprise.
- Added regression coverage for fallback persistence, paid-license survival across service reloads, and recovery after startup initially misses the license files.
- Fixed **BUG-012** by selecting the first unassigned Windows printer port beginning at 9100, displaying the resolved port in the setup summary, and rechecking for conflicts throughout installation.
- Read machine-wide Windows printer and TCP/IP port assignments from the print registry so port conflicts are detected correctly when the background service runs as `LocalService`.
- Kept same-name reinstall behavior idempotent while preventing a different printer from claiming the selected port during setup.
- Reused an existing emulator listener only when both its bind address and Epson TM-T88V profile are compatible with the requested Windows printer endpoint.
- Added stage-specific Windows spooler errors for the wizard's Print Test Receipt action.
- Passed all 105 automated tests, an installed v0.3.23-to-v0.3.24 Enterprise upgrade and maintenance reinstall, and an installed Windows printer test that selected port 9101 beside an existing 9100 queue, created the matching Enterprise listener and Epson queue, sent a 112-byte ESC/POS test job, and then selected 9102 for the next printer.

## v0.3.23 — 2026-07-19

- Fixed **BUG-009**, which returned HTTP 500 after a valid Enterprise key was accepted when optional paid-history or listener storage could not initialize; activation now succeeds immediately and reports storage recovery separately.
- Hardened activation-key parsing so malformed or truncated keys return a validation result instead of reaching an unhandled server exception.
- Made paid-storage initialization resilient with unique temporary files, atomic replacement, and safe fallback when optional listener state cannot be loaded.
- Fixed **BUG-010**, where the Printer Setup Wizard failed with `System.Management.ManagementException: Invalid parameter` while creating the Windows printer queue.
- Replaced WMI printer-queue creation with the native Windows `AddPrinter` API while retaining automated TCP/IP port creation, Epson driver assignment, verification, rollback, and plain-language Windows error reporting.
- Added activation, storage-failure, listener-runtime, and native printer-configuration regression tests.
- Passed all 83 automated tests and an installed Windows validation that created the `POS Printer Emulator` queue with `EPSON TM-T88V Receipt5` on `127.0.0.1:9100` and successfully sent the wizard Test Receipt.

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
