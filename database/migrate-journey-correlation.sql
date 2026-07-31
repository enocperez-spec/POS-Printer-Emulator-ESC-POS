-- Adds a single trace identifier across customer registration, checkout, email,
-- device linking, license activation, and administrative audit records.

ALTER TABLE customer_purchases
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER purchase_status,
    ADD INDEX IF NOT EXISTS ix_customer_purchases_journey (journey_correlation_id, updated_at);

ALTER TABLE customer_events
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER event_summary,
    ADD INDEX IF NOT EXISTS ix_customer_events_journey (journey_correlation_id, occurred_at);

ALTER TABLE customer_email_verifications
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER token_hash,
    ADD INDEX IF NOT EXISTS ix_customer_verification_journey (journey_correlation_id, requested_at);

ALTER TABLE customer_admin_audit
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER reason,
    ADD INDEX IF NOT EXISTS ix_customer_audit_journey (journey_correlation_id, created_at);

ALTER TABLE communication_outbox
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER idempotency_key,
    ADD INDEX IF NOT EXISTS ix_communication_outbox_journey (journey_correlation_id, created_at);

ALTER TABLE communication_delivery_events
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER event_summary,
    ADD INDEX IF NOT EXISTS ix_communication_delivery_journey (journey_correlation_id, occurred_at);

ALTER TABLE portal_password_resets
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER token_hash,
    ADD INDEX IF NOT EXISTS ix_portal_password_reset_journey (journey_correlation_id, requested_at);

ALTER TABLE portal_device_actions
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER reason,
    ADD INDEX IF NOT EXISTS ix_portal_device_journey (journey_correlation_id, created_at);

ALTER TABLE portal_computer_link_requests
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER user_code_hash,
    ADD INDEX IF NOT EXISTS ix_portal_link_journey (journey_correlation_id, created_at);

UPDATE portal_computer_link_requests
SET journey_correlation_id = link_id
WHERE journey_correlation_id IS NULL;

ALTER TABLE portal_computer_link_requests
    MODIFY journey_correlation_id CHAR(36) NOT NULL;

ALTER TABLE license_device_bindings
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER activation_method,
    ADD INDEX IF NOT EXISTS ix_license_binding_journey (journey_correlation_id, created_at);

ALTER TABLE license_activation_events
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER event_summary,
    ADD INDEX IF NOT EXISTS ix_activation_event_journey (journey_correlation_id, created_at);

ALTER TABLE portal_mail_outbox
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER text_body,
    ADD INDEX IF NOT EXISTS ix_portal_mail_journey (journey_correlation_id, created_at);

ALTER TABLE portal_checkout_intents
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER checkout_token_hash,
    ADD INDEX IF NOT EXISTS ix_portal_checkout_journey (journey_correlation_id, prepared_at);

UPDATE portal_checkout_intents
SET journey_correlation_id = intent_id
WHERE journey_correlation_id IS NULL;

ALTER TABLE portal_checkout_intents
    MODIFY journey_correlation_id CHAR(36) NOT NULL;

ALTER TABLE portal_checkout_events
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER event_data,
    ADD INDEX IF NOT EXISTS ix_portal_checkout_event_journey (journey_correlation_id, created_at);

UPDATE portal_checkout_events e
INNER JOIN portal_checkout_intents i ON i.intent_id=e.intent_id
SET e.journey_correlation_id=i.journey_correlation_id
WHERE e.journey_correlation_id IS NULL;

ALTER TABLE portal_checkout_events
    MODIFY journey_correlation_id CHAR(36) NOT NULL;

ALTER TABLE issued_license_events
    ADD COLUMN IF NOT EXISTS journey_correlation_id CHAR(36) NULL AFTER admin_ip,
    ADD INDEX IF NOT EXISTS ix_license_events_journey (journey_correlation_id, created_at);
