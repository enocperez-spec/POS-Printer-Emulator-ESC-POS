<?php
declare(strict_types=1);

require_once __DIR__ . '/data_protection.php';

function ensure_license_management_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $lock = $pdo->query("SELECT GET_LOCK('ppe_license_management_schema_v2', 10)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('The License Manager database upgrade is busy. Try again shortly.');
    }
    try {
        $installationColumns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM installations')->fetchAll() as $column) {
            $installationColumns[(string)$column['Field']] = true;
        }
        if (!isset($installationColumns['maintenance_status'])) {
            $pdo->exec("ALTER TABLE installations ADD COLUMN maintenance_status ENUM('NotApplicable', 'Active', 'Expired', 'Revoked') NOT NULL DEFAULT 'NotApplicable' AFTER license_id");
        } else {
            $maintenanceStatusColumn = $pdo->query("SHOW COLUMNS FROM installations LIKE 'maintenance_status'")->fetch();
            if ($maintenanceStatusColumn && !str_contains((string)$maintenanceStatusColumn['Type'], "'Revoked'")) {
                $pdo->exec("ALTER TABLE installations MODIFY maintenance_status ENUM('NotApplicable', 'Active', 'Expired', 'Revoked') NOT NULL DEFAULT 'NotApplicable'");
            }
        }
        if (!isset($installationColumns['maintenance_expires_at'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER maintenance_status');
        }

        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM issued_licenses')->fetchAll() as $column) {
            $columns[(string)$column['Field']] = true;
        }

        $additions = [
            'control_state' => "ALTER TABLE issued_licenses ADD COLUMN control_state ENUM('Enabled', 'Deactivated', 'Revoked', 'Deleted') NOT NULL DEFAULT 'Enabled' AFTER created_by",
            'deactivated_at' => 'ALTER TABLE issued_licenses ADD COLUMN deactivated_at DATETIME(6) NULL AFTER control_state',
            'deleted_at' => 'ALTER TABLE issued_licenses ADD COLUMN deleted_at DATETIME(6) NULL AFTER revoked_at',
            'superseded_by_license_id' => 'ALTER TABLE issued_licenses ADD COLUMN superseded_by_license_id CHAR(36) NULL AFTER deleted_at',
            'license_source' => "ALTER TABLE issued_licenses ADD COLUMN license_source ENUM('Manual', 'Purchase') NOT NULL DEFAULT 'Manual' AFTER superseded_by_license_id",
            'source_reference' => 'ALTER TABLE issued_licenses ADD COLUMN source_reference VARCHAR(64) NULL AFTER license_source',
            'maintenance_expires_at' => 'ALTER TABLE issued_licenses ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER source_reference',
            'maintenance_revoked_at' => 'ALTER TABLE issued_licenses ADD COLUMN maintenance_revoked_at DATETIME(6) NULL AFTER maintenance_expires_at',
            'row_version' => 'ALTER TABLE issued_licenses ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER maintenance_revoked_at',
            'updated_at' => 'ALTER TABLE issued_licenses ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at',
            'activation_key_ciphertext' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_ciphertext VARBINARY(768) NULL AFTER activation_key',
            'activation_key_nonce' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_nonce BINARY(12) NULL AFTER activation_key_ciphertext',
            'activation_key_tag' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_tag BINARY(16) NULL AFTER activation_key_nonce',
            'activation_key_fingerprint' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_fingerprint BINARY(32) NULL AFTER activation_key_nonce',
            'activation_key_ending' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_ending CHAR(4) NULL AFTER activation_key_fingerprint',
        ];
        foreach ($additions as $name => $statement) {
            if (!isset($columns[$name])) {
                $pdo->exec($statement);
            }
        }

        $indexes = [];
        foreach ($pdo->query('SHOW INDEX FROM issued_licenses')->fetchAll() as $index) {
            $indexes[(string)$index['Key_name']] = true;
        }
        if (!isset($indexes['ix_issued_licenses_control_state'])) {
            $pdo->exec('ALTER TABLE issued_licenses ADD KEY ix_issued_licenses_control_state (control_state)');
        }
        if (!isset($indexes['ix_issued_licenses_source_reference'])) {
            $pdo->exec('ALTER TABLE issued_licenses ADD KEY ix_issued_licenses_source_reference (source_reference)');
        }

        $pdo->exec(
            "UPDATE issued_licenses
             SET control_state = 'Revoked'
             WHERE revoked_at IS NOT NULL AND control_state = 'Enabled'"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS issued_license_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                license_id CHAR(36) NOT NULL,
                customer_name VARCHAR(160) NOT NULL,
                email_address VARCHAR(254) NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                previous_state VARCHAR(24) NULL,
                new_state VARCHAR(24) NULL,
                previous_tier VARCHAR(24) NULL,
                new_tier VARCHAR(24) NULL,
                replacement_license_id CHAR(36) NULL,
                reason VARCHAR(500) NULL,
                performed_by VARCHAR(80) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_license_events_license_id (license_id),
                KEY ix_license_events_created_at (created_at),
                KEY ix_license_events_event_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS license_maintenance_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                license_id CHAR(36) NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                previous_expires_at DATETIME(6) NULL,
                new_expires_at DATETIME(6) NULL,
                source_reference VARCHAR(80) NULL,
                reason VARCHAR(500) NULL,
                performed_by VARCHAR(80) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uq_license_maintenance_source (license_id, source_reference),
                KEY ix_license_maintenance_license (license_id),
                KEY ix_license_maintenance_created (created_at),
                KEY ix_license_maintenance_event (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS maintenance_refresh_rate_limits (
                bucket_hash BINARY(32) NOT NULL,
                hits INT UNSIGNED NOT NULL,
                reset_at DATETIME(6) NOT NULL,
                PRIMARY KEY (bucket_hash),
                KEY ix_maintenance_rate_reset (reset_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "UPDATE issued_licenses
             SET maintenance_expires_at = '2027-07-19 23:59:59.000000'
             WHERE maintenance_expires_at IS NULL"
        );
        $pdo->exec(
            "INSERT IGNORE INTO license_maintenance_events
                (license_id, event_type, new_expires_at, source_reference, reason, performed_by, created_at)
             SELECT license_id, 'LEGACY_GRANDFATHERED', maintenance_expires_at,
                    CONCAT('grandfather:', license_id),
                    'Existing paid license granted maintenance through July 19, 2027.',
                    'schema-migration', UTC_TIMESTAMP(6)
             FROM issued_licenses
             WHERE maintenance_expires_at = '2027-07-19 23:59:59.000000'"
        );
        $pdo->exec(
            "INSERT INTO issued_license_events
                (license_id, customer_name, email_address, event_type, new_state, new_tier, reason, performed_by, created_at)
             SELECT l.license_id, l.customer_name, l.email_address, 'LEGACY_IMPORTED', l.control_state,
                    l.license_tier, 'Existing license imported when lifecycle auditing was enabled.', 'schema-migration', l.created_at
             FROM issued_licenses l
             WHERE NOT EXISTS (
                 SELECT 1 FROM issued_license_events e WHERE e.license_id = l.license_id
             )"
        );
        $ready = true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ppe_license_management_schema_v2')")->fetchColumn();
    }
}

function canonical_license_uuid(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
        throw new InvalidArgumentException('The selected license ID is invalid.');
    }
    return $value;
}

function canonical_installation_uuid(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
        throw new InvalidArgumentException('The selected installation ID is invalid.');
    }
    return $value;
}

