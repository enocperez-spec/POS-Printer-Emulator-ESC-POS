<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'error' => 'Legacy license synchronization is retired. Purchases are fulfilled directly as account entitlements.',
    'code' => 'ACCOUNT_ENTITLEMENTS_REQUIRED',
], JSON_UNESCAPED_SLASHES);
