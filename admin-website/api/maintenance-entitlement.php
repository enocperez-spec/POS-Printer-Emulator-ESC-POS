<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'error' => 'Standalone maintenance credentials are retired. Linked computers synchronize license and coverage through the account entitlement service.',
    'code' => 'ACCOUNT_ENTITLEMENTS_REQUIRED',
], JSON_UNESCAPED_SLASHES);
