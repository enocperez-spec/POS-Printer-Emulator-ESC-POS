<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
require_same_origin();
enforce_rate_limit('create-portal-order', 10, 3600);

try {
    $input = request_json();
    $token = trim((string)($input['checkoutToken'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        throw new InvalidArgumentException('The checkout session is invalid.');
    }
    $session = portal_commerce_service_request(['action' => 'resolve', 'checkoutToken' => $token]);
    $intentId = (string)($session['intentId'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $intentId)) {
        throw new RuntimeException('The checkout session could not be verified.');
    }
    $existing = db()->prepare('SELECT paypal_order_id,status FROM orders WHERE portal_intent_id=? LIMIT 1');
    $existing->execute([$intentId]);
    $existingOrder = $existing->fetch();
    if (is_array($existingOrder)) {
        if ((string)$existingOrder['status'] === 'CREATED') {
            json_response(['orderId' => (string)$existingOrder['paypal_order_id'], 'idempotent' => true]);
        }
        throw new DomainException('This checkout session has already been processed.');
    }

    $offer = self_service_offer(
        (string)$session['orderType'],
        (string)$session['currentTier'],
        (string)$session['targetTier']
    );
    $amount = (string)$offer['price'];
    $currency = (string)$offer['currency'];
    $tier = clean_license_tier((string)$session['targetTier']);
    $orderType = strtoupper((string)$session['orderType']);
    $description = $orderType === 'MAINTENANCE'
        ? "POS Printer Emulator {$tier} Annual Maintenance Renewal"
        : "POS Printer Emulator {$session['currentTier']} to {$tier} Permanent License Upgrade";
    $publicId = random_public_id();
    $created = paypal_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $publicId,
            'description' => $description,
            'amount' => ['currency_code' => $currency, 'value' => $amount],
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'brand_name' => 'POS Emulator',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => config('app_url') . '/self-service.php?status=return',
            'cancel_url' => config('app_url') . '/self-service.php?status=cancel',
        ]]],
    ], 'portal-' . $intentId);
    $paypalOrderId = $created['id'] ?? null;
    if (!is_string($paypalOrderId) || !preg_match('/^[A-Z0-9]{8,30}$/i', $paypalOrderId)) {
        throw new RuntimeException('PayPal did not return a valid order ID.');
    }

    portal_commerce_service_request([
        'action' => 'provider-created',
        'checkoutToken' => $token,
        'providerOrderId' => $paypalOrderId,
        'amount' => $amount,
        'currency' => $currency,
    ]);
    $insert = db()->prepare(
        'INSERT INTO orders
            (public_id,customer_name,email,order_type,license_tier,renewal_license_id,paypal_order_id,
             amount,currency,status,created_at,maintenance_previous_expires_at,portal_intent_id)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $publicId,
        clean_customer((string)$session['customerName']),
        clean_email((string)$session['email']),
        $orderType,
        $tier,
        $session['licenseId'] ?: null,
        $paypalOrderId,
        $amount,
        $currency,
        'CREATED',
        now_utc(),
        $session['maintenanceExpiresAt'] ?: null,
        $intentId,
    ]);
    $orderId = (int)db()->lastInsertId();
    audit($orderId, 'PORTAL_ORDER_CREATED', json_encode([
        'intentId' => $intentId,
        'paypalOrderId' => $paypalOrderId,
        'orderType' => $orderType,
        'currentTier' => $session['currentTier'],
        'targetTier' => $tier,
    ], JSON_UNESCAPED_SLASHES));
    json_response(['orderId' => $paypalOrderId, 'idempotent' => false]);
} catch (InvalidArgumentException $exception) {
    json_response(['error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    json_response(['error' => $exception->getMessage()], 409);
}