function canonical_paid_tier(string $value): string
{
    if (!in_array($value, ['Lite', 'Pro', 'Enterprise'], true)) {
        throw new InvalidArgumentException('Choose Lite, Pro, or Enterprise.');
    }
    return $value;
}

function maintenance_registration_digest(string $customerName, string $emailAddress): string
{
    $customer = strtoupper(trim(preg_replace('/[ \t\r\n\f\v]+/', ' ', $customerName) ?? '', " \t\r\n\f\v"));
    $email = strtolower(trim($emailAddress, " \t\r\n\f\v"));
    return hash('sha256', $customer . "\n" . $email);
}

function maintenance_status(array $license, ?DateTimeImmutable $now = null): string
{
    if (!hash_equals('Enabled', (string)($license['control_state'] ?? '')) ||
        !empty($license['maintenance_revoked_at'])) {
        return 'revoked';
    }
    $expiresAt = trim((string)($license['maintenance_expires_at'] ?? ''));
    if ($expiresAt === '') {
        return 'expired';
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiration = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
    return $expiration >= $now ? 'active' : 'expired';
}

function calculate_maintenance_renewal_expiration(string $currentExpiresAt, string $capturedAt): string
{
    $utc = new DateTimeZone('UTC');
    try {
        $current = new DateTimeImmutable($currentExpiresAt, $utc);
        $captured = new DateTimeImmutable($capturedAt, $utc);
    } catch (Throwable) {
        throw new InvalidArgumentException('The maintenance renewal date is invalid.');
    }
    $base = $current > $captured ? $current : $captured;
    return $base->modify('+1 year')->setTimezone($utc)->format('Y-m-d H:i:s');
}

function record_maintenance_event(
    PDO $pdo,
    string $licenseId,
    string $eventType,
    string $actor,
    ?string $previousExpiresAt,
    ?string $newExpiresAt,
    ?string $sourceReference = null,
    ?string $reason = null
): void {
    $statement = $pdo->prepare(
        'INSERT INTO license_maintenance_events
            (license_id, event_type, previous_expires_at, new_expires_at, source_reference, reason, performed_by)
         VALUES
            (:license_id, :event_type, :previous_expires_at, :new_expires_at, :source_reference, :reason, :performed_by)'
    );
    $statement->execute([
        'license_id' => canonical_license_uuid($licenseId),
        'event_type' => substr($eventType, 0, 40),
        'previous_expires_at' => $previousExpiresAt,
        'new_expires_at' => $newExpiresAt,
        'source_reference' => $sourceReference !== null ? substr($sourceReference, 0, 80) : null,
        'reason' => $reason !== null ? substr($reason, 0, 500) : null,
        'performed_by' => substr($actor !== '' ? $actor : 'owner', 0, 80),
    ]);
}

function record_license_event(PDO $pdo, array $license, string $eventType, string $actor, array $changes = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO issued_license_events
            (license_id, customer_name, email_address, event_type, previous_state, new_state,
             previous_tier, new_tier, replacement_license_id, reason, performed_by)
         VALUES
            (:license_id, :customer_name, :email_address, :event_type, :previous_state, :new_state,
             :previous_tier, :new_tier, :replacement_license_id, :reason, :performed_by)'
    );
    $statement->execute([
        'license_id' => canonical_license_uuid((string)$license['license_id']),
        'customer_name' => (string)$license['customer_name'],
        'email_address' => (string)$license['email_address'],
        'event_type' => $eventType,
        'previous_state' => $changes['previous_state'] ?? null,
        'new_state' => $changes['new_state'] ?? null,
        'previous_tier' => $changes['previous_tier'] ?? null,
        'new_tier' => $changes['new_tier'] ?? null,
        'replacement_license_id' => $changes['replacement_license_id'] ?? null,
        'reason' => $changes['reason'] ?? null,
        'performed_by' => substr($actor !== '' ? $actor : 'owner', 0, 80),
    ]);
}

