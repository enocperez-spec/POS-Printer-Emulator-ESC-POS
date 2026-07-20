-- Expand the existing paid-tier enums without rewriting any license value.
-- Pro remains the default for legacy issued-license rows and activation keys.
ALTER TABLE installations
    MODIFY license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Trial';

ALTER TABLE issued_licenses
    MODIFY license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro';

UPDATE development_roadmap
SET status = 'Released',
    completed_at = COALESCE(completed_at, '2026-07-19 00:00:00.000000')
WHERE item_key = 'v0.3.25';

UPDATE development_roadmap
SET status = 'Released',
    completed_at = COALESCE(completed_at, '2026-07-20 00:00:00.000000')
WHERE item_key = 'v0.3.26';

UPDATE development_roadmap
SET status = 'Next',
    completed_at = NULL
WHERE item_key = 'v0.3.27';
