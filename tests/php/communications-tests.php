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

$communications = file_get_contents($root . '/admin-website/includes/communications.php') ?: '';
$admin = file_get_contents($root . '/admin-website/communications.php') ?: '';
$auth = file_get_contents($root . '/admin-website/includes/auth.php') ?: '';
$bootstrap = file_get_contents($root . '/admin-website/includes/bootstrap.php') ?: '';
$worker = file_get_contents($root . '/admin-website/api/v1/communications-worker.php') ?: '';
$webhook = file_get_contents($root . '/admin-website/api/v1/brevo-webhook.php') ?: '';
$scheduler = file_get_contents($root . '/admin-website/api/v1/communications-scheduler.php') ?: '';
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
$expect(!str_contains($publisher, 'smtp_sasl_password_maps'), 'SMTP credential material must never appear in source.');

if ($failures !== []) {
    fwrite(STDERR, "Communications tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Communications tests passed.\n";
