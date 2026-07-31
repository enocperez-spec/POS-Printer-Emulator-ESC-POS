<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
$allowedOrigins = [
    'https://www.posprinteremulator.com',
    'https://posprinteremulator.com',
];

if ($origin !== '') {
    if (!in_array($origin, $allowedOrigins, true)) {
        json_response(['error' => 'This origin is not allowed.'], 403);
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

enforce_rate_limit('public-pricing', 600, 3600);

json_response([
    'schemaVersion' => 1,
    'generatedAt' => gmdate(DATE_ATOM),
    'licenseOffers' => license_offers(),
    'maintenanceOffers' => maintenance_offers(),
]);
