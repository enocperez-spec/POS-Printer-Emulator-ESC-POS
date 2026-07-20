<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
header('Cache-Control: no-store');
require_admin_api_token();
enforce_rate_limit('admin-price', 120, 3600);
if ($_SERVER['REQUEST_METHOD'] === 'GET') json_response(['offers'=>license_offers(),'maintenanceOffers'=>maintenance_offers()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
try {
    $input = request_json();
    $product = clean_purchase_product((string)($input['product'] ?? 'license'));
    $offer = $product === 'maintenance'
        ? update_maintenance_offer((string)($input['tier'] ?? ''), (string)($input['price'] ?? ''), (string)($input['currency'] ?? 'USD'))
        : update_license_offer((string)($input['tier'] ?? ''), (string)($input['price'] ?? ''), (string)($input['currency'] ?? 'USD'));
    json_response(['offer'=>$offer, 'product'=>$product, 'offers'=>license_offers(), 'maintenanceOffers'=>maintenance_offers()]);
} catch (InvalidArgumentException $exception) {
    json_response(['error'=>$exception->getMessage()],422);
}
