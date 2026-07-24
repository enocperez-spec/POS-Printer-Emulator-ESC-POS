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
        'v0.3.28' => ['key' => 'v0.3.36', 'title' => 'Receipt comparison and automated validation', 'priority' => 336],
        'v0.3.29' => ['key' => 'v0.3.37', 'title' => 'Guided update installation and restart', 'priority' => 337],
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
                     WHEN 'v0.3.28' THEN 'v0.3.36'
                     WHEN 'v0.3.29' THEN 'v0.3.37'
                     ELSE target_release
                 END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.27' THEN 'v0.3.33'
                     WHEN 'v0.3.28' THEN 'v0.3.36'
                     WHEN 'v0.3.29' THEN 'v0.3.37'
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

function migrate_trial_onboarding_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['trial-onboarding-schedule-v0.3.37']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // Move existing planned issue targets before the release rows are synchronized below.
        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.38' THEN 'v0.3.39'
                     WHEN 'v0.3.37' THEN 'v0.3.38'
                     ELSE target_release
                 END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.38' THEN 'v0.3.39'
                     WHEN 'v0.3.37' THEN 'v0.3.38'
                     ELSE fixed_version
                 END
             WHERE target_release IN ('v0.3.37', 'v0.3.38')
                OR fixed_version IN ('v0.3.37', 'v0.3.38')"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

migrate_trial_onboarding_schedule();

function migrate_trial_onboarding_clarity_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['trial-onboarding-clarity-v0.3.38']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.40', version_label='v0.3.40', priority_rank=340 WHERE item_key='v0.3.39' AND title='Guided update installation and restart'");
        $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.39', version_label='v0.3.39', priority_rank=339 WHERE item_key='v0.3.38' AND title='Receipt comparison and automated validation'");
        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release WHEN 'v0.3.39' THEN 'v0.3.40' WHEN 'v0.3.38' THEN 'v0.3.39' ELSE target_release END,
                 fixed_version = CASE fixed_version WHEN 'v0.3.39' THEN 'v0.3.40' WHEN 'v0.3.38' THEN 'v0.3.39' ELSE fixed_version END
             WHERE target_release IN ('v0.3.38', 'v0.3.39') OR fixed_version IN ('v0.3.38', 'v0.3.39')"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_trial_onboarding_clarity_schedule();

function migrate_receipt_comparison_to_end_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['receipt-comparison-to-end-v0.3.48']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        $pdo->exec("UPDATE development_roadmap SET item_key='schedule-temp-receipt-comparison', version_label='v0.3.48', status='Planned', priority_rank=348 WHERE item_key='v0.3.39' AND title='Receipt comparison and automated validation'");
        foreach ([40 => 39, 41 => 40, 42 => 41, 43 => 42, 44 => 43, 45 => 44, 46 => 45, 47 => 46, 48 => 47] as $old => $new) {
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}'");
        }
        $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.48' WHERE item_key='schedule-temp-receipt-comparison'");
        $pdo->exec("UPDATE development_roadmap SET status='Next' WHERE item_key='v0.3.39'");

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.39' THEN 'v0.3.48' WHEN 'v0.3.40' THEN 'v0.3.39'
                     WHEN 'v0.3.41' THEN 'v0.3.40' WHEN 'v0.3.42' THEN 'v0.3.41'
                     WHEN 'v0.3.43' THEN 'v0.3.42' WHEN 'v0.3.44' THEN 'v0.3.43'
                     WHEN 'v0.3.45' THEN 'v0.3.44' WHEN 'v0.3.46' THEN 'v0.3.45'
                     WHEN 'v0.3.47' THEN 'v0.3.46' WHEN 'v0.3.48' THEN 'v0.3.47'
                     ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.39' THEN 'v0.3.48' WHEN 'v0.3.40' THEN 'v0.3.39'
                     WHEN 'v0.3.41' THEN 'v0.3.40' WHEN 'v0.3.42' THEN 'v0.3.41'
                     WHEN 'v0.3.43' THEN 'v0.3.42' WHEN 'v0.3.44' THEN 'v0.3.43'
                     WHEN 'v0.3.45' THEN 'v0.3.44' WHEN 'v0.3.46' THEN 'v0.3.45'
                     WHEN 'v0.3.47' THEN 'v0.3.46' WHEN 'v0.3.48' THEN 'v0.3.47'
                     ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.39' AND 'v0.3.48'
                OR fixed_version BETWEEN 'v0.3.39' AND 'v0.3.48'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_receipt_comparison_to_end_schedule();

function migrate_installer_branding_fix_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['installer-branding-fix-v0.3.41']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // v0.3.41 became a dedicated visual-correction release. Move every
        // unfinished release forward without reusing or deleting its scope.
        for ($old = 49; $old >= 41; $old--) {
            $new = $old + 1;
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}'");
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.41' THEN 'v0.3.42' WHEN 'v0.3.42' THEN 'v0.3.43'
                     WHEN 'v0.3.43' THEN 'v0.3.44' WHEN 'v0.3.44' THEN 'v0.3.45'
                     WHEN 'v0.3.45' THEN 'v0.3.46' WHEN 'v0.3.46' THEN 'v0.3.47'
                     WHEN 'v0.3.47' THEN 'v0.3.48' WHEN 'v0.3.48' THEN 'v0.3.49'
                     WHEN 'v0.3.49' THEN 'v0.3.50' ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.41' THEN 'v0.3.42' WHEN 'v0.3.42' THEN 'v0.3.43'
                     WHEN 'v0.3.43' THEN 'v0.3.44' WHEN 'v0.3.44' THEN 'v0.3.45'
                     WHEN 'v0.3.45' THEN 'v0.3.46' WHEN 'v0.3.46' THEN 'v0.3.47'
                     WHEN 'v0.3.47' THEN 'v0.3.48' WHEN 'v0.3.48' THEN 'v0.3.49'
                     WHEN 'v0.3.49' THEN 'v0.3.50' ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.41' AND 'v0.3.49'
                OR fixed_version BETWEEN 'v0.3.41' AND 'v0.3.49'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_installer_branding_fix_schedule();

