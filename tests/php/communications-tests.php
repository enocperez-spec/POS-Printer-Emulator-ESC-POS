<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
function private_config(): array
{
    return [
        'data_protection' => ['activation_key_key' => base64_encode(str_repeat('K', 32))],
        'communications' => [
            'enabled' => false,
            'mode' => 'disabled',
            'brevo_api_key' => '',
            'webhook_token' => '',
        ],
    ];
}
require $root . '/admin-website/includes/communications.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$contains = static function (string $needle, string $haystack, string $message) use ($expect): void {
    $expect(str_contains($haystack, $needle), $message);
};

$decision = communication_policy_decision('Marketing', 'Granted', []);
$expect($decision['allowed'] === true, 'Granted marketing consent should permit an unsuppressed message.');
$expect(communication_policy_decision('Marketing', 'Not Asked', [])['allowed'] === false, 'Marketing must be opt-in.');
$expect(communication_policy_decision('Marketing', 'Granted', ['Unsubscribed'])['allowed'] === false, 'An unsubscribe must block marketing.');
$expect(communication_policy_decision('Service', 'Withdrawn', ['Unsubscribed'])['allowed'] === true, 'A marketing unsubscribe must not block a required service message.');
$expect(communication_policy_decision('Service', 'Granted', ['Complaint'])['allowed'] === false, 'A complaint must suppress service and marketing delivery.');
$expect(communication_policy_decision('Service', 'Granted', ['Bounced'])['allowed'] === false, 'A hard bounce must suppress all delivery.');

$limits = ['provider_daily_limit' => 300, 'automated_daily_limit' => 290, 'service_reserve' => 50];
$expect(communication_quota_decision(['provider_used' => 239, 'automated_used' => 239], 'Marketing', false, false, $limits)['allowed'], 'Marketing should use the non-reserved quota.');
$expect(!communication_quota_decision(['provider_used' => 240, 'automated_used' => 240], 'Marketing', false, false, $limits)['allowed'], 'Marketing must not consume the 50-message service reserve.');
$expect(communication_quota_decision(['provider_used' => 289, 'automated_used' => 289], 'Service', true, false, $limits)['allowed'], 'Essential automated service mail should use the final automated slot.');
$expect(!communication_quota_decision(['provider_used' => 290, 'automated_used' => 290], 'Service', true, false, $limits)['allowed'], 'Automated mail must stop at 290.');
$expect(communication_quota_decision(['provider_used' => 299, 'automated_used' => 290], 'Service', true, true, $limits)['allowed'], 'A reviewed essential manual service send may use the provider slack.');
$expect(!communication_quota_decision(['provider_used' => 300, 'automated_used' => 290], 'Service', true, true, $limits)['allowed'], 'No delivery may exceed the provider quota.');

$usage = ['provider_used' => 0, 'automated_used' => 0];
$sentMarketing = $sentService = $deferred = 0;
for ($index = 0; $index < 300; $index++) {
    $result = communication_quota_decision($usage, 'Marketing', false, false, $limits);
    if (!$result['allowed']) { $deferred++; continue; }
    $usage['provider_used']++; $usage['automated_used']++; $sentMarketing++;
}
for ($index = 0; $index < 50; $index++) {
    $result = communication_quota_decision($usage, 'Service', true, false, $limits);
    if (!$result['allowed']) { $deferred++; continue; }
    $usage['provider_used']++; $usage['automated_used']++; $sentService++;
}
$expect($sentMarketing === 240 && $sentService === 50 && $deferred === 60, 'A 350-message queue must preserve 50 service slots and defer overflow without loss.');
$expect($usage['provider_used'] === 290, 'Automated queue processing must retain 10 provider slots for reviewed service sends.');
$expect(communication_template_priority('email_verification') < communication_template_priority('support_confirmation'), 'Account security mail must precede support confirmations.');
$expect(communication_template_priority('support_confirmation') < communication_template_priority('release_announcement'), 'Required support mail must precede optional release announcements.');
$expect(communication_template_priority('release_announcement') < communication_template_priority('promotion'), 'Educational lifecycle mail must precede promotions.');

$clean = communication_validate_parameters(['customer_name' => 'Example Customer', 'portal_url' => 'https://userportal.posprinteremulator.com/']);
$expect($clean['customer_name'] === 'Example Customer', 'Approved plain-text parameters changed unexpectedly.');
foreach ([
    ['receipt_data' => 'secret'],
    ['customer_name' => 'Activation key PPE1-DO-NOT-SEND'],
    ['support_reference' => 'password=secret'],
    ['release_summary' => 'Connect to 192.168.1.25:9100'],
    ['support_reference' => 'customer@example.com'],
    ['support_url' => 'https://evil.example/collect'],
] as $unsafe) {
    try {
        communication_validate_parameters($unsafe);
        $expect(false, 'Sensitive or unapproved message data must fail closed.');
    } catch (InvalidArgumentException) {
    }
}

