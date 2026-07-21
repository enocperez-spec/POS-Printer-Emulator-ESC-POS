<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_authentication();

function ensure_dev_support_schema(): void
{
    $pdo = database();
    try {
        $roadmapCount = (int)$pdo->query('SELECT COUNT(*) FROM development_roadmap')->fetchColumn();
        $bugCount = (int)$pdo->query('SELECT COUNT(*) FROM development_bugs')->fetchColumn();
        if ($roadmapCount > 0 && $bugCount > 0) {
            return;
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S02') {
            throw $exception;
        }
        // A missing tracker table is expected on the first deployment.
    }

    $schemaPath = __DIR__ . '/private/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('The Dev Support database schema is unavailable.');
    }
    $statements = array_values(array_filter(
        array_map('trim', explode(';', file_get_contents($schemaPath) ?: '')),
        static fn(string $statement): bool => $statement !== ''
    ));
    if ($statements === []) {
        throw new RuntimeException('The Dev Support database schema is empty.');
    }
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

ensure_dev_support_schema();

database()->exec(
    "CREATE TABLE IF NOT EXISTS development_migrations (
        migration_key VARCHAR(96) NOT NULL,
        applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (migration_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function migrate_dev_support_release_numbers(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $migrationKey = 'release-number-shift-v0.3.20';
        $migrationClaim = $pdo->prepare(
            'INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)'
        );
        $migrationClaim->execute([$migrationKey]);
        if ($migrationClaim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        $rows = $pdo->query(
            "SELECT id, item_key, title FROM development_roadmap
             WHERE item_key IN ('v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23', 'v0.3.24')
             FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string)$row['item_key']] = $row;
        }

        $expectedOldTitles = [
            'v0.3.21' => 'Receipt comparison and automated validation',
            'v0.3.22' => 'Enhanced support and connection diagnostics',
            'v0.3.23' => 'Guided update installation and restart',
        ];
        $expectedShiftedTitles = [
            'v0.3.21' => 'Enterprise multiple printer listeners',
            'v0.3.22' => 'Receipt comparison and automated validation',
            'v0.3.23' => 'Enhanced support and connection diagnostics',
            'v0.3.24' => 'Guided update installation and restart',
        ];
        $hasCanonicalRelease = isset($byKey['v0.3.20'])
            && $byKey['v0.3.20']['title'] === 'Reliable SQLite receipt history';
        $oldReleaseRowsMatch = isset($byKey['v0.3.20'])
            && in_array(
                $byKey['v0.3.20']['title'],
                ['Multiple printer listeners', 'Enterprise multiple printer listeners'],
                true
            );
        foreach ($expectedOldTitles as $itemKey => $title) {
            $oldReleaseRowsMatch = $oldReleaseRowsMatch
                && isset($byKey[$itemKey])
                && $byKey[$itemKey]['title'] === $title;
        }
        $hasSeededHybridLayout = $oldReleaseRowsMatch
            && count($rows) === 5
            && isset($byKey['v0.3.24'])
            && $byKey['v0.3.24']['title'] === 'Guided update installation and restart';
        $hasOldLayout = $oldReleaseRowsMatch
            && ((count($rows) === 4 && !isset($byKey['v0.3.24'])) || $hasSeededHybridLayout);

        $hasPartiallyShiftedLayout = count($rows) === 4 && !isset($byKey['v0.3.20']);
        foreach ($expectedShiftedTitles as $itemKey => $title) {
            $hasPartiallyShiftedLayout = $hasPartiallyShiftedLayout
                && isset($byKey[$itemKey])
                && $byKey[$itemKey]['title'] === $title;
        }

        // Some production databases had the stale planned v0.3.20 row while
        // v0.3.21-v0.3.24 had already been replaced by released records. Those
        // rows must not be shifted again; remove only the stale v0.3.20 row and
        // let the canonical release upserts below restore the release sequence.
        $hasReleasedMixedLayout = isset(
            $byKey['v0.3.20'],
            $byKey['v0.3.22'],
            $byKey['v0.3.23'],
            $byKey['v0.3.24']
        )
            && in_array(
                $byKey['v0.3.20']['title'],
                ['Multiple printer listeners', 'Enterprise multiple printer listeners'],
                true
            )
            && $byKey['v0.3.22']['title'] === 'Receipt workflow regression fixes'
            && $byKey['v0.3.23']['title'] === 'Activation and Printer Setup Wizard fixes'
            && $byKey['v0.3.24']['title'] === 'Upgrade licensing and Printer Setup safeguards';

        if (!$hasCanonicalRelease && !$hasOldLayout && !$hasPartiallyShiftedLayout && !$hasReleasedMixedLayout && $rows !== []) {
            throw new RuntimeException('The release tracker has an unexpected v0.3.20-v0.3.24 layout.');
        }

        if ($hasReleasedMixedLayout) {
            $deleteStaleRelease = $pdo->prepare('DELETE FROM development_roadmap WHERE id = ?');
            $deleteStaleRelease->execute([$byKey['v0.3.20']['id']]);
        }

        if ($hasOldLayout) {
            if ($hasSeededHybridLayout) {
                $deleteSeededRelease = $pdo->prepare('DELETE FROM development_roadmap WHERE id = ?');
                $deleteSeededRelease->execute([$byKey['v0.3.24']['id']]);
            }
            $oldKeys = ['v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23'];
            $moveToTemporaryKey = $pdo->prepare(
                'UPDATE development_roadmap SET item_key = ?, version_label = NULL WHERE id = ?'
            );
            foreach ($oldKeys as $itemKey) {
                $row = $byKey[$itemKey];
                $moveToTemporaryKey->execute(['release-shift-' . $row['id'], $row['id']]);
            }

            $moveToFinalKey = $pdo->prepare(
                'UPDATE development_roadmap SET item_key = ?, version_label = ? WHERE id = ?'
            );
            $newKeys = [
                'v0.3.20' => 'v0.3.21',
                'v0.3.21' => 'v0.3.22',
                'v0.3.22' => 'v0.3.23',
                'v0.3.23' => 'v0.3.24',
            ];
            foreach ($newKeys as $oldKey => $newKey) {
                $moveToFinalKey->execute([$newKey, $newKey, $byKey[$oldKey]['id']]);
            }
        }

        if ($hasOldLayout || $hasPartiallyShiftedLayout) {
            $pdo->exec(
                "UPDATE development_bugs
                 SET target_release = CASE target_release
                         WHEN 'v0.3.20' THEN 'v0.3.21'
                         WHEN 'v0.3.21' THEN 'v0.3.22'
                         WHEN 'v0.3.22' THEN 'v0.3.23'
                         WHEN 'v0.3.23' THEN 'v0.3.24'
                         ELSE target_release
                     END,
                     fixed_version = CASE fixed_version
                         WHEN 'v0.3.20' THEN 'v0.3.21'
                         WHEN 'v0.3.21' THEN 'v0.3.22'
                         WHEN 'v0.3.22' THEN 'v0.3.23'
                         WHEN 'v0.3.23' THEN 'v0.3.24'
                         ELSE fixed_version
                     END
                 WHERE target_release IN ('v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23')
                    OR fixed_version IN ('v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23')"
            );
        }

        $pdo->exec(
            "INSERT INTO development_roadmap
                (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope,
                 priority_reason, completion_criteria, github_url, completed_at)
             VALUES
                ('v0.3.20', 'v0.3.20', 'Release', 'Reliable SQLite receipt history', 'Released', 320,
                 'Replace individual paid-history JSON files with a minimal transactional local database.',
                 'Embedded SQLite for Pro and Enterprise, session-only Trial behavior, schema versioning, WAL, transactions, listener-ready indexes, 500-job retention, verified JSON migration, rollback backup, damaged-row isolation, durable deletion, hardened permissions, and release-runtime verification.',
                 'Reliable storage is required before independently configured listeners share receipt history.',
                 'Existing paid history migrates without loss, Trial creates no database, paid history survives restart within its limit, and the all-in-one installer loads the bundled SQLite runtime.',
                 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/6', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                version_label = VALUES(version_label), item_type = VALUES(item_type), title = VALUES(title),
                status = VALUES(status), priority_rank = VALUES(priority_rank), purpose = VALUES(purpose),
                planned_scope = VALUES(planned_scope), priority_reason = VALUES(priority_reason),
                completion_criteria = VALUES(completion_criteria), github_url = VALUES(github_url),
                completed_at = COALESCE(completed_at, UTC_TIMESTAMP(6))"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

migrate_dev_support_release_numbers();

function migrate_pending_release_numbers(): void
{
    $pdo = database();
    $mappings = [
        'v0.3.27' => ['key' => 'v0.3.33', 'title' => 'Enhanced support package and connection diagnostics', 'priority' => 333],
        'v0.3.28' => ['key' => 'v0.3.35', 'title' => 'Receipt comparison and automated validation', 'priority' => 335],
        'v0.3.29' => ['key' => 'v0.3.36', 'title' => 'Guided update installation and restart', 'priority' => 336],
    ];

    $pdo->beginTransaction();
    try {
        $migrationClaim = $pdo->prepare(
            'INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)'
        );
        $migrationClaim->execute(['pending-release-renumber-v0.3.33']);
        if ($migrationClaim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        $keys = array_merge(array_keys($mappings), array_column($mappings, 'key'));
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $select = $pdo->prepare(
            "SELECT id, item_key, title, status FROM development_roadmap
             WHERE item_key IN ($placeholders) FOR UPDATE"
        );
        $select->execute($keys);
        $rows = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string)$row['item_key']] = $row;
        }

        $moveToTemporaryKey = $pdo->prepare(
            'UPDATE development_roadmap SET item_key = ?, version_label = NULL WHERE id = ?'
        );
        foreach ($mappings as $oldKey => $mapping) {
            $newKey = $mapping['key'];
            if (isset($rows[$newKey]) && isset($rows[$oldKey])) {
                throw new RuntimeException("Both $oldKey and $newKey exist in the pending release tracker.");
            }
            if (!isset($rows[$oldKey])) {
                continue;
            }
            if ($rows[$oldKey]['title'] !== $mapping['title'] || $rows[$oldKey]['status'] === 'Released') {
                throw new RuntimeException("The pending release $oldKey cannot be renumbered safely.");
            }
            $moveToTemporaryKey->execute(['pending-renumber-' . $rows[$oldKey]['id'], $rows[$oldKey]['id']]);
        }

        $moveToFinalKey = $pdo->prepare(
            'UPDATE development_roadmap SET item_key = ?, version_label = ?, priority_rank = ? WHERE id = ?'
        );
        foreach ($mappings as $oldKey => $mapping) {
            if (!isset($rows[$oldKey])) {
                continue;
            }
            $moveToFinalKey->execute([
                $mapping['key'],
                $mapping['key'],
                $mapping['priority'],
                $rows[$oldKey]['id'],
            ]);
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.27' THEN 'v0.3.33'
                     WHEN 'v0.3.28' THEN 'v0.3.35'
                     WHEN 'v0.3.29' THEN 'v0.3.36'
                     ELSE target_release
                 END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.27' THEN 'v0.3.33'
                     WHEN 'v0.3.28' THEN 'v0.3.35'
                     WHEN 'v0.3.29' THEN 'v0.3.36'
                     ELSE fixed_version
                 END
             WHERE target_release IN ('v0.3.27', 'v0.3.28', 'v0.3.29')
                OR fixed_version IN ('v0.3.27', 'v0.3.28', 'v0.3.29')"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

migrate_pending_release_numbers();

// Keep the protected tracker aligned with repository releases after a deployment.
$releaseSync = database()->prepare(
    "INSERT INTO development_roadmap
        (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria, completed_at)
     VALUES
        ('v0.3.16', 'v0.3.16', 'Release', 'In-place receipt export correction', 'Released', 316,
         'Correct the v0.3.15 desktop export failure without delaying the customer fix.',
         'Blob-based Text, Raw, and Capture downloads, native Windows Save dialog, resilient post-startup WebView navigation handling, progress, and errors.',
         'Customers must be able to save receipt artifacts without leaving the selected receipt.',
         'All three formats download, the viewer remains visible, and the desktop no longer shows a ConnectionAborted startup error.', UTC_TIMESTAMP(6)),
        ('v0.3.17', 'v0.3.17', 'Release', 'License tiers and Pro feature gates', 'Released', 317,
         'Establish Trial, Pro, and Enterprise licensing before Enterprise-specific features are introduced.',
         'Tier-aware activation keys, legacy-key compatibility, Pro feature gates for Stored Logos, Printer State, Updates, and Support, telemetry, database migration, and admin issuance.',
         'A stable commercial boundary must precede additional paid and Enterprise functionality.',
         'Trial requests are locked in the UI and APIs while Pro, Enterprise, and legacy paid keys receive the correct access.', UTC_TIMESTAMP(6)),
        ('v0.3.18', 'v0.3.18', 'Release', 'Admin Portal and tier-aware purchase pricing', 'Released', 318,
         'Give the business one clearly named administration area and sell Pro and Enterprise licenses independently.',
         'Admin Portal branding, separate Pro and Enterprise prices, tier-aware PayPal orders, approval, activation-key issuance, email delivery, backward-compatible order migration, and safe private-file deployment filtering.',
         'Commercial license tiers require matching server-controlled purchase pricing and fulfillment.',
         'Both prices save independently and every approved order receives the tier purchased by the customer.', UTC_TIMESTAMP(6)),
        ('v0.3.19', 'v0.3.19', 'Release', 'Printer profiles', 'Released', 319,
         'Model differences between printer configurations explicitly.',
         'Pro and Enterprise built-in and custom profiles for paper width, dots, image limits, code pages, fonts, cutter, drawer, images, barcode and QR, two-color output, DLE EOT, Automatic Status Back, import, export, job metadata, capture metadata, replay, capability warnings, and Trial API and UI gates.',
         'Profiles define behavior before multiple endpoints depend on it.',
         'One capture replayed against two profiles shows deterministic expected capability and rendering differences, while Trial access is rejected.', UTC_TIMESTAMP(6)),
        ('v0.3.20', 'v0.3.20', 'Release', 'Reliable SQLite receipt history', 'Released', 320,
         'Replace individual paid-history JSON files with a minimal transactional local database.',
         'Embedded SQLite for Pro and Enterprise, session-only Trial behavior, schema versioning, WAL, transactions, listener-ready indexes, 500-job retention, verified JSON migration, rollback backup, damaged-row isolation, durable deletion, hardened permissions, and release-runtime verification.',
         'Reliable storage is required before independently configured listeners share receipt history.',
         'Existing paid history migrates without loss, Trial creates no database, paid history survives restart within its limit, and the all-in-one installer loads the bundled SQLite runtime.', UTC_TIMESTAMP(6)),
        ('v0.3.21', 'v0.3.21', 'Release', 'Enterprise multiple printer listeners', 'Released', 321,
         'Let one Enterprise installation emulate multiple receipt printers while Trial and Pro retain one local listener.',
         'Persisted listener configuration, independent names, ports, addresses, profiles, state, buffers, counters, routing, filtering, conflict detection, firewall setup, Enterprise UI and API gates, and fault isolation.',
         'Transactional storage and profiles now provide the reliable foundation needed for isolated multi-printer operation.',
         'Two simultaneous Enterprise listeners receive jobs, apply different profiles, restart safely, and remain independently controllable while Trial and Pro single-listener behavior remains unchanged.', UTC_TIMESTAMP(6)),
        ('v0.3.22', 'v0.3.22', 'Release', 'Receipt workflow regression fixes', 'Released', 322,
         'Restore fast Test Receipt feedback and reliable paid-history cleanup.',
         'Immediate complete Test Receipt response and selection; background Activity refresh; redundant detail-fetch avoidance; SQLite-authoritative Clear All; best-effort obsolete legacy JSON cleanup; plain-language delete failures; regression and end-to-end timing coverage.',
         'Core receipt workflows must be reliable before the next feature release.',
         'Test Receipt appears without a multi-second delay, Clear All removes paid history without HTTP 500, deletion remains durable, and all automated and end-to-end tests pass.', UTC_TIMESTAMP(6)),
        ('v0.3.23', 'v0.3.23', 'Release', 'Activation and Printer Setup Wizard fixes', 'Released', 323,
         'Correct High-severity Enterprise activation and Windows printer installation failures.',
         'Resilient Enterprise activation and storage recovery; safe malformed-key handling; unique temporary persistence; native Windows AddPrinter queue creation; retained Epson driver, TCP/IP port, verification, rollback, and error reporting; automated and installed Windows verification.',
         'Core activation and printer setup must be reliable before the next feature release.',
         'Valid Enterprise activation avoids HTTP 500, the wizard creates the Epson queue on 127.0.0.1:9100 without Invalid parameter, the Test Receipt sends successfully, and all 83 tests pass.', UTC_TIMESTAMP(6)),
        ('v0.3.24', 'v0.3.24', 'Release', 'Upgrade licensing and Printer Setup safeguards', 'Released', 324,
         'Preserve paid licensing through updates and prevent Windows printer-port conflicts.',
         'Matched registration and activation recovery; hardened-folder ACL repair; license-aware post-update health; Trial-safe activation diagnostics; sequential Windows port selection; automatic Enterprise listener alignment; repeated conflict checks; rollback; installed validation.',
         'Upgrade and printer setup reliability must be restored before the next feature release.',
         'Paid activation survives upgrade and maintenance reinstall, Trial can export activation diagnostics, a second Enterprise printer receives a test job on the first free port, and all 105 tests pass.', UTC_TIMESTAMP(6)),
        ('v0.3.25', 'v0.3.25', 'Release', 'Four-tier licensing and upgrade paths', 'Released', 325,
         'Add an affordable Lite license while preserving every existing Pro and Enterprise activation key and purchase record.',
         'Trial, Lite, Pro, and Enterprise licensing; Lite activation tier byte 3 with legacy key compatibility; Lite $24.99 server-controlled pricing; tier-targeted purchase links; PayPal fulfillment and email; Admin Portal issuance, replacement, Trial upgrade, audits, and purchase synchronization; Lite single-listener access, Pro capacity up to two listeners, and Enterprise capacity up to fifteen.',
         'The commercial and activation contracts must stay aligned before Lite keys are sold or upgraded.',
         'Existing Pro and Enterprise keys remain valid, a Lite purchase completes through activation and telemetry, all three paid tiers can be issued or replaced safely, targeted purchase links preselect the requested tier, and automated licensing and commerce tests pass.', UTC_TIMESTAMP(6)),
        ('v0.3.26', 'v0.3.26', 'Release', 'Annual Application Maintenance and Support', 'Released', 326,
         'Keep permanent-license ownership separate from optional annual updates and technical support.',
         'One included year for new paid licenses; existing-license grandfathering through July 19, 2027; optional one-time Lite $9.99, Pro $19.99, and Enterprise $59.99 renewals; signed entitlement refresh; server-verified PayPal renewal orders; Admin pricing, status, history, extension, and revocation controls; telemetry without keys or receipt data.',
         'Maintenance must be implemented before future releases are delivered under the coverage policy.',
         'Permanent paid features keep working after coverage ends, early and lapsed renewals calculate correctly and idempotently, only covered customers receive signed update entitlements, and commerce, licensing, migration, and telemetry tests pass.', '2026-07-20 00:00:00.000000'),
        ('v0.3.33', 'v0.3.33', 'Release', 'Enhanced support package and connection diagnostics', 'Released', 333,
         'Guide nontechnical customers through emulator, printer, listener, and Windows configuration problems and produce privacy-reviewed support evidence.',
         'Guided emulator-side checks for the service, viewer, storage, listeners, ports, firewall, Windows queues, and Epson drivers; reviewed repair actions; previewed redacted ZIP packages; and an in-app Support Request workflow that sends consented, redacted reports through a secure backend to correctly labeled GitHub issues without embedding GitHub credentials.',
         'Customer diagnostics, safe package export, and structured support requests reduce support time while avoiding unreliable testing of unknown POS implementations.',
         'Supported emulator and Windows failures are explained and safely repairable; support packages and GitHub issues exclude receipt contents, IP addresses, contact details, and secrets; offline drafts survive restart and retry.', UTC_TIMESTAMP(6)),
        ('v0.3.34', 'v0.3.34', 'Release', 'End User License Agreement and support policy', 'Released', 334,
         'Present the product-use, licensing, compatibility, privacy, support, and liability terms before installation and on the public website.',
         'Installer acceptance; canonical website EULA; EPCOM Ltd. and Georgia jurisdiction; open-source rights; Windows 11 Pro support boundary; third-party and legacy POS exclusions; separately approved custom work; and maintenance response terms.',
         'Customers must receive consistent legal and support terms before installing or purchasing the software.',
         'Website and installer terms match, acceptance is required, policy content is consistent, and the versioned installer plus checksum are published.', UTC_TIMESTAMP(6)),
        ('v0.3.35', 'v0.3.35', 'Release', 'Receipt comparison and automated validation', 'Next', 335,
         'Provide repeatable compatibility and regression testing.',
         'Compare bytes, commands, text, warnings, and rendered output, with saved baselines, ignored dynamic fields, validation suites, and HTML, PDF, and JSON results.',
         'Diagnostics now takes priority because it directly helps customers resolve installation and connection problems; deterministic captures and profiles remain ready for the following comparison release.',
         'Known-good captures pass, intentional changes fail precisely, and ignored dynamic fields avoid false failures.', NULL),
        ('v0.3.36', 'v0.3.36', 'Release', 'Guided update installation and restart', 'Planned', 336,
         'Close the application safely before an update replaces installed files, then return the customer to the updated application.',
         'Background installer download; checksum and signature verification; Install and Restart, Install Later, and Cancel choices; active-job drain; listener and service shutdown; external updater process; file-lock wait; state preservation; minimal-prompt installation; automatic relaunch; success confirmation; logs; rollback-safe failure recovery; optional automatic downloads.',
         'A controlled external updater eliminates self-update file locks without unexpected listener downtime or lost customer state.',
         'Install and Restart completes without locked-file errors, relaunches the new version, preserves customer state and data, and leaves the current installation usable after cancellation or failure.', NULL),
        ('v0.3.30', 'v0.3.30', 'Release', 'Security remediation (Phase 1)', 'Released', 330,
         'Resolve the actionable security findings from the completed deep review before adding more externally reachable functionality.',
         'Credential rotation and separation; HTTPS, cookie, CSRF, authorization, input-validation, and rate-limit hardening; encrypted and redacted sensitive desktop data; license-boundary enforcement; signed update and installer verification; dependency, secret, and package-integrity checks; security regression coverage.',
         'Security findings affecting the public website, Admin Portal, purchase flow, and Windows application must be remediated before feature development continues.',
         'No critical or high findings remain, exposed credentials are rejected and absent from tracked files and logs, security tests pass, and trusted update and installer verification succeeds.', '2026-07-21 00:00:00.000000'),
        ('v0.3.31', 'v0.3.31', 'Release', 'Secure development lifecycle (Phase 2)', 'Released', 331,
         'Make security review and verification a repeatable requirement for every future product release.',
         'Security checklist and threat-model notes; automated dependency, secret, static-analysis, package-signing, and HTTPS checks; API and desktop security regression suites; tracked findings and evidence; explicit security sign-off; scheduled lightweight and deep reviews.',
         'The Phase 1 protections must remain enforceable as the website, Admin Portal, and desktop application evolve.',
         'The documented checklist, CI gates, regression suites, tracker evidence, and release sign-off are exercised successfully on a complete release.', '2026-07-21 00:00:00.000000'),
        ('v0.3.32', 'v0.3.32', 'Release', 'Updater installer-asset validation', 'Released', 332,
         'Prevent documentation-only GitHub releases from being presented as installable Windows updates.',
         'Require a trusted Windows executable release asset; clearly report releases without an installer; add installer and no-installer response regression tests; publish the self-contained v0.3.32 Windows installer and SHA-256 checksum.',
         'Customers must receive a real installer asset instead of a GitHub release webpage when the desktop updater offers installation.',
         'Installed customers receive a valid v0.3.32 installer download, while releases without a Windows installer cannot trigger an installation attempt.', '2026-07-21 00:00:00.000000')
     ON DUPLICATE KEY UPDATE
        version_label = VALUES(version_label), item_type = VALUES(item_type), title = VALUES(title),
        status = VALUES(status), priority_rank = VALUES(priority_rank), purpose = VALUES(purpose),
        planned_scope = VALUES(planned_scope), priority_reason = VALUES(priority_reason),
        completion_criteria = VALUES(completion_criteria),
        completed_at = IF(VALUES(status) = 'Released', COALESCE(completed_at, UTC_TIMESTAMP(6)), NULL)"
);
$releaseSync->execute();
$backlogSync = database()->prepare(
    "INSERT INTO development_roadmap
        (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria, completed_at)
     VALUES
        ('BACKLOG-007', NULL, 'Backlog', 'Listener security and lifecycle hardening', 'Planned', 1002,
         'Bound network resource use and make listener management cancellation-safe.',
         'Per-listener and global connection caps, per-source and slow-client limits, aggregate in-flight byte limits, queue memory controls, rate-limited diagnostics, cancellation-safe lifecycle completion or rollback, atomic profile assignment/deletion, reviewed firewall narrowing, and adversarial concurrency tests.',
         'Configurable private-network listeners increase the service resource and lifecycle surface, so hardening should precede larger histories and additional network-facing features.',
         'Untrusted or slow LAN clients cannot cause unbounded memory growth, management cancellation cannot strand a listener transition, profile changes cannot race listener updates, and healthy listeners remain isolated.', NULL),
        ('BACKLOG-002', NULL, 'Backlog', 'Advanced SQLite maintenance and retention', 'Planned', 1003,
         'Extend the v0.3.20 SQLite foundation with customer-facing scale and recovery controls.',
         'Paging, fast search, source/listener/profile filters, aggregate counts, configurable count/size/age and fair per-listener retention, health checks, repair, backup, restore, and reviewed legacy-backup cleanup.',
         'The transactional foundation and safe JSON migration are now part of v0.3.20; maintenance controls should follow after the listener runtime is hardened.',
         'Large histories remain fast, one busy listener cannot evict all other history, and customers can validate, retain, back up, restore, repair, and safely clean migrated data.', NULL),
        ('BACKLOG-008', NULL, 'Backlog', 'Admin Portal License Manager tabs', 'Planned', 1008,
         'Organize license administration into focused views without creating separate or conflicting admin areas.',
         'Add accessible tabs for Issued Licenses, Trial Installations, and Recent License Activity; keep key generation and license actions in Issued Licenses; preserve per-tab filters, counts, deleted-license view, scroll position, direct links, and browser navigation; retain Trial verification warnings and audit disclosures; support responsive layouts and regression tests.',
         'This is a contained usability enhancement to the completed License Manager foundation. It follows higher-risk security, listener, storage, signing, entitlement, export, and compatibility work, but can be pulled forward for a short Admin Portal release.',
         'All three sections render as accessible tabs, the active tab survives refresh and Back/Forward navigation, existing confirmations work unchanged, filters and counts remain accurate, and desktop and mobile browser tests pass.', NULL)
     ON DUPLICATE KEY UPDATE
        version_label = VALUES(version_label), item_type = VALUES(item_type), title = VALUES(title),
        priority_rank = VALUES(priority_rank), purpose = VALUES(purpose),
        planned_scope = VALUES(planned_scope), priority_reason = VALUES(priority_reason),
        completion_criteria = VALUES(completion_criteria)"
);
$backlogSync->execute();
database()->prepare(
    "UPDATE development_roadmap
     SET purpose = ?, planned_scope = ?, priority_reason = ?, completion_criteria = ?
     WHERE item_key = 'BACKLOG-004'"
)->execute([
    'Complete outage-safe enforcement after the Admin Portal license-control foundation.',
    'The portal now provides confirmed tier replacement, Trial upgrades, deactivation, reactivation, revocation, soft deletion, purchase synchronization, and audit history. Remaining work is per-computer activation tracking, transfer limits and cooldowns, server-signed entitlement checks that replace client-reported legacy paid status, a defined offline grace period, and privacy-minimized enforcement events.',
    'Commercial control is valuable but must not disable customers during temporary outages; v0.3.23 offline keys remain valid until the enforcement release.',
    'Transfers and remote revocations work with auditable state, the desktop clearly reports its entitlement, and temporary service outages preserve valid licensed use.',
]);
database()->exec(
    "UPDATE development_roadmap
     SET priority_rank = CASE item_key
         WHEN 'BACKLOG-001' THEN 1001
         WHEN 'BACKLOG-007' THEN 1002
         WHEN 'BACKLOG-002' THEN 1003
         WHEN 'BACKLOG-003' THEN 1004
         WHEN 'BACKLOG-004' THEN 1005
         WHEN 'BACKLOG-005' THEN 1006
         WHEN 'BACKLOG-006' THEN 1007
         WHEN 'BACKLOG-008' THEN 1008
         ELSE priority_rank
     END
     WHERE item_key IN ('BACKLOG-001', 'BACKLOG-007', 'BACKLOG-002', 'BACKLOG-003', 'BACKLOG-004', 'BACKLOG-005', 'BACKLOG-006', 'BACKLOG-008')"
);
database()->prepare(
    "UPDATE development_roadmap
     SET github_url = CASE item_key
         WHEN 'v0.3.20' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/6'
         WHEN 'v0.3.21' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/5'
         WHEN 'v0.3.33' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/20'
         WHEN 'v0.3.34' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.34'
         WHEN 'v0.3.35' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/21'
         WHEN 'v0.3.36' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/3'
         WHEN 'v0.3.30' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.30'
         WHEN 'v0.3.31' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.31'
         WHEN 'v0.3.32' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.32'
         WHEN 'BACKLOG-007' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/9'
         WHEN 'BACKLOG-008' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/12'
         ELSE NULL
     END
     WHERE item_key IN ('v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23', 'v0.3.24', 'v0.3.25', 'v0.3.26', 'v0.3.30', 'v0.3.31', 'v0.3.32', 'v0.3.33', 'v0.3.34', 'v0.3.35', 'v0.3.36', 'BACKLOG-007', 'BACKLOG-008')"
)->execute();
$bugSync = database()->prepare(
    "INSERT INTO development_bugs
        (bug_key, title, severity, status, affected_versions, target_release, fixed_version, customer_impact,
         expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
     VALUES
        ('BUG-005', 'Receipt exports replaced the desktop viewer with a ConnectionAborted error',
         'Medium', 'Released', 'v0.3.15', 'v0.3.16', 'v0.3.16',
         'Pro customers could not save Text, Raw, or Capture files without leaving the desktop receipt viewer.',
         'Selecting an export should open a Save dialog, download the file, and keep the current receipt visible.',
         'Direct attachment links were treated as main-frame WebView navigation and the aborted navigation was displayed as a startup failure.',
         'Select a receipt in the v0.3.15 desktop application and choose Text, Raw, or Capture.',
         'Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors.',
         UTC_TIMESTAMP(6)),
        ('BUG-006', 'Listener manager double disposal raised an unhandled shutdown error',
         'Medium', 'Released', 'v0.3.21 development build', 'v0.3.21', 'v0.3.21',
         'Application or service shutdown could end with an unhandled ObjectDisposedException after listeners had already stopped.',
         'Hosted-service stop and dependency-injection disposal should be safe and idempotent.',
         'The singleton listener manager was tracked by two service descriptors and its second disposal reused an already disposed lifecycle semaphore.',
         'Start two listeners, stop the host, and allow dependency injection to dispose the listener manager.',
         'Disposal is idempotent, a regression test repeats disposal after hosted-service stop, all 79 tests pass, and live Ctrl+C shutdown completes without an unhandled exception.',
         UTC_TIMESTAMP(6)),
        ('BUG-007', 'Test Receipt display regressed to approximately three seconds',
         'Medium', 'Released', 'v0.3.21', 'v0.3.22', 'v0.3.22',
         'Customers waited several seconds for a built-in Test Receipt that previously appeared almost instantly.',
         'The generated receipt should be selected and displayed immediately.',
         'The UI waited for Activity refresh and a second receipt-detail request before rendering the generated receipt.',
         'Open the desktop application and select Test receipt.',
         'The endpoint returns the complete receipt, the UI selects it immediately while Activity refreshes in the background, and end-to-end display completes in 280 ms.',
         UTC_TIMESTAMP(6)),
        ('BUG-008', 'Delete All Print Jobs returned HTTP 500 on locked legacy history',
         'High', 'Released', 'v0.3.21', 'v0.3.22', 'v0.3.22',
         'Customers could not clear paid print-job history and the Activity list remained populated.',
         'Clear All should durably remove receipt history even when obsolete migration files cannot be cleaned up.',
         'Successful SQLite deletion was followed by legacy JSON cleanup that could throw on a stale, read-only, or locked file and turn the request into HTTP 500.',
         'Keep an obsolete legacy history file locked and select Delete All Print Jobs.',
         'SQLite deletion remains authoritative, locked legacy cleanup is best effort, the regression test passes, all 80 tests pass, and end-to-end Clear All completes in 285 ms.',
         UTC_TIMESTAMP(6)),
        ('BUG-009', 'Enterprise activation returned HTTP 500 during optional storage initialization',
         'High', 'Released', 'v0.3.22', 'v0.3.23', 'v0.3.23',
         'Customers with a valid Enterprise key could not complete activation.',
         'A valid signed key should unlock Enterprise immediately and optional storage recovery should be reported separately.',
         'Paid-history or listener storage initialization could throw after signature validation and turn activation into HTTP 500.',
         'Validate a signed Enterprise key while forcing optional storage initialization to fail.',
         'Activation succeeds, malformed keys fail safely, forced storage-failure regression tests pass, and all 83 tests pass.',
         UTC_TIMESTAMP(6)),
        ('BUG-010', 'Printer Setup Wizard failed with Invalid parameter while creating queue',
         'High', 'Released', 'v0.3.22 and earlier wizard implementation', 'v0.3.23', 'v0.3.23',
         'Customers could not finish automated Windows printer installation.',
         'The wizard should create the Epson queue and RAW TCP/IP port without manual Windows configuration.',
         'WMI Put attempted to assign the read-only Win32_Printer Name property and raised System.Management.ManagementException.',
         'Run the Printer Setup Wizard with the Epson driver installed and select Install Printer.',
         'Native AddPrinter regression coverage passes; installed Windows validation created POS Printer Emulator with EPSON TM-T88V Receipt5 on 127.0.0.1:9100 and sent the wizard Test Receipt.',
         UTC_TIMESTAMP(6)),
        ('BUG-011', 'Upgrade could lose paid activation and fail to save the license',
         'High', 'Released', 'v0.3.23', 'v0.3.24', 'v0.3.24',
         'Updating could leave a paid installation in Trial and prevent reactivation.',
         'Registration and activation must survive updates as one validated pair.',
         'Hardened data permissions and partial persistence could hide or reject the saved paid license.',
         'Upgrade a registered paid v0.3.23 installation over protected application-data files.',
         'All 105 tests pass; installed Enterprise upgrade and maintenance-reinstall tests preserve registration and activation without re-entry.',
         UTC_TIMESTAMP(6)),
        ('BUG-012', 'Printer Setup Wizard could reuse an assigned TCP/IP port',
         'Medium', 'Released', 'v0.3.23 and earlier', 'v0.3.24', 'v0.3.24',
         'A second Windows printer could be assigned a conflicting endpoint.',
         'The wizard should select the first free port and keep the matching emulator listener available.',
         'Port 9100 could be reused without a complete conflict check.',
         'Install a differently named printer while an existing queue already uses port 9100.',
         'All 105 tests pass; installed Enterprise validation selected 9101, aligned its listener, delivered a 112-byte test job, and selected 9102 next.',
         UTC_TIMESTAMP(6)),
        ('BUG-013', 'Support diagnostics failed when Stored Logos directory was absent',
         'Medium', 'Released', 'v0.3.26 development build', 'v0.3.26', 'v0.3.26',
         'Customers could not save the always-available privacy-safe diagnostic file when optional logo storage had not been created or had been removed.',
         'Diagnostics should treat optional Stored Logos storage as empty and remain available regardless of maintenance state.',
         'Diagnostic export enumerated a missing optional directory and returned HTTP 500.',
         'Remove or omit the Stored Logos directory, then download diagnostics from Settings Support or Activation Diagnostics.',
         'Missing logo storage is treated as empty, imports recreate it safely, six Stored Graphic tests pass, and live expired-maintenance verification returns the diagnostic file with HTTP 200 while update checks remain HTTP 403.',
         '2026-07-20 00:00:00.000000')
     ON DUPLICATE KEY UPDATE
        status = IF(status IN ('Reported', 'Confirmed', 'In progress', 'Fixed locally'), VALUES(status), status),
        target_release = COALESCE(target_release, VALUES(target_release)),
        fixed_version = COALESCE(fixed_version, VALUES(fixed_version)),
        verification = IF(status <> 'Closed - not a bug', VALUES(verification), verification),
        resolved_at = IF(status = 'Released', COALESCE(resolved_at, UTC_TIMESTAMP(6)), resolved_at)"
);
$bugSync->execute();
database()->prepare(
    "UPDATE development_bugs
     SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/8'
     WHERE bug_key = 'BUG-006'"
)->execute();
database()->exec(
    "UPDATE development_bugs
     SET github_url = CASE bug_key
         WHEN 'BUG-009' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/13'
         WHEN 'BUG-010' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/14'
         WHEN 'BUG-011' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/16'
         WHEN 'BUG-012' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/17'
         ELSE github_url
     END
     WHERE bug_key IN ('BUG-009', 'BUG-010', 'BUG-011', 'BUG-012')"
);

$roadmapStatuses = ['Released', 'Next', 'Planned', 'In progress', 'Deferred'];
$bugStatuses = ['Reported', 'Confirmed', 'In progress', 'Fixed locally', 'Released', 'Deferred', 'Closed - not a bug'];
$severities = ['Critical', 'High', 'Medium', 'Low'];
$tab = ($_GET['tab'] ?? '') === 'bugs' ? 'bugs' : 'releases';
$notice = '';
$error = '';

function clean_field(string $name, int $maximum, bool $required = true): string
{
    $value = trim((string)($_POST[$name] ?? ''));
    if ($required && $value === '') {
        throw new InvalidArgumentException('Complete all required bug fields.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maximum) {
        throw new InvalidArgumentException('One or more fields are longer than allowed.');
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update-roadmap') {
            $tab = 'releases';
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $status = (string)($_POST['status'] ?? '');
            if ($id === false || !in_array($status, $roadmapStatuses, true)) {
                throw new InvalidArgumentException('Select a valid roadmap status.');
            }
            $statement = database()->prepare(
                "UPDATE development_roadmap
                 SET status = :status,
                     completed_at = CASE WHEN :released_status = 'Released' THEN COALESCE(completed_at, UTC_TIMESTAMP(6)) ELSE NULL END
                 WHERE id = :id"
            );
            $statement->execute(['status' => $status, 'released_status' => $status, 'id' => $id]);
            $notice = 'Roadmap status updated. Remember to make the matching change in GitHub.';
        } elseif ($action === 'add-bug') {
            $tab = 'bugs';
            $title = clean_field('title', 220);
            $severity = clean_field('severity', 16);
            if (!in_array($severity, $severities, true)) {
                throw new InvalidArgumentException('Select a valid severity.');
            }
            $affected = clean_field('affected_versions', 160, false);
            $target = clean_field('target_release', 32, false);
            $impact = clean_field('customer_impact', 5000);
            $expected = clean_field('expected_behavior', 5000);
            $actual = clean_field('actual_behavior', 5000);
            $steps = clean_field('reproduction_steps', 10000);
            $pdo = database();
            $pdo->beginTransaction();
            try {
                $nextNumber = (int)$pdo->query(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(bug_key, 5) AS UNSIGNED)), 0) + 1
                     FROM development_bugs FOR UPDATE"
                )->fetchColumn();
                $bugKey = sprintf('BUG-%03d', $nextNumber);
                $insert = $pdo->prepare(
                    'INSERT INTO development_bugs
                        (bug_key, title, severity, status, affected_versions, target_release,
                         customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification)
                     VALUES
                        (:bug_key, :title, :severity, \'Reported\', :affected_versions, :target_release,
                         :customer_impact, :expected_behavior, :actual_behavior, :reproduction_steps, \'\')'
                );
                $insert->execute([
                    'bug_key' => $bugKey,
                    'title' => $title,
                    'severity' => $severity,
                    'affected_versions' => $affected,
                    'target_release' => $target === '' ? null : $target,
                    'customer_impact' => $impact,
                    'expected_behavior' => $expected,
                    'actual_behavior' => $actual,
                    'reproduction_steps' => $steps,
                ]);
                $pdo->commit();
                $notice = $bugKey . ' was recorded. Create the matching GitHub issue and add its URL during triage.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } elseif ($action === 'update-bug') {
            $tab = 'bugs';
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $status = (string)($_POST['status'] ?? '');
            $severity = (string)($_POST['severity'] ?? '');
            $target = clean_field('target_release', 32, false);
            $fixed = clean_field('fixed_version', 32, false);
            $verification = clean_field('verification', 5000, false);
            $githubUrl = clean_field('github_url', 500, false);
            if ($id === false || !in_array($status, $bugStatuses, true) || !in_array($severity, $severities, true)) {
                throw new InvalidArgumentException('Select a valid bug status and severity.');
            }
            if ($githubUrl !== '' && filter_var($githubUrl, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Enter a valid GitHub issue URL.');
            }
            $resolved = in_array($status, ['Released', 'Closed - not a bug'], true);
            $statement = database()->prepare(
                'UPDATE development_bugs
                 SET status = :status, severity = :severity, target_release = :target_release,
                     fixed_version = :fixed_version, verification = :verification, github_url = :github_url,
                     resolved_at = CASE WHEN :is_resolved = 1 THEN COALESCE(resolved_at, UTC_TIMESTAMP(6)) ELSE NULL END
                 WHERE id = :id'
            );
            $statement->execute([
                'status' => $status,
                'severity' => $severity,
                'target_release' => $target === '' ? null : $target,
                'fixed_version' => $fixed === '' ? null : $fixed,
                'verification' => $verification,
                'github_url' => $githubUrl === '' ? null : $githubUrl,
                'is_resolved' => $resolved ? 1 : 0,
                'id' => $id,
            ]);
            $notice = 'Bug record updated. Remember to make the matching change in GitHub.';
        } else {
            throw new InvalidArgumentException('The requested Dev Support action is not valid.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('POS Printer Emulator Dev Support failure: ' . $exception->getMessage());
        $error = 'Dev Support could not save the change. Confirm the database schema is current and try again.';
    }
}

$roadmap = database()->query(
    'SELECT id, item_key, version_label, item_type, title, status, priority_rank, purpose,
            planned_scope, priority_reason, completion_criteria, github_url, completed_at, updated_at
     FROM development_roadmap ORDER BY priority_rank'
)->fetchAll();
$bugs = database()->query(
    'SELECT id, bug_key, title, severity, status, affected_versions, target_release, fixed_version,
            customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification,
            github_url, resolved_at, created_at, updated_at
     FROM development_bugs ORDER BY CAST(SUBSTRING(bug_key, 5) AS UNSIGNED) DESC'
)->fetchAll();

$releasedItems = array_values(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Release' && $item['status'] === 'Released'));
$releasedCount = count($releasedItems);
$currentRelease = $releasedCount > 0 ? (string)$releasedItems[$releasedCount - 1]['version_label'] : '—';
$scheduledCount = count(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Release' && $item['status'] !== 'Released'));
$backlogCount = count(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Backlog'));
$openBugCount = count(array_filter($bugs, static fn(array $bug): bool => !in_array($bug['status'], ['Released', 'Closed - not a bug'], true)));

function lines(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', $value) ?: [])));
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dev Support | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/dev-support.css?v=20260715-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a class="active" href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>GitHub and Dev Support statuses must stay aligned.</p></aside>
<main class="dev-support-main"><div class="page-heading"><div><h1>Dev Support</h1><p>Track product releases and defects from the protected Admin Portal.</p></div></div>
<?php if ($notice !== ''): ?><div class="dev-notice" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="dev-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<nav class="dev-tabs" aria-label="Dev Support sections"><a class="<?= $tab === 'releases' ? 'active' : '' ?>" href="?tab=releases" aria-current="<?= $tab === 'releases' ? 'page' : 'false' ?>">Release Tracker <span><?= $scheduledCount ?></span></a><a class="<?= $tab === 'bugs' ? 'active' : '' ?>" href="?tab=bugs" aria-current="<?= $tab === 'bugs' ? 'page' : 'false' ?>">Bug Tracker <span><?= $openBugCount ?></span></a><a href="/support-requests.php">Support Requests</a></nav>

<?php if ($tab === 'releases'): ?>
<section class="dev-metrics" aria-label="Release totals"><article><span>Current release</span><strong><?= e($currentRelease) ?></strong></article><article><span>Released</span><strong><?= $releasedCount ?></strong></article><article><span>Scheduled</span><strong><?= $scheduledCount ?></strong></article><article><span>Backlog</span><strong><?= $backlogCount ?></strong></article></section>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow">Delivery plan</span><h2>Scheduled releases</h2></div><p>Update GitHub and this status together.</p></div><div class="roadmap-list">
<?php foreach ($roadmap as $item): if ($item['item_type'] !== 'Release' || $item['status'] === 'Released') continue; ?>
<article class="roadmap-card"><header><div><span class="item-key"><?= e((string)$item['version_label']) ?></span><h3><?= e($item['title']) ?></h3></div><span class="tracker-status <?= e(strtolower(str_replace(' ', '-', $item['status']))) ?>"><?= e($item['status']) ?></span></header><p class="purpose"><?= e($item['purpose']) ?></p><details><summary>Detailed scope and completion criteria</summary><h4>Planned scope</h4><ul><?php foreach (lines($item['planned_scope']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><h4>Why this priority</h4><p><?= e($item['priority_reason']) ?></p><h4>Complete when</h4><p><?= e($item['completion_criteria']) ?></p></details><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-roadmap"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><label>Status<select name="status"><?php foreach ($roadmapStatuses as $status): ?><option <?= $item['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Save status</button></form></article>
<?php endforeach; ?></div></section>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow backlog">Prioritized</span><h2>Future backlog</h2></div><p>Version numbers are assigned after the order is approved.</p></div><div class="roadmap-list backlog-list">
<?php foreach ($roadmap as $item): if ($item['item_type'] !== 'Backlog') continue; ?>
<article class="roadmap-card"><header><div><span class="item-key">Priority <?= (int)$item['priority_rank'] - 1000 ?></span><h3><?= e($item['title']) ?></h3></div><span class="tracker-status <?= e(strtolower(str_replace(' ', '-', $item['status']))) ?>"><?= e($item['status']) ?></span></header><p class="purpose"><?= e($item['purpose']) ?></p><details><summary>Detailed scope and priority reason</summary><h4>Proposed scope</h4><ul><?php foreach (lines($item['planned_scope']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><h4>Why this priority</h4><p><?= e($item['priority_reason']) ?></p><h4>Complete when</h4><p><?= e($item['completion_criteria']) ?></p></details><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-roadmap"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><label>Status<select name="status"><?php foreach ($roadmapStatuses as $status): ?><option <?= $item['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Save status</button></form></article>
<?php endforeach; ?></div></section>
<section class="tracker-section completed-releases"><div class="section-heading"><div><span class="eyebrow released">History</span><h2>Completed releases</h2></div><p>Detailed public notes remain in CHANGELOG.md.</p></div><div class="release-history"><?php foreach (array_reverse($roadmap) as $item): if ($item['item_type'] !== 'Release' || $item['status'] !== 'Released') continue; ?><article><span><?= e((string)$item['version_label']) ?></span><div><strong><?= e($item['title']) ?></strong><p><?= e($item['planned_scope']) ?></p></div><b>Released</b></article><?php endforeach; ?></div></section>

<?php else: ?>
<section class="dev-metrics bug-metrics" aria-label="Bug totals"><article><span>Open bugs</span><strong><?= $openBugCount ?></strong></article><?php foreach ($severities as $severity): $count = count(array_filter($bugs, static fn(array $bug): bool => $bug['severity'] === $severity && !in_array($bug['status'], ['Released', 'Closed - not a bug'], true))); ?><article class="severity-<?= strtolower($severity) ?>"><span><?= e($severity) ?></span><strong><?= $count ?></strong></article><?php endforeach; ?></section>
<details class="new-bug-panel" <?= $error !== '' && ($_POST['action'] ?? '') === 'add-bug' ? 'open' : '' ?>><summary>＋ Record a new bug</summary><form method="post" class="new-bug-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add-bug"><label class="wide">Bug title<input name="title" maxlength="220" required value="<?= e((string)($_POST['action'] ?? '') === 'add-bug' ? (string)($_POST['title'] ?? '') : '') ?>"></label><label>Severity<select name="severity"><?php foreach ($severities as $severity): ?><option><?= e($severity) ?></option><?php endforeach; ?></select></label><label>Affected versions<input name="affected_versions" maxlength="160" placeholder="Example: v0.3.14"></label><label>Target release<input name="target_release" maxlength="32" placeholder="Example: v0.3.15"></label><label class="wide">Customer impact<textarea name="customer_impact" rows="3" required></textarea></label><label class="wide">Expected behavior<textarea name="expected_behavior" rows="3" required></textarea></label><label class="wide">Actual behavior<textarea name="actual_behavior" rows="3" required></textarea></label><label class="wide">Reproduction steps<textarea name="reproduction_steps" rows="5" required placeholder="1.&#10;2.&#10;3."></textarea></label><button type="submit">Record bug</button></form></details>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow bug">Defect register</span><h2>Bug records</h2></div><p>Never paste activation keys, customer data, private logs, or receipt contents.</p></div><div class="bug-list">
<?php foreach ($bugs as $bug): ?>
<article class="bug-card"><header><div><span class="item-key"><?= e($bug['bug_key']) ?></span><h3><?= e($bug['title']) ?></h3></div><div class="bug-badges"><span class="severity <?= e(strtolower($bug['severity'])) ?>"><?= e($bug['severity']) ?></span><span class="tracker-status <?= e(strtolower(str_replace([' ', '-'], ['', ''], $bug['status']))) ?>"><?= e(str_replace(' - ', ' — ', $bug['status'])) ?></span></div></header><div class="bug-summary"><div><span>Affected</span><strong><?= e($bug['affected_versions'] ?: 'Not recorded') ?></strong></div><div><span>Target</span><strong><?= e($bug['target_release'] ?: 'Unassigned') ?></strong></div><div><span>Fixed in</span><strong><?= e($bug['fixed_version'] ?: '—') ?></strong></div></div><details><summary>Report and verification details</summary><h4>Customer impact</h4><p><?= nl2br(e($bug['customer_impact'])) ?></p><h4>Expected behavior</h4><p><?= nl2br(e($bug['expected_behavior'])) ?></p><h4>Actual behavior</h4><p><?= nl2br(e($bug['actual_behavior'])) ?></p><h4>Reproduction steps</h4><p><?= nl2br(e($bug['reproduction_steps'])) ?></p></details><form method="post" class="bug-update-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-bug"><input type="hidden" name="id" value="<?= (int)$bug['id'] ?>"><label>Status<select name="status"><?php foreach ($bugStatuses as $status): ?><option value="<?= e($status) ?>" <?= $bug['status'] === $status ? 'selected' : '' ?>><?= e(str_replace(' - ', ' — ', $status)) ?></option><?php endforeach; ?></select></label><label>Severity<select name="severity"><?php foreach ($severities as $severity): ?><option <?= $bug['severity'] === $severity ? 'selected' : '' ?>><?= e($severity) ?></option><?php endforeach; ?></select></label><label>Target release<input name="target_release" maxlength="32" value="<?= e((string)($bug['target_release'] ?? '')) ?>"></label><label>Fixed version<input name="fixed_version" maxlength="32" value="<?= e((string)($bug['fixed_version'] ?? '')) ?>"></label><label class="wide">Verification<textarea name="verification" rows="3"><?= e($bug['verification']) ?></textarea></label><label class="wide">GitHub issue URL<input type="url" name="github_url" maxlength="500" value="<?= e((string)($bug['github_url'] ?? '')) ?>" placeholder="https://github.com/..."></label><button type="submit">Save bug</button><?php if ($bug['github_url']): ?><a class="github-link" href="<?= e($bug['github_url']) ?>" target="_blank" rel="noopener">Open GitHub issue ↗</a><?php endif; ?></form></article>
<?php endforeach; ?></div></section>
<?php endif; ?>
</main></div></body></html>
