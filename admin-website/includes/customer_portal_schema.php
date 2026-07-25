<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_crm.php';

function ensure_customer_portal_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensure_customer_crm_schema($pdo);
    $lock = $pdo->query("SELECT GET_LOCK('ppe_customer_portal_schema_v1',10)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('The Customer Portal database upgrade is busy. Try again shortly.');
    }

    try {
        $statements = [
            "CREATE TABLE IF NOT EXISTS portal_accounts (
                customer_id CHAR(36) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                mfa_secret_ciphertext VARBINARY(256) NULL,
                mfa_secret_nonce BINARY(12) NULL,
                mfa_secret_tag BINARY(16) NULL,
                mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
                mfa_reenrollment_required TINYINT(1) NOT NULL DEFAULT 0,
                failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME(6) NULL,
                session_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
                password_changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                last_login_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (customer_id),
                CONSTRAINT fk_portal_account_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_sessions (
                session_id_hash BINARY(32) NOT NULL,
                customer_id CHAR(36) NOT NULL,
                session_revision BIGINT UNSIGNED NOT NULL,
                user_agent_hash BINARY(32) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                expires_at DATETIME(6) NOT NULL,
                reauthenticated_at DATETIME(6) NULL,
                revoked_at DATETIME(6) NULL,
                PRIMARY KEY (session_id_hash),
                KEY ix_portal_sessions_customer (customer_id, revoked_at, expires_at),
                KEY ix_portal_sessions_expiry (expires_at),
                CONSTRAINT fk_portal_session_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_password_resets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id CHAR(36) NOT NULL,
                token_hash BINARY(32) NOT NULL,
                requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                expires_at DATETIME(6) NOT NULL,
                used_at DATETIME(6) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_portal_password_reset_token (token_hash),
                KEY ix_portal_password_reset_customer (customer_id, expires_at),
                CONSTRAINT fk_portal_password_reset_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_recovery_codes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id CHAR(36) NOT NULL,
                code_hash BINARY(32) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                used_at DATETIME(6) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_portal_recovery_code (customer_id, code_hash),
                KEY ix_portal_recovery_customer (customer_id, used_at),
                CONSTRAINT fk_portal_recovery_code_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_rate_limits (
                bucket_hash BINARY(32) NOT NULL,
                hits INT UNSIGNED NOT NULL,
                reset_at DATETIME(6) NOT NULL,
                PRIMARY KEY (bucket_hash),
                KEY ix_portal_rate_reset (reset_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_support_replies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_code VARCHAR(32) NOT NULL,
                customer_id CHAR(36) NOT NULL,
                message TEXT NOT NULL,
                author_type ENUM('Customer','Support') NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_portal_support_reply_reference (reference_code, created_at),
                KEY ix_portal_support_reply_customer (customer_id, created_at),
                CONSTRAINT fk_portal_support_reply_request FOREIGN KEY (reference_code)
                    REFERENCES support_requests(reference_code) ON DELETE CASCADE,
                CONSTRAINT fk_portal_support_reply_customer FOREIGN KEY (customer_id)
                    REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_device_actions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id CHAR(36) NOT NULL,
                installation_id BIGINT UNSIGNED NOT NULL,
                action ENUM('Deactivate') NOT NULL,
                reason VARCHAR(300) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_portal_device_customer (customer_id, created_at),
                KEY ix_portal_device_installation (installation_id, created_at),
                CONSTRAINT fk_portal_device_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
                CONSTRAINT fk_portal_device_installation FOREIGN KEY (installation_id) REFERENCES installations(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_computer_link_requests (
                link_id CHAR(36) NOT NULL,
                installation_id BIGINT UNSIGNED NOT NULL,
                request_token_hash BINARY(32) NOT NULL,
                user_code_hash BINARY(32) NOT NULL,
                status ENUM('Pending','Approved','Rejected','Expired','Consumed') NOT NULL DEFAULT 'Pending',
                approved_customer_id CHAR(36) NULL,
                selected_license_id CHAR(36) NULL,
                expires_at DATETIME(6) NOT NULL,
                approved_at DATETIME(6) NULL,
                consumed_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (link_id),
                UNIQUE KEY uq_portal_link_request_token (request_token_hash),
                UNIQUE KEY uq_portal_link_user_code (user_code_hash),
                KEY ix_portal_link_installation (installation_id,status,expires_at),
                KEY ix_portal_link_customer (approved_customer_id,created_at),
                KEY ix_portal_link_license (selected_license_id,created_at),
                CONSTRAINT fk_portal_link_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
                CONSTRAINT fk_portal_link_customer FOREIGN KEY (approved_customer_id) REFERENCES customers(customer_id),
                CONSTRAINT fk_portal_link_license FOREIGN KEY (selected_license_id) REFERENCES issued_licenses(license_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS license_device_bindings (
                binding_id CHAR(36) NOT NULL,
                license_id CHAR(36) NOT NULL,
                customer_id CHAR(36) NOT NULL,
                installation_id BIGINT UNSIGNED NOT NULL,
                binding_state ENUM('Pending','Active','Deactivated','Revoked') NOT NULL DEFAULT 'Pending',
                activation_method ENUM('PortalLink','AdminRecovery','LegacyMigration') NOT NULL,
                activated_at DATETIME(6) NULL,
                deactivated_at DATETIME(6) NULL,
                deactivation_reason VARCHAR(300) NULL,
                transfer_reference CHAR(36) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (binding_id),
                KEY ix_license_binding_license (license_id,binding_state,activated_at),
                KEY ix_license_binding_customer (customer_id,binding_state,activated_at),
                KEY ix_license_binding_installation (installation_id,binding_state,activated_at),
                KEY ix_license_binding_transfer (transfer_reference),
                CONSTRAINT fk_license_binding_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
                CONSTRAINT fk_license_binding_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
                CONSTRAINT fk_license_binding_installation FOREIGN KEY (installation_id) REFERENCES installations(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS license_activation_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id CHAR(36) NULL,
                license_id CHAR(36) NULL,
                installation_id BIGINT UNSIGNED NULL,
                link_id CHAR(36) NULL,
                event_type VARCHAR(64) NOT NULL,
                outcome ENUM('Succeeded','Rejected','Failed') NOT NULL,
                activation_method VARCHAR(40) NOT NULL,
                event_summary VARCHAR(500) NOT NULL,
                source_ip_digest BINARY(32) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_activation_event_customer (customer_id,created_at),
                KEY ix_activation_event_license (license_id,created_at),
                KEY ix_activation_event_installation (installation_id,created_at),
                KEY ix_activation_event_link (link_id,created_at),
                KEY ix_activation_event_outcome (outcome,created_at),
                CONSTRAINT fk_activation_event_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
                CONSTRAINT fk_activation_event_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
                CONSTRAINT fk_activation_event_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
                CONSTRAINT fk_activation_event_link FOREIGN KEY (link_id) REFERENCES portal_computer_link_requests(link_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portal_mail_outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id CHAR(36) NULL,
                message_type VARCHAR(40) NOT NULL,
                recipient_email VARCHAR(254) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                text_body TEXT NOT NULL,
                state ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                sent_at DATETIME(6) NULL,
                last_error VARCHAR(300) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY ix_portal_mail_state (state, available_at),
                KEY ix_portal_mail_customer (customer_id, created_at),
                CONSTRAINT fk_portal_mail_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $portalAccountColumns = crm_table_columns($pdo, 'portal_accounts');
        if (!isset($portalAccountColumns['mfa_reenrollment_required'])) {
            $pdo->exec(
                'ALTER TABLE portal_accounts
                 ADD COLUMN mfa_reenrollment_required TINYINT(1) NOT NULL DEFAULT 0 AFTER mfa_enabled'
            );
        }
        $customerColumns = crm_table_columns($pdo, 'customers');
        if (!isset($customerColumns['company_name'])) {
            $pdo->exec('ALTER TABLE customers ADD COLUMN company_name VARCHAR(160) NULL AFTER display_name');
        }

        $columns = crm_table_columns($pdo, 'installations');
        if (!isset($columns['device_label'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN device_label VARCHAR(120) NULL AFTER installation_uuid');
        }
        if (!isset($columns['windows_version'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN windows_version VARCHAR(120) NULL AFTER app_version');
        }
        if (!isset($columns['portal_deactivated_at'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN portal_deactivated_at DATETIME(6) NULL AFTER maintenance_expires_at');
        }
        if (!isset($columns['device_fingerprint_hash'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN device_fingerprint_hash BINARY(32) NULL AFTER device_label');
        }
        if (!isset($columns['license_last_sync_at'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN license_last_sync_at DATETIME(6) NULL AFTER last_seen_at');
        }
        if (!isset($columns['license_last_sync_status'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN license_last_sync_status VARCHAR(32) NULL AFTER license_last_sync_at');
        }
        if (!isset($columns['license_last_sync_error'])) {
            $pdo->exec('ALTER TABLE installations ADD COLUMN license_last_sync_error VARCHAR(300) NULL AFTER license_last_sync_status');
        }

        $indexes = [];
        foreach ($pdo->query('SHOW INDEX FROM installations')->fetchAll() as $index) {
            $indexes[(string)$index['Key_name']] = true;
        }
        if (!isset($indexes['ix_installations_portal_deactivated'])) {
            $pdo->exec('ALTER TABLE installations ADD INDEX ix_installations_portal_deactivated (portal_deactivated_at)');
        }
        $ready = true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ppe_customer_portal_schema_v1')")->fetchColumn();
    }
}
