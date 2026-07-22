ALTER TABLE installations
    ADD COLUMN IF NOT EXISTS country_code CHAR(2) NOT NULL DEFAULT 'ZZ' AFTER maintenance_expires_at,
    ADD COLUMN IF NOT EXISTS region_code VARCHAR(8) NOT NULL DEFAULT '' AFTER country_code,
    ADD COLUMN IF NOT EXISTS geo_updated_at DATETIME(6) NULL AFTER region_code;

CREATE INDEX IF NOT EXISTS ix_installations_geography
    ON installations (country_code, region_code);

CREATE TABLE IF NOT EXISTS download_events_daily (
    event_date DATE NOT NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'ZZ',
    region_code VARCHAR(8) NOT NULL DEFAULT '',
    app_version VARCHAR(32) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'other',
    download_starts BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (event_date, country_code, region_code, app_version, source),
    KEY ix_download_events_geography (country_code, region_code, event_date),
    KEY ix_download_events_version (app_version, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
