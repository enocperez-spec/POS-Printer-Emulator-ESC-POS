CREATE TABLE IF NOT EXISTS portal_accounts (
    customer_id CHAR(36) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    mfa_secret_ciphertext VARBINARY(256) NULL,
    mfa_secret_nonce BINARY(12) NULL,
    mfa_secret_tag BINARY(16) NULL,
    mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    session_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (customer_id),
    CONSTRAINT fk_portal_account_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_password_resets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id CHAR(36) NOT NULL,
    code_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    used_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_recovery_code (customer_id, code_hash),
    KEY ix_portal_recovery_customer (customer_id, used_at),
    CONSTRAINT fk_portal_recovery_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    reset_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_hash),
    KEY ix_portal_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_support_replies (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_device_actions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_mail_outbox (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE installations
    ADD COLUMN IF NOT EXISTS device_label VARCHAR(120) NULL AFTER installation_uuid,
    ADD COLUMN IF NOT EXISTS windows_version VARCHAR(120) NULL AFTER app_version,
    ADD COLUMN IF NOT EXISTS portal_deactivated_at DATETIME(6) NULL AFTER maintenance_expires_at,
    ADD INDEX IF NOT EXISTS ix_installations_portal_deactivated (portal_deactivated_at);
