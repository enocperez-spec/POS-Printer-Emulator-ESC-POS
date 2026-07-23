<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function crm_migration_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crm_migration_response(['error' => 'Not found.'], 404);
}

$authorization = crm_authorization_header();
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
    crm_migration_response(['error' => 'Authentication required.'], 401);
}
$expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) ||
    !hash_equals($expectedHash, hash('sha256', $match[1]))) {
    usleep(250000);
    crm_migration_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    $counts = backfill_customer_crm($pdo);
    $migration = $pdo->prepare('INSERT IGNORE INTO development_migrations (migration_key) VALUES (:migration_key)');
    $migration->execute(['migration_key' => 'customer-identity-consent-crm-v0.3.42']);
    $release = $pdo->prepare(
        "UPDATE development_roadmap
         SET status = 'Released',
             completed_at = COALESCE(completed_at, UTC_TIMESTAMP(6))
         WHERE item_key = 'v0.3.42'"
    );
    $release->execute();
    $diagnosticPath = dirname(__DIR__, 2) . '/private/crm-migration-error.log';
    if (is_file($diagnosticPath)) @unlink($diagnosticPath);
    crm_migration_response([
        'ok' => true,
        'migration' => 'customer-identity-consent-crm-v0.3.42',
        'linked' => [
            'customers' => (int)$counts['customers'],
            'licenses' => (int)$counts['licenses'],
            'installations' => (int)$counts['installations'],
            'supportRequests' => (int)$counts['support'],
            'protectedKeys' => (int)$counts['fingerprints'],
        ],
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator CRM migration failed: ' . $exception->getMessage());
    $diagnosticPath = dirname(__DIR__, 2) . '/private/crm-migration-error.log';
    @file_put_contents(
        $diagnosticPath,
        gmdate(DATE_ATOM) . "\n" . get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString(),
        LOCK_EX
    );
    @chmod($diagnosticPath, 0600);
    crm_migration_response(['error' => 'The customer migration could not be completed.'], 500);
}
