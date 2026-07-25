ALTER TABLE issued_licenses
    MODIFY COLUMN activation_key VARCHAR(512) NULL
        COMMENT 'Deprecated legacy record; never used for new entitlements',
    ADD COLUMN IF NOT EXISTS entitlement_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER row_version;

ALTER TABLE issued_license_events
    ADD COLUMN IF NOT EXISTS admin_ip VARCHAR(45) NULL AFTER performed_by;

ALTER TABLE license_maintenance_events
    ADD COLUMN IF NOT EXISTS admin_ip VARCHAR(45) NULL AFTER performed_by;

UPDATE license_device_bindings
SET activation_method='LegacyMigration'
WHERE activation_method='ActivationKeyClaim';

ALTER TABLE license_device_bindings
    MODIFY COLUMN activation_method ENUM('PortalLink','AdminRecovery','LegacyMigration') NOT NULL;

UPDATE issued_licenses
SET entitlement_revision=GREATEST(1,row_version)
WHERE entitlement_revision<1;

INSERT IGNORE INTO development_migrations(migration_key)
VALUES ('account-device-entitlements-v1');
