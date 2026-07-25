ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS company_name VARCHAR(160) NULL AFTER display_name;

CREATE TABLE IF NOT EXISTS portal_computer_link_requests (
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
    KEY ix_portal_link_installation (installation_id, status, expires_at),
    KEY ix_portal_link_customer (approved_customer_id, created_at),
    KEY ix_portal_link_license (selected_license_id, created_at),
    CONSTRAINT fk_portal_link_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
    CONSTRAINT fk_portal_link_customer FOREIGN KEY (approved_customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_portal_link_license FOREIGN KEY (selected_license_id) REFERENCES issued_licenses(license_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_device_bindings (
    binding_id CHAR(36) NOT NULL,
    license_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    installation_id BIGINT UNSIGNED NOT NULL,
    binding_state ENUM('Pending','Active','Deactivated','Revoked') NOT NULL DEFAULT 'Pending',
    activation_method ENUM('PortalLink','ActivationKeyClaim','AdminRecovery','LegacyMigration') NOT NULL,
    activated_at DATETIME(6) NULL,
    deactivated_at DATETIME(6) NULL,
    deactivation_reason VARCHAR(300) NULL,
    transfer_reference CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (binding_id),
    KEY ix_license_binding_license (license_id, binding_state, activated_at),
    KEY ix_license_binding_customer (customer_id, binding_state, activated_at),
    KEY ix_license_binding_installation (installation_id, binding_state, activated_at),
    KEY ix_license_binding_transfer (transfer_reference),
    CONSTRAINT fk_license_binding_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_license_binding_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_license_binding_installation FOREIGN KEY (installation_id) REFERENCES installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_activation_events (
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
    KEY ix_activation_event_customer (customer_id, created_at),
    KEY ix_activation_event_license (license_id, created_at),
    KEY ix_activation_event_installation (installation_id, created_at),
    KEY ix_activation_event_link (link_id, created_at),
    KEY ix_activation_event_outcome (outcome, created_at),
    CONSTRAINT fk_activation_event_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_activation_event_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_activation_event_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
    CONSTRAINT fk_activation_event_link FOREIGN KEY (link_id) REFERENCES portal_computer_link_requests(link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO development_migrations (migration_key)
VALUES ('account-based-activation-v1');
