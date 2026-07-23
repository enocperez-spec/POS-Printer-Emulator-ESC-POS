<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/mailer.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
require_same_origin();
enforce_rate_limit('capture-portal-order', 20, 3600);

try {
    $input = request_json();
    $token = trim((string)($input['checkoutToken'] ?? ''));
    $paypalId = trim((string)($input['orderId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token) || !preg_match('/^[A-Z0-9]{8,30}$/i', $paypalId)) {
        throw new InvalidArgumentException('The checkout session is invalid.');
    }
    $session = portal_commerce_service_request(['action' => 'resolve', 'checkoutToken' => $token]);
    $query = db()->prepare('SELECT * FROM orders WHERE paypal_order_id=? AND portal_intent_id=? LIMIT 1');
    $query->execute([$paypalId, (string)$session['intentId']]);
    $order = $query->fetch();
    if (!is_array($order)) {
        json_response(['error' => 'Order not found.'], 404);
    }
    if (in_array((string)$order['status'], ['APPROVED', 'EMAILED', 'EMAIL_FAILED'], true)) {
        json_response(['publicId' => $order['public_id'], 'status' => $order['status'], 'idempotent' => true]);
    }

    $paypalPath = '/v2/checkout/orders/' . rawurlencode($paypalId);
    try {
        $captured = paypal_request('POST', $paypalPath . '/capture', null, 'portal-capture-' . $order['public_id']);
    } catch (RuntimeException) {
        $captured = paypal_request('GET', $paypalPath);
    }
    $capture = $captured['purchase_units'][0]['payments']['captures'][0] ?? null;
    if (($captured['status'] ?? '') !== 'COMPLETED' || !is_array($capture) ||
        ($capture['status'] ?? '') !== 'COMPLETED' ||
        ($capture['amount']['value'] ?? '') !== $order['amount'] ||
        ($capture['amount']['currency_code'] ?? '') !== $order['currency']) {
        audit((int)$order['id'], 'PORTAL_CAPTURE_REJECTED', json_encode(['status' => $captured['status'] ?? null]));
        throw new DomainException('Payment could not be verified.');
    }
    $providerCapturedAt = trim((string)($capture['create_time'] ?? ''));
    $providerCaptureId = trim((string)($capture['id'] ?? ''));
    $paidAt = new DateTimeImmutable($providerCapturedAt);
    if ($providerCaptureId === '' || $paidAt > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
        throw new DomainException('Payment could not be verified.');
    }

    $fulfilled = portal_commerce_service_request([
        'action' => 'fulfill',
        'checkoutToken' => $token,
        'providerOrderId' => $paypalId,
        'providerCaptureId' => $providerCaptureId,
        'capturedAt' => $paidAt->format(DATE_ATOM),
    ]);
    $update = db()->prepare(
        "UPDATE orders SET paypal_capture_id=?,paid_at=?,status='APPROVED',approved_at=?,
            activation_key=?,license_id=?,maintenance_new_expires_at=?,maintenance_token=?,last_error=NULL
         WHERE id=? AND status IN ('CREATED','PAID_AWAITING_APPROVAL')"
    );
    $update->execute([
        $providerCaptureId,
        $paidAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        now_utc(),
        $fulfilled['activationKey'] ?? null,
        $fulfilled['licenseId'] ?? null,
        $fulfilled['maintenanceExpiresAt'] ?? null,
        $fulfilled['maintenanceToken'] ?? null,
        $order['id'],
    ]);
    audit((int)$order['id'], 'PORTAL_ORDER_FULFILLED', json_encode([
        'intentId' => $session['intentId'],
        'captureId' => $providerCaptureId,
        'idempotent' => (bool)($fulfilled['idempotent'] ?? false),
    ], JSON_UNESCAPED_SLASHES));
    $query->execute([$paypalId, (string)$session['intentId']]);
    $completedOrder = $query->fetch();
    try {
        email_activation_key($completedOrder);
        $sent = db()->prepare("UPDATE orders SET status='EMAILED',emailed_at=?,last_error=NULL WHERE id=?");
        $sent->execute([now_utc(), $order['id']]);
        audit((int)$order['id'], 'PORTAL_FULFILLMENT_EMAIL_SENT');
        $status = 'EMAILED';
    } catch (Throwable $mailError) {
        $failed = db()->prepare("UPDATE orders SET status='EMAIL_FAILED',last_error=? WHERE id=?");
        $failed->execute(['Fulfillment email delivery failed.', $order['id']]);
        audit((int)$order['id'], 'PORTAL_FULFILLMENT_EMAIL_FAILED', get_class($mailError));
        $status = 'EMAIL_FAILED';
    }
    json_response(['publicId' => $order['public_id'], 'status' => $status, 'idempotent' => false]);
} catch (InvalidArgumentException $exception) {
    json_response(['error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    json_response(['error' => $exception->getMessage()], 409);
}
