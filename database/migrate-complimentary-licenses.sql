-- POS Printer Emulator Admin Portal complimentary license support.
-- Existing Manual and Purchase source values are preserved.

ALTER TABLE issued_licenses
    MODIFY COLUMN license_source
    ENUM('Manual', 'Purchase', 'Complimentary') NOT NULL DEFAULT 'Manual';

ALTER TABLE issued_licenses
    ADD COLUMN IF NOT EXISTS license_expires_at DATETIME(6) NULL AFTER source_reference,
    ADD COLUMN IF NOT EXISTS complimentary_reason VARCHAR(24) NULL AFTER license_expires_at,
    ADD COLUMN IF NOT EXISTS complimentary_note VARCHAR(500) NULL AFTER complimentary_reason;
