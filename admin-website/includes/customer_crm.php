<?php
declare(strict_types=1);

require_once __DIR__ . '/data_protection.php';

const CRM_CUSTOMER_STATUSES = ['Active', 'Merged', 'Closed'];
const CRM_CONSENT_TYPES = ['Service', 'Marketing', 'Product Analytics'];
const CRM_CONSENT_STATES = ['Granted', 'Denied', 'Withdrawn', 'Not Asked'];

function crm_authorization_header(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
        $value = trim((string)($_SERVER[$name] ?? ''));
        if ($value !== '') return $value;
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                return trim((string)$value);
            }
        }
    }
    return '';
}

function crm_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function crm_text_slice(string $value, int $start, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
}

function crm_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }
    return $email;
}

function crm_mask_email(string $email): string
{
    $email = crm_normalize_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return 'Not provided';
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = crm_text_slice($local, 0, 1);
    return $visible . str_repeat('•', max(3, min(8, crm_text_length($local) - 1))) . '@' . $domain;
}

function crm_activation_key_ending(string $activationKey): string
{
    $compact = preg_replace('/[^A-Za-z0-9]/', '', $activationKey) ?? '';
    return $compact === '' ? '' : substr($compact, -4);
}

function crm_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function crm_table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll() as $column) {
        $columns[(string)$column['Field']] = true;
    }
    return $columns;
}

