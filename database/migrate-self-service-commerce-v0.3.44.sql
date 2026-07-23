START TRANSACTION;

CREATE TABLE IF NOT EXISTS portal_checkout_intents (
    intent_id CHAR(36) PRIMARY KEY,
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
    UNIQUE KEY uq_portal_checkout_token (checkout_token_hash),
    UNIQUE KEY uq_portal_checkout_provider_order (provider_order_id),
    UNIQUE KEY uq_portal_checkout_provider_capture (provider_capture_id),
    KEY ix_portal_checkout_customer (customer_id, prepared_at),
    KEY ix_portal_checkout_license (license_id, prepared_at),
    KEY ix_portal_checkout_installation (installation_id, prepared_at),
    CONSTRAINT fk_portal_checkout_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_portal_checkout_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_portal_checkout_installation FOREIGN KEY (installation_id) REFERENCES installations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_checkout_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intent_id CHAR(36) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    event_summary VARCHAR(500) NOT NULL,
    event_data JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_portal_checkout_events (intent_id, created_at),
    CONSTRAINT fk_portal_checkout_event_intent FOREIGN KEY (intent_id) REFERENCES portal_checkout_intents(intent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_promotion_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id CHAR(36) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    consumed_at DATETIME(6) NULL,
    consumed_by_promotion_id CHAR(36) NULL,
    KEY ix_portal_promotion_exception_customer (customer_id, consumed_at),
    CONSTRAINT fk_portal_promotion_exception_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_promotions (
    promotion_id CHAR(36) PRIMARY KEY,
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
    UNIQUE KEY uq_portal_promotion_token (entitlement_token_hash),
    KEY ix_portal_promotion_customer (customer_id, created_at),
    KEY ix_portal_promotion_license (license_id, state),
    KEY ix_portal_promotion_installation (installation_id, state),
    CONSTRAINT fk_portal_promotion_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    CONSTRAINT fk_portal_promotion_account FOREIGN KEY (customer_id) REFERENCES portal_accounts(customer_id),
    CONSTRAINT fk_portal_promotion_license FOREIGN KEY (license_id) REFERENCES issued_licenses(license_id),
    CONSTRAINT fk_portal_promotion_installation FOREIGN KEY (installation_id) REFERENCES installations(id),
    CONSTRAINT fk_portal_promotion_exception FOREIGN KEY (exception_id) REFERENCES portal_promotion_exceptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_promotion_claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id CHAR(36) NOT NULL,
    claim_type ENUM('Customer','Account','License','Installation') NOT NULL,
    claim_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_promotion_claim (claim_type, claim_hash),
    KEY ix_portal_promotion_claim_promotion (promotion_id),
    CONSTRAINT fk_portal_promotion_claim_promotion FOREIGN KEY (promotion_id)
        REFERENCES portal_promotions(promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_promotion_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id CHAR(36) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    previous_state VARCHAR(32) NULL,
    new_state VARCHAR(32) NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_portal_promotion_events (promotion_id, created_at),
    CONSTRAINT fk_portal_promotion_event FOREIGN KEY (promotion_id) REFERENCES portal_promotions(promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO development_items
    (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria)
VALUES
    ('v0.3.44', 'v0.3.44', 'Release', 'Self-service renewals, upgrades, and promotional trials', 'In progress', 344,
     'Provide auditable self-service commercial workflows without turning permanent licenses into subscriptions.',
     'PayPal maintenance renewal, tier upgrades, refunds, idempotent fulfillment, and one five-day promotional paid-edition trial.',
     'Commercial workflows require the secure portal and canonical ownership records.',
     'Payments and temporary entitlements are idempotent, auditable, and restore prior permanent access correctly.')
ON DUPLICATE KEY UPDATE
    title=VALUES(title),
    status=VALUES(status),
    priority_rank=VALUES(priority_rank),
    purpose=VALUES(purpose),
    planned_scope=VALUES(planned_scope),
    priority_reason=VALUES(priority_reason),
    completion_criteria=VALUES(completion_criteria);

COMMIT;
