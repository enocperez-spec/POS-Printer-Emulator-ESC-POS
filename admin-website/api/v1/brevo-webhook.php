<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function brevo_webhook_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    brevo_webhook_response(['error' => 'Not found.'], 404);
}
if (!communication_webhook_authorized()) {
    usleep(250000);
    brevo_webhook_response(['error' => 'Authentication failed.'], 401);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    brevo_webhook_response(['error' => 'Unsupported content type.'], 415);
}
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 131072) {
    brevo_webhook_response(['error' => 'Payload too large.'], 413);
}
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 20, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) brevo_webhook_response(['error' => 'Invalid event.'], 422);
    $result = communication_process_webhook(database(), $payload);
    brevo_webhook_response(['ok' => true, 'status' => $result['status']]);
} catch (JsonException) {
    brevo_webhook_response(['error' => 'Invalid JSON.'], 400);
} catch (InvalidArgumentException $exception) {
    brevo_webhook_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator Brevo webhook failed: ' . get_class($exception));
    brevo_webhook_response(['error' => 'The event could not be processed.'], 500);
}