$quietConfig = ['timezone' => 'America/New_York', 'quiet_hours_start' => 20, 'quiet_hours_end' => 8];
$atNight = new DateTimeImmutable('2026-07-23 02:00:00', new DateTimeZone('America/New_York'));
$atNoon = new DateTimeImmutable('2026-07-23 12:00:00', new DateTimeZone('America/New_York'));
$expect(communication_quiet_hours_delay($quietConfig, false, $atNight) > 0, 'Nonessential delivery must defer during quiet hours.');
$expect(communication_quiet_hours_delay($quietConfig, false, $atNoon) === 0, 'Daytime delivery should not be delayed.');
$expect(communication_quiet_hours_delay($quietConfig, true, $atNight) === 0, 'Essential service delivery may bypass quiet hours.');
$compliantTemplate = '<p>Need help? Our guides are online.</p><a href="{{ params.documentation_url }}">View Documentation</a><p>Please do not reply to this email. This inbox is not monitored.</p>';
$expect(
    communication_template_language_warnings('Account update', $compliantTemplate, false) === [],
    'The required no-reply notice and global documentation link should pass template policy.'
);
$expect(
    communication_template_language_warnings('Account update', '<p>Reply to this email for help.</p>', false) !== [],
    'Reply-oriented language must block template approval.'
);

$communications = file_get_contents($root . '/admin-website/includes/communications.php') ?: '';
$admin = file_get_contents($root . '/admin-website/communications.php') ?: '';
$auth = file_get_contents($root . '/admin-website/includes/auth.php') ?: '';
$bootstrap = file_get_contents($root . '/admin-website/includes/bootstrap.php') ?: '';
$worker = file_get_contents($root . '/admin-website/api/v1/communications-worker.php') ?: '';
$webhook = file_get_contents($root . '/admin-website/api/v1/brevo-webhook.php') ?: '';
$scheduler = file_get_contents($root . '/admin-website/api/v1/communications-scheduler.php') ?: '';
$preview = file_get_contents($root . '/admin-website/api/v1/template-preview.php') ?: '';
$communicationsJs = file_get_contents($root . '/admin-website/assets/communications.js') ?: '';
$portalMailer = file_get_contents($root . '/customer-portal/includes/mailer.php') ?: '';
$schema = file_get_contents($root . '/database/schema.sql') ?: '';
$telemetry = file_get_contents($root . '/website/api/v1/telemetry.php') ?: '';
$privacy = file_get_contents($root . '/website/privacy.html') ?: '';
$publisher = file_get_contents($root . '/tools/POSPrinterEmulator.WebsitePublisher/Program.cs') ?: '';

