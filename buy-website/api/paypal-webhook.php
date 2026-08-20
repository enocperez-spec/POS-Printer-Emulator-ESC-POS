<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function paypal_webhook_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paypal_webhook_response(['error' => 'Not found.'], 404);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    paypal_webhook_response(['error' => 'Unsupported content type.'], 415);
}
$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || strlen($raw) > 262144) {
    paypal_webhook_response(['error' => 'The event payload is invalid.'], 413);
}
try {
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($event) || !paypal_verify_webhook($event)) {
        paypal_webhook_response(['error' => 'Webhook verification failed.'], 401);
    }
    $reversal = paypal_reversal_event($event);
    if ($reversal === null) {
        paypal_webhook_response(['ok' => true, 'status' => 'ignored']);
    }
    $result = portal_commerce_service_request(['action' => 'record-provider-reversal'] + $reversal);
    paypal_webhook_response([
        'ok' => true,
        'status' => (string)($result['state'] ?? 'recorded'),
        'idempotent' => (bool)($result['idempotent'] ?? false),
    ]);
} catch (InvalidArgumentException|JsonException $exception) {
    paypal_webhook_response(['error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    error_log('POS Printer Emulator PayPal reversal could not be reconciled: ' . $exception->getMessage());
    paypal_webhook_response(['error' => 'The verified event could not yet be reconciled.'], 503);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator PayPal webhook failed: ' . get_class($exception));
    paypal_webhook_response(['error' => 'The event could not be processed.'], 500);
}
