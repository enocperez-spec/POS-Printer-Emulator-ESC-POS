CREATE TABLE IF NOT EXISTS installations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_uuid CHAR(36) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    app_version VARCHAR(32) NOT NULL,
    license_mode ENUM('Trial', 'Pro', 'Enterprise') NOT NULL DEFAULT 'Trial',
    license_id CHAR(36) NULL,
    first_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_launch_at DATETIME(6) NULL,
    last_print_job_at DATETIME(6) NULL,
    launch_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    print_job_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    activation_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_installations_uuid (installation_uuid),
    UNIQUE KEY uq_installations_token_hash (token_hash),
    KEY ix_installations_license_mode (license_mode),
    KEY ix_installations_last_seen (last_seen_at),
    KEY ix_installations_email (email_address),
    KEY ix_installations_license_id (license_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_usage (
    installation_id BIGINT UNSIGNED NOT NULL,
    usage_date DATE NOT NULL,
    launch_count INT UNSIGNED NOT NULL DEFAULT 0,
    print_job_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (installation_id, usage_date),
    KEY ix_daily_usage_date (usage_date),
    CONSTRAINT fk_daily_usage_installation
        FOREIGN KEY (installation_id) REFERENCES installations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issued_licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id CHAR(36) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    license_tier ENUM('Pro', 'Enterprise') NOT NULL DEFAULT 'Pro',
    activation_key VARCHAR(512) NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    created_by VARCHAR(80) NOT NULL DEFAULT 'owner',
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_issued_licenses_license_id (license_id),
    KEY ix_issued_licenses_email (email_address),
    KEY ix_issued_licenses_issued_at (issued_at),
    KEY ix_issued_licenses_revoked_at (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_roadmap (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_key VARCHAR(32) NOT NULL,
    version_label VARCHAR(32) NULL,
    item_type ENUM('Release', 'Backlog') NOT NULL,
    title VARCHAR(180) NOT NULL,
    status ENUM('Released', 'Next', 'Planned', 'In progress', 'Deferred') NOT NULL,
    priority_rank INT UNSIGNED NOT NULL,
    purpose TEXT NOT NULL,
    planned_scope TEXT NOT NULL,
    priority_reason TEXT NOT NULL,
    completion_criteria TEXT NOT NULL,
    github_url VARCHAR(500) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_development_roadmap_item_key (item_key),
    KEY ix_development_roadmap_type_status (item_type, status),
    KEY ix_development_roadmap_priority (priority_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_bugs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bug_key VARCHAR(16) NOT NULL,
    title VARCHAR(220) NOT NULL,
    severity ENUM('Critical', 'High', 'Medium', 'Low') NOT NULL,
    status ENUM('Reported', 'Confirmed', 'In progress', 'Fixed locally', 'Released', 'Deferred', 'Closed - not a bug') NOT NULL DEFAULT 'Reported',
    affected_versions VARCHAR(160) NOT NULL DEFAULT '',
    target_release VARCHAR(32) NULL,
    fixed_version VARCHAR(32) NULL,
    customer_impact TEXT NOT NULL,
    expected_behavior TEXT NOT NULL,
    actual_behavior TEXT NOT NULL,
    reproduction_steps TEXT NOT NULL,
    verification TEXT NOT NULL,
    github_url VARCHAR(500) NULL,
    resolved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_development_bugs_bug_key (bug_key),
    KEY ix_development_bugs_status (status),
    KEY ix_development_bugs_severity (severity),
    KEY ix_development_bugs_target_release (target_release)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria, completed_at)
VALUES
    ('v0.1.00', 'v0.1.00', 'Release', 'Initial MVP', 'Released', 100, 'Establish the first working receipt-emulation vertical slice.', 'Local ESC/POS listener, defensive parser, receipt viewer, and MVP build tooling.', 'Initial product foundation.', 'The initial listener, parser, viewer, and build tooling were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.2.00', 'v0.2.00', 'Release', 'Desktop application and installer', 'Released', 200, 'Deliver a branded Windows desktop product.', 'POS Printer Emulator branding, desktop HTML application, all-in-one installer, service configuration, and clean uninstall.', 'Turn the MVP into an installable product.', 'The branded desktop application and installer were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.00', 'v0.3.00', 'Release', 'Trial and Pro licensing', 'Released', 300, 'Add commercial Trial and Pro editions.', 'Offline signed activation, installer registration, full history, exports, and in-app activation.', 'Enable product evaluation and paid upgrades.', 'Trial and Pro licensing were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.01', 'v0.3.01', 'Release', 'Activity controls and vendor tools', 'Released', 301, 'Improve daily receipt management and launch vendor operations.', 'Collapsible panels, job deletion, stored-graphics parsing, License Manager, and public website.', 'Improve usability and support license operations.', 'The activity controls and vendor tools were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.02', 'v0.3.02', 'Release', 'Updated Test Receipt', 'Released', 302, 'Use the approved business details in the built-in receipt.', 'Updated Atlanta address, Check label, and E. Perez server name.', 'Keep the demonstration receipt accurate.', 'The updated Test Receipt was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.03', 'v0.3.03', 'Release', 'Settings, updates, and support', 'Released', 303, 'Centralize administration inside the desktop application.', 'Settings sections for License, Check for Updates, and Support with diagnostics and GitHub release updates.', 'Make licensing, updates, and support discoverable.', 'Settings, updates, and diagnostics were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.04', 'v0.3.04', 'Release', 'Telemetry and owner dashboard', 'Released', 304, 'Provide privacy-safe installation and usage visibility.', 'Aggregate telemetry, MariaDB schema, owner dashboard, usage charts, and publishing tools.', 'Support product operations without uploading receipt contents.', 'Telemetry and the owner dashboard were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.05', 'v0.3.05', 'Release', 'Unified administration and 2FA', 'Released', 305, 'Consolidate owner operations securely.', 'Direct Settings dialog, unified admin portal, activation-key generation, history, and authenticator-app 2FA.', 'Remove duplicate admin areas and protect vendor functions.', 'Unified administration and 2FA were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.06', 'v0.3.06', 'Release', 'Updater file-lock correction', 'Released', 306, 'Allow downloaded updates to launch reliably.', 'Unique download staging directories and correct installer file-handle disposal.', 'Correct a High-severity update failure.', 'The corrected updater was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.07', 'v0.3.07', 'Release', 'Faster upgrade registration', 'Released', 307, 'Avoid asking existing customers to re-enter saved registration.', 'Reuse valid saved customer details and prefill partial registration during upgrade.', 'Reduce friction during update installation.', 'Registration reuse was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.08', 'v0.3.08', 'Release', 'Windows branding', 'Released', 308, 'Apply the approved icon throughout Windows.', 'Branded executable, shortcuts, taskbar, uninstaller, and setup icons.', 'Make the installed product recognizable.', 'Windows branding was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.09', 'v0.3.09', 'Release', 'Raster receipt images', 'Released', 309, 'Render standard ESC/POS raster artwork.', 'Monochrome logo in the Test Receipt and GS v 0 raster-image parsing and preview.', 'Improve real receipt fidelity.', 'Raster receipt images were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.10', 'v0.3.10', 'Release', 'Printer Setup Wizard', 'Released', 310, 'Automate Windows printer configuration for nontechnical customers.', 'Epson driver detection and installation, RAW TCP/IP port, printer queue, verification, rollback, and Test Receipt.', 'Remove manual printer and driver setup.', 'The Printer Setup Wizard was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.11', 'v0.3.11', 'Release', 'Printer-state simulation', 'Released', 311, 'Emulate Epson printer status behavior for POS testing.', 'Ready and error scenarios, DLE EOT, GS a Automatic Status Back, DLE ENQ recovery, counters, diagnostics, and tests.', 'Support POS software that monitors printer state.', 'Printer-state simulation was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.12', 'v0.3.12', 'Release', 'Expanded ESC/POS compatibility', 'Released', 312, 'Render more real-world receipt commands accurately.', 'QR, barcode, legacy images, font modes, positioning, code pages, diagnostics, and standards-based QR output.', 'Reduce unsupported commands on customer receipts.', 'Expanded ESC/POS compatibility was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.13', 'v0.3.13', 'Release', 'Imported Stored Logos', 'Released', 313, 'Substitute customer artwork for printer-resident Epson NV graphics.', 'Stored Logos library, persistent local storage, NV key mapping, replacement controls, and regression tests.', 'Handle POS jobs that reference graphics stored in physical printers.', 'Imported Stored Logos were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.14', 'v0.3.14', 'Release', 'Reliable dashboard usage reporting', 'Released', 314, 'Ensure completed print-job counts reach the owner dashboard.', 'Canonical HTTPS telemetry, retained pending totals, retry behavior, and POST-preserving redirects.', 'Correct missing aggregate print-job counts.', 'Reliable telemetry was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.15', 'v0.3.15', 'Release', 'Capture, import, export, and replay', 'Released', 315, 'Make real customer print streams reproducible without reconnecting the original POS.', 'Capture original bytes and metadata, import bin files and capture packages, export with integrity checks, replay safely, label imported and replayed jobs, and keep capture data local.', 'Capture and replay provide reusable test data for profiles, comparison, and diagnostics.', 'A binary receipt imports, renders, exports, re-imports, and replays with identical bytes and output, while malformed files fail safely.', UTC_TIMESTAMP(6)),
    ('v0.3.16', 'v0.3.16', 'Release', 'In-place receipt export correction', 'Released', 316, 'Correct the v0.3.15 desktop export failure without delaying the customer fix.', 'Blob-based Text, Raw, and Capture downloads, native Windows Save dialog, resilient post-startup WebView navigation handling, progress, and errors.', 'Customers must be able to save receipt artifacts without leaving the selected receipt.', 'All three formats download, the viewer remains visible, and the desktop no longer shows a ConnectionAborted startup error.', UTC_TIMESTAMP(6)),
    ('v0.3.17', 'v0.3.17', 'Release', 'License tiers and Pro feature gates', 'Released', 317, 'Establish Trial, Pro, and Enterprise licensing before Enterprise-specific features are introduced.', 'Tier-aware activation keys, legacy-key compatibility, Pro feature gates for Stored Logos, Printer State, Updates, and Support, telemetry, database migration, and admin issuance.', 'A stable commercial boundary must precede additional paid and Enterprise functionality.', 'Trial requests are locked in the UI and APIs while Pro, Enterprise, and legacy paid keys receive the correct access.', UTC_TIMESTAMP(6)),
    ('v0.3.18', 'v0.3.18', 'Release', 'Printer profiles', 'Next', 318, 'Model differences between printer configurations explicitly.', 'Built-in and custom profiles for paper width, dots, code pages, fonts, cutter, drawer, images, barcode/QR features, status behavior, import, and export.', 'Profiles define behavior before multiple endpoints depend on it.', 'One capture replayed against two profiles shows deterministic expected capability and rendering differences.', NULL),
    ('v0.3.19', 'v0.3.19', 'Release', 'Multiple printer listeners', 'Planned', 319, 'Emulate multiple receipt printers from one computer.', 'Independent listener names, ports, addresses, profiles, state, counters, filtering, conflict detection, firewall setup, and fault isolation.', 'Multiple listeners reuse the profile model and enable multi-station testing.', 'Two simultaneous listeners receive jobs, apply different profiles, restart safely, and remain independently controllable.', NULL),
    ('v0.3.20', 'v0.3.20', 'Release', 'Receipt comparison and automated validation', 'Planned', 320, 'Provide repeatable compatibility and regression testing.', 'Compare bytes, commands, text, warnings, and rendered output, with saved baselines, ignored dynamic fields, validation suites, and HTML, PDF, and JSON results.', 'Deterministic captures and profiles are required for meaningful comparisons.', 'Known-good captures pass, intentional changes fail precisely, and ignored dynamic fields avoid false failures.', NULL),
    ('v0.3.21', 'v0.3.21', 'Release', 'Enhanced support and connection diagnostics', 'Planned', 321, 'Guide nontechnical customers through connection problems and support collection.', 'Test the service, listeners, ports, firewall, queues, drivers, viewer, and local and remote connectivity, then create redacted reviewed support packages and offer repair actions.', 'Diagnostics should understand the completed listener, profile, capture, and comparison system.', 'Common connection problems are explained without Windows admin tools and a reviewed redacted support package can be produced.', NULL),
    ('BACKLOG-001', NULL, 'Backlog', 'Service authentication and installer repair', 'Planned', 1001, 'Protect state-changing local APIs and provide a supported recovery path.', 'Per-installation credentials, origin restrictions, protected operations, repair workflow, data preservation, action logs, and health verification.', 'Highest backlog priority because it closes a security boundary before storage and licensing grow more complex.', 'Unauthorized local writes are rejected and repair restores a damaged installation without losing customer data.', NULL),
    ('BACKLOG-002', NULL, 'Backlog', 'SQLite history and migrations', 'Planned', 1002, 'Improve history reliability, scale, filtering, and recovery.', 'Versioned SQLite schema, lossless JSON migration, transactional writes, indexes, retention, deletion, health checks, backup, and restore.', 'Structured storage should precede additional history-dependent features.', 'Existing history migrates without loss and database operations remain transactional, searchable, and recoverable.', NULL),
    ('BACKLOG-003', NULL, 'Backlog', 'Production code-signing and deployment validation', 'Planned', 1003, 'Improve customer trust and verify distributed binaries.', 'Sign executables, installer, and uninstaller, apply trusted timestamps, verify builds and update hashes, and test clean install, upgrade, repair, silent install, and uninstall.', 'Signing is a production trust requirement and may move earlier when a certificate is available.', 'All distributed binaries verify successfully and supported deployment paths pass on Windows 10 and 11.', NULL),
    ('BACKLOG-004', NULL, 'Backlog', 'Online license transfer and revocation', 'Planned', 1004, 'Support customer deactivation, transfer, and owner license controls.', 'Deactivation, transfer limits, cooldowns, revocation, audit history, signed cached authorization, offline grace, and privacy-minimized events.', 'Commercial control is valuable but must not disable customers during temporary outages.', 'Transfers and revocations work with auditable state and temporary service outages preserve valid licensed use.', NULL),
    ('BACKLOG-005', NULL, 'Backlog', 'PNG and deterministic PDF export', 'Planned', 1005, 'Provide predictable receipt artifacts outside the application.', 'Complete receipt PNG, deterministic PDF, correct thermal dimensions, long pages, images, codes, watermark rules, batch export, and output tests.', 'Comparison should establish deterministic rendering before final export formats depend on it.', 'Exports are independent of window size, zoom, and theme and match tested receipt output.', NULL),
    ('BACKLOG-006', NULL, 'Backlog', 'Hardened Thermal adapter', 'Planned', 1006, 'Add deeper renderer compatibility through an isolated hardened process.', 'Stable ABI, structured errors and offsets, profile parity, safe malformed-input handling, golden tests, differential tests, fuzzing, performance limits, and managed fallback.', 'It carries the greatest integration risk and needs captures and baselines for safe validation.', 'The isolated renderer matches approved fixtures, survives hostile inputs, and falls back safely.', NULL);

INSERT IGNORE INTO development_bugs
    (bug_key, title, severity, status, affected_versions, fixed_version, customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
VALUES
    ('BUG-001', 'Updater could not launch a locked installer file', 'High', 'Released', 'v0.3.05 and earlier updater implementation', 'v0.3.06', 'Customers could detect an update but could not start its installer.', 'A downloaded installer should launch after the download completes.', 'The updater reused a temporary path while another process still held the file.', 'Check for an update and select the install action.', 'Downloads use unique staging directories and the file is closed before launch.', UTC_TIMESTAMP(6)),
    ('BUG-002', 'Upgrade asked for saved registration again', 'Medium', 'Released', 'v0.3.06 and earlier installer implementation', 'v0.3.07', 'Existing customers had to re-enter their company name and email during an update.', 'Valid saved registration should be reused and partial values should be prefilled.', 'The installer displayed blank registration fields during an upgrade.', 'Run a newer installer over an existing registered installation.', 'Valid saved registration skips the page and partial registration is prefilled.', UTC_TIMESTAMP(6)),
    ('BUG-003', 'Missing printer-resident NV graphic was reported as unsupported', 'Medium', 'Released', 'v0.3.12 and earlier parser behavior', 'v0.3.13', 'Valid customer receipts displayed a misleading unsupported-command warning.', 'A recognized NV graphic request without local image data should be informational.', 'The recognized command was counted as unsupported when physical-printer image data was unavailable.', 'Send a receipt that requests an Epson NV graphic not stored in the emulator.', 'Recognized missing graphics are informational and imported matching logos render.', UTC_TIMESTAMP(6)),
    ('BUG-004', 'Dashboard print-job totals were not reported reliably', 'High', 'Released', 'v0.3.13 telemetry implementation', 'v0.3.14', 'The owner dashboard did not reflect completed customer print jobs.', 'Completed aggregate job totals should reach the canonical telemetry service and retry after outages.', 'Reports could use a noncanonical path and temporary failures dropped pending totals.', 'Complete print jobs and inspect the owner dashboard totals.', 'The canonical HTTPS endpoint is used and pending totals are retained and retried.', UTC_TIMESTAMP(6));

INSERT IGNORE INTO development_bugs
    (bug_key, title, severity, status, affected_versions, target_release, fixed_version, customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
VALUES
    ('BUG-005', 'Receipt exports replaced the desktop viewer with a ConnectionAborted error', 'Medium', 'Released', 'v0.3.15', 'v0.3.16', 'v0.3.16', 'Pro customers could not save Text, Raw, or Capture files without leaving the desktop receipt viewer.', 'Selecting an export should open a Save dialog, download the file, and keep the current receipt visible.', 'Direct attachment links were treated as main-frame WebView navigation and the aborted navigation was displayed as a startup failure.', 'Select a receipt in the v0.3.15 desktop application and choose Text, Raw, or Capture.', 'Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors.', UTC_TIMESTAMP(6));
