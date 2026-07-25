<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_crm.php';

const COMMUNICATION_MESSAGE_CLASSES = ['Service', 'Marketing'];
const COMMUNICATION_OUTBOX_STATES = ['Pending', 'Processing', 'Deferred', 'Sent', 'Failed', 'DeliveryUnknown', 'Cancelled'];
const COMMUNICATION_BLOCKING_SUPPRESSIONS = ['Bounced', 'Complaint', 'Account Closed', 'Administrative'];
const COMMUNICATION_PROVIDER_EVENTS = [
    'request', 'sent', 'delivered', 'deferred', 'softbounce', 'soft_bounce',
    'hardbounce', 'hard_bounce', 'blocked', 'error', 'invalid', 'invalid_email',
    'opened', 'unique_opened', 'click', 'clicked', 'unique_clicked',
    'spam', 'complaint', 'unsubscribed',
];
const COMMUNICATION_PARAMETER_KEYS = [
    'customer_name', 'first_name', 'license_tier', 'application_version',
    'installed_version', 'latest_version', 'versions_behind', 'maintenance_end',
    'renewal_url', 'portal_url', 'download_url', 'support_reference',
    'support_url', 'verification_url', 'reset_url', 'release_summary',
    'setup_url', 'troubleshooting_url', 'contact_support_url', 'event_label',
    'feature_summary', 'preview_text', 'documentation_url', 'help_center_url',
    'support_request_url', 'no_reply_notice',
];
const COMMUNICATION_FORBIDDEN_REPLY_LANGUAGE = [
    'reply to this email',
    'reply directly to this email',
    'contact us by email',
    'email us for help',
    'respond to this email',
];
const COMMUNICATION_TEMPLATE_TAGS = [
    'welcome' => ['label' => 'Welcome', 'color' => '#06b6d4', 'description' => 'Customer onboarding and first-use guidance.'],
    'trial' => ['label' => 'Trial', 'color' => '#8b5cf6', 'description' => 'Trial-license communication.'],
    'lite' => ['label' => 'Lite', 'color' => '#3b82f6', 'description' => 'Lite-license communication.'],
    'pro' => ['label' => 'Pro', 'color' => '#14b8a6', 'description' => 'Pro-license communication.'],
    'enterprise' => ['label' => 'Enterprise', 'color' => '#f59e0b', 'description' => 'Enterprise-license communication.'],
    'inactive-user' => ['label' => 'Inactive User', 'color' => '#f97316', 'description' => 'Assistance after an inactivity interval.'],
    'troubleshooting' => ['label' => 'Troubleshooting', 'color' => '#ef4444', 'description' => 'Configuration and troubleshooting help.'],
    'setup' => ['label' => 'Setup', 'color' => '#22c55e', 'description' => 'Installation and initial configuration.'],
    'purchase' => ['label' => 'Purchase', 'color' => '#eab308', 'description' => 'Purchase and paid-license onboarding.'],
    'upgrade' => ['label' => 'Upgrade', 'color' => '#a855f7', 'description' => 'License upgrade onboarding.'],
    'marketing' => ['label' => 'Marketing', 'color' => '#ec4899', 'description' => 'Optional communication requiring marketing consent.'],
    'service' => ['label' => 'Service', 'color' => '#0284c7', 'description' => 'Operational customer service communication.'],
    'essential' => ['label' => 'Essential', 'color' => '#dc2626', 'description' => 'Security or transaction-critical communication.'],
    'it' => ['label' => 'IT', 'color' => '#64748b', 'description' => 'Technical or account-management communication.'],
];

/**
 * Communication secrets are loaded from private/communications.php by bootstrap.php.
 * Nothing in this module writes or displays a provider credential.
 */
function communication_config(): array
{
    $configured = private_config()['communications'] ?? [];
    $defaults = [
        'enabled' => false,
        'mode' => 'disabled',
        'brevo_api_base' => 'https://api.brevo.com/v3',
        'brevo_api_key' => '',
        'webhook_token' => '',
        'sender_email' => '',
        'service_sender_email' => 'info@buy.posprinteremulator.com',
        'sales_sender_email' => 'sales@buy.posprinteremulator.com',
        'sender_name' => 'POS Printer Emulator',
        'reply_to_email' => '',
        'reply_to_name' => 'POS Printer Emulator Support',
        'inbox_monitored' => false,
        'provider_daily_limit' => 300,
        'automated_daily_limit' => 290,
        'service_reserve' => 50,
        'timezone' => 'America/New_York',
        'quiet_hours_start' => 20,
        'quiet_hours_end' => 8,
        'test_allowlist' => [],
    ];
    return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
}

function communication_service_authorized(): bool
{
    $authorization = crm_authorization_header();
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
        return false;
    }
    $expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
    return preg_match('/^[0-9a-f]{64}$/', $expectedHash) === 1
        && hash_equals($expectedHash, hash('sha256', $match[1]));
}

function communication_webhook_authorized(): bool
{
    $authorization = crm_authorization_header();
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/', $authorization, $match)) {
        return false;
    }
    $expected = trim((string)(communication_config()['webhook_token'] ?? ''));
    return strlen($expected) >= 32 && hash_equals($expected, $match[1]);
}

