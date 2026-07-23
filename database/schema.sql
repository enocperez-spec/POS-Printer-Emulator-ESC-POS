CREATE TABLE IF NOT EXISTS installations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_uuid CHAR(36) NOT NULL,
    device_label VARCHAR(120) NULL,
    customer_id CHAR(36) NULL,
    token_hash BINARY(32) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    app_version VARCHAR(32) NOT NULL,
    windows_version VARCHAR(120) NULL,
    license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Trial',
    license_id CHAR(36) NULL,
    maintenance_status ENUM('NotApplicable', 'Active', 'Expired', 'Revoked') NOT NULL DEFAULT 'NotApplicable',
    maintenance_expires_at DATETIME(6) NULL,
    portal_deactivated_at DATETIME(6) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'ZZ',
    region_code VARCHAR(8) NOT NULL DEFAULT '',
    geo_updated_at DATETIME(6) NULL,
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
    KEY ix_installations_license_id (license_id),
    KEY ix_installations_customer (customer_id),
    KEY ix_installations_portal_deactivated (portal_deactivated_at),
    KEY ix_installations_geography (country_code, region_code)
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

CREATE TABLE IF NOT EXISTS issued_licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro',
    activation_key VARCHAR(512) NOT NULL,
    activation_key_ciphertext VARBINARY(768) NULL,
    activation_key_nonce BINARY(12) NULL,
    activation_key_tag BINARY(16) NULL,
    activation_key_fingerprint BINARY(32) NULL,
    activation_key_ending CHAR(4) NULL,
    issued_at DATETIME(6) NOT NULL,
    created_by VARCHAR(80) NOT NULL DEFAULT 'owner',
    control_state ENUM('Enabled', 'Deactivated', 'Revoked', 'Deleted') NOT NULL DEFAULT 'Enabled',
    deactivated_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    deleted_at DATETIME(6) NULL,
    superseded_by_license_id CHAR(36) NULL,
    license_source ENUM('Manual', 'Purchase') NOT NULL DEFAULT 'Manual',
    source_reference VARCHAR(64) NULL,
    maintenance_expires_at DATETIME(6) NULL,
    maintenance_revoked_at DATETIME(6) NULL,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_issued_licenses_license_id (license_id),
    UNIQUE KEY uq_issued_licenses_key_fingerprint (activation_key_fingerprint),
    KEY ix_issued_licenses_customer (customer_id),
    KEY ix_issued_licenses_email (email_address),
    KEY ix_issued_licenses_issued_at (issued_at),
    KEY ix_issued_licenses_revoked_at (revoked_at),
    KEY ix_issued_licenses_control_state (control_state),
    KEY ix_issued_licenses_source_reference (source_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS support_request_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    reset_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_hash),
    KEY ix_support_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_requests (
    reference_code VARCHAR(32) NOT NULL,
    customer_id CHAR(36) NULL,
    license_id CHAR(36) NOT NULL,
    request_type ENUM('Bug Report', 'Feature Request', 'License Issue', 'Other Issue') NOT NULL,
    subject VARCHAR(160) NOT NULL,
    contact_name VARCHAR(160) NOT NULL,
    contact_email VARCHAR(254) NOT NULL,
    private_diagnostics MEDIUMTEXT NULL,
    github_issue_number BIGINT UNSIGNED NULL,
    github_issue_url VARCHAR(500) NULL,
    state ENUM('Pending', 'Submitted') NOT NULL DEFAULT 'Pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    submitted_at DATETIME(6) NULL,
    PRIMARY KEY (reference_code),
    KEY ix_support_requests_customer (customer_id),
    KEY ix_support_license_created (license_id, created_at),
    KEY ix_support_state_created (state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_consents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_purchases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_email_suppressions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_merge_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_customer_id CHAR(36) NOT NULL,
    target_customer_id CHAR(36) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    merged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_customer_merge_source (source_customer_id),
    KEY ix_customer_merge_target (target_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_email_verifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_admin_audit (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_api_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    reset_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_hash),
    KEY ix_customer_api_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value VARCHAR(500) NOT NULL,
    updated_by VARCHAR(80) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_templates (
    template_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    message_class ENUM('Service','Marketing') NOT NULL,
    essential TINYINT(1) NOT NULL DEFAULT 0,
    brevo_template_id BIGINT UNSIGNED NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    frequency_cap_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    description VARCHAR(500) NOT NULL,
    updated_by VARCHAR(80) NOT NULL DEFAULT 'system',
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (template_key),
    KEY ix_communication_templates_enabled (enabled, message_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_campaigns (
    campaign_id CHAR(36) NOT NULL,
    campaign_name VARCHAR(120) NOT NULL,
    template_key VARCHAR(64) NOT NULL,
    segment_key VARCHAR(64) NOT NULL,
    state ENUM('Draft','Scheduled','Running','Paused','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
    scheduled_at DATETIME(6) NULL,
    created_by VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (campaign_id),
    KEY ix_communication_campaigns_state (state, scheduled_at),
    CONSTRAINT fk_communication_campaign_template FOREIGN KEY (template_key) REFERENCES communication_templates(template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_outbox (
    message_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    template_key VARCHAR(64) NOT NULL,
    campaign_id CHAR(36) NULL,
    message_class ENUM('Service','Marketing') NOT NULL,
    essential TINYINT(1) NOT NULL DEFAULT 0,
    manual_send TINYINT(1) NOT NULL DEFAULT 0,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 50,
    recipient_hash BINARY(32) NOT NULL,
    parameters_json TEXT NOT NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    state ENUM('Pending','Processing','Deferred','Sent','Failed','DeliveryUnknown','Cancelled') NOT NULL DEFAULT 'Pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    locked_at DATETIME(6) NULL,
    provider_message_id VARCHAR(160) NULL,
    last_error_code VARCHAR(64) NULL,
    last_error_detail VARCHAR(240) NULL,
    sent_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (message_id),
    UNIQUE KEY uq_communication_idempotency (idempotency_key),
    KEY ix_communication_outbox_ready (state, priority, available_at),
    KEY ix_communication_outbox_customer (customer_id, created_at),
    KEY ix_communication_outbox_template (template_key, state, created_at),
    CONSTRAINT fk_communication_outbox_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_communication_outbox_template FOREIGN KEY (template_key) REFERENCES communication_templates(template_key),
    CONSTRAINT fk_communication_outbox_campaign FOREIGN KEY (campaign_id) REFERENCES communication_campaigns(campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_delivery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_event_key BINARY(32) NOT NULL,
    message_id CHAR(36) NULL,
    provider_message_id VARCHAR(160) NULL,
    event_type VARCHAR(40) NOT NULL,
    event_summary VARCHAR(240) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_communication_provider_event (provider_event_key),
    KEY ix_communication_delivery_message (message_id, occurred_at),
    KEY ix_communication_delivery_type (event_type, occurred_at),
    CONSTRAINT fk_communication_delivery_message FOREIGN KEY (message_id) REFERENCES communication_outbox(message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_quota_daily (
    quota_date DATE NOT NULL,
    provider_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    automated_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    service_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    marketing_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    manual_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (quota_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_lifecycle_daily (
    activity_date DATE NOT NULL,
    lifecycle_stage VARCHAR(40) NOT NULL,
    license_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
    customer_count INT UNSIGNED NOT NULL DEFAULT 0,
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (activity_date, lifecycle_stage, license_tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_lifecycle_presence (
    activity_date DATE NOT NULL,
    lifecycle_stage VARCHAR(40) NOT NULL,
    license_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
    customer_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (activity_date,lifecycle_stage,license_tier,customer_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO communication_settings(setting_key,setting_value,updated_by) VALUES
    ('emergency_stop','1','system'),
    ('marketing_pause','1','system'),
    ('provider_timezone','America/New_York','system'),
    ('provider_daily_limit','300','system'),
    ('automated_daily_limit','290','system'),
    ('service_reserve','50','system');

INSERT IGNORE INTO communication_templates
    (template_key,display_name,message_class,essential,enabled,frequency_cap_hours,description)
VALUES
    ('email_verification','Email verification','Service',1,0,1,'Verify a customer-controlled email address.'),
    ('password_recovery','Password recovery','Service',1,0,1,'Recover access to the Customer Portal.'),
    ('purchase_confirmation','Purchase confirmation','Service',1,0,1,'Confirm a completed purchase without including an activation key.'),
    ('activation_ready','Activation ready','Service',1,0,1,'Direct the customer to the secure portal for activation delivery.'),
    ('support_confirmation','Support request confirmation','Service',1,0,1,'Confirm a submitted support request and reference number.'),
    ('maintenance_reminder','Maintenance reminder','Service',0,0,168,'Remind an eligible customer before maintenance coverage ends.'),
    ('welcome_setup','Welcome and setup guidance','Service',0,0,72,'Help a newly registered customer complete setup.'),
    ('release_announcement','Release announcement','Marketing',0,0,168,'Announce an available product release to opted-in customers.'),
    ('trial_guidance','Trial guidance','Marketing',0,0,168,'Offer setup guidance to opted-in Trial customers.'),
    ('inactivity_help','Inactivity help','Marketing',0,0,720,'Offer help to an opted-in customer after an inactivity interval.'),
    ('promotion','Product promotion','Marketing',0,0,720,'Send an owner-approved promotion to opted-in customers.');

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

CREATE TABLE IF NOT EXISTS portal_checkout_intents (
    intent_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    license_id CHAR(36) NULL,
    installation_id BIGINT UNSIGNED NULL,
    checkout_token_hash BINARY(32) NOT NULL,
    order_type ENUM('MAINTENANCE','UPGRADE') NOT NULL,
    current_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
    target_tier ENUM('Lite','Pro','Enterprise') NOT NULL,
    state ENUM('Prepared','ProviderCreated','Captured','Fulfilled','Canceled','Expired','Refunded','ChargebackReview','Failed') NOT NULL DEFAULT 'Prepared',
    amount DECIMAL(10,2) NULL,
    currency CHAR(3) NULL,
    provider_order_id VARCHAR(64) NULL,
    provider_capture_id VARCHAR(64) NULL,
    replacement_license_id CHAR(36) NULL,
    maintenance_previous_expires_at DATETIME(6) NULL,
    maintenance_new_expires_at DATETIME(6) NULL,
    prepared_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    captured_at DATETIME(6) NULL,
    fulfilled_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (intent_id),
    UNIQUE KEY uq_portal_checkout_token (checkout_token_hash),
    UNIQUE KEY uq_portal_checkout_provider_order (provider_order_id),
    UNIQUE KEY uq_portal_checkout_provider_capture (provider_capture_id),
    KEY ix_portal_checkout_customer (customer_id, prepared_at),
    KEY ix_portal_checkout_license (license_id, prepared_at),
    KEY ix_portal_checkout_installation (installation_id, prepared_at),
    CONSTRAINT fk_portal_checkout_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_portal_checkout_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_portal_checkout_installation FOREIGN KEY (installation_id) REFERENCES installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_checkout_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    intent_id CHAR(36) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    event_summary VARCHAR(500) NOT NULL,
    event_data JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_portal_checkout_events (intent_id, created_at),
    CONSTRAINT fk_portal_checkout_event_intent FOREIGN KEY (intent_id)
        REFERENCES portal_checkout_intents(intent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_promotion_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id CHAR(36) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    consumed_at DATETIME(6) NULL,
    consumed_by_promotion_id CHAR(36) NULL,
    PRIMARY KEY (id),
    KEY ix_portal_promotion_exception_customer (customer_id, consumed_at),
    CONSTRAINT fk_portal_promotion_exception_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_promotions (
    promotion_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    license_id CHAR(36) NULL,
    installation_id BIGINT UNSIGNED NULL,
    exception_id BIGINT UNSIGNED NULL,
    previous_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
    granted_tier ENUM('Lite','Pro','Enterprise') NOT NULL,
    state ENUM('Active','Expired','Canceled','Superseded') NOT NULL DEFAULT 'Active',
    entitlement_token_hash BINARY(32) NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    ended_at DATETIME(6) NULL,
    created_by VARCHAR(80) NOT NULL,
    exception_reason VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (promotion_id),
    UNIQUE KEY uq_portal_promotion_token (entitlement_token_hash),
    KEY ix_portal_promotion_customer (customer_id, created_at),
    KEY ix_portal_promotion_license (license_id, state),
    KEY ix_portal_promotion_installation (installation_id, state),
    CONSTRAINT fk_portal_promotion_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_portal_promotion_account FOREIGN KEY (customer_id) REFERENCES portal_accounts(customer_id),
    CONSTRAINT fk_portal_promotion_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_portal_promotion_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
    CONSTRAINT fk_portal_promotion_exception FOREIGN KEY (exception_id) REFERENCES portal_promotion_exceptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_promotion_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promotion_id CHAR(36) NOT NULL,
    claim_type ENUM('Customer','Account','License','Installation') NOT NULL,
    claim_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_promotion_claim (claim_type, claim_hash),
    KEY ix_portal_promotion_claim_promotion (promotion_id),
    CONSTRAINT fk_portal_promotion_claim_promotion FOREIGN KEY (promotion_id)
        REFERENCES portal_promotions(promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_promotion_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promotion_id CHAR(36) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    previous_state VARCHAR(32) NULL,
    new_state VARCHAR(32) NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_portal_promotion_events (promotion_id, created_at),
    CONSTRAINT fk_portal_promotion_event FOREIGN KEY (promotion_id)
        REFERENCES portal_promotions(promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_request_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_code VARCHAR(32) NOT NULL,
    file_name VARCHAR(120) NOT NULL,
    content_type VARCHAR(64) NOT NULL,
    content MEDIUMBLOB NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT fk_support_attachment_request FOREIGN KEY (reference_code)
        REFERENCES support_requests(reference_code) ON DELETE CASCADE,
    KEY ix_support_attachment_reference (reference_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issued_license_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id CHAR(36) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    email_address VARCHAR(254) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    previous_state VARCHAR(24) NULL,
    new_state VARCHAR(24) NULL,
    previous_tier VARCHAR(24) NULL,
    new_tier VARCHAR(24) NULL,
    replacement_license_id CHAR(36) NULL,
    reason VARCHAR(500) NULL,
    performed_by VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_license_events_license_id (license_id),
    KEY ix_license_events_created_at (created_at),
    KEY ix_license_events_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_roadmap (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_key VARCHAR(32) NOT NULL,
    version_label VARCHAR(32) NULL,
    item_type ENUM('Release', 'Backlog') NOT NULL,
    title VARCHAR(180) NOT NULL,
    status ENUM('Released', 'Next', 'Planned', 'In progress', 'Deferred') NOT NULL,
    priority_rank INT UNSIGNED NOT NULL,
    purpose TEXT NOT NULL,
    planned_scope TEXT NOT NULL,
    priority_reason TEXT NOT NULL,
    completion_criteria TEXT NOT NULL,
    github_url VARCHAR(500) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_development_roadmap_item_key (item_key),
    KEY ix_development_roadmap_type_status (item_type, status),
    KEY ix_development_roadmap_priority (priority_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_bugs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bug_key VARCHAR(16) NOT NULL,
    title VARCHAR(220) NOT NULL,
    severity ENUM('Critical', 'High', 'Medium', 'Low') NOT NULL,
    status ENUM('Reported', 'Confirmed', 'In progress', 'Fixed locally', 'Released', 'Deferred', 'Closed - not a bug') NOT NULL DEFAULT 'Reported',
    affected_versions VARCHAR(160) NOT NULL DEFAULT '',
    target_release VARCHAR(32) NULL,
    fixed_version VARCHAR(32) NULL,
    customer_impact TEXT NOT NULL,
    expected_behavior TEXT NOT NULL,
    actual_behavior TEXT NOT NULL,
    reproduction_steps TEXT NOT NULL,
    verification TEXT NOT NULL,
    github_url VARCHAR(500) NULL,
    resolved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_development_bugs_bug_key (bug_key),
    KEY ix_development_bugs_status (status),
    KEY ix_development_bugs_severity (severity),
    KEY ix_development_bugs_target_release (target_release)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_migrations (
    migration_key VARCHAR(96) NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria, completed_at)
VALUES
    ('v0.1.00', 'v0.1.00', 'Release', 'Initial MVP', 'Released', 100, 'Establish the first working receipt-emulation vertical slice.', 'Local ESC/POS listener, defensive parser, receipt viewer, and MVP build tooling.', 'Initial product foundation.', 'The initial listener, parser, viewer, and build tooling were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.2.00', 'v0.2.00', 'Release', 'Desktop application and installer', 'Released', 200, 'Deliver a branded Windows desktop product.', 'POS Printer Emulator branding, desktop HTML application, all-in-one installer, service configuration, and clean uninstall.', 'Turn the MVP into an installable product.', 'The branded desktop application and installer were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.00', 'v0.3.00', 'Release', 'Trial and Pro licensing', 'Released', 300, 'Add commercial Trial and Pro editions.', 'Offline signed activation, installer registration, full history, exports, and in-app activation.', 'Enable product evaluation and paid upgrades.', 'Trial and Pro licensing were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.01', 'v0.3.01', 'Release', 'Activity controls and vendor tools', 'Released', 301, 'Improve daily receipt management and launch vendor operations.', 'Collapsible panels, job deletion, stored-graphics parsing, License Manager, and public website.', 'Improve usability and support license operations.', 'The activity controls and vendor tools were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.02', 'v0.3.02', 'Release', 'Updated Test Receipt', 'Released', 302, 'Use the approved business details in the built-in receipt.', 'Updated Atlanta address, Check label, and E. Perez server name.', 'Keep the demonstration receipt accurate.', 'The updated Test Receipt was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.03', 'v0.3.03', 'Release', 'Settings, updates, and support', 'Released', 303, 'Centralize administration inside the desktop application.', 'Settings sections for License, Check for Updates, and Support with diagnostics and GitHub release updates.', 'Make licensing, updates, and support discoverable.', 'Settings, updates, and diagnostics were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.04', 'v0.3.04', 'Release', 'Telemetry and owner dashboard', 'Released', 304, 'Provide privacy-safe installation and usage visibility.', 'Aggregate telemetry, MariaDB schema, owner dashboard, usage charts, and publishing tools.', 'Support product operations without uploading receipt contents.', 'Telemetry and the owner dashboard were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.05', 'v0.3.05', 'Release', 'Unified administration and 2FA', 'Released', 305, 'Consolidate owner operations securely.', 'Direct Settings dialog, unified admin portal, activation-key generation, history, and authenticator-app 2FA.', 'Remove duplicate admin areas and protect vendor functions.', 'Unified administration and 2FA were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.06', 'v0.3.06', 'Release', 'Updater file-lock correction', 'Released', 306, 'Allow downloaded updates to launch reliably.', 'Unique download staging directories and correct installer file-handle disposal.', 'Correct a High-severity update failure.', 'The corrected updater was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.07', 'v0.3.07', 'Release', 'Faster upgrade registration', 'Released', 307, 'Avoid asking existing customers to re-enter saved registration.', 'Reuse valid saved customer details and prefill partial registration during upgrade.', 'Reduce friction during update installation.', 'Registration reuse was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.08', 'v0.3.08', 'Release', 'Windows branding', 'Released', 308, 'Apply the approved icon throughout Windows.', 'Branded executable, shortcuts, taskbar, uninstaller, and setup icons.', 'Make the installed product recognizable.', 'Windows branding was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.09', 'v0.3.09', 'Release', 'Raster receipt images', 'Released', 309, 'Render standard ESC/POS raster artwork.', 'Monochrome logo in the Test Receipt and GS v 0 raster-image parsing and preview.', 'Improve real receipt fidelity.', 'Raster receipt images were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.10', 'v0.3.10', 'Release', 'Printer Setup Wizard', 'Released', 310, 'Automate Windows printer configuration for nontechnical customers.', 'Epson driver detection and installation, RAW TCP/IP port, printer queue, verification, rollback, and Test Receipt.', 'Remove manual printer and driver setup.', 'The Printer Setup Wizard was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.11', 'v0.3.11', 'Release', 'Printer-state simulation', 'Released', 311, 'Emulate Epson printer status behavior for POS testing.', 'Ready and error scenarios, DLE EOT, GS a Automatic Status Back, DLE ENQ recovery, counters, diagnostics, and tests.', 'Support POS software that monitors printer state.', 'Printer-state simulation was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.12', 'v0.3.12', 'Release', 'Expanded ESC/POS compatibility', 'Released', 312, 'Render more real-world receipt commands accurately.', 'QR, barcode, legacy images, font modes, positioning, code pages, diagnostics, and standards-based QR output.', 'Reduce unsupported commands on customer receipts.', 'Expanded ESC/POS compatibility was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.13', 'v0.3.13', 'Release', 'Imported Stored Logos', 'Released', 313, 'Substitute customer artwork for printer-resident Epson NV graphics.', 'Stored Logos library, persistent local storage, NV key mapping, replacement controls, and regression tests.', 'Handle POS jobs that reference graphics stored in physical printers.', 'Imported Stored Logos were publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.14', 'v0.3.14', 'Release', 'Reliable dashboard usage reporting', 'Released', 314, 'Ensure completed print-job counts reach the owner dashboard.', 'Canonical HTTPS telemetry, retained pending totals, retry behavior, and POST-preserving redirects.', 'Correct missing aggregate print-job counts.', 'Reliable telemetry was publicly released.', UTC_TIMESTAMP(6)),
    ('v0.3.15', 'v0.3.15', 'Release', 'Capture, import, export, and replay', 'Released', 315, 'Make real customer print streams reproducible without reconnecting the original POS.', 'Capture original bytes and metadata, import bin files and capture packages, export with integrity checks, replay safely, label imported and replayed jobs, and keep capture data local.', 'Capture and replay provide reusable test data for profiles, comparison, and diagnostics.', 'A binary receipt imports, renders, exports, re-imports, and replays with identical bytes and output, while malformed files fail safely.', UTC_TIMESTAMP(6)),
    ('v0.3.16', 'v0.3.16', 'Release', 'In-place receipt export correction', 'Released', 316, 'Correct the v0.3.15 desktop export failure without delaying the customer fix.', 'Blob-based Text, Raw, and Capture downloads, native Windows Save dialog, resilient post-startup WebView navigation handling, progress, and errors.', 'Customers must be able to save receipt artifacts without leaving the selected receipt.', 'All three formats download, the viewer remains visible, and the desktop no longer shows a ConnectionAborted startup error.', UTC_TIMESTAMP(6)),
    ('v0.3.17', 'v0.3.17', 'Release', 'License tiers and Pro feature gates', 'Released', 317, 'Establish Trial, Pro, and Enterprise licensing before Enterprise-specific features are introduced.', 'Tier-aware activation keys, legacy-key compatibility, Pro feature gates for Stored Logos, Printer State, Updates, and Support, telemetry, database migration, and admin issuance.', 'A stable commercial boundary must precede additional paid and Enterprise functionality.', 'Trial requests are locked in the UI and APIs while Pro, Enterprise, and legacy paid keys receive the correct access.', UTC_TIMESTAMP(6)),
    ('v0.3.18', 'v0.3.18', 'Release', 'Admin Portal and tier-aware purchase pricing', 'Released', 318, 'Give the business one clearly named administration area and sell Pro and Enterprise licenses independently.', 'Admin Portal branding, separate Pro and Enterprise prices, tier-aware PayPal orders, approval, activation-key issuance, email delivery, backward-compatible order migration, and safe private-file deployment filtering.', 'Commercial license tiers require matching server-controlled purchase pricing and fulfillment.', 'Both prices save independently and every approved order receives the tier purchased by the customer.', UTC_TIMESTAMP(6)),
    ('v0.3.19', 'v0.3.19', 'Release', 'Printer profiles', 'Released', 319, 'Model differences between printer configurations explicitly.', 'Pro and Enterprise built-in and custom profiles for paper width, dots, image limits, code pages, fonts, cutter, drawer, images, barcode/QR, two-color output, DLE EOT, Automatic Status Back, import, export, job metadata, capture metadata, replay, capability warnings, and Trial API/UI gates.', 'Profiles define behavior before multiple endpoints depend on it.', 'One capture replayed against two profiles shows deterministic expected capability and rendering differences, while Trial access is rejected.', UTC_TIMESTAMP(6)),
    ('v0.3.20', 'v0.3.20', 'Release', 'Reliable SQLite receipt history', 'Released', 320, 'Replace individual paid-history JSON files with a minimal transactional local database.', 'Embedded SQLite for Pro and Enterprise, session-only Trial behavior, schema versioning, WAL, transactions, listener-ready indexes, 500-job retention, verified JSON migration, rollback backup, damaged-row isolation, durable deletion, hardened permissions, and release-runtime verification.', 'Reliable storage is required before independently configured listeners share receipt history.', 'Existing paid history migrates without loss, Trial creates no database, paid history survives restart within its limit, and the all-in-one installer loads the bundled SQLite runtime.', UTC_TIMESTAMP(6)),
    ('v0.3.21', 'v0.3.21', 'Release', 'Enterprise multiple printer listeners', 'Released', 321, 'Let one Enterprise installation emulate multiple receipt printers while Trial and Pro retain one local listener.', 'Persisted listener configuration; independent names, IPv4 addresses, ports, profiles, printer state, bounded buffers, counters, routing, Activity filtering, conflict validation, program-scoped firewall setup, Enterprise UI/API gates, capture metadata, and fault isolation.', 'Transactional storage and profiles provide the reliable foundation needed for isolated multi-printer operation.', 'Two simultaneous Enterprise listeners receive jobs, apply different profiles, restart safely, persist configuration, and remain independently controllable while Trial and Pro single-listener behavior remains unchanged.', '2026-07-18 00:00:00.000000'),
    ('v0.3.22', 'v0.3.22', 'Release', 'Receipt workflow regression fixes', 'Released', 322, 'Restore fast Test Receipt feedback and reliable paid-history cleanup.', 'Immediate complete Test Receipt response and selection; background Activity refresh; redundant detail-fetch avoidance; SQLite-authoritative Clear All; best-effort obsolete legacy JSON cleanup; plain-language delete failures; regression and end-to-end timing coverage.', 'Core receipt workflows must be reliable before the next feature release.', 'Test Receipt appears without a multi-second delay, Clear All removes paid history without HTTP 500, deletion remains durable, and all automated and end-to-end tests pass.', '2026-07-18 00:00:00.000000'),
    ('v0.3.23', 'v0.3.23', 'Release', 'Activation and Printer Setup Wizard fixes', 'Released', 323, 'Correct High-severity Enterprise activation and Windows printer installation failures.', 'Resilient Enterprise activation and storage recovery; safe malformed-key handling; unique temporary persistence; native Windows AddPrinter queue creation; retained Epson driver, TCP/IP port, verification, rollback, and error reporting; automated and installed Windows verification.', 'Core activation and printer setup must be reliable before the next feature release.', 'Valid Enterprise activation avoids HTTP 500, the wizard creates the Epson queue on 127.0.0.1:9100 without Invalid parameter, the Test Receipt sends successfully, and all 83 tests pass.', '2026-07-19 00:00:00.000000'),
    ('v0.3.24', 'v0.3.24', 'Release', 'Upgrade licensing and Printer Setup safeguards', 'Released', 324, 'Preserve paid licensing through updates and prevent Windows printer-port conflicts.', 'Matched registration and activation recovery; hardened-folder ACL repair; license-aware post-update health; Trial-safe activation diagnostics; sequential Windows port selection; automatic Enterprise listener alignment; repeated conflict checks; rollback; installed validation.', 'Upgrade and printer setup reliability must be restored before the next feature release.', 'Paid activation survives upgrade and maintenance reinstall, Trial can export activation diagnostics, a second Enterprise printer receives a test job on the first free port, and all 105 tests pass.', '2026-07-19 00:00:00.000000'),
    ('v0.3.25', 'v0.3.25', 'Release', 'Four-tier licensing and upgrade paths', 'Released', 325, 'Add an affordable Lite license while preserving every existing Pro and Enterprise activation key and purchase record.', 'Trial, Lite, Pro, and Enterprise licensing; Lite activation tier byte 3 with legacy key compatibility; Lite $24.99 server-controlled pricing; tier-targeted purchase links; PayPal fulfillment and email; Admin Portal issuance, replacement, Trial upgrade, audits, and purchase synchronization; Lite single-listener access, Pro capacity up to two listeners, and Enterprise capacity up to fifteen.', 'The commercial and activation contracts must stay aligned before Lite keys are sold or upgraded.', 'Existing Pro and Enterprise keys remain valid, a Lite purchase completes through activation and telemetry, all three paid tiers can be issued or replaced safely, targeted purchase links preselect the requested tier, and automated licensing and commerce tests pass.', '2026-07-19 00:00:00.000000'),
    ('v0.3.26', 'v0.3.26', 'Release', 'Annual Application Maintenance and Support', 'Released', 326, 'Keep permanent-license ownership separate from optional annual updates and technical support.', 'One included year for new paid licenses; existing-license grandfathering through July 19, 2027; optional one-time Lite $9.99, Pro $19.99, and Enterprise $59.99 renewals; signed entitlement refresh; server-verified PayPal renewal orders; Admin pricing, status, history, extension, and revocation controls; telemetry without keys or receipt data.', 'Maintenance must be implemented before future releases are delivered under the coverage policy.', 'Permanent paid features keep working after coverage ends, early and lapsed renewals calculate correctly and idempotently, only covered customers receive signed update entitlements, and commerce, licensing, migration, and telemetry tests pass.', '2026-07-20 00:00:00.000000'),
    ('v0.3.30', 'v0.3.30', 'Release', 'Security remediation (Phase 1)', 'Released', 330, 'Resolve the actionable security findings from the completed deep review before adding more externally reachable functionality.', 'Credential rotation and separation; web and desktop boundary hardening; sensitive-data protection; signed update and installer verification; automated security checks and regression coverage.', 'Security findings affecting the public website, Admin Portal, purchase flow, and Windows application must be remediated before feature development continues.', 'No critical or high findings remain, exposed credentials are rejected and absent from tracked files and logs, security tests pass, and trusted update and installer verification succeeds.', '2026-07-21 00:00:00.000000'),
    ('v0.3.31', 'v0.3.31', 'Release', 'Secure development lifecycle (Phase 2)', 'Released', 331, 'Make security review and verification a repeatable requirement for every future product release.', 'Security checklist and threat-model notes; automated security checks; API and desktop regression suites; tracked evidence; explicit sign-off; scheduled reviews.', 'The Phase 1 protections must remain enforceable as the website, Admin Portal, and desktop application evolve.', 'The documented checklist, CI gates, regression suites, tracker evidence, and release sign-off are exercised successfully on a complete release.', '2026-07-21 00:00:00.000000'),
    ('v0.3.32', 'v0.3.32', 'Release', 'Updater installer-asset validation', 'Released', 332, 'Prevent documentation-only GitHub releases from being presented as installable Windows updates.', 'Require a trusted Windows executable asset, report releases without an installer, add regression tests, and publish the self-contained installer and checksum.', 'Customers must receive a real installer asset instead of a GitHub release webpage when the desktop updater offers installation.', 'Installed customers receive a valid v0.3.32 installer download, while releases without a Windows installer cannot trigger installation.', '2026-07-21 00:00:00.000000'),
    ('v0.3.33', 'v0.3.33', 'Release', 'Enhanced support package and connection diagnostics', 'Released', 333, 'Guide nontechnical customers through emulator, printer, listener, and Windows configuration problems and produce privacy-reviewed support evidence.', 'Guided emulator-side checks for the service, viewer, storage, listeners, ports, firewall, Windows queues, and Epson drivers; reviewed repair actions; previewed redacted ZIP packages; and an in-app Support Request workflow that sends consented, redacted reports through a secure backend to correctly labeled GitHub issues without embedding GitHub credentials.', 'Customer diagnostics, safe package export, and structured support requests reduce support time while avoiding unreliable testing of unknown POS implementations.', 'Supported emulator and Windows failures are explained and safely repairable; support packages and GitHub issues exclude receipt contents, IP addresses, contact details, and secrets; offline drafts survive restart and retry.', '2026-07-21 00:00:00.000000'),
    ('v0.3.34', 'v0.3.34', 'Release', 'Encrypted backup, EULA, and support policy', 'Released', 334, 'Protect portable emulator configuration while presenting consistent product-use, licensing, compatibility, privacy, support, and liability terms.', 'Password-encrypted PPE backups with verified preview and rollback-safe restore; installer EULA acceptance; canonical website EULA; EPCOM Ltd. and Georgia jurisdiction; Windows 11 Pro and third-party POS support boundaries; and maintenance-response terms.', 'Customers need both a safe configuration recovery path and clear legal and support terms before comparison suites and guided updates expand the workflow.', 'Encrypted backup validation and rollback protection pass all 151 tests; website and installer terms match; acceptance is required; release and SEO checks pass; and the installer checksum is published.', '2026-07-21 00:00:00.000000'),
    ('v0.3.35', 'v0.3.35', 'Release', 'Backup restore usability and compatibility', 'Released', 335, 'Remove confusing Windows ZIP behavior from encrypted backups and make restoration understandable without leaving the application.', 'Native .ppebackup save handling; compatibility with v0.3.34 .ppebackup.zip file names; accessible in-app restore guidance; and a responsive illustrated website guide.', 'Customers must be able to recover the v0.3.34 backup they already created without extracting an encrypted package or guessing the restore sequence.', 'New backups keep .ppebackup, legacy names restore successfully, all 158 tests and rendered restore-flow checks pass, and the guide plus screenshots are public.', '2026-07-22 00:00:00.000000'),
    ('v0.3.36', 'v0.3.36', 'Release', 'Privacy-preserving geographic analytics dashboard', 'Released', 336, 'Show where website downloads and product activity occur without retaining raw IP addresses.', 'Coarse country and U.S. state derivation; transient IP processing; daily download-start aggregates; world and United States maps; exact regional tables; date, metric, license, and version filters; accessibility; privacy and EULA disclosures; and automated contract checks.', 'Geographic adoption data helps EPCOM prioritize documentation, compatibility, and support while data minimization protects customers.', 'The private Admin dashboard reports approximate regional download starts, installations, launches, and print jobs; raw IP addresses are not stored in the analytics schema; filters and keyboard controls work; and legal disclosures match implementation.', '2026-07-22 00:00:00.000000'),
    ('v0.3.37', 'v0.3.37', 'Release', 'Trial Setup and Onboarding Improvements', 'Released', 337, 'Let nontechnical Trial customers begin testing immediately without understanding listeners, ports, or Windows printer configuration.', 'First-launch welcome; Trial Configuration Wizard; one automatic listener; confirmed sequential port recovery; unlimited ephemeral Test Receipts; complete-job allowance counter; irreversible ten-line redaction after the fifth external job; upgrade guidance; and local diagnostics.', 'Removing first-run friction improves evaluation while ingestion-time redaction protects receipt data after the Trial allowance is used.', 'Fresh Trial setup, unlimited Test Receipts, port-conflict recovery, five complete external jobs, accepted redacted later jobs, and non-recoverability across APIs, history, exports, and diagnostics are verified.', '2026-07-22 00:00:00.000000'),
    ('v0.3.38', 'v0.3.38', 'Release', 'Trial Onboarding Clarity Correction', 'Released', 338, 'Make Trial setup impossible to lose and show customers exactly where their POS must send print jobs.', 'Versioned and reopenable two-step welcome guide; wizard-first instruction; visible read-only included listener; local and LAN IPv4 endpoints; copyable RAW TCP details; and retained server-side mutation denial.', 'The v0.3.37 guide could remain dismissed and hid the included listener behind an upgrade panel, leaving customers unsure how to connect.', 'Fresh and upgraded Trial installations see and can reopen the guide, view one locked listener, copy exact connection details, and receive HTTP 403 for listener mutations.', '2026-07-22 00:00:00.000000'),
    ('v0.3.39', 'v0.3.39', 'Release', 'Guided update installation and restart', 'Released', 339, 'Close the application safely before an update replaces installed files, then return the customer to the updated application.', 'Background installer download; SHA-256 verification; pre-update safety snapshot; install confirmation and safe deferral; active-job drain; listener and service shutdown; external updater process; file-lock wait; state preservation; minimal-prompt installation; automatic relaunch; success confirmation; and recovery-safe failure handling.', 'A controlled external updater eliminates self-update file locks without unexpected listener downtime or lost customer state.', 'Install and Restart completes without locked-file errors, relaunches the new version, preserves customer state and data, and leaves the current installation usable after cancellation or failure.', '2026-07-22 00:00:00.000000'),
    ('v0.3.40', 'v0.3.40', 'Release', 'Simple Mode and Expert Mode', 'Released', 340, 'Give new customers a task-focused experience while preserving the complete expert workspace.', 'Simple task cards; plain-language health and next action; retained Expert Mode; remembered mode choice; state-preserving switching; and unchanged server-side license enforcement.', 'Persistent task guidance addresses customer confusion without removing advanced receipt inspection.', 'Customers complete setup, connection, testing, review, and diagnostics in Simple Mode and switch to Expert Mode without losing state.', '2026-07-22 00:00:00.000000'),
    ('v0.3.41', 'v0.3.41', 'Release', 'Installer Branding Correction', 'Released', 341, 'Correct the stretched product artwork in the Windows installer.', 'Independent square and tall installer artwork with validated proportions.', 'Preserve the official product logo at installer dimensions.', 'The v0.3.41 installer displays proportional artwork and rejects invalid banner dimensions.', '2026-07-22 00:00:00.000000'),
    ('v0.3.42', 'v0.3.42', 'Release', 'Customer identity, consent, and CRM foundation', 'Released', 342, 'Connect registrations, installations, licenses, maintenance, support, and consent through one privacy-aware customer record.', 'Canonical customers; safe unverified backfill; duplicate review and merge history; verified ownership evidence; masked key lookup; consent, suppression, lifecycle, and audit ledgers; permission-controlled Admin search and export; and authenticated service APIs.', 'The Customer Portal and automated communications require trustworthy identity and consent foundations.', 'Existing entitlements remain unchanged, duplicate emails are not automatically merged, customer actions are auditable, exports exclude prohibited data, and CRM security tests pass.', '2026-07-22 00:00:00.000000'),
    ('v0.3.43', 'v0.3.43', 'Release', 'Secure Customer Portal MVP', 'Released', 343, 'Give verified customers secure self-service access to owned product records.', 'Verified accounts, recovery, optional MFA, masked licenses, maintenance, downloads, preferences, support history, and device deactivation.', 'The portal depends on v0.3.42 ownership and consent boundaries.', 'Customers can access only their own verified records and sensitive actions require reauthentication.', '2026-07-23 00:00:00.000000'),
    ('v0.3.44', 'v0.3.44', 'Release', 'Self-service renewals, upgrades, and promotional trials', 'Released', 344, 'Provide auditable self-service commercial workflows without turning permanent licenses into subscriptions.', 'PayPal maintenance renewal, tier upgrades, refunds, idempotent fulfillment, and one five-day promotional paid-edition trial.', 'Commercial workflows require the secure portal and canonical ownership records.', 'Payments and temporary entitlements are idempotent, auditable, and restore prior permanent access correctly.', '2026-07-23 00:00:00.000000'),
    ('v0.3.45', 'v0.3.45', 'Release', 'Consent-aware lifecycle communications and CRM analytics', 'Released', 345, 'Deliver useful lifecycle messages through Brevo while honoring consent and provider limits.', 'Protected Brevo integration, durable priority outbox, quota deferral, templates, authenticated webhooks, suppression, minimal telemetry, segmentation, and Admin dashboards.', 'Communication automation must follow the consent and commercial foundations.', 'Eligible messages send exactly once, opt-outs and suppressions are honored, quotas do not lose mail, and prohibited data never reaches Brevo.', '2026-07-23 00:00:00.000000'),
    ('v0.3.46', 'v0.3.46', 'Release', 'Accessibility and keyboard usability', 'In progress', 346, 'Make primary workflows usable with keyboard and assistive technology.', 'Maximized first launch, taskbar-safe remembered placement, focus order, semantics, scaling, high contrast, reduced motion, captions, and WCAG regression checks.', 'Accessibility should be established before additional interface growth.', 'Window placement behaves safely and primary workflows pass keyboard, Narrator, scaling, contrast, and automated checks.', NULL),
    ('v0.3.47', 'v0.3.47', 'Release', 'Automatic configuration restore points', 'Planned', 347, 'Protect customers from accidental configuration loss.', 'Encrypted pre-change and scheduled restore points, bounded retention, previews, transactional restore, and rollback.', 'Recovery protection precedes greater configuration complexity.', 'Customers restore a previous configuration without partial state, secret exposure, or license loss.', NULL),
    ('v0.3.48', 'v0.3.48', 'Release', 'Projects and testing sessions', 'Planned', 348, 'Organize receipt testing into isolated customer projects.', 'Projects, sessions, notes, tags, profiles, captures, baselines, reports, safe export, and integrity validation.', 'Projects establish clean boundaries for later comparison suites.', 'Projects remain isolated and export without leaking unrelated data.', NULL),
    ('v0.3.49', 'v0.3.49', 'Release', 'Privacy-safe receipt masking', 'Planned', 349, 'Reduce sensitive data exposure when receipts are shared.', 'Privacy View, configurable masking, safe screenshots, exports, reports, and support attachments with original preservation.', 'Sharing workflows require explicit privacy controls.', 'Privacy-safe artifacts contain no configured sensitive values.', NULL),
    ('v0.3.50', 'v0.3.50', 'Release', 'System tray health and notifications', 'Planned', 350, 'Keep customers informed while the main window is closed.', 'Tray health, quick actions, privacy-safe alerts, deduplication, rate limits, and recovery clearing.', 'Background awareness reduces missed faults.', 'One actionable notification represents each fault and clears after recovery.', NULL),
    ('v0.3.51', 'v0.3.51', 'Release', 'Character and code-page assistant', 'Planned', 351, 'Help customers correct receipt encoding problems.', 'Encoding diagnosis, byte tracing, code-page previews, profile recommendations, and immutable captures.', 'Deterministic encoding improves later comparisons.', 'Known fixtures produce the correct diagnosis without changing original bytes.', NULL),
    ('v0.3.52', 'v0.3.52', 'Release', 'Offline Enterprise update packages', 'Planned', 352, 'Support secure updates on restricted networks.', 'Signed portable manifests, removable-media verification, downgrade protection, and offline entitlement guidance.', 'Restricted networks require a trusted offline workflow.', 'Valid packages install and invalid packages leave the current installation unchanged.', NULL),
    ('v0.3.53', 'v0.3.53', 'Release', 'Receipt comparison and automated validation', 'Planned', 353, 'Provide repeatable compatibility testing.', 'Compare bytes, commands, text, warnings, and rendering against baselines with machine-readable results.', 'Earlier projects, privacy, and encoding work provide safe deterministic inputs.', 'Known-good captures pass and intentional differences fail precisely.', NULL),
    ('v0.3.54', 'v0.3.54', 'Release', 'Update Notifications for All License Types', 'Planned', 354, 'Notify every license tier about newer public releases.', 'Installed/latest versions, releases behind, summary, manual Trial download, entitlement-aware update and renewal actions, offline cache, and trusted links.', 'Awareness is universal while installation still honors maintenance.', 'Every license state receives accurate notification without bypassing maintenance rules.', NULL),
    ('BACKLOG-001', NULL, 'Backlog', 'Service authentication and installer repair', 'Planned', 1001, 'Protect state-changing local APIs and provide a supported recovery path.', 'Per-installation credentials, origin restrictions, protected operations, repair workflow, data preservation, action logs, and health verification.', 'Highest backlog priority because it closes a security boundary before storage and licensing grow more complex.', 'Unauthorized local writes are rejected and repair restores a damaged installation without losing customer data.', NULL),
    ('BACKLOG-007', NULL, 'Backlog', 'Listener security and lifecycle hardening', 'Planned', 1002, 'Bound network resource use and make listener management cancellation-safe.', 'Per-listener and global connection caps, per-source and slow-client limits, aggregate in-flight byte limits, queue memory controls, rate-limited diagnostics, cancellation-safe lifecycle completion or rollback, atomic profile assignment/deletion, reviewed firewall narrowing, and adversarial concurrency tests.', 'Configurable private-network listeners increase the service resource and lifecycle surface, so hardening should precede larger histories and additional network-facing features.', 'Untrusted or slow LAN clients cannot cause unbounded memory growth, management cancellation cannot strand a listener transition, profile changes cannot race listener updates, and healthy listeners remain isolated.', NULL),
    ('BACKLOG-002', NULL, 'Backlog', 'Advanced SQLite maintenance and retention', 'Planned', 1003, 'Extend the v0.3.20 SQLite foundation with customer-facing scale and recovery controls.', 'Paging, fast search, source/listener/profile filters, aggregate counts, configurable count/size/age and fair per-listener retention, health checks, repair, backup, restore, and reviewed legacy-backup cleanup.', 'The transactional foundation and safe JSON migration are now part of v0.3.20; maintenance controls should follow after the listener runtime is hardened.', 'Large histories remain fast, one busy listener cannot evict all other history, and customers can validate, retain, back up, restore, repair, and safely clean migrated data.', NULL),
    ('BACKLOG-003', NULL, 'Backlog', 'Production code-signing and deployment validation', 'Planned', 1004, 'Improve customer trust and verify distributed binaries.', 'Sign executables, installer, and uninstaller, apply trusted timestamps, verify builds and update hashes, and test clean install, upgrade, repair, silent install, and uninstall.', 'Signing is a production trust requirement and may move earlier when a certificate is available.', 'All distributed binaries verify successfully and supported deployment paths pass on Windows 10 and 11.', NULL),
    ('BACKLOG-004', NULL, 'Backlog', 'Online license transfer and revocation', 'Planned', 1005, 'Complete outage-safe enforcement after the Admin Portal license-control foundation.', 'The portal now provides confirmed tier replacement, Trial upgrades, deactivation, reactivation, revocation, soft deletion, purchase synchronization, and audit history. Remaining work is per-computer activation tracking, transfer limits and cooldowns, server-signed entitlement checks that replace client-reported legacy paid status, a defined offline grace period, and privacy-minimized enforcement events.', 'Commercial control is valuable but must not disable customers during temporary outages; v0.3.23 offline keys remain valid until the enforcement release.', 'Transfers and remote revocations work with auditable state, the desktop clearly reports its entitlement, and temporary service outages preserve valid licensed use.', NULL),
    ('BACKLOG-005', NULL, 'Backlog', 'PNG and deterministic PDF export', 'Planned', 1006, 'Provide predictable receipt artifacts outside the application.', 'Complete receipt PNG, deterministic PDF, correct thermal dimensions, long pages, images, codes, watermark rules, batch export, and output tests.', 'Comparison should establish deterministic rendering before final export formats depend on it.', 'Exports are independent of window size, zoom, and theme and match tested receipt output.', NULL),
    ('BACKLOG-006', NULL, 'Backlog', 'Hardened Thermal adapter', 'Planned', 1007, 'Add deeper renderer compatibility through an isolated hardened process.', 'Stable ABI, structured errors and offsets, profile parity, safe malformed-input handling, golden tests, differential tests, fuzzing, performance limits, and managed fallback.', 'It carries the greatest integration risk and needs captures and baselines for safe validation.', 'The isolated renderer matches approved fixtures, survives hostile inputs, and falls back safely.', NULL),
    ('BACKLOG-008', NULL, 'Backlog', 'Admin Portal License Manager tabs', 'Planned', 1008, 'Organize license administration into focused views without creating separate or conflicting admin areas.', 'Add accessible tabs for Issued Licenses, Trial Installations, and Recent License Activity; keep key generation and license actions in Issued Licenses; preserve per-tab filters, counts, deleted-license view, scroll position, direct links, and browser navigation; retain Trial verification warnings and audit disclosures; support responsive layouts and regression tests.', 'This is a contained usability enhancement to the completed License Manager foundation. It follows higher-risk security, listener, storage, signing, entitlement, export, and compatibility work, but can be pulled forward for a short Admin Portal release.', 'All three sections render as accessible tabs, the active tab survives refresh and Back/Forward navigation, existing confirmations work unchanged, filters and counts remain accurate, and desktop and mobile browser tests pass.', NULL);

UPDATE development_roadmap
SET status = 'Released',
    purpose = 'Let one Enterprise installation emulate multiple receipt printers while Trial and Pro retain one local listener.',
    planned_scope = 'Persisted listener configuration; independent names, IPv4 addresses, ports, profiles, printer state, bounded buffers, counters, routing, Activity filtering, conflict validation, program-scoped firewall setup, Enterprise UI/API gates, capture metadata, and fault isolation.',
    priority_reason = 'Transactional storage and profiles provide the reliable foundation needed for isolated multi-printer operation.',
    completion_criteria = 'Two simultaneous Enterprise listeners receive jobs, apply different profiles, restart safely, persist configuration, and remain independently controllable while Trial and Pro single-listener behavior remains unchanged.',
    completed_at = COALESCE(completed_at, '2026-07-18 00:00:00.000000')
WHERE item_key = 'v0.3.21';

UPDATE development_roadmap
SET status = 'Released',
    title = 'Receipt workflow regression fixes',
    purpose = 'Restore fast Test Receipt feedback and reliable paid-history cleanup.',
    planned_scope = 'Immediate complete Test Receipt response and selection; background Activity refresh; redundant detail-fetch avoidance; SQLite-authoritative Clear All; best-effort obsolete legacy JSON cleanup; plain-language delete failures; regression and end-to-end timing coverage.',
    priority_reason = 'Core receipt workflows must be reliable before the next feature release.',
    completion_criteria = 'Test Receipt appears without a multi-second delay, Clear All removes paid history without HTTP 500, deletion remains durable, and all automated and end-to-end tests pass.',
    completed_at = COALESCE(completed_at, '2026-07-18 00:00:00.000000')
WHERE item_key = 'v0.3.22';

UPDATE development_roadmap
SET status = 'Released',
    title = 'Activation and Printer Setup Wizard fixes',
    purpose = 'Correct High-severity Enterprise activation and Windows printer installation failures.',
    planned_scope = 'Resilient Enterprise activation and storage recovery; safe malformed-key handling; unique temporary persistence; native Windows AddPrinter queue creation; retained Epson driver, TCP/IP port, verification, rollback, and error reporting; automated and installed Windows verification.',
    priority_reason = 'Core activation and printer setup must be reliable before the next feature release.',
    completion_criteria = 'Valid Enterprise activation avoids HTTP 500, the wizard creates the Epson queue on 127.0.0.1:9100 without Invalid parameter, the Test Receipt sends successfully, and all 83 tests pass.',
    completed_at = COALESCE(completed_at, '2026-07-19 00:00:00.000000')
WHERE item_key = 'v0.3.23';

UPDATE development_roadmap
SET status = 'Released',
    title = 'Upgrade licensing and Printer Setup safeguards',
    purpose = 'Preserve paid licensing through updates and prevent Windows printer-port conflicts.',
    planned_scope = 'Matched registration and activation recovery; hardened-folder ACL repair; license-aware post-update health; Trial-safe activation diagnostics; sequential Windows port selection; automatic Enterprise listener alignment; repeated conflict checks; rollback; installed validation.',
    priority_reason = 'Upgrade and printer setup reliability must be restored before the next feature release.',
    completion_criteria = 'Paid activation survives upgrade and maintenance reinstall, Trial can export activation diagnostics, a second Enterprise printer receives a test job on the first free port, and all 105 tests pass.',
    completed_at = COALESCE(completed_at, '2026-07-19 00:00:00.000000')
WHERE item_key = 'v0.3.24';

UPDATE development_roadmap
SET title = 'Four-tier licensing and upgrade paths',
    status = 'Released',
    priority_rank = 325,
    purpose = 'Add an affordable Lite license while preserving every existing Pro and Enterprise activation key and purchase record.',
    planned_scope = 'Trial, Lite, Pro, and Enterprise licensing; Lite activation tier byte 3 with legacy key compatibility; Lite $24.99 server-controlled pricing; tier-targeted purchase links; PayPal fulfillment and email; Admin Portal issuance, replacement, Trial upgrade, audits, and purchase synchronization; Lite single-listener access, Pro capacity up to two listeners, and Enterprise capacity up to fifteen.',
    priority_reason = 'The commercial and activation contracts must stay aligned before Lite keys are sold or upgraded.',
    completion_criteria = 'Existing Pro and Enterprise keys remain valid, a Lite purchase completes through activation and telemetry, all three paid tiers can be issued or replaced safely, targeted purchase links preselect the requested tier, and automated licensing and commerce tests pass.',
    completed_at = COALESCE(completed_at, '2026-07-19 00:00:00.000000')
WHERE item_key = 'v0.3.25';

UPDATE development_roadmap
SET version_label = 'v0.3.26', title = 'Annual Application Maintenance and Support', status = 'Released', priority_rank = 326,
    purpose = 'Keep permanent-license ownership separate from optional annual updates and technical support.',
    planned_scope = 'One included year for new paid licenses; existing-license grandfathering through July 19, 2027; optional one-time Lite $9.99, Pro $19.99, and Enterprise $59.99 renewals; signed entitlement refresh; server-verified PayPal renewal orders; Admin pricing, status, history, extension, and revocation controls; telemetry without keys or receipt data.',
    priority_reason = 'Maintenance must be implemented before future releases are delivered under the coverage policy.',
    completion_criteria = 'Permanent paid features keep working after coverage ends, early and lapsed renewals calculate correctly and idempotently, only covered customers receive signed update entitlements, and commerce, licensing, migration, and telemetry tests pass.',
    completed_at = COALESCE(completed_at, '2026-07-20 00:00:00.000000')
WHERE item_key = 'v0.3.26';

UPDATE development_roadmap
SET version_label = 'v0.3.33', title = 'Enhanced support package and connection diagnostics', status = 'Released', priority_rank = 333,
    purpose = 'Guide nontechnical customers through emulator, printer, listener, and Windows configuration problems and produce privacy-reviewed support evidence.',
    planned_scope = 'Guided emulator-side checks for the service, viewer, storage, listeners, ports, firewall, Windows queues, and Epson drivers; reviewed repair actions; previewed redacted ZIP packages; and an in-app Support Request workflow that sends consented, redacted reports through a secure backend to correctly labeled GitHub issues without embedding GitHub credentials.',
    priority_reason = 'Customer diagnostics, safe package export, and structured support requests reduce support time while avoiding unreliable testing of unknown POS implementations.',
    completion_criteria = 'Supported emulator and Windows failures are explained and safely repairable; support packages and GitHub issues exclude receipt contents, IP addresses, contact details, and secrets; offline drafts survive restart and retry.',
    completed_at = COALESCE(completed_at, '2026-07-21 00:00:00.000000')
WHERE item_key = 'v0.3.33';

UPDATE development_roadmap
SET version_label = 'v0.3.34', title = 'Encrypted backup, EULA, and support policy', status = 'Released', priority_rank = 334,
    purpose = 'Protect portable emulator configuration while presenting consistent product-use, licensing, compatibility, privacy, support, and liability terms.',
    planned_scope = 'Password-encrypted PPE backups with verified preview and rollback-safe restore; installer EULA acceptance; canonical website EULA; EPCOM Ltd. and Georgia jurisdiction; Windows 11 Pro and third-party POS support boundaries; and maintenance-response terms.',
    priority_reason = 'Customers need both a safe configuration recovery path and clear legal and support terms before comparison suites and guided updates expand the workflow.',
    completion_criteria = 'Encrypted backup validation and rollback protection pass all 151 tests; website and installer terms match; acceptance is required; release and SEO checks pass; and the installer checksum is published.',
    completed_at = COALESCE(completed_at, '2026-07-21 00:00:00.000000')
WHERE item_key = 'v0.3.34';

INSERT INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.35', 'v0.3.35', 'Release', 'Backup restore usability and compatibility', 'Released', 335,
     'Remove confusing Windows ZIP behavior from encrypted backups and make restoration understandable without leaving the application.',
     'Native .ppebackup save handling; compatibility with v0.3.34 .ppebackup.zip file names; accessible in-app restore guidance; and a responsive illustrated website guide.',
     'Customers must be able to recover the v0.3.34 backup they already created without extracting an encrypted package or guessing the restore sequence.',
     'New backups keep .ppebackup, legacy names restore successfully, all 158 tests and rendered restore-flow checks pass, and the guide plus screenshots are public.')
ON DUPLICATE KEY UPDATE version_label=VALUES(version_label),title=VALUES(title),status=VALUES(status),priority_rank=VALUES(priority_rank),purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria),completed_at=COALESCE(completed_at, '2026-07-22 00:00:00.000000');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.35'
WHERE item_key = 'v0.3.35';

INSERT INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.36', 'v0.3.36', 'Release', 'Privacy-preserving geographic analytics dashboard', 'Released', 336,
     'Show where website downloads and product activity occur without retaining raw IP addresses.',
     'Coarse country and U.S. state derivation; transient IP processing; daily download-start aggregates; world and United States maps; exact regional tables; date, metric, license, and version filters; accessibility; privacy and EULA disclosures; and automated contract checks.',
     'Geographic adoption data helps EPCOM prioritize documentation, compatibility, and support while data minimization protects customers.',
     'The private Admin dashboard reports approximate regional download starts, installations, launches, and print jobs; raw IP addresses are not stored in the analytics schema; filters and keyboard controls work; and legal disclosures match implementation.')
ON DUPLICATE KEY UPDATE version_label=VALUES(version_label),title=VALUES(title),status=VALUES(status),priority_rank=VALUES(priority_rank),purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria),completed_at=NULL;

UPDATE development_roadmap
SET completed_at = COALESCE(completed_at, '2026-07-22 00:00:00.000000'),
    github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.36'
WHERE item_key = 'v0.3.36';

INSERT INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.37', 'v0.3.37', 'Release', 'Trial Setup and Onboarding Improvements', 'Released', 337,
     'Let nontechnical Trial customers begin testing immediately without understanding listeners, ports, or Windows printer configuration.',
     'First-launch welcome; Trial Configuration Wizard; one automatic listener; confirmed sequential port recovery; unlimited ephemeral Test Receipts; complete-job allowance counter; irreversible ten-line redaction after the fifth external job; upgrade guidance; and local diagnostics.',
     'Removing first-run friction improves evaluation while ingestion-time redaction protects receipt data after the Trial allowance is used.',
     'Fresh Trial setup, unlimited Test Receipts, port-conflict recovery, five complete external jobs, accepted redacted later jobs, and non-recoverability across APIs, history, exports, and diagnostics are verified.')
ON DUPLICATE KEY UPDATE version_label=VALUES(version_label),title=VALUES(title),status=VALUES(status),priority_rank=VALUES(priority_rank),purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria),completed_at=COALESCE(completed_at, '2026-07-22 00:00:00.000000');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.37'
WHERE item_key = 'v0.3.37';

INSERT INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.38', 'v0.3.38', 'Release', 'Trial Onboarding Clarity Correction', 'Released', 338,
     'Make Trial setup impossible to lose and show customers exactly where their POS must send print jobs.',
     'Versioned and reopenable two-step welcome guide; wizard-first instruction; visible read-only included listener; local and LAN IPv4 endpoints; copyable RAW TCP details; and retained server-side mutation denial.',
     'The v0.3.37 guide could remain dismissed and hid the included listener behind an upgrade panel, leaving customers unsure how to connect.',
     'Fresh and upgraded Trial installations see and can reopen the guide, view one locked listener, copy exact connection details, and receive HTTP 403 for listener mutations.')
ON DUPLICATE KEY UPDATE version_label=VALUES(version_label),title=VALUES(title),status=VALUES(status),priority_rank=VALUES(priority_rank),purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria),completed_at=COALESCE(completed_at, '2026-07-22 00:00:00.000000');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.38'
WHERE item_key = 'v0.3.38';

INSERT INTO development_roadmap
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.39', 'v0.3.39', 'Release', 'Guided update installation and restart', 'Released', 339,
     'Close the application safely before an update replaces installed files, then return the customer to the updated application.',
     'Background installer download; SHA-256 verification; pre-update safety snapshot; install confirmation and safe deferral; active-job drain; listener and service shutdown; external updater process; file-lock wait; state preservation; minimal-prompt installation; automatic relaunch; success confirmation; and recovery-safe failure handling.',
     'A controlled external updater eliminates self-update file locks without unexpected listener downtime or lost customer state.',
     'Install and Restart completes without locked-file errors, relaunches the new version, preserves customer state and data, and leaves the current installation usable after cancellation or failure.')
ON DUPLICATE KEY UPDATE version_label=VALUES(version_label),title=VALUES(title),status=VALUES(status),priority_rank=VALUES(priority_rank),purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria),completed_at='2026-07-22 00:00:00.000000';

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.39',
    completed_at = '2026-07-22 00:00:00.000000'
WHERE item_key = 'v0.3.39';

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.40',
    completed_at = '2026-07-22 00:00:00.000000'
WHERE item_key = 'v0.3.40';

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v0.3.34'
WHERE item_key = 'v0.3.34';

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/20'
WHERE item_key = 'v0.3.33';

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/5'
WHERE item_key = 'v0.3.21' AND (github_url IS NULL OR github_url = '');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/6'
WHERE item_key = 'v0.3.20' AND (github_url IS NULL OR github_url = '');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/9'
WHERE item_key = 'BACKLOG-007' AND (github_url IS NULL OR github_url = '');

UPDATE development_roadmap
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/12'
WHERE item_key = 'BACKLOG-008' AND (github_url IS NULL OR github_url = '');

INSERT IGNORE INTO development_bugs
    (bug_key, title, severity, status, affected_versions, fixed_version, customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
VALUES
    ('BUG-001', 'Updater could not launch a locked installer file', 'High', 'Released', 'v0.3.05 and earlier updater implementation', 'v0.3.06', 'Customers could detect an update but could not start its installer.', 'A downloaded installer should launch after the download completes.', 'The updater reused a temporary path while another process still held the file.', 'Check for an update and select the install action.', 'Downloads use unique staging directories and the file is closed before launch.', UTC_TIMESTAMP(6)),
    ('BUG-002', 'Upgrade asked for saved registration again', 'Medium', 'Released', 'v0.3.06 and earlier installer implementation', 'v0.3.07', 'Existing customers had to re-enter their company name and email during an update.', 'Valid saved registration should be reused and partial values should be prefilled.', 'The installer displayed blank registration fields during an upgrade.', 'Run a newer installer over an existing registered installation.', 'Valid saved registration skips the page and partial registration is prefilled.', UTC_TIMESTAMP(6)),
    ('BUG-003', 'Missing printer-resident NV graphic was reported as unsupported', 'Medium', 'Released', 'v0.3.12 and earlier parser behavior', 'v0.3.13', 'Valid customer receipts displayed a misleading unsupported-command warning.', 'A recognized NV graphic request without local image data should be informational.', 'The recognized command was counted as unsupported when physical-printer image data was unavailable.', 'Send a receipt that requests an Epson NV graphic not stored in the emulator.', 'Recognized missing graphics are informational and imported matching logos render.', UTC_TIMESTAMP(6)),
    ('BUG-004', 'Dashboard print-job totals were not reported reliably', 'High', 'Released', 'v0.3.13 telemetry implementation', 'v0.3.14', 'The owner dashboard did not reflect completed customer print jobs.', 'Completed aggregate job totals should reach the canonical telemetry service and retry after outages.', 'Reports could use a noncanonical path and temporary failures dropped pending totals.', 'Complete print jobs and inspect the owner dashboard totals.', 'The canonical HTTPS endpoint is used and pending totals are retained and retried.', UTC_TIMESTAMP(6));

INSERT IGNORE INTO development_bugs
    (bug_key, title, severity, status, affected_versions, target_release, fixed_version, customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
VALUES
    ('BUG-005', 'Receipt exports replaced the desktop viewer with a ConnectionAborted error', 'Medium', 'Released', 'v0.3.15', 'v0.3.16', 'v0.3.16', 'Pro customers could not save Text, Raw, or Capture files without leaving the desktop receipt viewer.', 'Selecting an export should open a Save dialog, download the file, and keep the current receipt visible.', 'Direct attachment links were treated as main-frame WebView navigation and the aborted navigation was displayed as a startup failure.', 'Select a receipt in the v0.3.15 desktop application and choose Text, Raw, or Capture.', 'Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors.', UTC_TIMESTAMP(6)),
    ('BUG-006', 'Listener manager double disposal raised an unhandled shutdown error', 'Medium', 'Released', 'v0.3.21 development build', 'v0.3.21', 'v0.3.21', 'Application or service shutdown could end with an unhandled ObjectDisposedException after listeners had already stopped.', 'Hosted-service stop and dependency-injection disposal should be safe and idempotent.', 'The singleton listener manager was tracked by two service descriptors and its second disposal reused an already disposed lifecycle semaphore.', 'Start two listeners, stop the host, and allow dependency injection to dispose the listener manager.', 'Disposal is idempotent, a regression test repeats disposal after hosted-service stop, all 79 tests pass, and live Ctrl+C shutdown completes without an unhandled exception.', UTC_TIMESTAMP(6)),
    ('BUG-007', 'Test Receipt display regressed to approximately three seconds', 'Medium', 'Released', 'v0.3.21', 'v0.3.22', 'v0.3.22', 'Customers waited several seconds for a built-in Test Receipt that previously appeared almost instantly.', 'The generated receipt should be selected and displayed immediately.', 'The UI waited for Activity refresh and a second receipt-detail request before rendering the generated receipt.', 'Open the desktop application and select Test receipt.', 'The endpoint returns the complete receipt, the UI selects it immediately while Activity refreshes in the background, and end-to-end display completes in 280 ms.', UTC_TIMESTAMP(6)),
    ('BUG-008', 'Delete All Print Jobs returned HTTP 500 on locked legacy history', 'High', 'Released', 'v0.3.21', 'v0.3.22', 'v0.3.22', 'Customers could not clear paid print-job history and the Activity list remained populated.', 'Clear All should durably remove receipt history even when obsolete migration files cannot be cleaned up.', 'Successful SQLite deletion was followed by legacy JSON cleanup that could throw on a stale, read-only, or locked file and turn the request into HTTP 500.', 'Keep an obsolete legacy history file locked and select Delete All Print Jobs.', 'SQLite deletion remains authoritative, locked legacy cleanup is best effort, the regression test passes, all 80 tests pass, and end-to-end Clear All completes in 285 ms.', UTC_TIMESTAMP(6)),
    ('BUG-009', 'Enterprise activation returned HTTP 500 during optional storage initialization', 'High', 'Released', 'v0.3.22', 'v0.3.23', 'v0.3.23', 'Customers with a valid Enterprise key could not complete activation.', 'A valid signed key should unlock Enterprise immediately and optional storage recovery should be reported separately.', 'Paid-history or listener storage initialization could throw after signature validation and turn activation into HTTP 500.', 'Validate a signed Enterprise key while forcing optional storage initialization to fail.', 'Activation succeeds, malformed keys fail safely, forced storage-failure regression tests pass, and all 83 tests pass.', UTC_TIMESTAMP(6)),
    ('BUG-010', 'Printer Setup Wizard failed with Invalid parameter while creating queue', 'High', 'Released', 'v0.3.22 and earlier wizard implementation', 'v0.3.23', 'v0.3.23', 'Customers could not finish automated Windows printer installation.', 'The wizard should create the Epson queue and RAW TCP/IP port without manual Windows configuration.', 'WMI Put attempted to assign the read-only Win32_Printer Name property and raised System.Management.ManagementException.', 'Run the Printer Setup Wizard with the Epson driver installed and select Install Printer.', 'Native AddPrinter regression coverage passes; installed Windows validation created POS Printer Emulator with EPSON TM-T88V Receipt5 on 127.0.0.1:9100 and sent the wizard Test Receipt.', UTC_TIMESTAMP(6)),
    ('BUG-011', 'Upgrade could lose paid activation and fail to save the license', 'High', 'Released', 'v0.3.23', 'v0.3.24', 'v0.3.24', 'Updating could leave a paid installation in Trial and prevent reactivation.', 'Registration and activation must survive updates as one validated pair.', 'Hardened data permissions and partial persistence could hide or reject the saved paid license.', 'Upgrade a registered paid v0.3.23 installation over protected application-data files.', 'All 105 tests pass; installed Enterprise upgrade and maintenance-reinstall tests preserve registration and activation without re-entry.', UTC_TIMESTAMP(6)),
    ('BUG-012', 'Printer Setup Wizard could reuse an assigned TCP/IP port', 'Medium', 'Released', 'v0.3.23 and earlier', 'v0.3.24', 'v0.3.24', 'A second Windows printer could be assigned a conflicting endpoint.', 'The wizard should select the first free port and keep the matching emulator listener available.', 'Port 9100 could be reused without a complete conflict check.', 'Install a differently named printer while an existing queue already uses port 9100.', 'All 105 tests pass; installed Enterprise validation selected 9101, aligned its listener, delivered a 112-byte test job, and selected 9102 next.', UTC_TIMESTAMP(6)),
    ('BUG-013', 'Support diagnostics failed when Stored Logos directory was absent', 'Medium', 'Released', 'v0.3.26 development build', 'v0.3.26', 'v0.3.26', 'Customers could not save the always-available privacy-safe diagnostic file when optional logo storage had not been created or had been removed.', 'Diagnostics should treat optional Stored Logos storage as empty and remain available regardless of maintenance state.', 'Diagnostic export enumerated a missing optional directory and returned HTTP 500.', 'Remove or omit the Stored Logos directory, then download diagnostics from Settings Support or Activation Diagnostics.', 'Missing logo storage is treated as empty, imports recreate it safely, six Stored Graphic tests pass, and live expired-maintenance verification returns the diagnostic file with HTTP 200 while update checks remain HTTP 403.', '2026-07-20 00:00:00.000000'),
    ('BUG-014', 'Windows added a ZIP suffix to configuration backups', 'Medium', 'Released', 'v0.3.34', 'v0.3.35', 'v0.3.35', 'Customers could not extract the encrypted backup in Windows, and the restore picker rejected the resulting .ppebackup.zip name.', 'Backups should retain the native .ppebackup name, and existing v0.3.34 backup names should restore without extraction.', 'The desktop save filter did not recognize .ppebackup, so Windows appended .zip and the API accepted only the final extension.', 'Create a configuration backup in v0.3.34, then select the generated .ppebackup.zip file for restore.', 'The save dialog now uses .ppebackup directly; both native and legacy names pass validation; all 158 tests and the complete rendered restore workflow pass.', '2026-07-22 00:00:00.000000'),
    ('BUG-015', 'Trial welcome and included listener were difficult to find', 'Medium', 'Released', 'v0.3.37', 'v0.3.38', 'v0.3.38', 'Trial customers could dismiss the welcome guide permanently and then saw only an upgrade panel instead of the included listener connection details.', 'Trial setup should remain reopenable and show one read-only listener with exact local and LAN connection targets.', 'A persistent v1 completion flag hid the guide, while the single-license Printer Listeners page returned early to an upgrade-only panel.', 'Dismiss the v0.3.37 welcome guide, reopen the application, then open Settings and select Printer Listeners.', 'The v2 guide is reopenable from the header; the listener is readable without edit controls; the server rejects Trial changes with HTTP 403; the production viewer builds and all 166 desktop tests pass.', '2026-07-22 00:00:00.000000');

UPDATE development_bugs
SET title = 'Support diagnostics failed when Stored Logos directory was absent',
    severity = 'Medium',
    status = 'Released',
    affected_versions = 'v0.3.26 development build',
    target_release = 'v0.3.26',
    fixed_version = 'v0.3.26',
    customer_impact = 'Customers could not save the always-available privacy-safe diagnostic file when optional logo storage had not been created or had been removed.',
    expected_behavior = 'Diagnostics should treat optional Stored Logos storage as empty and remain available regardless of maintenance state.',
    actual_behavior = 'Diagnostic export enumerated a missing optional directory and returned HTTP 500.',
    reproduction_steps = 'Remove or omit the Stored Logos directory, then download diagnostics from Settings Support or Activation Diagnostics.',
    verification = 'Missing logo storage is treated as empty, imports recreate it safely, six Stored Graphic tests pass, and live expired-maintenance verification returns the diagnostic file with HTTP 200 while update checks remain HTTP 403.',
    resolved_at = COALESCE(resolved_at, '2026-07-20 00:00:00.000000')
WHERE bug_key = 'BUG-013';

UPDATE development_bugs
SET github_url = 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/8'
WHERE bug_key = 'BUG-006' AND (github_url IS NULL OR github_url = '');

UPDATE development_bugs
SET github_url = CASE bug_key
    WHEN 'BUG-009' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/13'
    WHEN 'BUG-010' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/14'
    WHEN 'BUG-011' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/16'
    WHEN 'BUG-012' THEN 'https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/17'
    ELSE github_url
END
WHERE bug_key IN ('BUG-009', 'BUG-010', 'BUG-011', 'BUG-012');
