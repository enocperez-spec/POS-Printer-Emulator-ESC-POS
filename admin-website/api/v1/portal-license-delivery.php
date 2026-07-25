<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'error' => 'Legacy credential delivery has been retired. Sign in to the verified Customer Portal and link the computer; eligible licenses are applied automatically.',
    'code' => 'ACCOUNT_LICENSING_REQUIRED',
], JSON_UNESCAPED_SLASHES);
