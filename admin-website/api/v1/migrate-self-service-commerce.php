<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function commerce_migration_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    commerce_migration_response(['error' => 'Not found.'], 404);
}

$authorization = crm_authorization_header();
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
    commerce_migration_response(['error' => 'Authentication required.'], 401);
}
$expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) ||
    !hash_equals($expectedHash, hash('sha256', $match[1]))) {
    usleep(250000);
    commerce_migration_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    ensure_self_service_commerce_schema($pdo);
    $migration = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (:migration_key)');
    $migration->execute(['migration_key' => 'self-service-commerce-v0.3.44']);
    $status = $pdo->prepare(
        "UPDATE development_roadmap SET status='In progress',completed_at=NULL WHERE item_key='v0.3.44'"
    );
    $status->execute();
    commerce_migration_response([
        'ok' => true,
        'migration' => 'self-service-commerce-v0.3.44',
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator self-service commerce migration failed: ' . get_class($exception));
    commerce_migration_response(['error' => 'The self-service commerce migration could not be completed.'], 500);
}