$contains("CURLOPT_PROTOCOLS => CURLPROTO_HTTPS", $communications, 'Brevo delivery must be restricted to HTTPS.');
$contains("'api-key: ' . (string)\$config['brevo_api_key']", $communications, 'Brevo REST delivery must authenticate outside the payload.');
$contains('DeliveryUnknown', $communications, 'Unknown network outcomes must not be retried blindly.');
$contains('uq_communication_idempotency', $communications, 'The durable outbox requires database-enforced idempotency.');
$contains('communication_policy_decision', $communications, 'Consent and suppression must be checked at delivery time.');
$contains('communication_reserve_quota', $communications, 'Quota must be reserved atomically before provider delivery.');
$contains("'hardbounce', 'hard_bounce'", $communications, 'Brevo hard-bounce events must create a global delivery suppression.');
$contains('emergency_stop', $admin, 'The Admin Portal needs an emergency delivery stop.');
$contains("require_admin_capability('communications.manage')", $admin, 'Communication mutations need a dedicated capability.');
$contains("require_admin_capability('communications.export')", $admin, 'Communication exports need a dedicated capability.');
$contains("'communications.manage'", $auth, 'Admin roles are missing communication capabilities.');
$contains('private/communications.php', $bootstrap, 'Protected communications configuration is not loaded outside public files.');
$contains('communication_service_authorized()', $worker, 'The worker endpoint must require protected service authentication.');
$contains('communication_webhook_authorized()', $webhook, 'The Brevo webhook must require its own protected bearer token.');
$contains('communication_schedule_lifecycle', $scheduler, 'The authenticated scheduler must run every reviewed lifecycle schedule.');
$contains('communication_enqueue(', $communications, 'Lifecycle schedules must use the same policy-aware queue.');
$contains('portal_try_communication_outbox', $portalMailer, 'Customer Portal security messages must use the new outbox when approved templates are enabled.');
$contains('portal_mail_uuid', $portalMailer, 'Customer Portal mail intents need an entry-point-independent UUID generator.');
$contains('portal_mail_global_parameters', $portalMailer, 'Customer Portal security messages must receive the centrally managed help links and no-reply notice.');
$contains('portal_mail_support_footer', $portalMailer, 'The PHP mail fallback must direct customers to documentation and the support-request process.');
$expect(!str_contains($portalMailer, 'Reply-To:'), 'The Customer Portal must not add a reply header for an unmonitored inbox.');
$contains("'email_verification'", $portalMailer, 'Customer Portal enrollment must map to the approved verification template.');
$contains("'password_recovery'", $portalMailer, 'Customer Portal recovery must map to the approved password template.');
$contains('communication_outbox', $schema, 'Fresh databases are missing the durable communications outbox.');
$contains('customer_lifecycle_presence', $schema, 'Fresh databases are missing privacy-minimized unique lifecycle aggregation.');
$contains("'Product Analytics'", $telemetry, 'Lifecycle analytics must require the recorded Product Analytics decision.');
$contains("!== 'Granted'", $telemetry, 'Lifecycle analytics must fail closed when opt-in is absent.');
$contains('does not send receipt content', $privacy, 'The privacy notice must disclose prohibited communication data.');
$contains('configure-communications', $publisher, 'The C# publisher must configure provider secrets without PowerShell.');
$contains('require_recent_admin_authentication', $admin, 'Communication mutations and exports must require recent administrator authentication.');
$contains('Opened · approximate', $admin, 'Open and click analytics must be clearly labeled as approximate.');
$contains('communication_template_tags', $communications, 'Approved templates need normalized multi-tag storage.');
$contains('data-template-tag-filter', $admin, 'The template registry is missing tag filters.');
$contains('data-template-status-filter', $admin, 'The template registry is missing enabled and disabled filters.');
$contains('data-template-tag-input', $admin, 'Authorized users need editable multi-tag controls.');
$contains('/smtp/templates/', $preview, 'Template previews must load the mapped provider source and metadata.');
$contains('communication_service_authorized', $preview, 'Protected service diagnostics must be able to validate template previews without an Admin browser session.');
$contains('set_exception_handler', $preview, 'Template preview failures must always return a structured JSON error.');
$contains('mapped_template_id', $preview, 'Native prepared statements must use distinct names for repeated template-ID values.');
$contains("'update_profile'", $preview, 'Preview samples must safely resolve Brevo profile-management placeholders.');
$contains('$templateSource ?? $html', $communications, 'Preview validation must inspect placeholders before sample substitution.');
$contains('preview_brevo_template_id', $communications, 'Template activation requires a preview tied to the exact provider mapping.');
$contains('preview_warnings_json', $admin, 'Template approval must reject previews with unresolved warnings.');
$contains('Missing sample data for placeholder', $preview, 'Preview validation must report unresolved placeholders.');
$contains('Invalid or unapproved link', $preview, 'Preview validation must report invalid links.');
$contains("'we_want_to_help'", $communications, 'The 30-day inactivity workflow needs its own approved template.');
$contains('CUSTOMER_RETURNED', $communications, 'Inactive-user mail must stop when customer activity resumes.');
$contains('communication_onboarding_template', $communications, 'License events need deterministic tier-aware template routing.');
$contains('welcome_enterprise_upgrade', $communications, 'The onboarding registry is missing an Enterprise upgrade variation.');
$contains('Validating sender, placeholders, links, and responsive content', $communicationsJs, 'The iframe loading state must explain preview validation.');
$contains('response.text()', $communicationsJs, 'Preview refresh must inspect the response before parsing JSON.');
$contains('empty response', $communicationsJs, 'Preview refresh must explain empty server responses.');
$contains('data-preview-viewport', $admin, 'The preview dialog needs desktop and mobile layout controls.');
$contains('template-preview-refresh', $admin, 'Administrators need an explicit preview refresh control.');
$contains('communication_template_trigger_flow', $admin, 'Every registry template must expose its trigger flow.');
$contains('data-template-trigger', $admin, 'Template cards must carry their trigger flow into hover and dialog views.');
$contains('template-dialog-trigger', $communicationsJs, 'The template editor must render the selected trigger flow.');
$contains('communication_tags', $communications, 'The registry needs an editable color-tag catalog.');
$contains('tag_delete', $admin, 'Authorized administrators need tag removal controls.');
$contains('communication_global_parameters', $communications, 'Every message must receive centrally managed documentation and support URLs.');
$contains('COMMUNICATION_FORBIDDEN_REPLY_LANGUAGE', $communications, 'Future templates need a permanent reply-language policy.');
$contains("'inbox_monitored'", $communications, 'Reply-to headers must depend on an explicit monitored-inbox setting.');
$contains("'support_request_url'", $schema, 'Fresh databases need the global support-request URL.');
$contains('activeTags', $communicationsJs, 'The registry needs combined tag filtering.');
$contains('statusFilter', $communicationsJs, 'The registry needs combined status filtering.');
$expect(!str_contains($publisher, 'smtp_sasl_password_maps'), 'SMTP credential material must never appear in source.');

if ($failures !== []) {
    fwrite(STDERR, "Communications tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Communications tests passed.\n";