function migrate_crm_customer_portal_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['crm-customer-portal-roadmap-v0.3.42']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // Reserve v0.3.42-v0.3.45 for the four dependency-ordered CRM and
        // Customer Portal releases. Preserve every existing planned scope by
        // shifting it forward four release numbers, highest number first.
        for ($old = 50; $old >= 42; $old--) {
            $new = $old + 4;
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}'");
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.42' THEN 'v0.3.46' WHEN 'v0.3.43' THEN 'v0.3.47'
                     WHEN 'v0.3.44' THEN 'v0.3.48' WHEN 'v0.3.45' THEN 'v0.3.49'
                     WHEN 'v0.3.46' THEN 'v0.3.50' WHEN 'v0.3.47' THEN 'v0.3.51'
                     WHEN 'v0.3.48' THEN 'v0.3.52' WHEN 'v0.3.49' THEN 'v0.3.53'
                     WHEN 'v0.3.50' THEN 'v0.3.54' ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.42' THEN 'v0.3.46' WHEN 'v0.3.43' THEN 'v0.3.47'
                     WHEN 'v0.3.44' THEN 'v0.3.48' WHEN 'v0.3.45' THEN 'v0.3.49'
                     WHEN 'v0.3.46' THEN 'v0.3.50' WHEN 'v0.3.47' THEN 'v0.3.51'
                     WHEN 'v0.3.48' THEN 'v0.3.52' WHEN 'v0.3.49' THEN 'v0.3.53'
                     WHEN 'v0.3.50' THEN 'v0.3.54' ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.42' AND 'v0.3.50'
                OR fixed_version BETWEEN 'v0.3.42' AND 'v0.3.50'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_crm_customer_portal_schedule();

function migrate_five_day_promotional_trial_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['five-day-promotional-trial-v0.3.47']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // Reserve v0.3.47 for the customer-facing promotional trial work.
        // Move every existing planned release forward, highest first, so no
        // item key collides and no previously scheduled scope is discarded.
        for ($old = 54; $old >= 47; $old--) {
            $new = $old + 1;
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}' AND status='Planned'");
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.47' THEN 'v0.3.48' WHEN 'v0.3.48' THEN 'v0.3.49'
                     WHEN 'v0.3.49' THEN 'v0.3.50' WHEN 'v0.3.50' THEN 'v0.3.51'
                     WHEN 'v0.3.51' THEN 'v0.3.52' WHEN 'v0.3.52' THEN 'v0.3.53'
                     WHEN 'v0.3.53' THEN 'v0.3.54' WHEN 'v0.3.54' THEN 'v0.3.55'
                     ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.47' THEN 'v0.3.48' WHEN 'v0.3.48' THEN 'v0.3.49'
                     WHEN 'v0.3.49' THEN 'v0.3.50' WHEN 'v0.3.50' THEN 'v0.3.51'
                     WHEN 'v0.3.51' THEN 'v0.3.52' WHEN 'v0.3.52' THEN 'v0.3.53'
                     WHEN 'v0.3.53' THEN 'v0.3.54' WHEN 'v0.3.54' THEN 'v0.3.55'
                     ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.47' AND 'v0.3.54'
                OR fixed_version BETWEEN 'v0.3.47' AND 'v0.3.54'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_five_day_promotional_trial_schedule();

function migrate_settings_version_visibility_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['settings-version-visibility-v0.3.48']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // Reserve v0.3.48 for the customer-facing troubleshooting and setup
        // clarity release without discarding any previously planned scope.
        for ($old = 55; $old >= 48; $old--) {
            $new = $old + 1;
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}' AND status='Planned'");
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.48' THEN 'v0.3.49' WHEN 'v0.3.49' THEN 'v0.3.50'
                     WHEN 'v0.3.50' THEN 'v0.3.51' WHEN 'v0.3.51' THEN 'v0.3.52'
                     WHEN 'v0.3.52' THEN 'v0.3.53' WHEN 'v0.3.53' THEN 'v0.3.54'
                     WHEN 'v0.3.54' THEN 'v0.3.55' WHEN 'v0.3.55' THEN 'v0.3.56'
                     ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.48' THEN 'v0.3.49' WHEN 'v0.3.49' THEN 'v0.3.50'
                     WHEN 'v0.3.50' THEN 'v0.3.51' WHEN 'v0.3.51' THEN 'v0.3.52'
                     WHEN 'v0.3.52' THEN 'v0.3.53' WHEN 'v0.3.53' THEN 'v0.3.54'
                     WHEN 'v0.3.54' THEN 'v0.3.55' WHEN 'v0.3.55' THEN 'v0.3.56'
                     ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.48' AND 'v0.3.55'
                OR fixed_version BETWEEN 'v0.3.48' AND 'v0.3.55'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_settings_version_visibility_schedule();

