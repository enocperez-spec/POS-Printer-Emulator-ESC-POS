<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function communications_migration_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    communications_migration_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    communications_migration_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    ensure_communication_schema($pdo);
    $migration = $pdo->prepare('INSERT IGNORE INTO development_migrations(migration_key) VALUES(:migration_key)');
    $migration->execute(['migration_key' => 'consent-aware-communications-v0.3.45']);
    $pdo->exec("UPDATE development_roadmap SET status='In progress',completed_at=NULL WHERE item_key='v0.3.45'");
    $summary = communication_dashboard_summary($pdo);
    communications_migration_response([
        'ok' => true,
        'migration' => 'consent-aware-communications-v0.3.45',
        'providerConfigured' => $summary['provider_configured'],
        'deliveryPaused' => $summary['emergency_stop'],
        'marketingPaused' => $summary['marketing_pause'],
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator communications migration failed: ' . get_class($exception));
    communications_migration_response(['error' => 'The communications migration could not be completed.'], 500);
}
