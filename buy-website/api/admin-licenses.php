<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Cache-Control: no-store');
require_admin_api_token();
enforce_rate_limit('admin-licenses', 240, 3600);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$cursorValue = (string)($_GET['cursor'] ?? '0');
if (!preg_match('/^\d{1,18}$/', $cursorValue)) {
    json_response(['error' => 'Invalid license cursor.'], 422);
}
$cursor = max(0, (int)$cursorValue);
$query = db()->prepare(
    "SELECT id,
            license_id,
            customer_name,
            email AS email_address,
            license_tier,
            activation_key,
            public_id AS order_reference,
            COALESCE(approved_at, created_at) AS issued_at
     FROM orders
     WHERE id > ?
       AND activation_key IS NOT NULL
       AND license_id IS NOT NULL
       AND status = 'EMAILED'
     ORDER BY id ASC
     LIMIT 201"
);
$query->execute([$cursor]);
$licenses = $query->fetchAll();
$hasMore = count($licenses) > 200;
if ($hasMore) {
    array_pop($licenses);
}
$nextCursor = $hasMore && $licenses ? (int)$licenses[array_key_last($licenses)]['id'] : null;
foreach ($licenses as &$license) {
    unset($license['id']);
}
unset($license);

json_response(['licenses' => $licenses, 'nextCursor' => $nextCursor]);
