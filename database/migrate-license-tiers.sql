ALTER TABLE installations
    MODIFY license_mode ENUM('Trial', 'Full', 'Pro', 'Enterprise') NOT NULL DEFAULT 'Trial';

UPDATE installations SET license_mode = 'Pro' WHERE license_mode = 'Full';

ALTER TABLE installations
    MODIFY license_mode ENUM('Trial', 'Pro', 'Enterprise') NOT NULL DEFAULT 'Trial';

ALTER TABLE issued_licenses
    ADD COLUMN license_tier ENUM('Pro', 'Enterprise') NOT NULL DEFAULT 'Pro' AFTER email_address;
