<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    exit;
}

portal_require_csrf();
$account = portal_current_account();
if (!is_array($account)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.'], JSON_THROW_ON_ERROR);
    exit;
}

echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
