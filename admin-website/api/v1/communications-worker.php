<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function communications_worker_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    communications_worker_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    communications_worker_response(['error' => 'Authentication failed.'], 401);
}

$requested = filter_var($_GET['max'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
$maximum = $requested === false ? 10 : (int)$requested;
try {
    $pdo = database();
    $results = [];
    for ($index = 0; $index < $maximum; $index++) {
        $result = communication_worker_process_one($pdo);
        if ($result['status'] === 'idle') break;
        $results[] = $result;
    }
    communications_worker_response([
        'ok' => true,
        'processed' => count($results),
        'results' => $results,
        'summary' => communication_dashboard_summary($pdo),
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator communications worker failed: ' . get_class($exception));
    communications_worker_response(['error' => 'The communications worker could not finish safely.'], 500);
}