function insert_issued_license(
    PDO $pdo,
    array $issued,
    string $actor,
    string $source = 'Manual',
    ?string $sourceReference = null,
    string $eventType = 'ISSUED',
    string $maintenanceEventType = 'INITIAL_INCLUDED',
    ?string $maintenanceReason = null
): void {
    if (!in_array($source, ['Manual', 'Purchase'], true)) {
        throw new InvalidArgumentException('The license source is invalid.');
    }
    $activationKey = (string)$issued['activation_key'];
    $protectedKey = protect_activation_key($activationKey);
    $statement = $pdo->prepare(
        'INSERT INTO issued_licenses
            (license_id, customer_name, email_address, license_tier, activation_key, activation_key_ciphertext,
             activation_key_nonce, activation_key_tag, activation_key_fingerprint, activation_key_ending, issued_at,
             created_by, control_state, license_source, source_reference, maintenance_expires_at)
         VALUES
            (:license_id, :customer_name, :email_address, :license_tier, :activation_key, :activation_key_ciphertext,
             :activation_key_nonce, :activation_key_tag, UNHEX(SHA2(:activation_key_digest_value,256)), :activation_key_ending, :issued_at,
             :created_by, \'Enabled\', :license_source, :source_reference, :maintenance_expires_at)'
    );
    $statement->execute([
        'license_id' => canonical_license_uuid((string)$issued['license_id']),
        'customer_name' => (string)$issued['customer_name'],
        'email_address' => (string)$issued['email_address'],
        'license_tier' => canonical_paid_tier((string)$issued['license_tier']),
        'activation_key' => $protectedKey['plaintext'],
        'activation_key_ciphertext' => $protectedKey['ciphertext'],
        'activation_key_nonce' => $protectedKey['nonce'],
        'activation_key_tag' => $protectedKey['tag'],
        'activation_key_digest_value' => $activationKey,
        'activation_key_ending' => crm_activation_key_ending_compat($activationKey),
        'issued_at' => (string)$issued['issued_at'],
        'created_by' => substr($actor !== '' ? $actor : 'owner', 0, 80),
        'license_source' => $source,
        'source_reference' => $sourceReference,
        'maintenance_expires_at' => (string)($issued['maintenance_expires_at'] ??
            (new DateTimeImmutable((string)$issued['issued_at'], new DateTimeZone('UTC')))->modify('+1 year')->format('Y-m-d H:i:s')),
    ]);
    record_license_event($pdo, $issued, $eventType, $actor, [
        'new_state' => 'Enabled',
        'new_tier' => (string)$issued['license_tier'],
    ]);
    record_maintenance_event(
        $pdo,
        (string)$issued['license_id'],
        $maintenanceEventType,
        $actor,
        null,
        (string)($issued['maintenance_expires_at'] ??
            (new DateTimeImmutable((string)$issued['issued_at'], new DateTimeZone('UTC')))->modify('+1 year')->format('Y-m-d H:i:s')),
        'initial:' . canonical_license_uuid((string)$issued['license_id']),
        $maintenanceReason ?? 'One year of Application Maintenance and Support included with the permanent license.'
    );
}

function find_license_for_update(PDO $pdo, string $licenseId): array
{
    $statement = $pdo->prepare(
        'SELECT license_id, customer_name, email_address, license_tier, issued_at,
                control_state, deactivated_at, revoked_at, deleted_at, superseded_by_license_id,
                license_source, source_reference, maintenance_expires_at, maintenance_revoked_at, row_version
         FROM issued_licenses WHERE license_id = :license_id FOR UPDATE'
    );
    $statement->execute(['license_id' => canonical_license_uuid($licenseId)]);
    $license = $statement->fetch();
    if (!is_array($license)) {
        throw new DomainException('The selected license no longer exists.');
    }
    return $license;
}

function verify_license_row_version(array $license, int $expectedVersion): void
{
    if ($expectedVersion < 1 || (int)$license['row_version'] !== $expectedVersion) {
        throw new DomainException('This license changed after the page loaded. Refresh and review it again.');
    }
}

function unlink_license_installations(PDO $pdo, string $licenseId): int
{
    $statement = $pdo->prepare(
        "UPDATE installations SET license_mode = 'Trial', license_id = NULL WHERE license_id = :license_id"
    );
    $statement->execute(['license_id' => canonical_license_uuid($licenseId)]);
    return $statement->rowCount();
}

function manage_issued_license(
    PDO $pdo,
    string $action,
    string $licenseId,
    int $expectedVersion,
    string $actor,
    callable $keyIssuer,
    ?string $targetTier = null,
    string $reason = ''
): array {
    if (!in_array($action, [
        'change_tier', 'deactivate', 'reactivate', 'revoke', 'delete',
        'extend_maintenance', 'revoke_maintenance', 'restore_maintenance',
    ], true)) {
        throw new InvalidArgumentException('The requested license action is invalid.');
    }
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    if (strlen($reason) > 500) {
        throw new InvalidArgumentException('The reason must be 500 characters or fewer.');
    }
    if (stripos($reason, 'PPE1-') !== false) {
        throw new InvalidArgumentException('Do not include an activation key in the reason.');
    }
    if (in_array($action, ['revoke', 'delete'], true) && strlen($reason) < 3) {
        throw new InvalidArgumentException('Enter a brief reason before continuing.');
    }

    $pdo->beginTransaction();
    try {
        $license = find_license_for_update($pdo, $licenseId);
        verify_license_row_version($license, $expectedVersion);
        $state = (string)$license['control_state'];
        $tier = (string)$license['license_tier'];
        $result = ['issued' => null, 'message' => ''];

        if ($action === 'change_tier') {
            $newTier = canonical_paid_tier((string)$targetTier);
            if ($state !== 'Enabled') {
                throw new DomainException('Only an enabled license can be changed to another level.');
            }
            if ($newTier === $tier) {
                throw new DomainException("This is already a {$tier} license.");
            }
            if (!empty($license['maintenance_revoked_at'])) {
                throw new DomainException('Restore maintenance before changing this license level.');
            }
            $replacement = $keyIssuer(
                (string)$license['customer_name'],
                (string)$license['email_address'],
                $newTier,
                (string)$license['maintenance_expires_at']
            );
            insert_issued_license(
                $pdo,
                $replacement,
                $actor,
                (string)$license['license_source'],
                $license['source_reference'] !== null ? (string)$license['source_reference'] : null,
                'REPLACEMENT_ISSUED',
                'COVERAGE_TRANSFERRED',
                'Existing Application Maintenance and Support coverage transferred to the replacement license.'
            );
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET control_state = 'Revoked', revoked_at = UTC_TIMESTAMP(6),
                        deactivated_at = NULL, superseded_by_license_id = :replacement, row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute([
                'replacement' => $replacement['license_id'],
                'license_id' => $license['license_id'],
                'row_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before the replacement could be saved.');
            }
            unlink_license_installations($pdo, (string)$license['license_id']);
            record_license_event($pdo, $license, 'TIER_REPLACED', $actor, [
                'previous_state' => 'Enabled',
                'new_state' => 'Revoked',
                'previous_tier' => $tier,
                'new_tier' => $newTier,
                'replacement_license_id' => $replacement['license_id'],
                'reason' => $reason !== '' ? $reason : 'License level changed by the Admin Portal.',
            ]);
            $result['issued'] = $replacement;
            $result['message'] = "A replacement {$newTier} key was generated. The customer must enter the new key in the application.";
        } elseif ($action === 'extend_maintenance') {
            if ($state !== 'Enabled') {
                throw new DomainException('Maintenance can be extended only for an enabled permanent license.');
            }
            if (!empty($license['maintenance_revoked_at'])) {
                throw new DomainException('Restore maintenance before extending its coverage period.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $previous = (string)$license['maintenance_expires_at'];
            $newExpiration = calculate_maintenance_renewal_expiration($previous, $now);
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET maintenance_expires_at = :expiration,
                        row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['expiration'=>$newExpiration,'license_id'=>$license['license_id'],'row_version'=>$expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before maintenance could be extended.');
            }
            record_maintenance_event($pdo,(string)$license['license_id'],'MANUAL_EXTENDED',$actor,$previous,$newExpiration,null,$reason ?: 'Extended one year in the Admin Portal.');
            $result['message'] = 'Application Maintenance and Support was extended through ' . $newExpiration . ' UTC.';
        } elseif ($action === 'revoke_maintenance') {
            if ($state !== 'Enabled' || !empty($license['maintenance_revoked_at'])) {
                throw new DomainException('Maintenance is already unavailable for this license.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET maintenance_revoked_at = UTC_TIMESTAMP(6), row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id'=>$license['license_id'],'row_version'=>$expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before maintenance could be revoked.');
            }
            record_maintenance_event($pdo,(string)$license['license_id'],'MAINTENANCE_REVOKED',$actor,(string)$license['maintenance_expires_at'],(string)$license['maintenance_expires_at'],null,$reason ?: 'Maintenance access revoked in the Admin Portal.');
            $result['message'] = 'Updates and technical support were revoked. The permanent license remains active.';
        } elseif ($action === 'restore_maintenance') {
            if ($state !== 'Enabled' || empty($license['maintenance_revoked_at'])) {
                throw new DomainException('Maintenance is not revoked for this license.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET maintenance_revoked_at = NULL, row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id'=>$license['license_id'],'row_version'=>$expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before maintenance could be restored.');
            }
            record_maintenance_event($pdo,(string)$license['license_id'],'MAINTENANCE_RESTORED',$actor,(string)$license['maintenance_expires_at'],(string)$license['maintenance_expires_at'],null,$reason ?: 'Maintenance access restored in the Admin Portal.');
            $result['message'] = 'Maintenance access was restored through the existing expiration date.';
        } elseif ($action === 'deactivate') {
            if ($state !== 'Enabled') {
                throw new DomainException('Only an enabled license can be deactivated.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET control_state = 'Deactivated', deactivated_at = UTC_TIMESTAMP(6),
                        row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be deactivated.');
            }
            unlink_license_installations($pdo, (string)$license['license_id']);
            record_license_event($pdo, $license, 'DEACTIVATED', $actor, [
                'previous_state' => 'Enabled', 'new_state' => 'Deactivated',
                'previous_tier' => $tier, 'new_tier' => $tier, 'reason' => $reason ?: null,
            ]);
            $result['message'] = 'The license was deactivated in the Admin Portal and linked server registrations were returned to Trial.';
        } elseif ($action === 'reactivate') {
            if ($state !== 'Deactivated') {
                throw new DomainException('Only a deactivated license can be reactivated.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET control_state = 'Enabled', deactivated_at = NULL,
                        row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be reactivated.');
            }
            record_license_event($pdo, $license, 'REACTIVATED', $actor, [
                'previous_state' => 'Deactivated', 'new_state' => 'Enabled',
                'previous_tier' => $tier, 'new_tier' => $tier, 'reason' => $reason ?: null,
            ]);
            $result['message'] = 'The license was reactivated in the Admin Portal.';
        } elseif ($action === 'revoke') {
            if (!in_array($state, ['Enabled', 'Deactivated'], true)) {
                throw new DomainException('This license cannot be revoked from its current state.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET control_state = 'Revoked', revoked_at = UTC_TIMESTAMP(6),
                        deactivated_at = NULL, row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be revoked.');
            }
            unlink_license_installations($pdo, (string)$license['license_id']);
            record_license_event($pdo, $license, 'REVOKED', $actor, [
                'previous_state' => $state, 'new_state' => 'Revoked',
                'previous_tier' => $tier, 'new_tier' => $tier, 'reason' => $reason,
            ]);
            $result['message'] = 'The license was permanently revoked in the Admin Portal.';
        } else {
            if ($state === 'Deleted') {
                throw new DomainException('This license is already deleted.');
            }
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET control_state = 'Deleted', deleted_at = UTC_TIMESTAMP(6),
                        row_version = row_version + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be deleted.');
            }
            unlink_license_installations($pdo, (string)$license['license_id']);
            record_license_event($pdo, $license, 'DELETED', $actor, [
                'previous_state' => $state, 'new_state' => 'Deleted',
                'previous_tier' => $tier, 'new_tier' => $tier, 'reason' => $reason,
            ]);
            $result['message'] = 'The license was deleted from normal License Manager views. Its audit record was retained.';
        }

        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function upgrade_trial_installation(
    PDO $pdo,
    string $installationUuid,
    string $targetTier,
    string $actor,
    callable $keyIssuer
): array {
    $targetTier = canonical_paid_tier($targetTier);
    $installationUuid = canonical_installation_uuid($installationUuid);
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            'SELECT installation_uuid, customer_name, email_address, license_mode
             FROM installations WHERE installation_uuid = :uuid FOR UPDATE'
        );
        $find->execute(['uuid' => $installationUuid]);
        $installation = $find->fetch();
        if (!is_array($installation) || (string)$installation['license_mode'] !== 'Trial') {
            throw new DomainException('The selected Trial installation is no longer available for upgrade.');
        }
        $sourceReference = 'trial:' . $installationUuid;
        $pending = $pdo->prepare(
            "SELECT 1 FROM issued_licenses
             WHERE source_reference = :source_reference
               AND control_state IN ('Enabled', 'Deactivated')
             LIMIT 1"
        );
        $pending->execute(['source_reference' => $sourceReference]);
        if ($pending->fetchColumn() !== false) {
            throw new DomainException('An upgrade key has already been issued for this Trial installation.');
        }
        $issued = $keyIssuer((string)$installation['customer_name'], (string)$installation['email_address'], $targetTier);
        insert_issued_license($pdo, $issued, $actor, 'Manual', $sourceReference, 'TRIAL_UPGRADE_ISSUED');
        record_license_event($pdo, $issued, 'TRIAL_INSTALLATION_SELECTED', $actor, [
            'previous_state' => 'Trial',
            'new_state' => 'Enabled',
            'new_tier' => $targetTier,
            'reason' => 'Upgrade key issued for installation ' . $installationUuid,
        ]);
        $pdo->commit();
        return $issued;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function apply_paid_maintenance_renewal(
    PDO $pdo,
    string $licenseId,
    string $licenseTier,
    string $registrationDigest,
    string $capturedAt,
    string $sourceReference,
    string $actor = 'purchase-renewal'
): array {
    $licenseTier = canonical_paid_tier($licenseTier);
    $licenseId = canonical_license_uuid($licenseId);
    $registrationDigest = strtolower(trim($registrationDigest));
    if (!preg_match('/^[0-9a-f]{64}$/', $registrationDigest)) {
        throw new InvalidArgumentException('The renewal registration digest is invalid.');
    }
    $sourceReference = trim($sourceReference);
    if ($sourceReference === '' || strlen($sourceReference) > 80 || !preg_match('/^[A-Za-z0-9:_-]+$/', $sourceReference)) {
        throw new InvalidArgumentException('The renewal order reference is invalid.');
    }

    $pdo->beginTransaction();
    try {
        $license = find_license_for_update($pdo, $licenseId);
        if (!hash_equals($registrationDigest, maintenance_registration_digest((string)$license['customer_name'], (string)$license['email_address'])) ||
            !hash_equals($licenseTier, (string)$license['license_tier'])) {
            throw new DomainException('The renewal details do not match an issued license.');
        }
        if (!hash_equals('Enabled', (string)$license['control_state'])) {
            throw new DomainException('This permanent license is not eligible for maintenance renewal.');
        }
        if (!empty($license['maintenance_revoked_at'])) {
            throw new DomainException('Maintenance was revoked by the Admin Portal. Restore it before accepting a renewal.');
        }

        $existing = $pdo->prepare(
            'SELECT new_expires_at FROM license_maintenance_events
             WHERE license_id = :license_id AND source_reference = :source_reference LIMIT 1'
        );
        $existing->execute(['license_id'=>$licenseId,'source_reference'=>$sourceReference]);
        $existingExpiration = $existing->fetchColumn();
        if (is_string($existingExpiration) && $existingExpiration !== '') {
            $pdo->commit();
            return [
                'license_id'=>$licenseId,
                'license_tier'=>$licenseTier,
                'maintenance_expires_at'=>$existingExpiration,
                'maintenance_token'=>issue_maintenance_token($licenseId,$licenseTier,$existingExpiration),
                'idempotent'=>true,
            ];
        }

        try {
            $captureTime = (new DateTimeImmutable($capturedAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InvalidArgumentException('The verified payment time is invalid.');
        }
        if ($captureTime > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('The verified payment time cannot be in the future.');
        }
        $previous = (string)$license['maintenance_expires_at'];
        $newExpiration = calculate_maintenance_renewal_expiration($previous,$captureTime->format('Y-m-d H:i:s'));
        $update = $pdo->prepare(
            'UPDATE issued_licenses
             SET maintenance_expires_at = :expiration, row_version = row_version + 1
             WHERE license_id = :license_id'
        );
        $update->execute(['expiration'=>$newExpiration,'license_id'=>$licenseId]);
        record_maintenance_event(
            $pdo,$licenseId,'PAID_RENEWAL',$actor,$previous,$newExpiration,$sourceReference,
            'One-time annual Application Maintenance and Support renewal.'
        );
        $pdo->commit();
        return [
            'license_id'=>$licenseId,
            'license_tier'=>$licenseTier,
            'maintenance_expires_at'=>$newExpiration,
            'maintenance_token'=>issue_maintenance_token($licenseId,$licenseTier,$newExpiration),
            'idempotent'=>false,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sync_purchase_licenses(PDO $pdo, array $licenses, string $actor = 'purchase-sync'): int
{
    $imported = 0;
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare(
            'SELECT customer_name, email_address, license_tier, activation_key, activation_key_ciphertext, activation_key_nonce, activation_key_tag, license_source, source_reference,
                    maintenance_expires_at, maintenance_revoked_at
             FROM issued_licenses WHERE license_id = :license_id'
        );
        foreach ($licenses as $license) {
            if (!is_array($license)) {
                continue;
            }
            $licenseId = canonical_license_uuid((string)($license['license_id'] ?? ''));
            $issued = [
                'license_id' => $licenseId,
                'customer_name' => trim((string)($license['customer_name'] ?? '')),
                'email_address' => strtolower(trim((string)($license['email_address'] ?? ''))),
                'license_tier' => canonical_paid_tier((string)($license['license_tier'] ?? '')),
                'activation_key' => (string)($license['activation_key'] ?? ''),
                'issued_at' => (string)($license['issued_at'] ?? gmdate('Y-m-d H:i:s')),
                'maintenance_expires_at' => (string)($license['maintenance_expires_at'] ?? '2027-07-19 23:59:59'),
            ];
            if ($issued['customer_name'] === '' || $issued['activation_key'] === '') {
                throw new InvalidArgumentException('The Buy website returned an incomplete license record.');
            }
            validate_activation_key_record($issued);
            $sourceReference = substr((string)($license['order_reference'] ?? ''), 0, 64) ?: null;
            $exists->execute(['license_id' => $licenseId]);
            $existing = $exists->fetch();
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['customer_name'], $issued['customer_name']) ||
                    !hash_equals((string)$existing['email_address'], $issued['email_address']) ||
                    !hash_equals((string)$existing['license_tier'], $issued['license_tier']) ||
                    !hash_equals(reveal_activation_key($existing), $issued['activation_key'])) {
                    throw new DomainException('A purchase license conflicts with an existing License Manager record.');
                }
                if ((string)$existing['maintenance_expires_at'] < $issued['maintenance_expires_at']) {
                    $updateCoverage = $pdo->prepare(
                        'UPDATE issued_licenses SET maintenance_expires_at = :expiration,
                                row_version = row_version + 1
                         WHERE license_id = :license_id'
                    );
                    $updateCoverage->execute(['expiration'=>$issued['maintenance_expires_at'],'license_id'=>$licenseId]);
                    record_maintenance_event(
                        $pdo,$licenseId,'PURCHASE_SYNC_UPDATED',$actor,(string)$existing['maintenance_expires_at'],
                        $issued['maintenance_expires_at'],'purchase-sync:' . substr((string)($license['order_reference'] ?? $licenseId),0,60),
                        'Maintenance entitlement refreshed from the verified purchase service.'
                    );
                }
                continue;
            }
            insert_issued_license(
                $pdo,
                $issued,
                $actor,
                'Purchase',
                $sourceReference,
                'PURCHASE_IMPORTED'
            );
            $imported++;
        }
        $pdo->commit();
        return $imported;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
