<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function communications_scheduler_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    communications_scheduler_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    communications_scheduler_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    communications_scheduler_response(['ok' => true, 'schedules' => communication_schedule_lifecycle($pdo)]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator communications scheduler failed: ' . get_class($exception));
    communications_scheduler_response(['error' => 'The lifecycle scheduler could not finish safely.'], 500);
}
