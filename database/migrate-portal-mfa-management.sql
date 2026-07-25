-- POS Printer Emulator customer and administrator MFA management enhancement.
-- Safe to execute repeatedly on MariaDB 10.11.

ALTER TABLE portal_accounts
    ADD COLUMN IF NOT EXISTS mfa_reenrollment_required TINYINT(1) NOT NULL DEFAULT 0 AFTER mfa_enabled;

ALTER TABLE customer_admin_audit
    ADD COLUMN IF NOT EXISTS actor_ip_address VARCHAR(45) NULL AFTER actor;

INSERT INTO communication_templates
    (template_key,display_name,message_class,essential,enabled,frequency_cap_hours,description,updated_by)
VALUES
    ('mfa_disabled_notification','Two-factor authentication disabled','Service',1,0,1,
     'Security notification sent after a customer disables two-factor authentication and every portal session is revoked.',
     'mfa-management-migration'),
    ('mfa_admin_reset_notification','Two-factor authentication administrator reset','Service',1,0,1,
     'Security notification sent after an authorized administrator resets customer MFA and requires enrollment at next sign-in.',
     'mfa-management-migration')
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name),
    message_class=VALUES(message_class),
    essential=VALUES(essential),
    description=VALUES(description),
    updated_by=VALUES(updated_by);

INSERT IGNORE INTO communication_template_tags(template_key,tag_key,created_by) VALUES
    ('mfa_disabled_notification','service','mfa-management-migration'),
    ('mfa_disabled_notification','essential','mfa-management-migration'),
    ('mfa_disabled_notification','it','mfa-management-migration'),
    ('mfa_admin_reset_notification','service','mfa-management-migration'),
    ('mfa_admin_reset_notification','essential','mfa-management-migration'),
    ('mfa_admin_reset_notification','it','mfa-management-migration');
