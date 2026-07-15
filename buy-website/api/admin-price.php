<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
header('Cache-Control: no-store');
require_admin_api_token();
enforce_rate_limit('admin-price', 120, 3600);
if ($_SERVER['REQUEST_METHOD'] === 'GET') json_response(['offer'=>license_offer()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
try {
    $input = request_json();
    $offer = update_license_offer((string)($input['price'] ?? ''), (string)($input['currency'] ?? 'USD'));
    json_response(['offer'=>$offer]);
} catch (InvalidArgumentException $exception) {
    json_response(['error'=>$exception->getMessage()],422);
}
