<?php
declare(strict_types=1);

return [
    'app_url' => 'https://buy.posprinteremulator.com',
    'environment' => 'production',
    'license' => [
        'product_name' => 'POS Printer Emulator',
        'lite_price' => '24.99', // The Admin Portal can override this value.
        'pro_price' => '0.00', // The Admin Portal can override this value.
        'enterprise_price' => '0.00', // The Admin Portal can override this value.
        'currency' => 'USD',
    ],
    'paypal' => [
        'client_id' => 'REPLACE_WITH_PAYPAL_CLIENT_ID',
        'secret' => 'REPLACE_WITH_PAYPAL_SECRET',
        'base_url' => 'https://api-m.paypal.com',
    ],
    'admin_api_token' => 'REPLACE_WITH_RANDOM_32_BYTE_TOKEN',
    'mail' => [
        'from_email' => 'licenses@posprinteremulator.com',
        'from_name' => 'POS Printer Emulator',
        'reply_to' => 'support@posprinteremulator.com',
    ],
];