function migrate_receipt_image_and_diagnostic_pdf_schedule(): void
{
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
        $claim->execute(['receipt-image-diagnostic-pdf-v0.3.49-v0.3.51']);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return;
        }

        // Reserve three consecutive releases for receipt image sharing and the
        // shared Advanced/Standard diagnostic PDF foundation without dropping
        // any already scheduled roadmap scope.
        for ($old = 56; $old >= 49; $old--) {
            $new = $old + 3;
            $pdo->exec("UPDATE development_roadmap SET item_key='v0.3.{$new}', version_label='v0.3.{$new}', priority_rank=3{$new} WHERE item_key='v0.3.{$old}' AND status='Planned'");
        }

        $pdo->exec(
            "UPDATE development_bugs
             SET target_release = CASE target_release
                     WHEN 'v0.3.49' THEN 'v0.3.52' WHEN 'v0.3.50' THEN 'v0.3.53'
                     WHEN 'v0.3.51' THEN 'v0.3.54' WHEN 'v0.3.52' THEN 'v0.3.55'
                     WHEN 'v0.3.53' THEN 'v0.3.56' WHEN 'v0.3.54' THEN 'v0.3.57'
                     WHEN 'v0.3.55' THEN 'v0.3.58' WHEN 'v0.3.56' THEN 'v0.3.59'
                     ELSE target_release END,
                 fixed_version = CASE fixed_version
                     WHEN 'v0.3.49' THEN 'v0.3.52' WHEN 'v0.3.50' THEN 'v0.3.53'
                     WHEN 'v0.3.51' THEN 'v0.3.54' WHEN 'v0.3.52' THEN 'v0.3.55'
                     WHEN 'v0.3.53' THEN 'v0.3.56' WHEN 'v0.3.54' THEN 'v0.3.57'
                     WHEN 'v0.3.55' THEN 'v0.3.58' WHEN 'v0.3.56' THEN 'v0.3.59'
                     ELSE fixed_version END
             WHERE target_release BETWEEN 'v0.3.49' AND 'v0.3.56'
                OR fixed_version BETWEEN 'v0.3.49' AND 'v0.3.56'"
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

migrate_receipt_image_and_diagnostic_pdf_schedule();

function mark_customer_crm_release_in_progress(): void
{
    $pdo = database();
    $claim = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (?)');
    $claim->execute(['customer-crm-v0.3.42-in-progress']);
    $pdo->exec("UPDATE development_roadmap SET status='In progress' WHERE item_key='v0.3.42' AND status='Planned'");
}

mark_customer_crm_release_in_progress();

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
        ('v0.3.34', 'v0.3.34', 'Release', 'Encrypted backup, EULA, and support policy', 'Released', 334,
         'Protect portable emulator configuration while presenting consistent product-use, licensing, compatibility, privacy, support, and liability terms.',
         'Password-encrypted PPE backups with verified preview and rollback-safe restore; installer EULA acceptance; canonical website EULA; EPCOM Ltd. and Georgia jurisdiction; Windows 11 Pro and third-party POS support boundaries; and maintenance-response terms.',
         'Customers need both a safe configuration recovery path and clear legal and support terms before comparison suites and guided updates expand the workflow.',
         'Encrypted create, inspect, and restore pass wrong-password and tamper rejection, automatic safety snapshots, rollback protection, all 151 tests, rendered UI validation, matching website and installer terms, required acceptance, and published installer checksum.', UTC_TIMESTAMP(6)),
        ('v0.3.35', 'v0.3.35', 'Release', 'Backup restore usability and compatibility', 'Released', 335,
         'Remove confusing Windows ZIP behavior from encrypted backups and make restoration understandable without leaving the application.',
         'Native .ppebackup save handling; compatibility with v0.3.34 .ppebackup.zip file names; accessible in-app restore guidance; and a responsive illustrated website guide.',
         'Customers must be able to recover the v0.3.34 backup they already created without extracting an encrypted package or guessing the restore sequence.',
         'New backups keep .ppebackup, legacy names restore successfully, all 158 tests and rendered restore-flow checks pass, and the guide plus screenshots are public.', UTC_TIMESTAMP(6)),
        ('v0.3.36', 'v0.3.36', 'Release', 'Privacy-preserving geographic analytics dashboard', 'Released', 336,
         'Show where website downloads and product activity occur without retaining raw IP addresses.',
         'Coarse country and U.S. state derivation; transient IP processing; daily download-start aggregates; world and United States maps; exact regional tables; date, metric, license, and version filters; accessibility; privacy and EULA disclosures; and automated contract checks.',
         'Geographic adoption data helps EPCOM prioritize documentation, compatibility, and support while data minimization protects customers.',
         'The private Admin dashboard reports approximate regional download starts, installations, launches, and print jobs; raw IP addresses are not stored in the analytics schema; filters and keyboard controls work; and legal disclosures match implementation.', UTC_TIMESTAMP(6)),
        ('v0.3.37', 'v0.3.37', 'Release', 'Trial Setup and Onboarding Improvements', 'Released', 337,
         'Let nontechnical Trial customers begin testing immediately without understanding listeners, ports, or Windows printer configuration.',
         'First-launch welcome; Trial Configuration Wizard; one automatic listener; confirmed sequential port recovery; unlimited ephemeral Test Receipts; complete-job allowance counter; irreversible ten-line redaction after the fifth external job; upgrade guidance; and local diagnostics.',
         'Removing first-run friction improves evaluation while ingestion-time redaction protects receipt data after the Trial allowance is used.',
         'Fresh Trial setup, unlimited Test Receipts, port-conflict recovery, five complete external jobs, accepted redacted later jobs, and non-recoverability across APIs, history, exports, and diagnostics are verified.', UTC_TIMESTAMP(6)),
        ('v0.3.38', 'v0.3.38', 'Release', 'Trial Onboarding Clarity Correction', 'Released', 338,
         'Make Trial setup impossible to lose and show customers exactly where their POS must send print jobs.',
         'Versioned and reopenable two-step welcome guide; wizard-first instruction; visible read-only included listener; local and LAN IPv4 endpoints; copyable RAW TCP details; and retained server-side mutation denial.',
         'The v0.3.37 guide could remain dismissed and hid the included listener behind an upgrade panel, leaving customers unsure how to connect.',
         'Fresh and upgraded Trial installations see and can reopen the guide, view one locked listener, copy exact connection details, and receive HTTP 403 for listener mutations.', UTC_TIMESTAMP(6)),
        ('v0.3.59', 'v0.3.59', 'Release', 'Update Notifications for All License Types', 'Planned', 359,
         'Notify every license tier about newer public desktop releases even when paid maintenance has expired.',
         'Public release notification checks for Trial, Lite, Pro, and Enterprise; installed and latest versions; eligible releases-behind count; concise update summary; accessible visual indicator; Trial manual-download action; active-maintenance guided update; expired-maintenance release and renewal guidance; offline cache; rate limiting; and trusted-link enforcement.',
         'Update awareness should be universal while in-app installation continues to honor maintenance entitlements.',
         'Every license state receives accurate non-blocking notifications, Trial opens the official download page, active-maintenance paid users can install in-app, expired-maintenance users cannot bypass renewal, and privacy, offline, counting, and trust tests pass.', NULL),
        ('v0.3.58', 'v0.3.58', 'Release', 'Receipt comparison and automated validation', 'Planned', 358,
         'Provide repeatable compatibility and regression testing.',
         'Compare bytes, commands, text, warnings, and rendered output, with saved baselines, ignored dynamic fields, validation suites, and privacy-safe HTML, PDF, and JSON results.',
         'Projects, privacy masking, encoding diagnostics, and update recovery provide safer foundations for comparison suites.',
         'Known-good captures pass, intentional changes fail precisely, ignored dynamic fields avoid false failures, and privacy-safe exports protect configured sensitive values.', NULL),
        ('v0.3.39', 'v0.3.39', 'Release', 'Guided update installation and restart', 'Released', 339,
         'Close the application safely before an update replaces installed files, then return the customer to the updated application.',
         'Background installer download; SHA-256 verification; pre-update safety snapshot; install confirmation and safe deferral; active-job drain; listener and service shutdown; external updater process; file-lock wait; state preservation; minimal-prompt installation; automatic relaunch; success confirmation; and recovery-safe failure handling.',
         'A controlled external updater eliminates self-update file locks without unexpected listener downtime or lost customer state.',
         'Install and Restart completes without locked-file errors, relaunches the new version, preserves customer state and data, and leaves the current installation usable after cancellation or failure.', UTC_TIMESTAMP(6)),
        ('v0.3.40', 'v0.3.40', 'Release', 'Simple Mode and Expert Mode', 'Released', 340,
         'Give new customers a task-focused experience while preserving the complete expert workspace.',
         'Simple task cards; plain-language health and next action; retained Expert Mode; remembered mode choice; state-preserving switching; and unchanged server-side license enforcement.',
         'Persistent task guidance addresses customer confusion without removing advanced receipt inspection.',
         'Customers complete setup, connection, testing, review, and diagnostics in Simple Mode and switch to Expert Mode without losing state.', UTC_TIMESTAMP(6)),
        ('v0.3.41', 'v0.3.41', 'Release', 'Installer Branding Correction', 'Released', 341,
         'Correct the stretched product logo on the Windows installer welcome and completion pages.',
         'Dedicated 656x1256 wizard banner at the exact 164:314 display ratio; unchanged official square mark; independent compact header icon; and packaging validation for the PNG, required files, directives, and aspect ratio.',
         'A dedicated maintenance release avoids silently replacing the already-published v0.3.40 installer.',
         'The C# packaging tool builds without warnings or errors, Inno Setup reads both independent branding assets, and the compiled installer displays the product mark without stretching.', UTC_TIMESTAMP(6)),
        ('v0.3.42', 'v0.3.42', 'Release', 'Customer identity, consent, and CRM foundation', 'Released', 342,
         'Create one privacy-aware customer record before exposing portal or automated marketing workflows.',
         'Canonical verified customer IDs; normalized registration, installation, license, maintenance, purchase, support, consent, suppression, and event records; safe backfill; Admin customer search, filters, detail, and controlled export; masked key lookup; retention and correction workflows; and authenticated service APIs.',
         'Every later portal, renewal, promotional, email, and analytics workflow depends on trustworthy ownership and consent evidence.',
         'Existing entitlements migrate unchanged, verified customers resolve to one auditable profile, unauthorized enumeration is blocked, and prohibited receipt or secret data is absent.', '2026-07-22 00:00:00.000000'),
        ('v0.3.43', 'v0.3.43', 'Release', 'Secure Customer Portal MVP', 'Released', 343,
         'Give verified customers secure self-service access at userportal.posprinteremulator.com.',
         'Verified enrollment; secure sessions and recovery; optional TOTP MFA; masked license, maintenance, purchase, computer, download, support, and promotional-eligibility views; contact and preference management; support submission and replies; controlled old-computer deactivation; accessibility; and responsive deployment.',
         'The portal depends on v0.3.42 customer ownership, consent, audit, and authenticated API foundations.',
         'Verified customers can manage only their own records and primary portal workflows pass authorization, recovery, accessibility, desktop, and mobile tests.', '2026-07-23 00:00:00.000000'),
        ('v0.3.44', 'v0.3.44', 'Release', 'Self-service renewals, upgrades, and promotional trials', 'Released', 344,
         'Add auditable commercial self-service while preserving permanent-license ownership.',
         'Server-controlled PayPal renewals and upgrades; exact product and price confirmation; idempotent fulfillment, refunds, and chargebacks; one five-day paid-edition promotion per verified customer; prior-license restoration; repeat prevention; audited exceptions; and offline and clock-tamper handling.',
         'Payments and temporary entitlements require the verified ownership and portal foundations before campaigns direct customers to them.',
         'Renewals and upgrades fulfill exactly once, eligible promotions occur exactly once, expiration restores the prior license, and failure and refund tests keep entitlements consistent.', '2026-07-23 00:00:00.000000'),
        ('v0.3.45', 'v0.3.45', 'Release', 'Consent-aware lifecycle communications and CRM analytics', 'Released', 345,
         'Improve onboarding, conversion, renewal, and support follow-up through Brevo without building a custom mail server.',
         'Protected Brevo transactional email, contact, template, and authenticated webhook APIs; authenticated sender domain; configurable 300-send provider quota with a 290-send automated cap and 50 reserved service slots; durable priority outbox and next-day deferral; separated service and marketing messages; retries, quiet hours, caps, approvals, pause, and emergency stop; welcome, Trial, promotion, release, inactivity, support, and maintenance schedules; unsubscribe, bounce, complaint, and closure suppression; minimal consented telemetry; segmentation; and Admin dashboards.',
         'Automation follows only after identity, portal destinations, commercial workflows, consent, and lifecycle events are reliable.',
         'Eligible messages send exactly once, opt-outs and suppressions are honored, more than 300 queued messages respect the configured quota without loss or starving service mail, dashboards reconcile, and prohibited data is absent from Brevo payloads and logs.', '2026-07-23 00:00:00.000000'),
        ('v0.3.46', 'v0.3.46', 'Release', 'Accessibility and keyboard usability', 'Released', 346,
         'Make primary workflows usable with keyboard, assistive technology, scaling, and high contrast.',
         'Maximized first launch; taskbar-safe remembered placement; disconnected-monitor recovery; focus order and visibility; semantic names and landmarks; screen-reader announcements; keyboard shortcuts; text and display scaling; high contrast; reduced motion; WCAG 2.2 AA checks; captions; and automated plus manual accessibility tests.',
         'Accessibility should be established before additional desktop screens and controls increase remediation cost.',
         'Window placement behaves safely and primary workflows pass keyboard-only, Narrator, 200 percent scaling, high-contrast, and automated accessibility verification.', UTC_TIMESTAMP(6)),
        ('v0.3.47', 'v0.3.47', 'Release', 'Five-Day Promotional Trial Experience', 'Released', 347,
         'Replace manual promotional-key entry with a one-click server-authorized Lite, Pro, or Enterprise evaluation.',
         'Edition selection; verified-customer eligibility; privacy-preserving installation identity; signed automatic activation; eligible, activating, active, expired, used, and offline states; exact five-day countdown; purchase actions; safe prior-license restoration; and server-side idempotency, uniqueness, and anti-repeat controls.',
         'The verified customer and promotional entitlement foundations should become a clear desktop workflow before more configuration screens are added.',
         'Each edition activates correctly without key entry, the active and expired states are accurate, prior ownership is restored, and reinstall, deletion, clock rollback, retries, concurrency, alternate installations, or cross-edition requests cannot create or extend another promotion.', UTC_TIMESTAMP(6)),
        ('v0.3.48', 'v0.3.48', 'Release', 'Settings Version Visibility and Setup Clarity', 'Released', 348,
         'Make troubleshooting screenshots self-identifying and separate automatic evaluation from purchased-license activation.',
         'Persistent build-derived Settings version; clearly separated permanent-license entry; corrected Customer Portal verification action; and desktop shortcut selected by default during setup.',
         'Customers and support staff need to identify the installed release immediately, and evaluation customers must not be asked for an activation key.',
         'Every Settings section shows the running version, one-click evaluation remains keyless, verification opens the live Customer Portal, and setup creates a desktop shortcut unless the customer opts out.', UTC_TIMESTAMP(6)),
        ('v0.3.49', 'v0.3.49', 'Release', 'Receipt Image Sharing', 'Released', 349,
         'Let customers share or document the complete rendered receipt without capturing the surrounding application window.',
         'Paid-tier Image menu; full off-screen receipt rendering; logos, raster graphics, barcodes, and QR codes; Windows clipboard PNG; native Save As with descriptive filename and overwrite confirmation; bounded dimensions and transfer size; server authorization; PNG signature validation; and privacy-safe action logging.',
         'Direct image sharing is a contained, high-value workflow and provides the receipt-rendering artifact that later diagnostic reports can reuse.',
         'Lite, Pro, and Enterprise customers copy or save a complete receipt-only PNG, Trial calls are denied server-side, and no application chrome or logged receipt content is included.', UTC_TIMESTAMP(6)),
        ('v0.3.50', 'v0.3.50', 'Release', 'Advanced Diagnostics PDF Report', 'Released', 350,
         'Give Enterprise customers and developers one detailed, professional diagnostic document for receipt and listener problems.',
         'Application-logo branding; format version and report ID; issue narrative; receipt preview; comprehensive command and bounded raw-data analysis; job, listener, profile, printer-state, environment, storage, performance, warning, health, redacted-log, and checksum sections; review and consent; server authorization; deterministic pagination; and shared document model.',
         'The comprehensive report establishes the secure collection, redaction, branding, and PDF engine that the shorter Standard report can reuse.',
         'A representative long receipt produces a readable, logo-branded, multi-page PDF with correct tables, page breaks, checksums, redactions, consent, and Enterprise enforcement.', UTC_TIMESTAMP(6)),
        ('v0.3.51', 'v0.3.51', 'Release', 'Standard Diagnostics PDF Report', 'Planned', 351,
         'Provide a shorter Enterprise support report with the most important findings and next actions.',
         'Reuse the Advanced report collection, redaction, logo branding, report metadata, pagination, checksum, authorization, review, consent, and secure local export services; include concise issue, application, Windows, listener, job, receipt thumbnail, state, warning, health, and recent-error summaries.',
         'Building the comprehensive engine first avoids duplicate security and rendering logic while allowing this release to focus on concise customer-support presentation.',
         'The Standard report is materially shorter, readable, branded, complete for common support cases, free of prohibited data, and validated by the shared PDF and redaction tests.', NULL),
        ('v0.3.52', 'v0.3.52', 'Release', 'Automatic configuration restore points', 'Planned', 352,
         'Protect customers from accidental configuration loss without requiring manual backups.',
         'Encrypted restore points before material configuration changes; optional schedules; bounded retention; content and integrity preview; transactional restore; safety snapshots; rollback; storage controls; and protected local storage.',
         'Recovery protection should precede projects and additional customer configuration complexity.',
         'Customers recover the previous working configuration after a failed or accidental change with no partial state, secret exposure, or license loss.', NULL),
        ('v0.3.53', 'v0.3.53', 'Release', 'Projects and testing sessions', 'Planned', 353,
         'Organize receipts and configuration by customer, store, migration, register, or support engagement.',
         'Named projects and sessions; notes and tags; listener, profile, capture, baseline, and report references; default-project migration; recent and archived projects; safe copy, export, and import; state retention; and integrity validation.',
         'Restore-point foundations make isolated project workflows safe and establish clean data boundaries for later comparison suites.',
         'Two customer projects remain isolated and one can be exported without leaking data or configuration from the other.', NULL),
        ('v0.3.54', 'v0.3.54', 'Release', 'Privacy-safe receipt masking', 'Planned', 354,
         'Let customers demonstrate, screenshot, export, and share receipts without unnecessarily exposing sensitive data.',
         'Reversible display-only Privacy View; built-in and custom masking; detection of common personal and transaction values; masked screenshots, exports, reports, and support attachments; original preservation; preview; warnings; and bypass tests.',
         'Project, support, and receipt exports increase sharing, so privacy controls should precede later comparison reports.',
         'Privacy-safe artifacts contain no configured sensitive values while authorized originals remain unchanged and protected.', NULL),
        ('v0.3.55', 'v0.3.55', 'Release', 'System tray health and notifications', 'Planned', 355,
         'Keep customers informed about important listener events without leaving the main window open.',
         'Health-state tray icon; Open, Test Receipt, status, Diagnostics, and Exit actions; configurable local fault, conflict, rejection, Trial, maintenance, and update notifications; deduplication; rate limiting; expiry; recovery clearing; and Focus Assist support.',
         'Background awareness reduces missed faults and unnecessary support requests after core privacy controls are established.',
         'One actionable privacy-safe notification represents a background fault and clears with the tray state after verified recovery.', NULL),
        ('v0.3.56', 'v0.3.56', 'Release', 'Character and code-page assistant', 'Planned', 356,
         'Help customers correct garbled symbols, accents, currencies, and multilingual receipt text.',
         'Encoding mismatch detection; byte and command tracing; compatible code-page previews; mid-job change explanations; profile recommendations with explicit preview; international golden fixtures; and immutable original captures.',
         'Profiles, privacy, and projects make encoding recommendations safe and prepare deterministic inputs for later comparison.',
         'Known mojibake fixtures produce the correct diagnosis and deterministic preview without modifying original capture bytes.', NULL),
        ('v0.3.57', 'v0.3.57', 'Release', 'Offline Enterprise update packages', 'Planned', 357,
         'Support secure updates on restricted or air-gapped POS networks.',
         'Portable installer package with manifest, architecture, checksums, trusted signature, and release metadata; removable-media import; full verification; downgrade and incompatibility rejection; guided updater reuse; offline entitlement guidance; and privacy-safe audit evidence.',
         'This depends on guided updates, production signing, rollback, and entitlement foundations.',
         'A valid offline package installs successfully while tampered, unsigned, downgraded, incompatible, or unentitled packages leave the current installation unchanged.', NULL),
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
         'All three sections render as accessible tabs, the active tab survives refresh and Back/Forward navigation, existing confirmations work unchanged, filters and counts remain accurate, and desktop and mobile browser tests pass.', NULL),
        ('UPE-123', NULL, 'Backlog', 'Device and Listener Health Dashboard', 'Planned', 1123,
         'Give customers one clear view of the health of every registered computer and printer listener.',
         'Show computer online status, installed version, listener names and ports, last successful print job, recent connection warnings, and Maintenance and Support eligibility.',
         'User Portal Enhancement. This provides the highest immediate customer and support value by exposing operational health without opening the desktop application.',
         'Customers can identify offline, outdated, or faulted computers and listeners from accurate, privacy-safe portal data.', NULL),
        ('UPE-124', NULL, 'Backlog', 'Guided Setup Wizard', 'Planned', 1124,
         'Guide customers from account login to a verified working POS connection.',
         'Select a POS or generic ESC/POS profile, choose TCP/IP settings, test connectivity, send a test receipt, confirm rendering, and download a configuration summary.',
         'User Portal Enhancement. Guided setup reduces configuration mistakes and shortens time to the first successful receipt.',
         'A customer can complete the supported setup flow and verify a receipt without guessing listener or port settings.', NULL),
        ('UPE-125', NULL, 'Backlog', 'Diagnostic Package and Support Integration', 'Planned', 1125,
         'Let customers attach privacy-reviewed diagnostic evidence directly to a support request.',
         'Collect application version, listener configuration, operating-system details, and relevant errors while excluding receipt contents, full activation keys, credentials, and unnecessary personal data.',
         'User Portal Enhancement. Structured diagnostic packages reduce support time while preserving customer privacy.',
         'Customers can preview and submit a redacted diagnostic package, and support staff can retrieve it only through authorized workflows.', NULL),
        ('UPE-126', NULL, 'Backlog', 'Active Sessions and Security History', 'Planned', 1126,
         'Give customers visibility and control over Customer Portal access.',
         'List active and recent sessions with browser, approximate location, IP-derived security context, login time, idle time, and security events; allow remote sign-out without exposing session secrets.',
         'User Portal Enhancement. Session visibility and revocation strengthen account security and help customers recognize unfamiliar access.',
         'Customers can review account activity and terminate other sessions, with every security action recorded and notified appropriately.', NULL),
        ('UPE-127', NULL, 'Backlog', 'License Transfer Wizard', 'Planned', 1127,
         'Provide a controlled self-service process for moving a license to another computer.',
         'Select the old computer, verify identity and eligibility, deactivate the installation, confirm the transfer, show installation instructions, and record the complete transfer history.',
         'User Portal Enhancement. A guided transfer reduces manual support work without weakening activation limits.',
         'Eligible customers can transfer a license once under enforced limits, while duplicate, unauthorized, or replayed transfers are rejected and audited.', NULL),
        ('UPE-128', NULL, 'Backlog', 'Enterprise Team Management', 'Planned', 1128,
         'Allow Enterprise organizations to share portal responsibilities safely.',
         'Invite and remove users; assign Owner, Administrator, Technician, Billing, and Read Only roles; enforce least privilege, MFA policy, and organization-scoped audit history.',
         'User Portal Enhancement. Enterprise customers need delegated access without sharing one account or exposing unrelated controls.',
         'Each role can perform only its authorized actions, ownership transfers are protected, and organization access is fully auditable.', NULL),
        ('UPE-129', NULL, 'Backlog', 'Customer Notification Center', 'Planned', 1129,
         'Centralize important customer notices inside the portal.',
         'Display software updates, maintenance reminders, security alerts, purchase confirmations, support responses, and license changes with read state, priority, expiration, and destination links.',
         'User Portal Enhancement. An in-portal inbox reduces dependence on email delivery and keeps actionable notices discoverable.',
         'Customers receive each eligible notification once, can mark it read, and can open the correct secure destination without exposing sensitive content.', NULL),
        ('UPE-130', NULL, 'Backlog', 'Release and Update Center', 'Planned', 1130,
         'Turn Downloads into a complete entitlement-aware software release center.',
         'Show release notes, release date, installed and latest versions, known issues, eligible previous versions, Maintenance and Support eligibility, and trusted download or renewal actions.',
         'User Portal Enhancement. A consistent update center helps customers understand what they can install and why.',
         'The portal offers only entitled, integrity-verified installers and accurately explains current, available, behind, and renewal-required states.', NULL),
        ('UPE-131', NULL, 'Backlog', 'Purchase and Billing History', 'Released', 1131,
         'Give customers a complete financial record of their product ownership.',
         'Show purchases, upgrades, maintenance renewals, payment status, license association, downloadable receipts, and transaction references without exposing sensitive payment credentials.',
         'User Portal Enhancement. Clear billing history reduces purchase confusion and supports customer recordkeeping.',
         'Customers can reconcile every completed or pending transaction with the correct license and download a consistent receipt.', '2026-07-24 00:00:00.000000'),
        ('UPE-132', NULL, 'Backlog', 'Portal Activity Timeline', 'Planned', 1132,
         'Present important account, license, device, purchase, support, and security events in one chronological view.',
         'Provide filterable events for activations, transfers, downloads, purchases, renewals, support requests, password and MFA changes, and administrative actions visible to the customer.',
         'User Portal Enhancement. A unified timeline makes account changes understandable and improves troubleshooting and trust.',
         'Customers can filter and review accurate, privacy-safe events while protected secrets and internal-only administrative details remain hidden.', NULL)
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
         WHEN 'v0.3.35' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.35'
         WHEN 'v0.3.36' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.36'
         WHEN 'v0.3.37' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.37'
         WHEN 'v0.3.38' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.38'
         WHEN 'v0.3.39' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.39'
         WHEN 'v0.3.40' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.40'
         WHEN 'v0.3.41' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.41'
         WHEN 'v0.3.42' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.42'
         WHEN 'v0.3.43' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.43'
         WHEN 'v0.3.44' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.44'
         WHEN 'v0.3.45' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.45'
         WHEN 'v0.3.46' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.46'
         WHEN 'v0.3.47' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.47'
         WHEN 'v0.3.48' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.48'
         WHEN 'v0.3.49' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.49'
         WHEN 'v0.3.50' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.50'
         WHEN 'v0.3.51' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/58'
         WHEN 'v0.3.52' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/32'
         WHEN 'v0.3.53' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/33'
         WHEN 'v0.3.54' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/34'
         WHEN 'v0.3.55' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/35'
         WHEN 'v0.3.56' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/36'
         WHEN 'v0.3.57' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/37'
         WHEN 'v0.3.58' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/21'
         WHEN 'v0.3.59' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/40'
         WHEN 'v0.3.30' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.30'
         WHEN 'v0.3.31' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.31'
         WHEN 'v0.3.32' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.32'
         WHEN 'BACKLOG-007' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/9'
         WHEN 'BACKLOG-008' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/12'
         ELSE NULL
     END
     WHERE item_key IN ('v0.3.20', 'v0.3.21', 'v0.3.22', 'v0.3.23', 'v0.3.24', 'v0.3.25', 'v0.3.26', 'v0.3.30', 'v0.3.31', 'v0.3.32', 'v0.3.33', 'v0.3.34', 'v0.3.35', 'v0.3.36', 'v0.3.37', 'v0.3.38', 'v0.3.39', 'v0.3.40', 'v0.3.41', 'v0.3.42', 'v0.3.43', 'v0.3.44', 'v0.3.45', 'v0.3.46', 'v0.3.47', 'v0.3.48', 'v0.3.49', 'v0.3.50', 'v0.3.51', 'v0.3.52', 'v0.3.53', 'v0.3.54', 'v0.3.55', 'v0.3.56', 'v0.3.57', 'v0.3.58', 'v0.3.59', 'BACKLOG-007', 'BACKLOG-008')"
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
         '2026-07-20 00:00:00.000000'),
        ('BUG-014', 'Windows added a ZIP suffix to configuration backups',
         'Medium', 'Released', 'v0.3.34', 'v0.3.35', 'v0.3.35',
         'Customers could not extract the encrypted backup in Windows, and the restore picker rejected the resulting .ppebackup.zip name.',
         'Backups should retain the native .ppebackup name, and existing v0.3.34 backup names should restore without extraction.',
         'The desktop save filter did not recognize .ppebackup, so Windows appended .zip and the API accepted only the final extension.',
         'Create a configuration backup in v0.3.34, then select the generated .ppebackup.zip file for restore.',
         'The save dialog now uses .ppebackup directly; both native and legacy names pass validation; all 158 tests and the complete rendered restore workflow pass.',
         UTC_TIMESTAMP(6)),
        ('BUG-015', 'Trial welcome and included listener were difficult to find',
         'Medium', 'Released', 'v0.3.37', 'v0.3.38', 'v0.3.38',
         'Trial customers could dismiss the welcome guide permanently and then saw only an upgrade panel instead of the included listener connection details.',
         'Trial setup should remain reopenable and show one read-only listener with exact local and LAN connection targets.',
         'A persistent v1 completion flag hid the guide, while the single-license Printer Listeners page returned early to an upgrade-only panel.',
         'Dismiss the v0.3.37 welcome guide, reopen the application, then open Settings and select Printer Listeners.',
         'The v2 guide is reopenable from the header; the listener is readable without edit controls; the server rejects Trial changes with HTTP 403; the production viewer builds and all 166 desktop tests pass.',
         NULL),
        ('BUG-016', 'Installer wizard stretched the square product logo',
         'Low', 'Released', 'v0.3.40', 'v0.3.41', 'v0.3.41',
         'The installer looked visually unpolished because the product mark appeared too tall and cramped on its welcome and completion pages.',
         'The installer should preserve the official square logo proportions inside a purpose-built tall wizard banner.',
         'The same square PNG was assigned to both the square header image and Inno Setup tall wizard image, so the wizard stretched it to fill a 164:314 panel.',
         'Open the v0.3.40 installer and compare the welcome or completion banner with the official square product icon.',
         'A separate 656x1256 banner preserves the logo proportions; the square header remains independent; build validation rejects an invalid ratio; and Inno Setup 6.7.1 compiles the corrected installer.',
         UTC_TIMESTAMP(6))
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
         WHEN 'BUG-016' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/43'
         ELSE github_url
     END
     WHERE bug_key IN ('BUG-009', 'BUG-010', 'BUG-011', 'BUG-012', 'BUG-016')"
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
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/customers.php"><span aria-hidden="true">◎</span>Customers</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/communications.php"><span aria-hidden="true">✉</span>Communications</a><a class="active" href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>GitHub and Dev Support statuses must stay aligned.</p></aside>
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
<article class="roadmap-card"><header><div><span class="item-key"><?= e((string)$item['item_key']) ?></span><?php if (str_starts_with((string)$item['item_key'], 'UPE-')): ?><span class="eyebrow backlog">User Portal Enhancement</span><?php else: ?><span class="eyebrow backlog">Priority <?= (int)$item['priority_rank'] - 1000 ?></span><?php endif; ?><h3><?= e($item['title']) ?></h3></div><span class="tracker-status <?= e(strtolower(str_replace(' ', '-', $item['status']))) ?>"><?= e($item['status']) ?></span></header><p class="purpose"><?= e($item['purpose']) ?></p><details><summary>Detailed scope and priority reason</summary><h4>Proposed scope</h4><ul><?php foreach (lines($item['planned_scope']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><h4>Why this priority</h4><p><?= e($item['priority_reason']) ?></p><h4>Complete when</h4><p><?= e($item['completion_criteria']) ?></p></details><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-roadmap"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><label>Status<select name="status"><?php foreach ($roadmapStatuses as $status): ?><option <?= $item['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Save status</button></form></article>
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