function ensure_communication_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    ensure_customer_crm_schema($pdo);
    $lock = $pdo->query("SELECT GET_LOCK('ppe_communication_schema_v1',10)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('The communications database upgrade is busy. Try again shortly.');
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_settings (
                setting_key VARCHAR(64) NOT NULL,
                setting_value VARCHAR(500) NOT NULL,
                updated_by VARCHAR(80) NOT NULL,
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_templates (
                template_key VARCHAR(64) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                message_class ENUM('Service','Marketing') NOT NULL,
                essential TINYINT(1) NOT NULL DEFAULT 0,
                brevo_template_id BIGINT UNSIGNED NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                frequency_cap_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
                description VARCHAR(500) NOT NULL,
                preview_brevo_template_id BIGINT UNSIGNED NULL,
                preview_verified_at DATETIME(6) NULL,
                preview_warnings_json TEXT NULL,
                mapping_candidate_brevo_template_id BIGINT UNSIGNED NULL,
                mapping_status ENUM('NotMapped','Creating','Validating','Mapped','Failed') NOT NULL DEFAULT 'NotMapped',
                mapping_error_detail VARCHAR(500) NULL,
                mapping_validated_at DATETIME(6) NULL,
                mapping_test_sent_at DATETIME(6) NULL,
                updated_by VARCHAR(80) NOT NULL DEFAULT 'system',
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (template_key),
                KEY ix_communication_templates_enabled (enabled, message_class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS preview_brevo_template_id BIGINT UNSIGNED NULL AFTER description");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS preview_verified_at DATETIME(6) NULL AFTER preview_brevo_template_id");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS preview_warnings_json TEXT NULL AFTER preview_verified_at");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS mapping_candidate_brevo_template_id BIGINT UNSIGNED NULL AFTER preview_warnings_json");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS mapping_status ENUM('NotMapped','Creating','Validating','Mapped','Failed') NOT NULL DEFAULT 'NotMapped' AFTER mapping_candidate_brevo_template_id");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS mapping_error_detail VARCHAR(500) NULL AFTER mapping_status");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS mapping_validated_at DATETIME(6) NULL AFTER mapping_error_detail");
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN IF NOT EXISTS mapping_test_sent_at DATETIME(6) NULL AFTER mapping_validated_at");
        $pdo->exec(
            "UPDATE communication_templates
             SET mapping_status=IF(brevo_template_id IS NULL,'NotMapped','Mapped')
             WHERE mapping_status='NotMapped' AND brevo_template_id IS NOT NULL"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_tags (
                tag_key VARCHAR(32) NOT NULL,
                display_name VARCHAR(64) NOT NULL,
                color_hex CHAR(7) NOT NULL,
                description VARCHAR(240) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by VARCHAR(80) NOT NULL DEFAULT 'system',
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (tag_key),
                UNIQUE KEY uq_communication_tags_name (display_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_template_tags (
                template_key VARCHAR(64) NOT NULL,
                tag_key VARCHAR(32) NOT NULL,
                created_by VARCHAR(80) NOT NULL DEFAULT 'system',
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (template_key,tag_key),
                KEY ix_communication_template_tags_tag (tag_key,template_key),
                CONSTRAINT fk_communication_template_tags_template
                    FOREIGN KEY (template_key) REFERENCES communication_templates(template_key)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_campaigns (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_outbox (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_delivery_events (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS communication_quota_daily (
                quota_date DATE NOT NULL,
                provider_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                automated_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                service_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                marketing_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                manual_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (quota_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_lifecycle_daily (
                activity_date DATE NOT NULL,
                lifecycle_stage VARCHAR(40) NOT NULL,
                license_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
                customer_count INT UNSIGNED NOT NULL DEFAULT 0,
                event_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (activity_date, lifecycle_stage, license_tier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_lifecycle_presence (
                activity_date DATE NOT NULL,
                lifecycle_stage VARCHAR(40) NOT NULL,
                license_tier ENUM('Trial','Lite','Pro','Enterprise') NOT NULL,
                customer_hash BINARY(32) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (activity_date,lifecycle_stage,license_tier,customer_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        communication_seed_templates($pdo);
        communication_seed_tags($pdo);
        communication_seed_managed_lifecycle_mappings($pdo);
        $pdo->exec(
            "INSERT IGNORE INTO communication_settings(setting_key,setting_value,updated_by) VALUES
                ('emergency_stop','1','system'),
                ('marketing_pause','1','system'),
                ('provider_timezone','America/New_York','system'),
                ('provider_daily_limit','300','system'),
                ('automated_daily_limit','290','system'),
                ('service_reserve','50','system'),
                ('documentation_url','https://www.posprinteremulator.com/documentation','system'),
                ('help_center_url','https://www.posprinteremulator.com/documentation','system'),
                ('support_request_url','https://www.posprinteremulator.com/how-to-submit-a-support-request','system'),
                ('no_reply_notice','Please do not reply to this email. This inbox is not monitored.','system')"
        );
        communication_seed_template_tags($pdo);
        $ready = true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ppe_communication_schema_v1')")->fetchColumn();
    }
}

function communication_seed_template_tags(PDO $pdo): void
{
    $seeded = communication_setting($pdo, 'template_tags_seeded', '0') === '1';
    $version = (int)communication_setting($pdo, 'template_tag_seed_version', '0');
    if ($seeded && $version >= 3) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO communication_template_tags(template_key,tag_key,created_by)
         VALUES(:template_key,:tag_key,:created_by)'
    );
    if (!$seeded) {
        $templates = $pdo->query(
            'SELECT template_key,message_class,essential FROM communication_templates'
        )->fetchAll();
        foreach ($templates as $template) {
            $tags = [strtolower((string)$template['message_class'])];
            if ((int)$template['essential'] === 1) {
                $tags[] = 'essential';
            }
            if (in_array((string)$template['template_key'], [
                'email_verification', 'password_recovery', 'support_confirmation',
                'release_announcement', 'inactivity_help',
            ], true)) {
                $tags[] = 'it';
            }
            foreach (array_unique($tags) as $tag) {
                $insert->execute([
                    'template_key' => (string)$template['template_key'],
                    'tag_key' => $tag,
                    'created_by' => 'system',
                ]);
            }
        }
    }

    $newAssignments = [
        'mfa_disabled_notification' => ['service', 'essential', 'it'],
        'mfa_admin_reset_notification' => ['service', 'essential', 'it'],
        'we_want_to_help' => ['marketing', 'inactive-user', 'troubleshooting'],
        'welcome_trial_start' => ['service', 'welcome', 'trial', 'setup'],
        'welcome_lite_purchase' => ['service', 'welcome', 'lite', 'setup', 'purchase'],
        'welcome_pro_purchase' => ['service', 'welcome', 'pro', 'setup', 'purchase'],
        'welcome_enterprise_purchase' => ['service', 'welcome', 'enterprise', 'setup', 'purchase'],
        'welcome_lite_upgrade' => ['service', 'welcome', 'lite', 'setup', 'upgrade'],
        'welcome_pro_upgrade' => ['service', 'welcome', 'pro', 'setup', 'upgrade'],
        'welcome_enterprise_upgrade' => ['service', 'welcome', 'enterprise', 'setup', 'upgrade'],
    ];
    foreach ($newAssignments as $templateKey => $tags) {
        foreach ($tags as $tag) {
            $insert->execute([
                'template_key' => $templateKey,
                'tag_key' => $tag,
                'created_by' => 'system',
            ]);
        }
    }

    $setting = $pdo->prepare(
        "INSERT INTO communication_settings(setting_key,setting_value,updated_by) VALUES
           ('template_tags_seeded','1','system'),
           ('template_tag_seed_version','3','system')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by='system'"
    );
    $setting->execute();
}

function communication_seed_tags(PDO $pdo): void
{
    $insert = $pdo->prepare(
        'INSERT INTO communication_tags(tag_key,display_name,color_hex,description,is_system,updated_by)
         VALUES(:key,:name,:color,:description,1,\'system\')
         ON DUPLICATE KEY UPDATE
           display_name=IF(is_system=1,VALUES(display_name),display_name),
           color_hex=IF(is_system=1,VALUES(color_hex),color_hex),
           description=IF(is_system=1,VALUES(description),description)'
    );
    foreach (COMMUNICATION_TEMPLATE_TAGS as $key => $tag) {
        $insert->execute([
            'key' => $key,
            'name' => $tag['label'],
            'color' => $tag['color'],
            'description' => $tag['description'],
        ]);
    }
}

function communication_tag_catalog(PDO $pdo): array
{
    ensure_communication_schema($pdo);
    $rows = $pdo->query(
        'SELECT tag_key,display_name,color_hex,description,is_system
         FROM communication_tags WHERE active=1 ORDER BY display_name'
    )->fetchAll();
    $catalog = [];
    foreach ($rows as $row) {
        $catalog[(string)$row['tag_key']] = [
            'label' => (string)$row['display_name'],
            'color' => (string)$row['color_hex'],
            'description' => (string)$row['description'],
            'system' => (int)$row['is_system'] === 1,
        ];
    }
    return $catalog;
}

function communication_template_tag_keys(PDO $pdo, string $templateKey): array
{
    $statement = $pdo->prepare(
        'SELECT tag_key FROM communication_template_tags
         WHERE template_key=:template_key ORDER BY tag_key'
    );
    $statement->execute(['template_key' => $templateKey]);
    return array_values(array_filter(array_map(
        'strval',
        $statement->fetchAll(PDO::FETCH_COLUMN)
    )));
}

function communication_template_sender(
    PDO $pdo,
    string $templateKey,
    ?string $messageClass = null,
    ?array $config = null
): array {
    $config ??= communication_config();
    $tags = communication_template_tag_keys($pdo, $templateKey);
    $salesIntent = strcasecmp((string)$messageClass, 'Marketing') === 0
        || array_intersect($tags, ['purchase', 'marketing', 'upgrade']) !== [];
    $email = trim((string)($config[
        $salesIntent ? 'sales_sender_email' : 'service_sender_email'
    ] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The approved template sender email is not configured.');
    }
    return [
        'email' => strtolower($email),
        'name' => trim((string)($config['sender_name'] ?? 'POS Printer Emulator')),
        'channel' => $salesIntent ? 'sales' : 'service',
    ];
}

function communication_sync_brevo_template_sender(
    PDO $pdo,
    string $templateKey,
    int $templateId,
    string $actor
): array {
    if ($templateId < 1) {
        throw new InvalidArgumentException('A mapped Brevo template ID is required.');
    }
    $class = $pdo->prepare(
        'SELECT message_class FROM communication_templates
         WHERE template_key=:template_key AND brevo_template_id=:template_id LIMIT 1'
    );
    $class->execute(['template_key' => $templateKey, 'template_id' => $templateId]);
    $messageClass = $class->fetchColumn();
    if (!is_string($messageClass)) {
        throw new DomainException('The approved registry mapping was not found.');
    }
    $sender = communication_template_sender($pdo, $templateKey, $messageClass);
    $provider = communication_brevo_request('GET', '/smtp/templates/' . $templateId);
    $current = is_array($provider['sender'] ?? null) ? $provider['sender'] : [];
    $changed = strcasecmp(trim((string)($current['email'] ?? '')), $sender['email']) !== 0
        || !hash_equals(trim((string)($current['name'] ?? '')), $sender['name']);
    if ($changed) {
        communication_brevo_request('PUT', '/smtp/templates/' . $templateId, [
            'sender' => ['email' => $sender['email'], 'name' => $sender['name']],
        ]);
    }
    $verified = communication_brevo_request('GET', '/smtp/templates/' . $templateId);
    $verifiedSender = is_array($verified['sender'] ?? null) ? $verified['sender'] : [];
    if (strcasecmp(trim((string)($verifiedSender['email'] ?? '')), $sender['email']) !== 0) {
        throw new DomainException('Brevo did not retain the approved sender address.');
    }
    if ($changed) {
        crm_record_admin_audit(
            $pdo,
            null,
            'COMMUNICATION_TEMPLATE_SENDER_UPDATED',
            $actor,
            'Communication Template',
            $templateKey . ':' . $templateId,
            'Applied the approved ' . $sender['channel'] . ' sender address'
        );
    }
    return ['changed' => $changed, 'sender' => $sender];
}

function communication_seed_managed_lifecycle_mappings(PDO $pdo): void
{
    if ((int)communication_setting($pdo, 'managed_lifecycle_mapping_version', '0') >= 1) {
        return;
    }
    // These non-secret IDs belong to the production Brevo account. Each
    // provider template was content-verified before this migration was added.
    $mappings = [
        'we_want_to_help' => 17,
        'welcome_trial_start' => 18,
        'welcome_lite_purchase' => 19,
        'welcome_pro_purchase' => 20,
        'welcome_enterprise_purchase' => 21,
        'welcome_lite_upgrade' => 22,
        'welcome_pro_upgrade' => 23,
        'welcome_enterprise_upgrade' => 24,
    ];
    $statement = $pdo->prepare(
        "UPDATE communication_templates
         SET brevo_template_id=:template_id,enabled=1,
             preview_brevo_template_id=:preview_id,preview_verified_at=UTC_TIMESTAMP(6),
             preview_warnings_json='[]',updated_by='managed-migration'
         WHERE template_key=:template_key"
    );
    foreach ($mappings as $templateKey => $templateId) {
        $statement->execute([
            'template_id' => $templateId,
            'preview_id' => $templateId,
            'template_key' => $templateKey,
        ]);
    }
    $pdo->exec(
        "UPDATE communication_templates SET enabled=0,updated_by='managed-migration'
         WHERE template_key IN ('welcome_setup','trial_guidance','inactivity_help')"
    );
    $setting = $pdo->prepare(
        "INSERT INTO communication_settings(setting_key,setting_value,updated_by) VALUES
           ('managed_lifecycle_mapping_version','1','managed-migration'),
           ('inactivity_followup_days','','managed-migration')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)"
    );
    $setting->execute();
}

function communication_seed_templates(PDO $pdo): void
{
    $templates = [
        ['email_verification', 'Email verification', 'Service', 1, 1, 'Sends a secure, single-use link that confirms the customer controls the email address used for their Customer Portal account.'],
        ['password_recovery', 'Password recovery', 'Service', 1, 1, 'Sends a secure, expiring password-reset link when a verified Customer Portal user requests account recovery.'],
        ['mfa_disabled_notification', 'Two-factor authentication disabled', 'Service', 1, 1, 'Notifies a customer after they disable two-factor authentication and every Customer Portal session is revoked.'],
        ['mfa_admin_reset_notification', 'Two-factor authentication administrator reset', 'Service', 1, 1, 'Notifies a customer after an authorized administrator resets MFA, revokes sessions, and requires enrollment at next sign-in.'],
        ['purchase_confirmation', 'Purchase confirmation', 'Service', 1, 1, 'Confirms a completed purchase and directs the customer to the secure portal for automatic account and computer licensing.'],
        ['activation_ready', 'License ready', 'Service', 1, 1, 'Notifies the customer that their approved account license is ready and directs them to secure computer linking.'],
        ['support_confirmation', 'Support request confirmation', 'Service', 1, 1, 'Acknowledges a submitted support request and provides its reference number and secure tracking link.'],
        ['maintenance_reminder', 'Maintenance reminder', 'Service', 0, 168, 'Reminds an eligible paid customer before maintenance coverage ends and links to optional renewal without implying the permanent license expires.'],
        ['welcome_setup', 'Welcome and setup guidance', 'Service', 0, 72, 'Welcomes a newly registered customer and links to the quick-start guide, TCP/IP port 9100 setup, documentation, and FAQ.'],
        ['release_announcement', 'Release announcement', 'Marketing', 0, 168, 'Introduces an available product release to customers who opted in to product updates, with release notes and download links.'],
        ['trial_guidance', 'Trial guidance', 'Marketing', 0, 168, 'Offers setup help and Trial-use guidance to Trial customers who explicitly opted in to marketing communication.'],
        ['inactivity_help', 'Inactivity help', 'Marketing', 0, 720, 'Offers troubleshooting and setup assistance to an opted-in customer after a defined period without product activity.'],
        ['promotion', 'Product promotion', 'Marketing', 0, 720, 'Shares an owner-approved offer or upgrade opportunity only with customers who explicitly opted in to promotional email.'],
        ['we_want_to_help', 'We Want to Help', 'Marketing', 0, 720, 'Offers tier-aware setup and troubleshooting help after more than 30 days without product activity.'],
        ['welcome_trial_start', 'Trial welcome and setup', 'Service', 0, 72, 'Welcomes a new Trial customer and explains the five-job daily allowance, receipt preview, and first-time setup.'],
        ['welcome_lite_purchase', 'Lite purchase welcome and setup', 'Service', 0, 72, 'Thanks a new Lite customer and explains single-listener setup and included features.'],
        ['welcome_pro_purchase', 'Pro purchase welcome and setup', 'Service', 0, 72, 'Thanks a new Pro customer and explains two-listener setup and included features.'],
        ['welcome_enterprise_purchase', 'Enterprise purchase welcome and setup', 'Service', 0, 72, 'Thanks a new Enterprise customer and explains multi-listener setup and included features.'],
        ['welcome_lite_upgrade', 'Lite upgrade welcome and setup', 'Service', 0, 72, 'Confirms an upgrade to Lite and explains the newly available features and setup steps.'],
        ['welcome_pro_upgrade', 'Pro upgrade welcome and setup', 'Service', 0, 72, 'Confirms an upgrade to Pro and explains the newly available features and setup steps.'],
        ['welcome_enterprise_upgrade', 'Enterprise upgrade welcome and setup', 'Service', 0, 72, 'Confirms an upgrade to Enterprise and explains the newly available features and setup steps.'],
    ];
    $insert = $pdo->prepare(
        'INSERT INTO communication_templates
            (template_key,display_name,message_class,essential,enabled,frequency_cap_hours,description)
         VALUES(:key,:name,:class,:essential,0,:cap,:description)
         ON DUPLICATE KEY UPDATE
            display_name=VALUES(display_name),
            message_class=VALUES(message_class),
            essential=VALUES(essential),
            description=VALUES(description)'
    );
    foreach ($templates as [$key, $name, $class, $essential, $cap, $description]) {
        $insert->execute([
            'key' => $key, 'name' => $name, 'class' => $class,
            'essential' => $essential, 'cap' => $cap, 'description' => $description,
        ]);
    }
}

function communication_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $statement = $pdo->prepare('SELECT setting_value FROM communication_settings WHERE setting_key=:key');
    $statement->execute(['key' => $key]);
    $value = $statement->fetchColumn();
    return is_string($value) ? $value : $default;
}

function communication_template_priority(string $templateKey): int
{
    return match ($templateKey) {
        'email_verification', 'password_recovery',
        'mfa_disabled_notification', 'mfa_admin_reset_notification' => 10,
        'purchase_confirmation', 'activation_ready' => 20,
        'support_confirmation' => 30,
        'maintenance_reminder' => 40,
        'welcome_setup', 'welcome_trial_start', 'welcome_lite_purchase',
        'welcome_pro_purchase', 'welcome_enterprise_purchase',
        'welcome_lite_upgrade', 'welcome_pro_upgrade', 'welcome_enterprise_upgrade' => 50,
        'trial_guidance' => 60,
        'release_announcement' => 70,
        'inactivity_help', 'we_want_to_help' => 80,
        'promotion' => 90,
        default => 100,
    };
}

function communication_template_trigger_flow(string $templateKey): string
{
    return match ($templateKey) {
        'email_verification' =>
            'Customer selects Verify your email → one eligible customer record is found → a single-use 30-minute link is sent → the customer creates a portal password and verifies ownership.',
        'password_recovery' =>
            'Existing portal user requests a reset → a single-use 30-minute link is sent → the customer chooses a new password → existing portal sessions are revoked.',
        'mfa_disabled_notification' =>
            'Customer confirms MFA removal with their password and a valid authenticator or recovery code → all portal sessions are revoked → this security notification is queued immediately.',
        'mfa_admin_reset_notification' =>
            'Authorized owner verifies customer identity, records a reason, and confirms reset → the encrypted secret is cleared without being read → sessions are revoked → this security notification is queued → MFA enrollment is required at next sign-in.',
        'purchase_confirmation' =>
            'Verified payment is captured and fulfilled → the account entitlement is recorded → confirmation is queued with a secure Customer Portal link.',
        'activation_ready' =>
            'A new paid license or upgrade is fulfilled → the account entitlement is ready → the customer is directed to the secure Customer Portal to link a computer.',
        'support_confirmation' =>
            'A support request is successfully submitted → a private reference is created → confirmation is queued with the reference and tracking destination.',
        'maintenance_reminder' =>
            'Daily scheduler finds eligible paid licenses at configured dates before or after maintenance ends → a renewal reminder is queued without implying that the permanent license expires.',
        'welcome_setup' =>
            'Legacy general onboarding flow → a newly registered customer becomes eligible → general setup, TCP/IP port 9100, documentation, and FAQ guidance is queued.',
        'release_announcement' =>
            'Daily scheduler identifies the latest release → opted-in customers running an older version and active within 120 days are selected → one announcement is queued per customer and release.',
        'trial_guidance' =>
            'Legacy Trial marketing flow → an opted-in Trial customer is selected by an approved campaign or controlled send → setup and Trial-use guidance is queued.',
        'inactivity_help' =>
            'Legacy inactivity flow → an opted-in customer reaches the configured inactivity period → troubleshooting and setup assistance is queued.',
        'promotion' =>
            'An administrator approves an offer or upgrade campaign → eligible marketing-opted-in customers are selected → frequency, pause, suppression, and quota rules are applied before delivery.',
        'we_want_to_help' =>
            'Daily scheduler detects more than 30 days without application activity → tier-aware setup and troubleshooting help is queued → delivery is cancelled if activity resumes first.',
        'welcome_trial_start' =>
            'A new Trial installation reports its first use → the next-day lifecycle scheduler selects the customer → Trial limits, features, and first-time setup guidance are queued.',
        'welcome_lite_purchase' =>
            'A new Lite purchase is captured and fulfilled → the Lite onboarding template is selected → included features and single-listener setup guidance are queued.',
        'welcome_pro_purchase' =>
            'A new Pro purchase is captured and fulfilled → the Pro onboarding template is selected → included features and two-listener setup guidance are queued.',
        'welcome_enterprise_purchase' =>
            'A new Enterprise purchase is captured and fulfilled → the Enterprise onboarding template is selected → multi-listener setup and Enterprise capabilities are queued.',
        'welcome_lite_upgrade' =>
            'An existing customer completes an upgrade to Lite → the new entitlement is fulfilled → newly available Lite features and setup guidance are queued.',
        'welcome_pro_upgrade' =>
            'An existing customer completes an upgrade to Pro → the new entitlement is fulfilled → newly available Pro features and setup guidance are queued.',
        'welcome_enterprise_upgrade' =>
            'An existing customer completes an upgrade to Enterprise → the new entitlement is fulfilled → newly available Enterprise features and setup guidance are queued.',
        default =>
            'An approved application or administrator action selects this template → customer eligibility and communication policy checks run → the message is queued for protected delivery.',
    };
}

function communication_global_parameters(PDO $pdo): array
{
    return [
        'documentation_url' => (string)communication_setting(
            $pdo,
            'documentation_url',
            'https://www.posprinteremulator.com/documentation'
        ),
        'help_center_url' => (string)communication_setting(
            $pdo,
            'help_center_url',
            'https://www.posprinteremulator.com/documentation'
        ),
        'support_request_url' => (string)communication_setting(
            $pdo,
            'support_request_url',
            'https://www.posprinteremulator.com/how-to-submit-a-support-request'
        ),
        'no_reply_notice' => (string)communication_setting(
            $pdo,
            'no_reply_notice',
            'Please do not reply to this email. This inbox is not monitored.'
        ),
    ];
}

function communication_template_language_warnings(
    string $subject,
    string $html,
    bool $inboxMonitored,
    ?string $templateSource = null
): array {
    $plain = strtolower(html_entity_decode(
        trim($subject . ' ' . preg_replace('/\s+/', ' ', strip_tags($html))),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    ));
    $requiredNotice = 'please do not reply to this email. this inbox is not monitored.';
    $replyLanguage = str_replace($requiredNotice, '', $plain);
    $warnings = [];
    foreach (COMMUNICATION_FORBIDDEN_REPLY_LANGUAGE as $phrase) {
        if (str_contains($replyLanguage, $phrase)) {
            $warnings[] = 'Reply-oriented language is prohibited: ' . $phrase;
        }
    }
    if (str_contains(strtolower($html), 'mailto:')) {
        $warnings[] = 'Email templates cannot contain mailto links.';
    }
    if (!$inboxMonitored &&
        !str_contains($plain, $requiredNotice)) {
        $warnings[] = 'The required unmonitored-inbox notice is missing.';
    }
    if (!preg_match(
        '/{{\s*params\.(documentation_url|help_center_url|support_request_url)\s*}}/i',
        $templateSource ?? $html
    )) {
        $warnings[] = 'Use a global documentation, Help Center, or support-request URL.';
    }
    if (!preg_match('/(view documentation|visit the help center|submit a support request)/i', strip_tags($html))) {
        $warnings[] = 'Add a standard documentation or support call to action.';
    }
    return $warnings;
}

function communication_onboarding_template(string $licenseTier, string $event): string
{
    $tier = strtolower(trim($licenseTier));
    $event = strtolower(trim($event));
    if ($tier === 'trial' && $event === 'start') {
        return 'welcome_trial_start';
    }
    if (in_array($tier, ['lite', 'pro', 'enterprise'], true) &&
        in_array($event, ['purchase', 'upgrade'], true)) {
        return 'welcome_' . $tier . '_' . $event;
    }
    throw new InvalidArgumentException('No approved onboarding template exists for this license event.');
}

function communication_tier_features(string $licenseTier): string
{
    return match (strtolower(trim($licenseTier))) {
        'trial' => 'Up to five external POS print jobs per day, unlimited built-in Test Receipts, one listener, session-only activity, and a Trial watermark.',
        'lite' => 'Unlimited jobs, one printer listener, full local history, watermark-free preview, exports, and Copy Receipt as Image.',
        'pro' => 'Everything in Lite, up to two printer listeners, full local history, capture, import, replay, exports, and Copy Receipt as Image.',
        'enterprise' => 'Everything in Pro, up to fifteen printer listeners, Copy Receipt as Image, the Standard Development Diagnostics Report, and the Advanced Diagnostics Package.',
        default => 'POS receipt emulation, preview, and troubleshooting tools.',
    };
}

function communication_setup_url(string $licenseTier): string
{
    $tier = strtolower(trim($licenseTier));
    return 'https://www.posprinteremulator.com/documentation?license=' . rawurlencode($tier);
}

function communication_test_parameters(string $templateKey, string $customerName): array
{
    $portal = 'https://userportal.posprinteremulator.com/';
    return match ($templateKey) {
        'email_verification' => ['customer_name' => $customerName, 'verification_url' => $portal],
        'password_recovery' => ['customer_name' => $customerName, 'reset_url' => $portal],
        'mfa_disabled_notification' => [
            'customer_name' => $customerName, 'event_label' => 'disabled by you', 'portal_url' => $portal,
        ],
        'mfa_admin_reset_notification' => [
            'customer_name' => $customerName, 'event_label' => 'reset by an authorized administrator',
            'portal_url' => $portal,
        ],
        'purchase_confirmation', 'activation_ready' => [
            'customer_name' => $customerName, 'license_tier' => 'Pro', 'portal_url' => $portal,
        ],
        'support_confirmation' => [
            'customer_name' => $customerName, 'support_reference' => 'SUP-TEST00000000', 'support_url' => $portal,
        ],
        'maintenance_reminder' => [
            'customer_name' => $customerName, 'license_tier' => 'Pro',
            'maintenance_end' => 'December 31, 2026', 'renewal_url' => $portal,
        ],
        'release_announcement' => [
            'customer_name' => $customerName, 'installed_version' => '0.3.44',
            'latest_version' => 'v0.3.47', 'release_summary' => 'One-click server-authorized Five-Day Promotional Trial',
            'download_url' => 'https://www.posprinteremulator.com/download',
        ],
        'inactivity_help', 'we_want_to_help' => [
            'customer_name' => $customerName,
            'license_tier' => 'Pro',
            'feature_summary' => communication_tier_features('Pro'),
            'setup_url' => communication_setup_url('Pro'),
            'troubleshooting_url' => 'https://www.posprinteremulator.com/esc-pos-troubleshooting',
            'contact_support_url' => $portal,
        ],
        'welcome_trial_start', 'welcome_lite_purchase', 'welcome_pro_purchase',
        'welcome_enterprise_purchase', 'welcome_lite_upgrade',
        'welcome_pro_upgrade', 'welcome_enterprise_upgrade' => (static function () use ($templateKey, $customerName, $portal): array {
            preg_match('/welcome_(trial|lite|pro|enterprise)_(start|purchase|upgrade)/', $templateKey, $match);
            $tier = ucfirst((string)($match[1] ?? 'trial'));
            $event = ucfirst((string)($match[2] ?? 'start'));
            return [
                'customer_name' => $customerName,
                'license_tier' => $tier,
                'event_label' => $event,
                'feature_summary' => communication_tier_features($tier),
                'setup_url' => communication_setup_url($tier),
                'contact_support_url' => $portal,
            ];
        })(),
        default => ['customer_name' => $customerName, 'portal_url' => $portal],
    };
}

function communication_template_blueprint(string $templateKey): array
{
    $catalog = [
        'email_verification' => [
            'subject' => 'Verify your POS Printer Emulator account',
            'headline' => 'Verify your customer account',
            'intro' => 'Hello {{ params.customer_name }}, use the secure button below within 30 minutes to verify your email address and create your Customer Portal password.',
            'detail' => 'This single-use link protects your account and will expire automatically.',
            'button' => 'Verify my account',
            'button_url' => '{{ params.verification_url }}',
        ],
        'password_recovery' => [
            'subject' => 'Reset your POS Printer Emulator password',
            'headline' => 'Reset your password',
            'intro' => 'Hello {{ params.customer_name }}, a password reset was requested for your POS Printer Emulator Customer Portal account.',
            'detail' => 'Use the secure button below within 30 minutes. The link is single-use and existing portal sessions will be revoked after the password changes.',
            'button' => 'Reset my password',
            'button_url' => '{{ params.reset_url }}',
        ],
        'mfa_disabled_notification' => [
            'subject' => 'Two-factor authentication was disabled',
            'headline' => 'Account security changed',
            'intro' => 'Hello {{ params.customer_name }}, two-factor authentication was {{ params.event_label }} for your Customer Portal account.',
            'detail' => 'All existing portal sessions were revoked. If you did not make this change, secure your account and submit a support request immediately.',
            'button' => 'Open Customer Portal',
            'button_url' => '{{ params.portal_url }}',
        ],
        'mfa_admin_reset_notification' => [
            'subject' => 'Two-factor authentication was reset',
            'headline' => 'Two-factor authentication reset',
            'intro' => 'Hello {{ params.customer_name }}, two-factor authentication was {{ params.event_label }} after an identity-verification request.',
            'detail' => 'All existing sessions were revoked. You must enroll a new authenticator the next time you sign in.',
            'button' => 'Open Customer Portal',
            'button_url' => '{{ params.portal_url }}',
        ],
        'purchase_confirmation' => [
            'subject' => 'Your POS Printer Emulator purchase is confirmed',
            'headline' => 'Thank you for your purchase',
            'intro' => 'Hello {{ params.customer_name }}, your {{ params.license_tier }} License purchase is confirmed.',
            'detail' => 'Open POS Printer Emulator and select Settings → License → Link This Computer. Then sign in to the Customer Portal, review the computer, choose this license, and approve the link. Licensing is applied automatically.',
            'button' => 'Open setup guide',
            'button_url' => 'https://www.posprinteremulator.com/user-portal-guide#computers',
        ],
        'activation_ready' => [
            'subject' => 'Your POS Printer Emulator license is ready',
            'headline' => 'Your license is ready',
            'intro' => 'Hello {{ params.customer_name }}, your {{ params.license_tier }} License entitlement is ready.',
            'detail' => 'In POS Printer Emulator, select Settings → License → Link This Computer. Sign in with your verified account, approve the computer, and choose the eligible license. The application applies it automatically.',
            'button' => 'Link this computer',
            'button_url' => 'https://www.posprinteremulator.com/user-portal-guide#computers',
        ],
        'support_confirmation' => [
            'subject' => 'Support request {{ params.support_reference }} received',
            'headline' => 'We received your support request',
            'intro' => 'Hello {{ params.customer_name }}, your POS Printer Emulator support request was recorded as {{ params.support_reference }}.',
            'detail' => 'Use the secure support area to review its status and add any requested information.',
            'button' => 'View support request',
            'button_url' => '{{ params.support_url }}',
        ],
        'maintenance_reminder' => [
            'subject' => 'Maintenance and Support coverage reminder',
            'headline' => 'Keep updates and support available',
            'intro' => 'Hello {{ params.customer_name }}, Maintenance and Support for your {{ params.license_tier }} License is scheduled through {{ params.maintenance_end }}.',
            'detail' => 'Your permanent software license does not expire. Renewal keeps future updates and eligible technical support available.',
            'button' => 'Review renewal options',
            'button_url' => '{{ params.renewal_url }}',
        ],
        'release_announcement' => [
            'subject' => 'POS Printer Emulator {{ params.latest_version }} is available',
            'headline' => 'A new version is ready',
            'intro' => 'Hello {{ params.customer_name }}, {{ params.latest_version }} is now available. Your reported installation is {{ params.installed_version }}.',
            'detail' => '{{ params.release_summary }}',
            'button' => 'Download latest version',
            'button_url' => '{{ params.download_url }}',
        ],
        'trial_guidance' => [
            'subject' => 'Get more from your POS Printer Emulator Trial',
            'headline' => 'Make the most of your Trial',
            'intro' => 'Hello {{ params.customer_name }}, use the setup guide to connect a Windows TCP/IP printer port and begin testing ESC/POS receipts.',
            'detail' => 'Trial Mode includes five external POS print jobs per day, unlimited built-in Test Receipts, one listener, live receipt preview, and core ESC/POS testing.',
            'button' => 'Start setup',
            'button_url' => '{{ params.documentation_url }}',
        ],
        'inactivity_help' => [
            'subject' => 'Need help with POS Printer Emulator?',
            'headline' => 'We want to help',
            'intro' => 'Hello {{ params.customer_name }}, it has been a while since POS Printer Emulator reported activity.',
            'detail' => 'Our setup and troubleshooting guides can help with listener configuration, port 9100, ESC/POS data, and receipt preview.',
            'button' => 'Get setup help',
            'button_url' => '{{ params.documentation_url }}',
        ],
        'promotion' => [
            'subject' => 'Explore more POS Printer Emulator capabilities',
            'headline' => 'More tools for receipt testing',
            'intro' => 'Hello {{ params.customer_name }}, explore the latest POS Printer Emulator plans and capabilities for your testing workflow.',
            'detail' => 'Compare listener allowances, receipt history, capture and replay, Copy Receipt as Image, and Enterprise diagnostic reports.',
            'button' => 'View documentation',
            'button_url' => '{{ params.documentation_url }}',
        ],
        'we_want_to_help' => [
            'subject' => 'We want to help with POS Printer Emulator',
            'headline' => 'We want to help',
            'intro' => 'Hello {{ params.customer_name }}, POS Printer Emulator has not reported activity for more than 30 days.',
            'detail' => 'Your {{ params.license_tier }} License includes {{ params.feature_summary }} Use the resources below for setup and troubleshooting assistance.',
            'button' => 'Get setup help',
            'button_url' => '{{ params.setup_url }}',
            'secondary_button' => 'View troubleshooting guide',
            'secondary_url' => '{{ params.troubleshooting_url }}',
        ],
        'welcome_setup' => [
            'subject' => 'Welcome to POS Printer Emulator',
            'headline' => 'Let’s get your emulator ready',
            'intro' => 'Hello {{ params.customer_name }}, thank you for choosing POS Printer Emulator.',
            'detail' => 'Follow the setup guide to install the listener, configure TCP/IP port 9100, and preview your first ESC/POS receipt.',
            'button' => 'Open setup guide',
            'button_url' => '{{ params.documentation_url }}',
        ],
    ];

    if (preg_match('/^welcome_(trial|lite|pro|enterprise)_(start|purchase|upgrade)$/', $templateKey, $match)) {
        $tier = ucfirst($match[1]);
        $event = $match[2];
        $eventText = $event === 'start'
            ? 'Thank you for trying POS Printer Emulator.'
            : ($event === 'purchase'
                ? 'Thank you for purchasing POS Printer Emulator.'
                : 'Your POS Printer Emulator license upgrade is complete.');
        $catalog[$templateKey] = [
            'subject' => $event === 'start'
                ? 'Welcome to your POS Printer Emulator Trial'
                : 'Welcome to POS Printer Emulator ' . $tier,
            'headline' => $event === 'upgrade' ? 'Your ' . $tier . ' upgrade is ready' : 'Welcome to ' . $tier,
            'intro' => 'Hello {{ params.customer_name }}, ' . $eventText,
            'detail' => 'Your {{ params.license_tier }} License includes {{ params.feature_summary }} Follow the recommended setup guide to begin.',
            'button' => 'Start setup',
            'button_url' => '{{ params.setup_url }}',
        ];
    }

    if (!isset($catalog[$templateKey])) {
        throw new InvalidArgumentException('No approved Brevo template blueprint exists for this registry entry.');
    }
    $blueprint = $catalog[$templateKey];
    $blueprint['template_name'] = 'PPE · ' . str_replace('_', ' ', $templateKey);
    $blueprint['tag'] = 'ppe-' . str_replace('_', '-', $templateKey);
    $blueprint['html'] = communication_template_html($blueprint);
    return $blueprint;
}

function communication_template_html(array $blueprint): string
{
    $secondary = '';
    if (!empty($blueprint['secondary_button']) && !empty($blueprint['secondary_url'])) {
        $secondary = '<a class="button secondary" href="' . $blueprint['secondary_url'] . '">' .
            htmlspecialchars((string)$blueprint['secondary_button'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' .
        '<style>body{margin:0;background:#eef4f8;color:#10233d;font:16px/1.55 Arial,sans-serif}.wrap{padding:28px 14px}' .
        '.card{max-width:640px;margin:auto;background:#fff;border:1px solid #d9e6ef;border-radius:14px;overflow:hidden}' .
        '.header{background:#071d35;padding:22px 28px}.header img{display:block;width:220px;max-width:100%;height:auto}' .
        '.content{padding:32px 28px}.content h1{margin:0 0 16px;font-size:27px;line-height:1.2}.content p{margin:0 0 18px}' .
        '.actions{display:flex;gap:10px;flex-wrap:wrap;margin:24px 0}.button{display:inline-block;padding:12px 18px;border-radius:8px;background:#16b9dc;color:#031526!important;font-weight:700;text-decoration:none}' .
        '.button.secondary{background:#e8f4f8}.help{padding-top:18px;border-top:1px solid #d9e6ef;font-size:14px}.help a{color:#087fa3}' .
        '.footer{padding:20px 28px;background:#f5f9fc;color:#60788b;font-size:12px}@media(max-width:520px){.content,.header,.footer{padding-left:20px;padding-right:20px}.button{display:block;text-align:center}}</style></head>' .
        '<body><div class="wrap"><div class="card"><div class="header"><img src="https://www.posprinteremulator.com/assets/logo-web.png" alt="POS Printer Emulator"></div>' .
        '<div class="content"><h1>' . htmlspecialchars((string)$blueprint['headline'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>' .
        '<p>' . $blueprint['intro'] . '</p><p>' . $blueprint['detail'] . '</p><div class="actions"><a class="button" href="' .
        $blueprint['button_url'] . '">' . htmlspecialchars((string)$blueprint['button'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>' . $secondary . '</div>' .
        '<div class="help"><strong>Need help with POS Printer Emulator?</strong><p>Our complete documentation and troubleshooting guides are available online.</p>' .
        '<p><a href="{{ params.documentation_url }}">View Documentation</a> &nbsp;·&nbsp; <a href="{{ params.support_request_url }}">Submit a Support Request</a></p></div></div>' .
        '<div class="footer"><p>{{ params.no_reply_notice }}</p><p>EPCOM Ltd. · POS Printer Emulator</p></div></div></div></body></html>';
}

function communication_brevo_request(string $method, string $path, ?array $payload = null): array
{
    $config = communication_config();
    $apiKey = trim((string)($config['brevo_api_key'] ?? ''));
    $apiBase = rtrim((string)($config['brevo_api_base'] ?? ''), '/');
    $parts = parse_url($apiBase);
    if ($apiKey === '' || !function_exists('curl_init') || !is_array($parts) ||
        strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== 'api.brevo.com') {
        throw new RuntimeException('Brevo template management is not configured on this server.');
    }
    if (!preg_match('#^/[A-Za-z0-9/_-]+$#', $path)) {
        throw new InvalidArgumentException('The Brevo API path is invalid.');
    }
    $curl = curl_init($apiBase . $path);
    $headers = ['Accept: application/json', 'api-key: ' . $apiKey];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($payload !== null) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $networkError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException($networkError !== '' ? $networkError : 'Brevo did not return a response.');
    }
    $decoded = trim($body) === '' ? [] : json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded) ? (string)($decoded['message'] ?? $decoded['code'] ?? '') : '';
        throw new RuntimeException('Brevo returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Brevo returned an invalid response.');
    }
    return $decoded;
}

function communication_validate_brevo_template(
    array $provider,
    array $blueprint,
    string $expectedSenderEmail
): array
{
    $warnings = [];
    $config = communication_config();
    $html = (string)($provider['htmlContent'] ?? '');
    $subject = trim((string)($provider['subject'] ?? ''));
    $sender = is_array($provider['sender'] ?? null) ? $provider['sender'] : [];
    if (!hash_equals((string)$blueprint['subject'], $subject)) {
        $warnings[] = 'The Brevo subject does not match the approved subject.';
    }
    if (!hash_equals(
        hash('sha256', (string)$blueprint['html']),
        hash('sha256', $html)
    )) {
        $warnings[] = 'The Brevo content, branding, buttons, links, or placeholders differ from the approved template.';
    }
    if (strcasecmp(trim($expectedSenderEmail), trim((string)($sender['email'] ?? ''))) !== 0) {
        $warnings[] = 'The Brevo sender does not match the approved sender.';
    }
    if (!str_contains($html, 'https://www.posprinteremulator.com/assets/logo-web.png')) {
        $warnings[] = 'The approved POS Printer Emulator logo is missing.';
    }
    foreach (['documentation_url', 'support_request_url', 'no_reply_notice'] as $required) {
        if (!preg_match('/{{\s*params\.' . preg_quote($required, '/') . '\s*}}/i', $html)) {
            $warnings[] = 'The required global placeholder is missing: ' . $required;
        }
    }
    preg_match_all('/{{\s*params\.([a-z0-9_]+)\s*}}/i', (string)$blueprint['subject'] . ' ' . $blueprint['html'], $approved);
    preg_match_all('/{{\s*params\.([a-z0-9_]+)\s*}}/i', $subject . ' ' . $html, $actual);
    $approvedPlaceholders = array_values(array_unique(array_map('strtolower', $approved[1] ?? [])));
    $actualPlaceholders = array_values(array_unique(array_map('strtolower', $actual[1] ?? [])));
    foreach (array_diff($approvedPlaceholders, $actualPlaceholders) as $missing) {
        $warnings[] = 'The approved placeholder is missing: ' . $missing;
    }
    foreach (array_diff($actualPlaceholders, COMMUNICATION_PARAMETER_KEYS) as $unknown) {
        $warnings[] = 'The template contains an unapproved placeholder: ' . $unknown;
    }
    if ((bool)($provider['isActive'] ?? false)) {
        $warnings[] = 'Newly created Brevo templates must remain inactive until approved.';
    }
    $policyHtml = preg_replace(
        '/{{\s*params\.no_reply_notice\s*}}/i',
        'Please do not reply to this email. This inbox is not monitored.',
        $html
    ) ?? $html;
    return array_values(array_unique(array_merge(
        $warnings,
        communication_template_language_warnings(
            $subject,
            $policyHtml,
            (bool)($config['inbox_monitored'] ?? false),
            $html
        )
    )));
}

function communication_create_and_map_template(
    PDO $pdo,
    string $templateKey,
    string $actor,
    string $reason
): array {
    ensure_communication_schema($pdo);
    $template = $pdo->prepare(
        'SELECT template_key,display_name,brevo_template_id,mapping_candidate_brevo_template_id
         FROM communication_templates WHERE template_key=:key LIMIT 1'
    );
    $template->execute(['key' => $templateKey]);
    $row = $template->fetch();
    if (!is_array($row)) {
        throw new InvalidArgumentException('The approved registry entry was not found.');
    }
    $blueprint = communication_template_blueprint($templateKey);
    $config = communication_config();
    $messageClass = $pdo->prepare(
        'SELECT message_class FROM communication_templates WHERE template_key=:key LIMIT 1'
    );
    $messageClass->execute(['key' => $templateKey]);
    $sender = communication_template_sender(
        $pdo,
        $templateKey,
        (string)$messageClass->fetchColumn(),
        $config
    );
    $mappedId = (int)($row['brevo_template_id'] ?? 0);
    $candidateId = $mappedId > 0
        ? $mappedId
        : (int)($row['mapping_candidate_brevo_template_id'] ?? 0);
    try {
        $mark = $pdo->prepare(
            "UPDATE communication_templates SET mapping_status=:status,mapping_error_detail=NULL,
             enabled=0,updated_by=:actor WHERE template_key=:key"
        );
        $mark->execute([
            'status' => $candidateId > 0 ? 'Validating' : 'Creating',
            'actor' => $actor,
            'key' => $templateKey,
        ]);
        if ($candidateId < 1) {
            $created = communication_brevo_request('POST', '/smtp/templates', [
                'sender' => [
                    'name' => $sender['name'],
                    'email' => $sender['email'],
                ],
                'subject' => (string)$blueprint['subject'],
                'templateName' => (string)$blueprint['template_name'],
                'htmlContent' => (string)$blueprint['html'],
                'isActive' => false,
                'tag' => (string)$blueprint['tag'],
            ]);
            $candidateId = (int)($created['id'] ?? 0);
            if ($candidateId < 1) {
                throw new RuntimeException('Brevo did not return a template ID.');
            }
            $saveCandidate = $pdo->prepare(
                "UPDATE communication_templates SET mapping_candidate_brevo_template_id=:id,
                 mapping_status='Validating',updated_by=:actor WHERE template_key=:key"
            );
            $saveCandidate->execute(['id' => $candidateId, 'actor' => $actor, 'key' => $templateKey]);
            crm_record_admin_audit(
                $pdo, null, 'COMMUNICATION_TEMPLATE_CREATED', $actor,
                'Communication Template', $templateKey . ':' . $candidateId, $reason
            );
        }
        if ($mappedId > 0) {
            $duplicate = $pdo->prepare(
                'SELECT COUNT(*) FROM communication_templates
                 WHERE brevo_template_id=:template_id AND template_key<>:template_key'
            );
            $duplicate->execute(['template_id' => $mappedId, 'template_key' => $templateKey]);
            if ((int)$duplicate->fetchColumn() > 0) {
                throw new DomainException('The Brevo template ID is assigned to more than one approved registry entry.');
            }
            communication_brevo_request('PUT', '/smtp/templates/' . $mappedId, [
                'sender' => [
                    'name' => $sender['name'],
                    'email' => $sender['email'],
                ],
                'subject' => (string)$blueprint['subject'],
                'templateName' => (string)$blueprint['template_name'],
                'htmlContent' => (string)$blueprint['html'],
                'isActive' => false,
                'tag' => (string)$blueprint['tag'],
            ]);
            crm_record_admin_audit(
                $pdo, null, 'COMMUNICATION_TEMPLATE_SYNCHRONIZED', $actor,
                'Communication Template', $templateKey . ':' . $mappedId, $reason
            );
        }

        $provider = communication_brevo_request('GET', '/smtp/templates/' . $candidateId);
        $warnings = communication_validate_brevo_template(
            $provider,
            $blueprint,
            $sender['email']
        );
        if ($warnings !== []) {
            throw new DomainException(implode(' ', $warnings));
        }
        crm_record_admin_audit(
            $pdo, null, 'COMMUNICATION_TEMPLATE_VALIDATED', $actor,
            'Communication Template', $templateKey . ':' . $candidateId, $reason
        );

        $allowlist = array_values(array_filter(
            array_map('trim', is_array($config['test_allowlist'] ?? null) ? $config['test_allowlist'] : []),
            static fn(string $email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
        if ($allowlist === []) {
            throw new DomainException('Add a verified test recipient to the protected Brevo test allowlist.');
        }
        $parameters = array_replace(
            communication_test_parameters($templateKey, 'Alex Morgan'),
            communication_global_parameters($pdo)
        );
        communication_brevo_request('POST', '/smtp/email', [
            'to' => [['email' => $allowlist[0], 'name' => 'POS Printer Emulator Test Recipient']],
            'templateId' => $candidateId,
            'params' => $parameters,
            'headers' => [
                'X-Mailin-custom' => 'ppe-template-validation|' . $templateKey,
                'Idempotency-Key' => hash('sha256', 'template-validation|' . $templateKey . '|' . $candidateId),
            ],
        ]);
        crm_record_admin_audit(
            $pdo, null, 'COMMUNICATION_TEMPLATE_TEST_SENT', $actor,
            'Communication Template', $templateKey . ':' . $candidateId, $reason
        );

        $map = $pdo->prepare(
            "UPDATE communication_templates SET brevo_template_id=:id,enabled=0,
             preview_brevo_template_id=:preview_id,preview_verified_at=UTC_TIMESTAMP(6),
             preview_warnings_json='[]',mapping_status='Mapped',mapping_error_detail=NULL,
             mapping_validated_at=UTC_TIMESTAMP(6),mapping_test_sent_at=UTC_TIMESTAMP(6),
             updated_by=:actor WHERE template_key=:key
               AND COALESCE(brevo_template_id,0)=:expected_id"
        );
        $map->execute([
            'id' => $candidateId, 'preview_id' => $candidateId,
            'actor' => $actor, 'key' => $templateKey, 'expected_id' => $mappedId,
        ]);
        if ($map->rowCount() !== 1) {
            throw new DomainException('The registry mapping changed while validation was in progress.');
        }
        crm_record_admin_audit(
            $pdo, null, $mappedId > 0 ? 'COMMUNICATION_TEMPLATE_REVALIDATED' : 'COMMUNICATION_TEMPLATE_MAPPED', $actor,
            'Communication Template', $templateKey . ':' . $candidateId, $reason
        );
        return ['status' => $mappedId > 0 ? 'revalidated' : 'mapped', 'template_id' => $candidateId];
    } catch (Throwable $exception) {
        $detail = communication_sanitize_provider_error($exception->getMessage());
        $failed = $pdo->prepare(
            "UPDATE communication_templates SET mapping_status='Failed',mapping_error_detail=:detail,
             enabled=0,preview_brevo_template_id=NULL,preview_verified_at=NULL,
             preview_warnings_json=NULL,updated_by=:actor WHERE template_key=:key"
        );
        $failed->execute(['detail' => crm_text_slice($detail, 0, 500), 'actor' => $actor, 'key' => $templateKey]);
        crm_record_admin_audit(
            $pdo, null, 'COMMUNICATION_TEMPLATE_MAPPING_FAILED', $actor,
            'Communication Template', $templateKey . ($candidateId > 0 ? ':' . $candidateId : ''), $detail
        );
        return ['status' => 'failed', 'error' => $detail];
    }
}

function communication_latest_consent(PDO $pdo, string $customerId, string $type): string
{
    $statement = $pdo->prepare(
        'SELECT consent_state FROM customer_consents
         WHERE customer_id=:customer_id AND consent_type=:type
         ORDER BY recorded_at DESC,id DESC LIMIT 1'
    );
    $statement->execute(['customer_id' => $customerId, 'type' => $type]);
    $value = $statement->fetchColumn();
    return is_string($value) ? $value : 'Not Asked';
}

function communication_active_suppressions(PDO $pdo, string $customerId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT reason FROM customer_email_suppressions
         WHERE customer_id=:customer_id AND active=1'
    );
    $statement->execute(['customer_id' => $customerId]);
    return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
}

function communication_has_pending_email_verification(PDO $pdo, string $customerId, string $email): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM customer_email_verifications
         WHERE customer_id=:customer_id AND email_hash=UNHEX(SHA2(:email,256))
           AND used_at IS NULL AND expires_at>UTC_TIMESTAMP(6)
         ORDER BY requested_at DESC LIMIT 1'
    );
    $statement->execute(['customer_id' => $customerId, 'email' => crm_normalize_email($email)]);
    return (bool)$statement->fetchColumn();
}

function communication_policy_decision(string $messageClass, string $marketingConsent, array $suppressions): array
{
    if (!in_array($messageClass, COMMUNICATION_MESSAGE_CLASSES, true)) {
        return ['allowed' => false, 'reason' => 'invalid_message_class'];
    }
    foreach ($suppressions as $reason) {
        if (in_array((string)$reason, COMMUNICATION_BLOCKING_SUPPRESSIONS, true)) {
            return ['allowed' => false, 'reason' => 'suppressed_' . strtolower(str_replace(' ', '_', (string)$reason))];
        }
    }
    if ($messageClass === 'Marketing') {
        if (in_array('Unsubscribed', $suppressions, true)) {
            return ['allowed' => false, 'reason' => 'unsubscribed'];
        }
        if ($marketingConsent !== 'Granted') {
            return ['allowed' => false, 'reason' => 'marketing_consent_required'];
        }
    }
    return ['allowed' => true, 'reason' => 'allowed'];
}

function communication_validate_parameters(array $parameters): array
{
    $clean = [];
    foreach ($parameters as $key => $value) {
        $key = strtolower(trim((string)$key));
        if (!in_array($key, COMMUNICATION_PARAMETER_KEYS, true)) {
            throw new InvalidArgumentException('The message contains an unapproved template parameter.');
        }
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Template parameters must be plain text values.');
        }
        $text = trim((string)$value);
        if (strlen($text) > 500) {
            throw new InvalidArgumentException('A template parameter is too long.');
        }
        if (preg_match('/(?:PPE1-|activation[\s_-]*key|password|secret|credential|receipt[\s_-]*(?:data|raw)|diagnostic[\s_-]*log)/i', $text)) {
            throw new InvalidArgumentException('Sensitive information cannot be included in an email template.');
        }
        if (preg_match('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', $text) ||
            preg_match('/(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)/', $text) ||
            preg_match('/\b(?:[A-F0-9]{1,4}:){2,}[A-F0-9:]+\b/i', $text)) {
            throw new InvalidArgumentException('Email addresses and network addresses cannot be included in template parameters.');
        }
        if (str_ends_with($key, '_url')) {
            $parts = parse_url($text);
            $trustedHosts = ['www.posprinteremulator.com', 'userportal.posprinteremulator.com', 'github.com'];
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
                !in_array(strtolower((string)($parts['host'] ?? '')), $trustedHosts, true) ||
                isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('Message links must use an approved HTTPS destination.');
            }
        }
        $clean[$key] = $text;
    }
    return $clean;
}

function communication_enqueue(
    PDO $pdo,
    string $customerId,
    string $templateKey,
    array $parameters,
    string $idempotencyKey,
    ?string $campaignId = null,
    bool $manualSend = false,
    ?DateTimeImmutable $availableAt = null
): string {
    ensure_communication_schema($pdo);
    $customer = $pdo->prepare(
        'SELECT customer_id,canonical_email,email_verified_at,status FROM customers WHERE customer_id=:id LIMIT 1'
    );
    $customer->execute(['id' => strtolower(trim($customerId))]);
    $customerRow = $customer->fetch();
    $verificationException = $templateKey === 'email_verification' && $customerRow &&
        communication_has_pending_email_verification(
            $pdo,
            (string)$customerRow['customer_id'],
            (string)$customerRow['canonical_email']
        );
    if (!$customerRow || $customerRow['status'] !== 'Active' ||
        (empty($customerRow['email_verified_at']) && !$verificationException)) {
        throw new DomainException('The customer must be active and have a verified email address.');
    }

    $template = $pdo->prepare('SELECT * FROM communication_templates WHERE template_key=:key LIMIT 1');
    $template->execute(['key' => $templateKey]);
    $templateRow = $template->fetch();
    if (!$templateRow || (int)$templateRow['enabled'] !== 1 || empty($templateRow['brevo_template_id'])) {
        throw new DomainException('The communication template is not enabled and mapped to an approved provider template.');
    }

    $decision = communication_policy_decision(
        (string)$templateRow['message_class'],
        communication_latest_consent($pdo, (string)$customerRow['customer_id'], 'Marketing'),
        communication_active_suppressions($pdo, (string)$customerRow['customer_id'])
    );
    if (!$decision['allowed']) {
        throw new DomainException('The communication is blocked by customer consent or suppression policy.');
    }

    $capHours = max(0, (int)$templateRow['frequency_cap_hours']);
    if ($capHours > 0) {
        $recent = $pdo->prepare(
            "SELECT COUNT(*) FROM communication_outbox
             WHERE customer_id=:customer_id AND template_key=:template_key
               AND state IN ('Pending','Processing','Deferred','Sent','DeliveryUnknown')
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL :cap HOUR)"
        );
        $recent->bindValue(':customer_id', $customerRow['customer_id']);
        $recent->bindValue(':template_key', $templateKey);
        $recent->bindValue(':cap', $capHours, PDO::PARAM_INT);
        $recent->execute();
        if ((int)$recent->fetchColumn() > 0) {
            throw new DomainException('The customer frequency cap has not elapsed.');
        }
    }

    $parameters = array_replace($parameters, communication_global_parameters($pdo));
    $clean = communication_validate_parameters($parameters);
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
        throw new InvalidArgumentException('A valid idempotency key is required.');
    }
    $messageId = crm_uuid();
    $availableAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $insert = $pdo->prepare(
        'INSERT INTO communication_outbox
            (message_id,customer_id,template_key,campaign_id,message_class,essential,manual_send,priority,recipient_hash,parameters_json,idempotency_key,available_at)
         VALUES
            (:message_id,:customer_id,:template_key,:campaign_id,:message_class,:essential,:manual_send,:priority,
             UNHEX(SHA2(:recipient_email,256)),:parameters,:idempotency_key,:available_at)'
    );
    try {
        $insert->execute([
            'message_id' => $messageId,
            'customer_id' => $customerRow['customer_id'],
            'template_key' => $templateKey,
            'campaign_id' => $campaignId,
            'message_class' => $templateRow['message_class'],
            'essential' => (int)$templateRow['essential'],
            'manual_send' => $manualSend ? 1 : 0,
            'priority' => communication_template_priority($templateKey),
            'recipient_email' => crm_normalize_email((string)$customerRow['canonical_email']),
            'parameters' => json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'idempotency_key' => $idempotencyKey,
            'available_at' => $availableAt->format('Y-m-d H:i:s.u'),
        ]);
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() !== '23000') throw $exception;
        $existing = $pdo->prepare('SELECT message_id FROM communication_outbox WHERE idempotency_key=:key LIMIT 1');
        $existing->execute(['key' => $idempotencyKey]);
        $existingId = $existing->fetchColumn();
        if (!is_string($existingId) || $existingId === '') throw $exception;
        return $existingId;
    }
    return $messageId;
}

function communication_quota_decision(array $usage, string $messageClass, bool $essential, bool $manualSend, array $limits): array
{
    $providerLimit = max(1, (int)($limits['provider_daily_limit'] ?? 300));
    $automatedLimit = min($providerLimit, max(1, (int)($limits['automated_daily_limit'] ?? 290)));
    $reserve = min($automatedLimit, max(0, (int)($limits['service_reserve'] ?? 50)));
    $providerUsed = max(0, (int)($usage['provider_used'] ?? 0));
    $automatedUsed = max(0, (int)($usage['automated_used'] ?? 0));
    $nonEssentialCeiling = max(0, $automatedLimit - $reserve);

    if ($providerUsed >= $providerLimit) {
        return ['allowed' => false, 'reason' => 'provider_daily_limit'];
    }
    if (!$manualSend && $automatedUsed >= $automatedLimit) {
        return ['allowed' => false, 'reason' => 'automated_daily_limit'];
    }
    if (!$essential && $providerUsed >= $nonEssentialCeiling) {
        return ['allowed' => false, 'reason' => 'service_reserve'];
    }
    if ($messageClass === 'Marketing' && $providerUsed >= $nonEssentialCeiling) {
        return ['allowed' => false, 'reason' => 'service_reserve'];
    }
    return ['allowed' => true, 'reason' => 'allowed'];
}

function communication_reserve_quota(PDO $pdo, array $message): array
{
    $config = communication_config();
    $date = gmdate('Y-m-d');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO communication_quota_daily(quota_date) VALUES(:quota_date)')
            ->execute(['quota_date' => $date]);
        $select = $pdo->prepare('SELECT * FROM communication_quota_daily WHERE quota_date=:quota_date FOR UPDATE');
        $select->execute(['quota_date' => $date]);
        $usage = $select->fetch() ?: [];
        $decision = communication_quota_decision(
            $usage,
            (string)$message['message_class'],
            (bool)$message['essential'],
            (bool)$message['manual_send'],
            $config
        );
        if (!$decision['allowed']) {
            $pdo->rollBack();
            return $decision;
        }
        $column = $message['message_class'] === 'Marketing' ? 'marketing_used' : 'service_used';
        $automatedIncrement = (int)$message['manual_send'] === 1 ? 0 : 1;
        $manualIncrement = (int)$message['manual_send'] === 1 ? 1 : 0;
        $pdo->exec(
            "UPDATE communication_quota_daily
             SET provider_used=provider_used+1,
                 automated_used=automated_used+{$automatedIncrement},
                 {$column}={$column}+1,
                 manual_used=manual_used+{$manualIncrement}
             WHERE quota_date=" . $pdo->quote($date)
        );
        $pdo->commit();
        return ['allowed' => true, 'reason' => 'allowed'];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function communication_next_quota_reset(): string
{
    return (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function communication_quiet_hours_delay(array $config, bool $essential, ?DateTimeImmutable $now = null): int
{
    if ($essential) return 0;
    try {
        $zone = new DateTimeZone((string)($config['timezone'] ?? 'America/New_York'));
    } catch (Throwable) {
        $zone = new DateTimeZone('America/New_York');
    }
    $local = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
    $start = min(23, max(0, (int)($config['quiet_hours_start'] ?? 20)));
    $end = min(23, max(0, (int)($config['quiet_hours_end'] ?? 8)));
    $hour = (int)$local->format('G');
    $quiet = $start === $end || ($start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end));
    if (!$quiet) return 0;
    $release = $local->setTime($end, 0);
    if ($release <= $local) $release = $release->modify('+1 day');
    return max(60, $release->getTimestamp() - $local->getTimestamp());
}

function communication_sanitize_provider_error(string $detail): string
{
    $detail = preg_replace('/api-key\s*[:=]\s*\S+/i', 'api-key=[redacted]', $detail) ?? '';
    $detail = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $detail) ?? '';
    $detail = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '[email redacted]', $detail) ?? '';
    return crm_text_slice(trim($detail), 0, 240);
}

function communication_claim_next(PDO $pdo): ?array
{
    ensure_communication_schema($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "UPDATE communication_outbox
             SET state='Deferred',available_at=UTC_TIMESTAMP(6),locked_at=NULL,last_error_code='STALE_WORKER'
             WHERE state='Processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE)"
        );
        $row = $pdo->query(
            "SELECT o.*,t.brevo_template_id,t.preview_brevo_template_id,t.preview_verified_at,
                    t.preview_warnings_json,c.canonical_email,c.email_verified_at,c.status AS customer_status
             FROM communication_outbox o
             JOIN communication_templates t ON t.template_key=o.template_key
             JOIN customers c ON c.customer_id=o.customer_id
             WHERE o.state IN ('Pending','Deferred') AND o.available_at<=UTC_TIMESTAMP(6)
             ORDER BY o.priority,o.essential DESC,o.available_at,o.created_at
             LIMIT 1 FOR UPDATE"
        )->fetch();
        if (!$row) {
            $pdo->commit();
            return null;
        }
        $update = $pdo->prepare(
            "UPDATE communication_outbox
             SET state='Processing',locked_at=UTC_TIMESTAMP(6),attempts=attempts+1
             WHERE message_id=:message_id AND state IN ('Pending','Deferred')"
        );
        $update->execute(['message_id' => $row['message_id']]);
        $pdo->commit();
        return $update->rowCount() === 1 ? $row : null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function communication_worker_process_one(PDO $pdo): array
{
    $message = communication_claim_next($pdo);
    if ($message === null) return ['status' => 'idle'];

    $messageId = (string)$message['message_id'];
    try {
        if ($message['template_key'] === 'we_want_to_help') {
            $returned = $pdo->prepare(
                'SELECT 1 FROM installations
                 WHERE customer_id=:customer_id AND portal_deactivated_at IS NULL
                   AND last_seen_at>:queued_at LIMIT 1'
            );
            $returned->execute([
                'customer_id' => $message['customer_id'],
                'queued_at' => $message['created_at'],
            ]);
            if ($returned->fetchColumn()) {
                communication_finish_message(
                    $pdo,
                    $messageId,
                    'Cancelled',
                    'CUSTOMER_RETURNED',
                    'Customer activity resumed after the inactivity message was queued.'
                );
                return ['status' => 'cancelled', 'message_id' => $messageId];
            }
        }
        $policy = communication_policy_decision(
            (string)$message['message_class'],
            communication_latest_consent($pdo, (string)$message['customer_id'], 'Marketing'),
            communication_active_suppressions($pdo, (string)$message['customer_id'])
        );
        $email = crm_normalize_email((string)$message['canonical_email']);
        $hashMatches = hash_equals((string)$message['recipient_hash'], hash('sha256', $email, true));
        $verificationException = $message['template_key'] === 'email_verification' &&
            communication_has_pending_email_verification($pdo, (string)$message['customer_id'], $email);
        if (!$policy['allowed'] || $email === '' ||
            (empty($message['email_verified_at']) && !$verificationException) ||
            $message['customer_status'] !== 'Active' || !$hashMatches) {
            communication_finish_message($pdo, $messageId, 'Cancelled', 'POLICY_BLOCKED', (string)$policy['reason']);
            return ['status' => 'cancelled', 'message_id' => $messageId];
        }

        if (communication_setting($pdo, 'emergency_stop', '1') === '1') {
            communication_defer_message($pdo, $messageId, 'EMERGENCY_STOP', 'Communications are paused.', 900);
            return ['status' => 'deferred', 'message_id' => $messageId];
        }
        $previewWarnings = json_decode((string)($message['preview_warnings_json'] ?? ''), true);
        $previewValid = (int)($message['preview_brevo_template_id'] ?? 0) === (int)($message['brevo_template_id'] ?? 0)
            && !empty($message['preview_verified_at'])
            && is_array($previewWarnings)
            && count($previewWarnings) === 0;
        if (!$previewValid) {
            communication_defer_message(
                $pdo,
                $messageId,
                'TEMPLATE_PREVIEW_REQUIRED',
                'The mapped template must pass Admin preview validation before delivery.',
                900
            );
            return ['status' => 'deferred', 'message_id' => $messageId];
        }
        if ($message['message_class'] === 'Marketing' && communication_setting($pdo, 'marketing_pause', '1') === '1') {
            communication_defer_message($pdo, $messageId, 'MARKETING_PAUSED', 'Optional marketing delivery is paused.', 900);
            return ['status' => 'deferred', 'message_id' => $messageId];
        }
        $config = communication_config();
        if (!(bool)$config['enabled'] || $config['mode'] === 'disabled' || trim((string)$config['brevo_api_key']) === '') {
            communication_defer_message($pdo, $messageId, 'PROVIDER_DISABLED', 'Provider delivery is not configured.', 900);
            return ['status' => 'deferred', 'message_id' => $messageId];
        }
        if ($config['mode'] === 'test' && !in_array($email, array_map('crm_normalize_email', (array)$config['test_allowlist']), true)) {
            communication_finish_message($pdo, $messageId, 'Cancelled', 'TEST_ALLOWLIST', 'Recipient is not on the test allowlist.');
            return ['status' => 'cancelled', 'message_id' => $messageId];
        }
        $quietHoursDelay = communication_quiet_hours_delay($config, (bool)$message['essential']);
        if ($quietHoursDelay > 0) {
            communication_defer_message($pdo, $messageId, 'QUIET_HOURS', 'Deferred until customer-friendly delivery hours.', $quietHoursDelay);
            return ['status' => 'deferred', 'message_id' => $messageId];
        }

        $quota = communication_reserve_quota($pdo, $message);
        if (!$quota['allowed']) {
            $reset = new DateTimeImmutable(communication_next_quota_reset(), new DateTimeZone('UTC'));
            communication_defer_message($pdo, $messageId, strtoupper((string)$quota['reason']), 'Deferred until the daily provider quota resets.', max(60, $reset->getTimestamp() - time()));
            return ['status' => 'deferred', 'message_id' => $messageId];
        }

        $sender = communication_template_sender(
            $pdo,
            (string)$message['template_key'],
            (string)$message['message_class'],
            $config
        );
        $result = communication_send_brevo($message, $email, $config, $sender);
        if ($result['status'] === 'sent') {
            $statement = $pdo->prepare(
                "UPDATE communication_outbox SET state='Sent',provider_message_id=:provider_id,sent_at=UTC_TIMESTAMP(6),
                 locked_at=NULL,last_error_code=NULL,last_error_detail=NULL WHERE message_id=:message_id"
            );
            $statement->execute(['provider_id' => $result['provider_message_id'], 'message_id' => $messageId]);
        } elseif ($result['status'] === 'unknown') {
            communication_finish_message($pdo, $messageId, 'DeliveryUnknown', 'DELIVERY_UNKNOWN', (string)$result['detail']);
        } elseif ($result['retryable']) {
            $completedAttempts = (int)$message['attempts'] + 1;
            if ($completedAttempts >= 8) {
                communication_finish_message($pdo, $messageId, 'Failed', 'DEAD_LETTER', (string)$result['detail']);
            } else {
                $delay = min(21600, 60 * (2 ** min(8, max(0, $completedAttempts - 1)))) + random_int(0, 60);
                communication_defer_message($pdo, $messageId, (string)$result['code'], (string)$result['detail'], $delay);
            }
        } else {
            communication_finish_message($pdo, $messageId, 'Failed', (string)$result['code'], (string)$result['detail']);
        }
        return ['status' => $result['status'], 'message_id' => $messageId];
    } catch (Throwable $exception) {
        communication_finish_message($pdo, $messageId, 'DeliveryUnknown', 'WORKER_EXCEPTION', 'Delivery outcome requires review.');
        error_log('Communication worker exception for message ' . $messageId . ': ' . get_class($exception));
        return ['status' => 'unknown', 'message_id' => $messageId];
    }
}

function communication_send_brevo(
    array $message,
    string $email,
    array $config,
    array $sender
): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 'failed', 'retryable' => false, 'code' => 'CURL_UNAVAILABLE', 'detail' => 'The secure HTTP client is unavailable.'];
    }
    $parameters = json_decode((string)$message['parameters_json'], true, 20, JSON_THROW_ON_ERROR);
    $payload = [
        'sender' => ['email' => (string)$sender['email'], 'name' => (string)$sender['name']],
        'to' => [['email' => $email]],
        'templateId' => (int)$message['brevo_template_id'],
        'params' => communication_validate_parameters(is_array($parameters) ? $parameters : []),
        'tags' => ['ppe', strtolower((string)$message['message_class'])],
        'headers' => ['X-PPE-Message-ID' => (string)$message['message_id']],
    ];
    if ((bool)($config['inbox_monitored'] ?? false) && trim((string)$config['reply_to_email']) !== '') {
        $payload['replyTo'] = ['email' => $config['reply_to_email'], 'name' => $config['reply_to_name']];
    }
    $ch = curl_init(rtrim((string)$config['brevo_api_base'], '/') . '/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . (string)$config['brevo_api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['status' => 'unknown', 'retryable' => false, 'code' => 'NETWORK_UNKNOWN', 'detail' => 'The provider connection ended before delivery was confirmed.'];
    }
    $decoded = json_decode((string)$body, true);
    if ($httpStatus === 201 && is_array($decoded) && is_string($decoded['messageId'] ?? null)) {
        return ['status' => 'sent', 'retryable' => false, 'code' => 'SENT', 'detail' => '', 'provider_message_id' => $decoded['messageId']];
    }
    $detail = is_array($decoded) ? (string)($decoded['message'] ?? 'Provider rejected the request.') : 'Provider rejected the request.';
    return [
        'status' => 'failed',
        'retryable' => $httpStatus === 429 || $httpStatus >= 500,
        'code' => 'BREVO_HTTP_' . $httpStatus,
        'detail' => communication_sanitize_provider_error($detail),
    ];
}

function communication_defer_message(PDO $pdo, string $messageId, string $code, string $detail, int $delaySeconds): void
{
    $available = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . max(60, $delaySeconds) . ' seconds');
    $statement = $pdo->prepare(
        "UPDATE communication_outbox SET state='Deferred',available_at=:available_at,locked_at=NULL,
         last_error_code=:code,last_error_detail=:detail WHERE message_id=:message_id"
    );
    $statement->execute([
        'available_at' => $available->format('Y-m-d H:i:s.u'),
        'code' => crm_text_slice($code, 0, 64),
        'detail' => communication_sanitize_provider_error($detail),
        'message_id' => $messageId,
    ]);
}

function communication_finish_message(PDO $pdo, string $messageId, string $state, string $code, string $detail): void
{
    if (!in_array($state, ['Failed', 'DeliveryUnknown', 'Cancelled'], true)) {
        throw new InvalidArgumentException('The final communication state is invalid.');
    }
    $statement = $pdo->prepare(
        "UPDATE communication_outbox SET state=:state,locked_at=NULL,last_error_code=:code,last_error_detail=:detail
         WHERE message_id=:message_id"
    );
    $statement->execute([
        'state' => $state, 'code' => crm_text_slice($code, 0, 64),
        'detail' => communication_sanitize_provider_error($detail), 'message_id' => $messageId,
    ]);
}

function communication_process_webhook(PDO $pdo, array $event): array
{
    ensure_communication_schema($pdo);
    $providerMessageId = trim((string)($event['message-id'] ?? $event['messageId'] ?? ''));
    $eventType = strtolower(trim((string)($event['event'] ?? 'unknown')));
    $eventId = trim((string)($event['id'] ?? $event['event_id'] ?? ''));
    $occurred = (int)($event['ts_event'] ?? $event['ts'] ?? time());
    if (!in_array($eventType, COMMUNICATION_PROVIDER_EVENTS, true)) {
        throw new InvalidArgumentException('The provider event type is not supported.');
    }
    if ($occurred < time() - 7_776_000 || $occurred > time() + 300) {
        throw new InvalidArgumentException('The provider event timestamp is outside the accepted window.');
    }
    $eventKeySource = $eventId !== '' ? $eventId : implode('|', [$providerMessageId, $eventType, (string)$occurred]);
    $messageId = trim((string)($event['tags']['ppe_message_id'] ?? $event['ppe_message_id'] ?? ''));
    if ($messageId === '' && $providerMessageId !== '') {
        $find = $pdo->prepare('SELECT message_id FROM communication_outbox WHERE provider_message_id=:provider_id LIMIT 1');
        $find->execute(['provider_id' => $providerMessageId]);
        $found = $find->fetchColumn();
        $messageId = is_string($found) ? $found : '';
    }
    if ($messageId !== '' && !preg_match('/^[0-9a-f-]{36}$/', $messageId)) $messageId = '';

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO communication_delivery_events
            (provider_event_key,message_id,provider_message_id,event_type,event_summary,occurred_at)
         VALUES(UNHEX(SHA2(:event_key,256)),:message_id,:provider_message_id,:event_type,:summary,:occurred_at)'
    );
    $insert->execute([
        'event_key' => $eventKeySource,
        'message_id' => $messageId !== '' ? $messageId : null,
        'provider_message_id' => $providerMessageId !== '' ? crm_text_slice($providerMessageId, 0, 160) : null,
        'event_type' => crm_text_slice($eventType, 0, 40),
        'summary' => 'Brevo delivery event: ' . crm_text_slice($eventType, 0, 40),
        'occurred_at' => gmdate('Y-m-d H:i:s', max(0, $occurred)),
    ]);
    if ($insert->rowCount() === 0) return ['status' => 'duplicate'];

    if ($messageId !== '' && in_array($eventType, ['hardbounce', 'hard_bounce', 'spam', 'complaint', 'unsubscribed'], true)) {
        $reason = match ($eventType) {
            'hardbounce', 'hard_bounce' => 'Bounced',
            'spam', 'complaint' => 'Complaint',
            default => 'Unsubscribed',
        };
        $message = $pdo->prepare('SELECT customer_id,recipient_hash FROM communication_outbox WHERE message_id=:id LIMIT 1');
        $message->execute(['id' => $messageId]);
        $row = $message->fetch();
        if ($row) {
            $suppress = $pdo->prepare(
                'INSERT INTO customer_email_suppressions(customer_id,email_hash,reason,source,actor)
                 SELECT :customer_id,:email_hash,:reason,:source,:actor
                 WHERE NOT EXISTS (
                    SELECT 1 FROM customer_email_suppressions
                    WHERE customer_id=:check_customer AND reason=:check_reason AND active=1
                 )'
            );
            $suppress->execute([
                'customer_id' => $row['customer_id'], 'email_hash' => $row['recipient_hash'],
                'reason' => $reason, 'source' => 'Brevo Webhook', 'actor' => 'communications-worker',
                'check_customer' => $row['customer_id'], 'check_reason' => $reason,
            ]);
        }
    }
    return ['status' => 'accepted'];
}

function communication_dashboard_summary(PDO $pdo): array
{
    ensure_communication_schema($pdo);
    $states = [];
    foreach ($pdo->query('SELECT state,COUNT(*) AS total FROM communication_outbox GROUP BY state')->fetchAll() as $row) {
        $states[(string)$row['state']] = (int)$row['total'];
    }
    $quota = $pdo->query("SELECT * FROM communication_quota_daily WHERE quota_date=UTC_DATE()")->fetch() ?: [
        'provider_used' => 0, 'automated_used' => 0, 'service_used' => 0, 'marketing_used' => 0, 'manual_used' => 0,
    ];
    $config = communication_config();
    return [
        'states' => $states,
        'quota' => $quota,
        'limits' => [
            'provider' => (int)$config['provider_daily_limit'],
            'automated' => (int)$config['automated_daily_limit'],
            'reserve' => (int)$config['service_reserve'],
        ],
        'emergency_stop' => communication_setting($pdo, 'emergency_stop', '1') === '1',
        'marketing_pause' => communication_setting($pdo, 'marketing_pause', '1') === '1',
        'provider_configured' => (bool)$config['enabled'] && trim((string)$config['brevo_api_key']) !== '',
        'mode' => (string)$config['mode'],
    ];
}

function communication_schedule_maintenance(PDO $pdo): array
{
    ensure_communication_schema($pdo);
    $candidates = $pdo->query(
        "SELECT DISTINCT c.customer_id,c.display_name,l.license_tier,l.maintenance_expires_at
         FROM issued_licenses l
         JOIN customers c ON c.customer_id=l.customer_id
         WHERE l.control_state='Enabled' AND l.maintenance_revoked_at IS NULL
           AND c.status='Active' AND c.email_verified_at IS NOT NULL
           AND DATE(l.maintenance_expires_at) IN (
             UTC_DATE(),DATE_ADD(UTC_DATE(),INTERVAL 7 DAY),DATE_ADD(UTC_DATE(),INTERVAL 14 DAY),
             DATE_ADD(UTC_DATE(),INTERVAL 30 DAY),DATE_ADD(UTC_DATE(),INTERVAL 60 DAY),
             DATE_SUB(UTC_DATE(),INTERVAL 7 DAY),DATE_SUB(UTC_DATE(),INTERVAL 30 DAY)
           )
         ORDER BY l.maintenance_expires_at LIMIT 500"
    )->fetchAll();
    $queued = 0;
    $blocked = 0;
    foreach ($candidates as $candidate) {
        $expiration = new DateTimeImmutable((string)$candidate['maintenance_expires_at'], new DateTimeZone('UTC'));
        $days = (int)(new DateTimeImmutable('today', new DateTimeZone('UTC')))->diff($expiration)->format('%r%a');
        try {
            communication_enqueue(
                $pdo,
                (string)$candidate['customer_id'],
                'maintenance_reminder',
                [
                    'customer_name' => (string)$candidate['display_name'],
                    'license_tier' => (string)$candidate['license_tier'],
                    'maintenance_end' => $expiration->format('F j, Y'),
                    'renewal_url' => 'https://userportal.posprinteremulator.com/',
                ],
                'maintenance:' . $candidate['customer_id'] . ':' . $expiration->format('Y-m-d') . ':' . $days
            );
            $queued++;
        } catch (DomainException|InvalidArgumentException) {
            $blocked++;
        }
    }
    return ['queued' => $queued, 'blocked' => $blocked];
}

function communication_schedule_lifecycle(PDO $pdo): array
{
    ensure_communication_schema($pdo);
    $results = ['maintenance' => communication_schedule_maintenance($pdo)];

    $trial = $pdo->query(
        "SELECT c.customer_id,c.display_name,MIN(i.first_seen_at) AS first_seen_at
         FROM customers c JOIN installations i ON i.customer_id=c.customer_id
         WHERE c.status='Active' AND c.email_verified_at IS NOT NULL
           AND i.license_mode='Trial' AND i.portal_deactivated_at IS NULL
         GROUP BY c.customer_id,c.display_name
         HAVING DATE(MIN(i.first_seen_at))=DATE_SUB(UTC_DATE(),INTERVAL 1 DAY)
         ORDER BY MIN(i.first_seen_at) LIMIT 500"
    )->fetchAll();
    $results['welcome_trial'] = communication_schedule_candidates(
        $pdo,
        $trial,
        'welcome_trial_start',
        static fn(array $row): array => [
            'customer_name' => (string)$row['display_name'],
            'license_tier' => 'Trial',
            'event_label' => 'Starting a Trial',
            'feature_summary' => communication_tier_features('Trial'),
            'setup_url' => communication_setup_url('Trial'),
            'contact_support_url' => 'https://userportal.posprinteremulator.com/',
        ],
        static fn(array $row): string => 'welcome-trial:' . $row['customer_id'] . ':' . substr((string)$row['first_seen_at'], 0, 10)
    );

    $followupDays = array_values(array_filter(array_map(
        static fn(string $day): int => max(0, min(365, (int)trim($day))),
        explode(',', (string)communication_setting($pdo, 'inactivity_followup_days', ''))
    ), static fn(int $day): bool => $day > 31));
    $inactiveDays = array_values(array_unique(array_merge([31], $followupDays)));
    $inactiveDates = implode(',', array_map(
        static fn(int $days): string => 'DATE_SUB(UTC_DATE(),INTERVAL ' . $days . ' DAY)',
        $inactiveDays
    ));
    $inactive = $pdo->query(
        "SELECT c.customer_id,c.display_name,MAX(i.last_seen_at) AS last_seen_at,
                COALESCE(
                  (SELECT l.license_tier FROM issued_licenses l
                   WHERE l.customer_id=c.customer_id AND l.control_state='Enabled'
                   ORDER BY FIELD(l.license_tier,'Enterprise','Pro','Lite','Trial'),l.issued_at DESC LIMIT 1),
                  'Trial'
                ) AS license_tier
         FROM customers c JOIN installations i ON i.customer_id=c.customer_id
         WHERE c.status='Active' AND c.email_verified_at IS NOT NULL
           AND i.portal_deactivated_at IS NULL
         GROUP BY c.customer_id,c.display_name
         HAVING DATE(MAX(i.last_seen_at)) IN (" . $inactiveDates . ")
         ORDER BY MAX(i.last_seen_at) LIMIT 500"
    )->fetchAll();
    $results['inactivity'] = communication_schedule_candidates(
        $pdo,
        $inactive,
        'we_want_to_help',
        static fn(array $row): array => [
            'customer_name' => (string)$row['display_name'],
            'license_tier' => (string)$row['license_tier'],
            'feature_summary' => communication_tier_features((string)$row['license_tier']),
            'setup_url' => communication_setup_url((string)$row['license_tier']),
            'troubleshooting_url' => 'https://www.posprinteremulator.com/esc-pos-troubleshooting',
            'contact_support_url' => 'https://userportal.posprinteremulator.com/',
        ],
        static fn(array $row): string => 'we-want-to-help:' . $row['customer_id'] . ':' . substr((string)$row['last_seen_at'], 0, 10)
    );

    $latestRelease = $pdo->query(
        "SELECT version_label,title FROM development_roadmap
         WHERE item_type='Release' AND status='Released' AND version_label IS NOT NULL
         ORDER BY priority_rank DESC LIMIT 1"
    )->fetch();
    if (is_array($latestRelease)) {
        $latestVersion = ltrim((string)$latestRelease['version_label'], "vV");
        $outdated = $pdo->prepare(
            "SELECT c.customer_id,c.display_name,MAX(i.app_version) AS installed_version
             FROM customers c JOIN installations i ON i.customer_id=c.customer_id
             WHERE c.status='Active' AND c.email_verified_at IS NOT NULL
               AND i.portal_deactivated_at IS NULL AND i.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 DAY)
               AND i.app_version<>:latest_version
             GROUP BY c.customer_id,c.display_name
             ORDER BY MAX(i.last_seen_at) DESC LIMIT 500"
        );
        $outdated->execute(['latest_version' => $latestVersion]);
        $results['release'] = communication_schedule_candidates(
            $pdo,
            $outdated->fetchAll(),
            'release_announcement',
            static fn(array $row): array => [
                'customer_name' => (string)$row['display_name'],
                'installed_version' => (string)$row['installed_version'],
                'latest_version' => (string)$latestRelease['version_label'],
                'release_summary' => (string)$latestRelease['title'],
                'download_url' => 'https://www.posprinteremulator.com/download',
            ],
            static fn(array $row): string => 'release:' . $latestRelease['version_label'] . ':' . $row['customer_id']
        );
    } else {
        $results['release'] = ['queued' => 0, 'blocked' => 0];
    }
    return $results;
}

function communication_schedule_candidates(
    PDO $pdo,
    array $candidates,
    string $templateKey,
    callable $parameters,
    callable $idempotencyKey
): array {
    $queued = 0;
    $blocked = 0;
    foreach ($candidates as $candidate) {
        try {
            communication_enqueue(
                $pdo,
                (string)$candidate['customer_id'],
                $templateKey,
                $parameters($candidate),
                $idempotencyKey($candidate)
            );
            $queued++;
        } catch (DomainException|InvalidArgumentException) {
            $blocked++;
        }
    }
    return ['queued' => $queued, 'blocked' => $blocked];
}
