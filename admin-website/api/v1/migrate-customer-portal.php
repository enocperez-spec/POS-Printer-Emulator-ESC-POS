<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_portal_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function portal_migration_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portal_migration_response(['error' => 'Not found.'], 404);
}

$authorization = crm_authorization_header();
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
    portal_migration_response(['error' => 'Authentication required.'], 401);
}
$expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) ||
    !hash_equals($expectedHash, hash('sha256', $match[1]))) {
    usleep(250000);
    portal_migration_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    ensure_customer_portal_schema($pdo);
    $migration = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (:migration_key)');
    $migration->execute(['migration_key' => 'secure-customer-portal-v0.3.43']);
    $status = $pdo->prepare(
        "UPDATE development_roadmap SET status='In progress',completed_at=NULL WHERE item_key='v0.3.43'"
    );
    $status->execute();
    $diagnosticPath = dirname(__DIR__, 2) . '/private/customer-portal-migration-error.log';
    if (is_file($diagnosticPath)) {
        @unlink($diagnosticPath);
    }
    portal_migration_response([
        'ok' => true,
        'migration' => 'secure-customer-portal-v0.3.43',
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator Customer Portal migration failed: ' . get_class($exception));
    $diagnosticPath = dirname(__DIR__, 2) . '/private/customer-portal-migration-error.log';
    @file_put_contents(
        $diagnosticPath,
        gmdate(DATE_ATOM) . "\n" . get_class($exception) . ': ' . $exception->getMessage(),
        LOCK_EX
    );
    @chmod($diagnosticPath, 0600);
    portal_migration_response(['error' => 'The Customer Portal migration could not be completed.'], 500);
}