function ensure_customer_crm_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $lock = $pdo->query("SELECT GET_LOCK('ppe_customer_crm_schema_v1',10)")->fetchColumn();
    if ((int)$lock !== 1) throw new RuntimeException('The customer database upgrade is busy. Try again shortly.');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            customer_id CHAR(36) NOT NULL,
            display_name VARCHAR(160) NOT NULL,
            canonical_email VARCHAR(254) NOT NULL,
            email_hash BINARY(32) NOT NULL,
            email_verified_at DATETIME(6) NULL,
            status ENUM('Active','Merged','Closed') NOT NULL DEFAULT 'Active',
            merged_into_customer_id CHAR(36) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (customer_id),
            KEY ix_customers_email_hash (email_hash),
            KEY ix_customers_status_updated (status, updated_at),
            KEY ix_customers_merged_into (merged_into_customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_consents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NOT NULL,
            consent_type ENUM('Service','Marketing','Product Analytics') NOT NULL,
            consent_state ENUM('Granted','Denied','Withdrawn','Not Asked') NOT NULL,
            policy_version VARCHAR(40) NOT NULL,
            source VARCHAR(64) NOT NULL,
            actor VARCHAR(80) NOT NULL,
            evidence_digest BINARY(32) NULL,
            recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY ix_customer_consents_customer (customer_id, consent_type, recorded_at),
            CONSTRAINT fk_customer_consents_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NULL,
            purchase_reference VARCHAR(64) NOT NULL,
            order_type ENUM('LICENSE','MAINTENANCE') NOT NULL,
            license_tier ENUM('Lite','Pro','Enterprise') NOT NULL,
            purchase_status VARCHAR(40) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            paid_at DATETIME(6) NULL,
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_customer_purchase_reference (purchase_reference),
            KEY ix_customer_purchases_customer (customer_id, paid_at),
            CONSTRAINT fk_customer_purchases_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            source VARCHAR(64) NOT NULL,
            source_reference VARCHAR(96) NULL,
            actor VARCHAR(80) NOT NULL,
            event_summary VARCHAR(500) NOT NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY ix_customer_events_customer (customer_id, occurred_at),
            KEY ix_customer_events_type (event_type, occurred_at),
            CONSTRAINT fk_customer_events_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_email_suppressions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NOT NULL,
            email_hash BINARY(32) NOT NULL,
            reason ENUM('Unsubscribed','Bounced','Complaint','Account Closed','Administrative') NOT NULL,
            source VARCHAR(64) NOT NULL,
            actor VARCHAR(80) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            released_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY ix_email_suppressions_customer (customer_id, active),
            KEY ix_email_suppressions_hash (email_hash, active),
            CONSTRAINT fk_email_suppressions_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_merge_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_customer_id CHAR(36) NOT NULL,
            target_customer_id CHAR(36) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            actor VARCHAR(80) NOT NULL,
            merged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY ix_customer_merge_source (source_customer_id),
            KEY ix_customer_merge_target (target_customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_email_verifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NOT NULL,
            email_hash BINARY(32) NOT NULL,
            token_hash BINARY(32) NOT NULL,
            requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_customer_verification_token (token_hash),
            KEY ix_customer_verification_customer (customer_id, expires_at),
            CONSTRAINT fk_customer_verification_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_admin_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id CHAR(36) NULL,
            action VARCHAR(64) NOT NULL,
            actor VARCHAR(80) NOT NULL,
            object_type VARCHAR(40) NOT NULL,
            object_reference VARCHAR(96) NULL,
            reason VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY ix_customer_audit_customer (customer_id, created_at),
            KEY ix_customer_audit_action (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_api_rate_limits (
            bucket_hash BINARY(32) NOT NULL,
            hits INT UNSIGNED NOT NULL,
            reset_at DATETIME(6) NOT NULL,
            PRIMARY KEY (bucket_hash),
            KEY ix_customer_api_rate_reset (reset_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_requests (
            reference_code VARCHAR(32) NOT NULL,
            customer_id CHAR(36) NULL,
            license_id CHAR(36) NOT NULL,
            request_type ENUM('Bug Report','Feature Request','License Issue','Other Issue') NOT NULL,
            subject VARCHAR(160) NOT NULL,
            contact_name VARCHAR(160) NOT NULL,
            contact_email VARCHAR(254) NOT NULL,
            private_diagnostics MEDIUMTEXT NULL,
            github_issue_number BIGINT UNSIGNED NULL,
            github_issue_url VARCHAR(500) NULL,
            state ENUM('Pending','Submitted') NOT NULL DEFAULT 'Pending',
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            submitted_at DATETIME(6) NULL,
            PRIMARY KEY (reference_code),
            KEY ix_support_requests_customer (customer_id),
            KEY ix_support_license_created (license_id, created_at),
            KEY ix_support_state_created (state, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS development_migrations (
            migration_key VARCHAR(96) NOT NULL,
            applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $installations = crm_table_columns($pdo, 'installations');
    if (!isset($installations['customer_id'])) {
        $pdo->exec('ALTER TABLE installations ADD COLUMN customer_id CHAR(36) NULL AFTER installation_uuid, ADD KEY ix_installations_customer (customer_id)');
    }
    $licenses = crm_table_columns($pdo, 'issued_licenses');
    if (!isset($licenses['customer_id'])) {
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN customer_id CHAR(36) NULL AFTER license_id, ADD KEY ix_issued_licenses_customer (customer_id)');
    }
    if (!isset($licenses['activation_key_fingerprint'])) {
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN activation_key_fingerprint BINARY(32) NULL AFTER activation_key, ADD COLUMN activation_key_ending CHAR(4) NULL AFTER activation_key_fingerprint, ADD UNIQUE KEY uq_issued_licenses_key_fingerprint (activation_key_fingerprint)');
    }
    if (!isset($licenses['activation_key_ciphertext'])) {
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN activation_key_ciphertext VARBINARY(768) NULL AFTER activation_key, ADD COLUMN activation_key_nonce BINARY(12) NULL AFTER activation_key_ciphertext, ADD COLUMN activation_key_tag BINARY(16) NULL AFTER activation_key_nonce');
    } elseif (!isset($licenses['activation_key_tag'])) {
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN activation_key_tag BINARY(16) NULL AFTER activation_key_nonce');
    }
    $licenseIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM issued_licenses')->fetchAll() as $index) {
        $licenseIndexes[(string)$index['Key_name']] = true;
    }
    if (!isset($licenseIndexes['uq_issued_licenses_key_fingerprint'])) {
        $pdo->exec('ALTER TABLE issued_licenses ADD UNIQUE KEY uq_issued_licenses_key_fingerprint (activation_key_fingerprint)');
    }
    $support = crm_table_columns($pdo, 'support_requests');
    if (!isset($support['customer_id'])) {
        $pdo->exec('ALTER TABLE support_requests ADD COLUMN customer_id CHAR(36) NULL AFTER reference_code, ADD KEY ix_support_requests_customer (customer_id)');
    }
    $ready = true;
    $pdo->query("SELECT RELEASE_LOCK('ppe_customer_crm_schema_v1')")->fetchColumn();
}

function crm_create_customer(PDO $pdo, string $name, string $email, string $source, string $sourceReference): string
{
    $customerId = crm_uuid();
    $email = crm_normalize_email($email);
    $name = trim($name) !== '' ? crm_text_slice(trim($name), 0, 160) : 'Unregistered customer';
    $insert = $pdo->prepare(
        'INSERT INTO customers (customer_id, display_name, canonical_email, email_hash)
         VALUES (:id, :name, :email, UNHEX(SHA2(:email_hash_value, 256)))'
    );
    $insert->execute(['id' => $customerId, 'name' => $name, 'email' => $email, 'email_hash_value' => $email]);
    $event = $pdo->prepare(
        "INSERT INTO customer_events (customer_id, event_type, source, source_reference, actor, event_summary)
         VALUES (:id, 'CUSTOMER_CREATED', :source, :reference, 'system-migration', 'Customer record created from an existing administrative record.')"
    );
    $event->execute(['id' => $customerId, 'source' => $source, 'reference' => substr($sourceReference, 0, 96)]);
    return $customerId;
}

function backfill_customer_crm(PDO $pdo): array
{
    ensure_customer_crm_schema($pdo);
    $counts = ['customers' => 0, 'licenses' => 0, 'installations' => 0, 'support' => 0, 'fingerprints' => 0];
    $pdo->beginTransaction();
    try {
        $licenses = $pdo->query("SELECT license_id, customer_name, email_address, activation_key FROM issued_licenses WHERE customer_id IS NULL ORDER BY id")->fetchAll();
        $attachLicense = $pdo->prepare('UPDATE issued_licenses SET customer_id = :customer_id WHERE license_id = :license_id AND customer_id IS NULL');
        $attachInstallations = $pdo->prepare('UPDATE installations SET customer_id = :customer_id WHERE license_id = :license_id AND customer_id IS NULL');
        foreach ($licenses as $license) {
            $customerId = crm_create_customer($pdo, (string)$license['customer_name'], (string)$license['email_address'], 'Issued License', (string)$license['license_id']);
            $counts['customers']++;
            $attachLicense->execute(['customer_id' => $customerId, 'license_id' => $license['license_id']]);
            $counts['licenses'] += $attachLicense->rowCount();
            $attachInstallations->execute(['customer_id' => $customerId, 'license_id' => $license['license_id']]);
            $counts['installations'] += $attachInstallations->rowCount();
        }

        $unlinked = $pdo->query('SELECT id, installation_uuid, customer_name, email_address FROM installations WHERE customer_id IS NULL ORDER BY id')->fetchAll();
        $attachInstallation = $pdo->prepare('UPDATE installations SET customer_id = :customer_id WHERE id = :id AND customer_id IS NULL');
        foreach ($unlinked as $installation) {
            // Deliberately create one record per unverified installation. Matching email alone is not ownership proof.
            $customerId = crm_create_customer($pdo, (string)$installation['customer_name'], (string)$installation['email_address'], 'Installation', (string)$installation['installation_uuid']);
            $counts['customers']++;
            $attachInstallation->execute(['customer_id' => $customerId, 'id' => $installation['id']]);
            $counts['installations'] += $attachInstallation->rowCount();
        }

        $linkSupport = $pdo->prepare(
            'UPDATE support_requests r
             JOIN issued_licenses l ON l.license_id = r.license_id
             SET r.customer_id = l.customer_id
             WHERE r.customer_id IS NULL AND l.customer_id IS NOT NULL'
        );
        $linkSupport->execute();
        $counts['support'] = $linkSupport->rowCount();

        $fingerprints = $pdo->query("SELECT license_id,activation_key,activation_key_ciphertext,activation_key_nonce,activation_key_tag FROM issued_licenses WHERE activation_key_fingerprint IS NULL OR (activation_key<>'' AND activation_key_ciphertext IS NULL)")->fetchAll();
        $saveFingerprint = $pdo->prepare('UPDATE issued_licenses SET activation_key=:plaintext,activation_key_ciphertext=:ciphertext,activation_key_nonce=:nonce,activation_key_tag=:tag,activation_key_fingerprint=UNHEX(SHA2(:key_value,256)),activation_key_ending=:ending WHERE license_id=:license_id');
        foreach ($fingerprints as $license) {
            $keyValue = reveal_activation_key($license);
            if ($keyValue === '') continue;
            $protected = protect_activation_key($keyValue);
            $saveFingerprint->execute([
                'plaintext' => $protected['plaintext'], 'ciphertext' => $protected['ciphertext'], 'nonce' => $protected['nonce'], 'tag' => $protected['tag'],
                'key_value' => $keyValue,
                'ending' => crm_activation_key_ending($keyValue),
                'license_id' => $license['license_id'],
            ]);
            $counts['fingerprints'] += $saveFingerprint->rowCount();
        }
        $pdo->commit();
        return $counts;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function crm_record_consent(PDO $pdo, string $customerId, string $type, string $state, string $policyVersion, string $source, string $actor): void
{
    if (!in_array($type, CRM_CONSENT_TYPES, true) || !in_array($state, CRM_CONSENT_STATES, true)) {
        throw new InvalidArgumentException('The consent selection is invalid.');
    }
    if (!preg_match('/^[0-9a-f-]{36}$/', strtolower($customerId)) || trim($policyVersion) === '') {
        throw new InvalidArgumentException('The consent evidence is incomplete.');
    }
    $evidence = implode('|', [$customerId, $type, $state, $policyVersion, $source, $actor, gmdate('Y-m-d')]);
    $statement = $pdo->prepare(
        'INSERT INTO customer_consents (customer_id, consent_type, consent_state, policy_version, source, actor, evidence_digest)
         VALUES (:customer_id, :type, :state, :policy, :source, :actor, UNHEX(SHA2(:evidence, 256)))'
    );
    $statement->execute([
        'customer_id' => strtolower($customerId), 'type' => $type, 'state' => $state,
        'policy' => crm_text_slice(trim($policyVersion), 0, 40), 'source' => crm_text_slice(trim($source), 0, 64),
        'actor' => crm_text_slice(trim($actor), 0, 80), 'evidence' => $evidence,
    ]);
}

function crm_record_admin_audit(PDO $pdo, ?string $customerId, string $action, string $actor, string $objectType, ?string $reference, ?string $reason): void
{
    $statement = $pdo->prepare(
        'INSERT INTO customer_admin_audit (customer_id, action, actor, object_type, object_reference, reason)
         VALUES (:customer_id, :action, :actor, :object_type, :reference, :reason)'
    );
    $statement->execute([
        'customer_id' => $customerId, 'action' => crm_text_slice($action, 0, 64), 'actor' => crm_text_slice($actor, 0, 80),
        'object_type' => crm_text_slice($objectType, 0, 40), 'reference' => $reference === null ? null : crm_text_slice($reference, 0, 96),
        'reason' => $reason === null ? null : crm_text_slice($reason, 0, 500),
    ]);
}

function sync_customer_purchases(PDO $pdo, array $orders): int
{
    ensure_customer_crm_schema($pdo);
    $findCustomer = $pdo->prepare('SELECT customer_id FROM issued_licenses WHERE license_id=:license_id OR source_reference=:reference ORDER BY customer_id IS NULL,issued_at DESC LIMIT 1');
    $upsert = $pdo->prepare(
        'INSERT INTO customer_purchases(customer_id,purchase_reference,order_type,license_tier,purchase_status,amount,currency,paid_at)
         VALUES(:customer_id,:reference,:order_type,:tier,:status,:amount,:currency,:paid_at)
         ON DUPLICATE KEY UPDATE customer_id=COALESCE(VALUES(customer_id),customer_id),purchase_status=VALUES(purchase_status),paid_at=COALESCE(VALUES(paid_at),paid_at)'
    );
    $count = 0;
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $reference = trim((string)($order['public_id'] ?? ''));
        $tier = (string)($order['license_tier'] ?? '');
        $type = (string)($order['order_type'] ?? 'LICENSE');
        if ($reference === '' || strlen($reference) > 64 || !in_array($tier, ['Lite','Pro','Enterprise'], true) || !in_array($type, ['LICENSE','MAINTENANCE'], true)) continue;
        $licenseId = strtolower(trim((string)($order['license_id'] ?? $order['renewal_license_id'] ?? '')));
        $findCustomer->execute(['license_id' => $licenseId, 'reference' => $reference]);
        $customerId = $findCustomer->fetchColumn();
        $upsert->execute([
            'customer_id' => is_string($customerId) && $customerId !== '' ? $customerId : null,
            'reference' => $reference, 'order_type' => $type, 'tier' => $tier,
            'status' => substr((string)($order['status'] ?? 'UNKNOWN'), 0, 40),
            'amount' => number_format((float)($order['amount'] ?? 0), 2, '.', ''),
            'currency' => substr(strtoupper((string)($order['currency'] ?? 'USD')), 0, 3),
            'paid_at' => !empty($order['paid_at']) ? (string)$order['paid_at'] : null,
        ]);
        $count++;
    }
    return $count;
}
