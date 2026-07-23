<?php
declare(strict_types=1);

// Copy this file to communications.php outside the public web root and set its
// permissions to 0600. Never commit the real file or paste its values into logs.
return [
    'communications' => [
        'enabled' => false,
        'mode' => 'disabled', // disabled, test, or live
        'brevo_api_base' => 'https://api.brevo.com/v3',
        'brevo_api_key' => '',
        'webhook_token' => '',
        'sender_email' => 'support@posprinteremulator.com',
        'sender_name' => 'POS Printer Emulator',
        'reply_to_email' => 'support@posprinteremulator.com',
        'reply_to_name' => 'POS Printer Emulator Support',
        'provider_daily_limit' => 300,
        'automated_daily_limit' => 290,
        'service_reserve' => 50,
        'timezone' => 'America/New_York',
        'quiet_hours_start' => 20,
        'quiet_hours_end' => 8,
        'test_allowlist' => [],
    ],
];
