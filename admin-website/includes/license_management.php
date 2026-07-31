<?php
declare(strict_types=1);

require_once __DIR__ . '/data_protection.php';

function license_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
        throw new InvalidArgumentException('The requested database table is invalid.');
    }
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $column) {
        $columns[(string)$column['Field']] = true;
    }
    return $columns;
}

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
        if (!isset($installationColumns['license_last_sync_at'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN license_last_sync_at DATETIME(6) NULL AFTER last_seen_at');
        }
        if (!isset($installationColumns['license_last_sync_status'])) {
            $pdo->exec("ALTER TABLE installations ADD COLUMN license_last_sync_status VARCHAR(32) NULL AFTER license_last_sync_at");
        }
        if (!isset($installationColumns['license_last_sync_error'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN license_last_sync_error VARCHAR(300) NULL AFTER license_last_sync_status');
        }
        if (!isset($installationColumns['device_fingerprint_hash'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN device_fingerprint_hash BINARY(32) NULL AFTER device_label');
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
            'license_source' => "ALTER TABLE issued_licenses ADD COLUMN license_source ENUM('Manual', 'Purchase', 'Complimentary') NOT NULL DEFAULT 'Manual' AFTER superseded_by_license_id",
            'source_reference' => 'ALTER TABLE issued_licenses ADD COLUMN source_reference VARCHAR(64) NULL AFTER license_source',
            'license_expires_at' => 'ALTER TABLE issued_licenses ADD COLUMN license_expires_at DATETIME(6) NULL AFTER source_reference',
            'complimentary_reason' => 'ALTER TABLE issued_licenses ADD COLUMN complimentary_reason VARCHAR(24) NULL AFTER license_expires_at',
            'complimentary_note' => 'ALTER TABLE issued_licenses ADD COLUMN complimentary_note VARCHAR(500) NULL AFTER complimentary_reason',
            'maintenance_expires_at' => 'ALTER TABLE issued_licenses ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER complimentary_note',
            'maintenance_revoked_at' => 'ALTER TABLE issued_licenses ADD COLUMN maintenance_revoked_at DATETIME(6) NULL AFTER maintenance_expires_at',
            'row_version' => 'ALTER TABLE issued_licenses ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER maintenance_revoked_at',
            'updated_at' => 'ALTER TABLE issued_licenses ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at',
            'activation_key_ciphertext' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_ciphertext VARBINARY(768) NULL AFTER activation_key',
            'activation_key_nonce' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_nonce BINARY(12) NULL AFTER activation_key_ciphertext',
            'activation_key_tag' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_tag BINARY(16) NULL AFTER activation_key_nonce',
            'activation_key_fingerprint' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_fingerprint BINARY(32) NULL AFTER activation_key_nonce',
            'activation_key_ending' => 'ALTER TABLE issued_licenses ADD COLUMN activation_key_ending CHAR(4) NULL AFTER activation_key_fingerprint',
            'entitlement_revision' => 'ALTER TABLE issued_licenses ADD COLUMN entitlement_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER row_version',
        ];
        foreach ($additions as $name => $statement) {
            if (!isset($columns[$name])) {
                $pdo->exec($statement);
            }
        }
        $licenseSourceColumn = $pdo->query(
            "SHOW COLUMNS FROM issued_licenses LIKE 'license_source'"
        )->fetch();
        if ($licenseSourceColumn &&
            !str_contains((string)$licenseSourceColumn['Type'], "'Complimentary'")) {
            $pdo->exec(
                "ALTER TABLE issued_licenses
                 MODIFY COLUMN license_source
                 ENUM('Manual', 'Purchase', 'Complimentary') NOT NULL DEFAULT 'Manual'"
            );
        }
        $activationKeyColumn = $pdo->query(
            "SHOW COLUMNS FROM issued_licenses LIKE 'activation_key'"
        )->fetch();
        if ($activationKeyColumn && strtoupper((string)$activationKeyColumn['Null']) !== 'YES') {
            $pdo->exec(
                "ALTER TABLE issued_licenses
                 MODIFY COLUMN activation_key VARCHAR(512) NULL
                 COMMENT 'Deprecated legacy record; never used for new entitlements'"
            );
        }
        $pdo->exec(
            'UPDATE issued_licenses
             SET entitlement_revision = GREATEST(1, row_version)
             WHERE entitlement_revision < 1'
        );

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
                admin_ip VARCHAR(45) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_license_events_license_id (license_id),
                KEY ix_license_events_created_at (created_at),
                KEY ix_license_events_event_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $eventColumns = license_table_columns($pdo, 'issued_license_events');
        if (!isset($eventColumns['admin_ip'])) {
            $pdo->exec('ALTER TABLE issued_license_events ADD COLUMN admin_ip VARCHAR(45) NULL AFTER performed_by');
        }
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
                admin_ip VARCHAR(45) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uq_license_maintenance_source (license_id, source_reference),
                KEY ix_license_maintenance_license (license_id),
                KEY ix_license_maintenance_created (created_at),
                KEY ix_license_maintenance_event (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $maintenanceEventColumns = license_table_columns($pdo, 'license_maintenance_events');
        if (!isset($maintenanceEventColumns['admin_ip'])) {
            $pdo->exec('ALTER TABLE license_maintenance_events ADD COLUMN admin_ip VARCHAR(45) NULL AFTER performed_by');
        }
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
        if ((bool)$pdo->query("SHOW TABLES LIKE 'license_device_bindings'")->fetchColumn()) {
            $activationMethodColumn = $pdo->query(
                "SHOW COLUMNS FROM license_device_bindings LIKE 'activation_method'"
            )->fetch();
            if ($activationMethodColumn &&
                str_contains((string)$activationMethodColumn['Type'], "'ActivationKeyClaim'")) {
                $pdo->exec(
                    "UPDATE license_device_bindings
                     SET activation_method = 'LegacyMigration'
                     WHERE activation_method = 'ActivationKeyClaim'"
                );
                $pdo->exec(
                    "ALTER TABLE license_device_bindings
                     MODIFY COLUMN activation_method
                     ENUM('PortalLink','AdminRecovery','LegacyMigration') NOT NULL"
                );
            }
        }
        if ((bool)$pdo->query("SHOW TABLES LIKE 'development_migrations'")->fetchColumn()) {
            $pdo->exec(
                "INSERT IGNORE INTO development_migrations(migration_key)
                 VALUES ('account-device-entitlements-v1')"
            );
        }
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

function canonical_complimentary_reason(string $value): string
{
    if (!in_array($value, ['Promotional', 'Partner', 'Evaluation', 'Referral', 'Other'], true)) {
        throw new InvalidArgumentException('Choose Promotional, Partner, Evaluation, Referral, or Other.');
    }
    return $value;
}

function normalize_complimentary_expiration(
    string $duration,
    ?string $expirationDate,
    ?DateTimeImmutable $now = null
): ?string {
    if ($duration === 'permanent') {
        return null;
    }
    if ($duration !== 'expires') {
        throw new InvalidArgumentException('Choose a permanent license or an expiration date.');
    }

    $expirationDate = trim((string)$expirationDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDate)) {
        throw new InvalidArgumentException('Choose a valid complimentary license expiration date.');
    }
    $utc = new DateTimeZone('UTC');
    $expiration = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate, $utc);
    if (!$expiration || $expiration->format('Y-m-d') !== $expirationDate) {
        throw new InvalidArgumentException('Choose a valid complimentary license expiration date.');
    }
    $expiration = $expiration->setTime(23, 59, 59, 999999);
    $now ??= new DateTimeImmutable('now', $utc);
    if ($expiration <= $now) {
        throw new InvalidArgumentException('The complimentary license expiration date must be in the future.');
    }
    return $expiration->format('Y-m-d H:i:s.u');
}

function license_entitlement_expired(array $license, ?DateTimeImmutable $now = null): bool
{
    $expiresAt = trim((string)($license['license_expires_at'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    try {
        return new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')) < $now;
    } catch (Throwable) {
        return true;
    }
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
            (license_id, event_type, previous_expires_at, new_expires_at, source_reference, reason, performed_by, admin_ip)
         VALUES
            (:license_id, :event_type, :previous_expires_at, :new_expires_at, :source_reference, :reason, :performed_by, :admin_ip)'
    );
    $statement->execute([
        'license_id' => canonical_license_uuid($licenseId),
        'event_type' => substr($eventType, 0, 40),
        'previous_expires_at' => $previousExpiresAt,
        'new_expires_at' => $newExpiresAt,
        'source_reference' => $sourceReference !== null ? substr($sourceReference, 0, 80) : null,
        'reason' => $reason !== null ? substr($reason, 0, 500) : null,
        'performed_by' => substr($actor !== '' ? $actor : 'owner', 0, 80),
        'admin_ip' => license_admin_ip(),
    ]);
}

function license_admin_ip(): ?string
{
    $value = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
}
function record_license_event(PDO $pdo, array $license, string $eventType, string $actor, array $changes = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO issued_license_events
            (license_id, customer_name, email_address, event_type, previous_state, new_state,
             previous_tier, new_tier, replacement_license_id, reason, performed_by, admin_ip)
         VALUES
            (:license_id, :customer_name, :email_address, :event_type, :previous_state, :new_state,
             :previous_tier, :new_tier, :replacement_license_id, :reason, :performed_by, :admin_ip)'
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
        'admin_ip' => license_admin_ip(),
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
    ?string $maintenanceReason = null,
    ?string $eventReason = null
): void {
    if (!in_array($source, ['Manual', 'Purchase', 'Complimentary'], true)) {
        throw new InvalidArgumentException('The license source is invalid.');
    }
    $customerLookup = $pdo->prepare(
        "SELECT customer_id FROM customers
         WHERE canonical_email=:email AND email_verified_at IS NOT NULL AND status='Active'
         LIMIT 1"
    );
    $customerLookup->execute(['email' => strtolower((string)$issued['email_address'])]);
    $customerId = $customerLookup->fetchColumn();
    if (!is_string($customerId) || $customerId === '') {
        throw new DomainException('A verified active Customer Portal account is required before assigning a license.');
    }
    $statement = $pdo->prepare(
        'INSERT INTO issued_licenses
            (license_id, customer_id, customer_name, email_address, license_tier, issued_at,
             created_by, control_state, license_source, source_reference, license_expires_at,
             complimentary_reason, complimentary_note, maintenance_expires_at)
         VALUES
            (:license_id, :customer_id, :customer_name, :email_address, :license_tier, :issued_at,
             :created_by, \'Enabled\', :license_source, :source_reference, :license_expires_at,
             :complimentary_reason, :complimentary_note, :maintenance_expires_at)'
    );
    $maintenanceExpiresAt = (string)($issued['maintenance_expires_at'] ??
        (new DateTimeImmutable((string)$issued['issued_at'], new DateTimeZone('UTC')))
            ->modify('+1 year')->format('Y-m-d H:i:s'));
    $licenseExpiresAt = isset($issued['license_expires_at']) &&
        trim((string)$issued['license_expires_at']) !== ''
        ? (string)$issued['license_expires_at']
        : null;
    if ($licenseExpiresAt !== null && $licenseExpiresAt < $maintenanceExpiresAt) {
        $maintenanceExpiresAt = $licenseExpiresAt;
    }
    $statement->execute([
        'license_id' => canonical_license_uuid((string)$issued['license_id']),
        'customer_id' => $customerId,
        'customer_name' => (string)$issued['customer_name'],
        'email_address' => (string)$issued['email_address'],
        'license_tier' => canonical_paid_tier((string)$issued['license_tier']),
        'issued_at' => (string)$issued['issued_at'],
        'created_by' => substr($actor !== '' ? $actor : 'owner', 0, 80),
        'license_source' => $source,
        'source_reference' => $sourceReference,
        'license_expires_at' => $licenseExpiresAt,
        'complimentary_reason' => $source === 'Complimentary'
            ? canonical_complimentary_reason((string)($issued['complimentary_reason'] ?? 'Other'))
            : null,
        'complimentary_note' => $source === 'Complimentary' && trim((string)($issued['complimentary_note'] ?? '')) !== ''
            ? mb_substr(trim((string)$issued['complimentary_note']), 0, 500)
            : null,
        'maintenance_expires_at' => $maintenanceExpiresAt,
    ]);
    record_license_event($pdo, $issued, $eventType, $actor, [
        'new_state' => 'Enabled',
        'new_tier' => (string)$issued['license_tier'],
        'reason' => $eventReason,
    ]);
    record_maintenance_event(
        $pdo,
        (string)$issued['license_id'],
        $maintenanceEventType,
        $actor,
        null,
        $maintenanceExpiresAt,
        'initial:' . canonical_license_uuid((string)$issued['license_id']),
        $maintenanceReason ?? 'One year of Application Maintenance and Support included with the permanent license.'
    );
}

function find_license_for_update(PDO $pdo, string $licenseId): array
{
    $statement = $pdo->prepare(
        'SELECT license_id, customer_id, customer_name, email_address, license_tier, issued_at,
                control_state, deactivated_at, revoked_at, deleted_at, superseded_by_license_id,
                license_source, source_reference, license_expires_at, complimentary_reason, complimentary_note,
                maintenance_expires_at, maintenance_revoked_at, row_version
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

function unlink_license_installations(
    PDO $pdo,
    string $licenseId,
    string $bindingState,
    string $actor,
    string $reason
): int
{
    if (!in_array($bindingState, ['Deactivated', 'Revoked'], true)) {
        throw new InvalidArgumentException('The device release state is invalid.');
    }
    $bindings = $pdo->prepare(
        "SELECT binding_id,customer_id,installation_id
         FROM license_device_bindings
         WHERE license_id=:license_id AND binding_state='Active' FOR UPDATE"
    );
    $bindings->execute(['license_id' => canonical_license_uuid($licenseId)]);
    $active = $bindings->fetchAll();
    $updateBindings = $pdo->prepare(
        "UPDATE license_device_bindings
         SET binding_state=:binding_state,deactivated_at=UTC_TIMESTAMP(6),deactivation_reason=:reason
         WHERE license_id=:license_id AND binding_state='Active'"
    );
    $updateBindings->execute([
        'binding_state'=>$bindingState,
        'reason'=>mb_substr($reason,0,300),
        'license_id'=>canonical_license_uuid($licenseId),
    ]);
    $event = $pdo->prepare(
        "INSERT INTO license_activation_events
           (customer_id,license_id,installation_id,event_type,outcome,activation_method,event_summary)
         VALUES(:customer_id,:license_id,:installation_id,:event_type,'Succeeded','AdminRecovery',:summary)"
    );
    foreach ($active as $binding) {
        $event->execute([
            'customer_id'=>$binding['customer_id'],
            'license_id'=>canonical_license_uuid($licenseId),
            'installation_id'=>$binding['installation_id'],
            'event_type'=>$bindingState === 'Revoked' ? 'ADMIN_DEVICE_REVOKED' : 'ADMIN_DEVICE_RELEASED',
            'summary'=>mb_substr(
                "{$actor} from " . (license_admin_ip() ?? 'unknown IP') . ": {$reason}",
                0,
                500
            ),
        ]);
    }
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
    ?string $targetTier = null,
    ?string $targetCustomerId = null,
    string $reason = ''
): array {
    if (!in_array($action, [
        'change_tier', 'deactivate', 'reactivate', 'revoke', 'delete',
        'extend_maintenance', 'revoke_maintenance', 'restore_maintenance', 'reassign_customer',
    ], true)) {
        throw new InvalidArgumentException('The requested license action is invalid.');
    }
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    if (strlen($reason) > 500) {
        throw new InvalidArgumentException('The reason must be 500 characters or fewer.');
    }
    if (in_array($action, ['revoke', 'delete', 'reassign_customer'], true) && strlen($reason) < 3) {
        throw new InvalidArgumentException('Enter a brief reason before continuing.');
    }

    $pdo->beginTransaction();
    try {
        $license = find_license_for_update($pdo, $licenseId);
        verify_license_row_version($license, $expectedVersion);
        $state = (string)$license['control_state'];
        $tier = (string)$license['license_tier'];
        $result = ['issued' => null, 'message' => ''];

        if ($action === 'reassign_customer') {
            $targetCustomerId = strtolower(trim((string)$targetCustomerId));
            if (!preg_match('/^[0-9a-f-]{36}$/', $targetCustomerId)) {
                throw new InvalidArgumentException('Choose a verified customer account.');
            }
            $customerQuery = $pdo->prepare(
                "SELECT customer_id,display_name,canonical_email
                 FROM customers
                 WHERE customer_id=:customer_id AND status='Active' AND email_verified_at IS NOT NULL
                 LIMIT 1 FOR UPDATE"
            );
            $customerQuery->execute(['customer_id'=>$targetCustomerId]);
            $customer = $customerQuery->fetch();
            if (!is_array($customer)) {
                throw new DomainException('The selected verified customer account was not found.');
            }
            if (hash_equals((string)($license['customer_id'] ?? ''), $targetCustomerId)) {
                throw new DomainException('This license already belongs to that customer account.');
            }
            unlink_license_installations($pdo,(string)$license['license_id'],'Deactivated',$actor,$reason);
            $update = $pdo->prepare(
                "UPDATE issued_licenses
                 SET customer_id=:customer_id,customer_name=:customer_name,email_address=:email,
                     row_version=row_version+1,entitlement_revision=entitlement_revision+1
                 WHERE license_id=:license_id AND row_version=:row_version"
            );
            $update->execute([
                'customer_id'=>$targetCustomerId,
                'customer_name'=>$customer['display_name'],
                'email'=>$customer['canonical_email'],
                'license_id'=>$license['license_id'],
                'row_version'=>$expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be reassigned.');
            }
            record_license_event($pdo,$license,'CUSTOMER_REASSIGNED',$actor,[
                'previous_state'=>$state,'new_state'=>$state,'previous_tier'=>$tier,'new_tier'=>$tier,
                'reason'=>$reason . ' New customer: ' . $customer['customer_id'],
            ]);
            $result['message'] = 'The license was reassigned to the verified customer account. The new customer can now link a computer.';
        } elseif ($action === 'change_tier') {
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
            $update = $pdo->prepare(
                "UPDATE issued_licenses SET license_tier=:new_tier,
                        row_version=row_version+1,entitlement_revision=entitlement_revision+1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute([
                'new_tier' => $newTier,
                'license_id' => $license['license_id'],
                'row_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before the replacement could be saved.');
            }
            $syncInstallations = $pdo->prepare(
                "UPDATE installations SET license_mode=:new_tier
                 WHERE license_id=:license_id AND portal_deactivated_at IS NULL"
            );
            $syncInstallations->execute(['new_tier'=>$newTier,'license_id'=>$license['license_id']]);
            record_license_event($pdo, $license, 'TIER_REPLACED', $actor, [
                'previous_state' => 'Enabled',
                'new_state' => 'Enabled',
                'previous_tier' => $tier,
                'new_tier' => $newTier,
                'reason' => $reason !== '' ? $reason : 'License level changed by the Admin Portal.',
            ]);
            $result['message'] = "The account entitlement was changed to {$newTier}. Linked computers will synchronize automatically.";
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
                        row_version = row_version + 1, entitlement_revision = entitlement_revision + 1
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
                "UPDATE issued_licenses SET maintenance_revoked_at = UTC_TIMESTAMP(6), row_version = row_version + 1,
                        entitlement_revision = entitlement_revision + 1
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
                "UPDATE issued_licenses SET maintenance_revoked_at = NULL, row_version = row_version + 1,
                        entitlement_revision = entitlement_revision + 1
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
                        row_version = row_version + 1, entitlement_revision = entitlement_revision + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be deactivated.');
            }
            unlink_license_installations(
                $pdo,
                (string)$license['license_id'],
                'Deactivated',
                $actor,
                $reason !== '' ? $reason : 'License deactivated by an authorized administrator.'
            );
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
                        row_version = row_version + 1, entitlement_revision = entitlement_revision + 1
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
                        deactivated_at = NULL, row_version = row_version + 1,
                        entitlement_revision = entitlement_revision + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be revoked.');
            }
            unlink_license_installations($pdo, (string)$license['license_id'], 'Revoked', $actor, $reason);
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
                        row_version = row_version + 1, entitlement_revision = entitlement_revision + 1
                 WHERE license_id = :license_id AND row_version = :row_version"
            );
            $update->execute(['license_id' => $license['license_id'], 'row_version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This license changed before it could be deleted.');
            }
            unlink_license_installations($pdo, (string)$license['license_id'], 'Revoked', $actor, $reason);
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
             SET maintenance_expires_at = :expiration, row_version = row_version + 1,
                 entitlement_revision = entitlement_revision + 1
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
            'idempotent'=>false,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
