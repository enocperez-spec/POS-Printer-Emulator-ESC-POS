<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'database_name',
        'username' => 'database_user',
        'password' => 'replace-on-server',
    ],
    'portal' => [
        'base_url' => 'https://userportal.posprinteremulator.com',
        'encryption_key' => 'base64-encoded-32-byte-key',
        'mail_transport' => 'outbox',
        'mail_from' => 'support@posprinteremulator.com',
        'support_url' => 'https://www.posprinteremulator.com/how-to-submit-a-support-request',
        'support_backend_url' => 'https://admin.posprinteremulator.com/api/v1/portal-support.php',
        'support_backend_token' => 'replace-on-server',
        'communications_worker_url' => 'https://admin.posprinteremulator.com/api/v1/communications-worker.php?max=5',
        'promotion_backend_url' => 'https://admin.posprinteremulator.com/api/v1/portal-promotion.php',
        'buy_base_url' => 'https://buy.posprinteremulator.com',
    ],
];
