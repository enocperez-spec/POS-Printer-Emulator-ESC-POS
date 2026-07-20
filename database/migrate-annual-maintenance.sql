-- v0.3.26: permanent licenses with optional annual Application Maintenance and Support.
-- Existing paid licenses are grandfathered through July 19, 2027. Expiration affects
-- update/support entitlement only; it never changes the permanent paid license tier.
ALTER TABLE issued_licenses
    ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER source_reference,
    ADD COLUMN maintenance_revoked_at DATETIME(6) NULL AFTER maintenance_expires_at;

ALTER TABLE installations
    ADD COLUMN maintenance_status ENUM('NotApplicable', 'Active', 'Expired', 'Revoked') NOT NULL DEFAULT 'NotApplicable' AFTER license_id,
    ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER maintenance_status;

CREATE TABLE IF NOT EXISTS license_maintenance_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_refresh_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    reset_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_hash),
    KEY ix_maintenance_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE issued_licenses
SET maintenance_expires_at = '2027-07-19 23:59:59.000000'
WHERE maintenance_expires_at IS NULL;

INSERT IGNORE INTO license_maintenance_events
    (license_id, event_type, new_expires_at, source_reference, reason, performed_by, created_at)
SELECT license_id, 'LEGACY_GRANDFATHERED', maintenance_expires_at,
       CONCAT('grandfather:', license_id),
       'Existing paid license granted maintenance through July 19, 2027.',
       'schema-migration', UTC_TIMESTAMP(6)
FROM issued_licenses
WHERE maintenance_expires_at = '2027-07-19 23:59:59.000000';
