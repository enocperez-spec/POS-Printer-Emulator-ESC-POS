CREATE TABLE IF NOT EXISTS installations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_uuid CHAR(36) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    app_version VARCHAR(32) NOT NULL,
    license_mode ENUM('Trial', 'Full') NOT NULL DEFAULT 'Trial',
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
