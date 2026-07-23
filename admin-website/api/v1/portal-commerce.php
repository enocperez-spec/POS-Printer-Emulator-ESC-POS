<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require dirname(__DIR__, 2) . '/includes/license_keys.php';
require dirname(__DIR__, 2) . '/includes/license_management.php';
require dirname(__DIR__, 2) . '/includes/purchase_site.php';
require dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function commerce_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function commerce_body(): array
{
    try {
        $body = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        commerce_response(['error' => 'Invalid request.'], 400);
    }
    if (!is_array($body)) {
        commerce_response(['error' => 'Invalid request.'], 400);
    }
    return $body;
}

function require_commerce_service_token(): void
{
    $provided = (string)($_SERVER['HTTP_X_PPE_ADMIN_TOKEN'] ?? '');
    $expected = (string)(purchase_site_config()['maintenance_token'] ?? '');
    if ($expected === '' || strlen($expected) < 32 || !hash_equals($expected, $provided)) {
        usleep(250000);
        commerce_response(['error' => 'Unauthorized.'], 401);
    }
}

function commerce_token(array $body): string
{
    $token = trim((string)($body['checkoutToken'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        commerce_response(['error' => 'The checkout session is invalid.'], 422);
    }
    return $token;
}

function commerce_intent(PDO $pdo, string $token): ?array
{
    $query = $pdo->prepare(
        "SELECT i.*,c.display_name,c.canonical_email,ins.installation_uuid
         FROM portal_checkout_intents i
         INNER JOIN customers c ON c.customer_id=i.customer_id
         LEFT JOIN installations ins ON ins.id=i.installation_id
         WHERE i.checkout_token_hash=UNHEX(SHA2(:token,256))
         LIMIT 1"
    );
    $query->execute(['token' => $token]);
    $intent = $query->fetch();
    return is_array($intent) ? $intent : null;
}

function commerce_event(
    PDO $pdo,
    string $intentId,
    string $type,
    string $summary,
    array $data = []
): void {
    $insert = $pdo->prepare(
        'INSERT INTO portal_checkout_events(intent_id,event_type,actor,event_summary,event_data)
         VALUES(:intent_id,:event_type,\'Buy Service\',:summary,:event_data)'
    );
    $insert->execute([
        'intent_id' => $intentId,
        'event_type' => $type,
        'summary' => $summary,
        'event_data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ]);
}

function commerce_reveal_replacement(PDO $pdo, string $licenseId): array
{
    $query = $pdo->prepare(
        'SELECT license_id,license_tier,maintenance_expires_at,activation_key,
                activation_key_ciphertext,activation_key_nonce,activation_key_tag
         FROM issued_licenses WHERE license_id=:license_id LIMIT 1'
    );
    $query->execute(['license_id' => $licenseId]);
    $license = $query->fetch();
    if (!is_array($license)) {
        throw new RuntimeException('The fulfilled license could not be loaded.');
    }
    return [
        'licenseId' => (string)$license['license_id'],
        'licenseTier' => (string)$license['license_tier'],
        'activationKey' => reveal_activation_key($license),
        'maintenanceExpiresAt' => (string)$license['maintenance_expires_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    commerce_response(['error' => 'Not found.'], 404);
}
require_commerce_service_token();
$body = commerce_body();
$action = strtolower(trim((string)($body['action'] ?? '')));
$token = commerce_token($body);

try {
    $pdo = database();
    ensure_license_management_schema($pdo);
    ensure_self_service_commerce_schema($pdo);
    $intent = commerce_intent($pdo, $token);
    if (!is_array($intent)) {
        commerce_response(['error' => 'The checkout session was not found.'], 404);
    }

    if (strtotime((string)$intent['expires_at']) <= time() &&
        !in_array((string)$intent['state'], ['Captured', 'Fulfilled', 'Refunded', 'ChargebackReview'], true)) {
        $expire = $pdo->prepare(
            "UPDATE portal_checkout_intents SET state='Expired'
             WHERE intent_id=:intent_id AND state IN ('Prepared','ProviderCreated')"
        );
        $expire->execute(['intent_id' => $intent['intent_id']]);
        commerce_response(['error' => 'This checkout session expired. Return to the Customer Portal and start again.'], 410);
    }

    if ($action === 'resolve') {
        if (!in_array((string)$intent['state'], ['Prepared', 'ProviderCreated', 'Captured', 'Fulfilled'], true)) {
            commerce_response(['error' => 'This checkout session is no longer available.'], 409);
        }
        commerce_response([
            'ok' => true,
            'intentId' => (string)$intent['intent_id'],
            'state' => (string)$intent['state'],
            'orderType' => strtolower((string)$intent['order_type']),
            'currentTier' => (string)$intent['current_tier'],
            'targetTier' => (string)$intent['target_tier'],
            'customerName' => (string)$intent['display_name'],
            'email' => (string)$intent['canonical_email'],
            'licenseId' => $intent['license_id'],
            'installationId' => $intent['installation_uuid'],
            'maintenanceExpiresAt' => $intent['maintenance_previous_expires_at'],
            'expiresAt' => (string)$intent['expires_at'],
        ]);
    }

    if ($action === 'provider-created') {
        $providerOrderId = trim((string)($body['providerOrderId'] ?? ''));
        $amount = trim((string)($body['amount'] ?? ''));
        $currency = strtoupper(trim((string)($body['currency'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $providerOrderId) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/', $amount) ||
            (float)$amount <= 0 ||
            !preg_match('/^[A-Z]{3}$/', $currency)) {
            commerce_response(['error' => 'The verified provider order details are invalid.'], 422);
        }
        if ((string)$intent['state'] === 'ProviderCreated') {
            if (!hash_equals((string)$intent['provider_order_id'], $providerOrderId) ||
                !hash_equals((string)$intent['amount'], $amount) ||
                !hash_equals((string)$intent['currency'], $currency)) {
                commerce_response(['error' => 'This checkout session is already bound to another order.'], 409);
            }
            commerce_response(['ok' => true, 'idempotent' => true]);
        }
        if ((string)$intent['state'] !== 'Prepared') {
            commerce_response(['error' => 'This checkout session cannot create another order.'], 409);
        }
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            "UPDATE portal_checkout_intents
             SET state='ProviderCreated',amount=:amount,currency=:currency,provider_order_id=:provider_order_id
             WHERE intent_id=:intent_id AND state='Prepared'"
        );
        $update->execute([
            'amount' => $amount,
            'currency' => $currency,
            'provider_order_id' => $providerOrderId,
            'intent_id' => $intent['intent_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            commerce_response(['error' => 'The checkout session changed. Refresh and try again.'], 409);
        }
        commerce_event($pdo, (string)$intent['intent_id'], 'PROVIDER_ORDER_CREATED', 'PayPal order created from a server-owned price.', [
            'providerOrderId' => $providerOrderId,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $pdo->commit();
        commerce_response(['ok' => true, 'idempotent' => false]);
    }

    if ($action === 'fulfill') {
        $providerOrderId = trim((string)($body['providerOrderId'] ?? ''));
        $providerCaptureId = trim((string)($body['providerCaptureId'] ?? ''));
        $capturedAt = trim((string)($body['capturedAt'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $providerOrderId) ||
            !preg_match('/^[A-Za-z0-9]{8,64}$/', $providerCaptureId) ||
            !hash_equals((string)$intent['provider_order_id'], $providerOrderId)) {
            commerce_response(['error' => 'The verified payment does not match this checkout session.'], 409);
        }
        try {
            $captureTime = (new DateTimeImmutable($capturedAt))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            commerce_response(['error' => 'The verified payment time is invalid.'], 422);
        }
        if ($captureTime > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
            commerce_response(['error' => 'The verified payment time is invalid.'], 422);
        }

        if ((string)$intent['state'] === 'Fulfilled') {
            $response = ['ok' => true, 'idempotent' => true, 'intentId' => (string)$intent['intent_id']];
            if ((string)$intent['order_type'] === 'MAINTENANCE' &&
                !empty($intent['license_id']) && !empty($intent['maintenance_new_expires_at'])) {
                $response += [
                    'licenseId' => (string)$intent['license_id'],
                    'licenseTier' => (string)$intent['target_tier'],
                    'maintenanceExpiresAt' => (string)$intent['maintenance_new_expires_at'],
                    'maintenanceToken' => issue_maintenance_token(
                        (string)$intent['license_id'],
                        (string)$intent['target_tier'],
                        (string)$intent['maintenance_new_expires_at']
                    ),
                ];
            } elseif (!empty($intent['replacement_license_id'])) {
                $response += commerce_reveal_replacement($pdo, (string)$intent['replacement_license_id']);
            }
            commerce_response($response);
        }
        if (!in_array((string)$intent['state'], ['ProviderCreated', 'Captured'], true)) {
            commerce_response(['error' => 'This checkout session is not ready for fulfillment.'], 409);
        }

        $sourceReference = 'portal:' . (string)$intent['intent_id'];
        if ((string)$intent['state'] === 'ProviderCreated') {
            $pdo->beginTransaction();
            $captured = $pdo->prepare(
                "UPDATE portal_checkout_intents
                 SET state='Captured',provider_capture_id=:capture_id,captured_at=:captured_at
                 WHERE intent_id=:intent_id AND state='ProviderCreated'"
            );
            $captured->execute([
                'capture_id' => $providerCaptureId,
                'captured_at' => $captureTime->format('Y-m-d H:i:s.u'),
                'intent_id' => $intent['intent_id'],
            ]);
            if ($captured->rowCount() !== 1) {
                $pdo->rollBack();
                commerce_response(['error' => 'The checkout session changed. Retry to reconcile the verified payment.'], 409);
            }
            commerce_event($pdo, (string)$intent['intent_id'], 'PAYMENT_CAPTURED', 'Verified PayPal capture durably recorded before entitlement fulfillment.', [
                'providerCaptureId' => $providerCaptureId,
            ]);
            $pdo->commit();
            $intent['state'] = 'Captured';
            $intent['provider_capture_id'] = $providerCaptureId;
            $intent['captured_at'] = $captureTime->format('Y-m-d H:i:s.u');
        } elseif (!hash_equals((string)$intent['provider_capture_id'], $providerCaptureId)) {
            commerce_response(['error' => 'This checkout session is already bound to another capture.'], 409);
        }

        $fulfillment = [];
        if ((string)$intent['order_type'] === 'MAINTENANCE') {
            $digest = maintenance_registration_digest((string)$intent['display_name'], (string)$intent['canonical_email']);
            $renewal = apply_paid_maintenance_renewal(
                $pdo,
                (string)$intent['license_id'],
                (string)$intent['target_tier'],
                $digest,
                $captureTime->format(DATE_ATOM),
                $sourceReference,
                'verified-self-service'
            );
            $fulfillment = [
                'licenseId' => (string)$renewal['license_id'],
                'licenseTier' => (string)$renewal['license_tier'],
                'maintenanceExpiresAt' => (string)$renewal['maintenance_expires_at'],
                'maintenanceToken' => (string)$renewal['maintenance_token'],
            ];
        } elseif ((string)$intent['current_tier'] === 'Trial') {
            $existing = $pdo->prepare(
                "SELECT license_id,license_tier,maintenance_expires_at,activation_key,
                        activation_key_ciphertext,activation_key_nonce,activation_key_tag
                 FROM issued_licenses
                 WHERE source_reference=:source_reference AND control_state IN ('Enabled','Deactivated')
                 ORDER BY issued_at DESC LIMIT 1"
            );
            $existing->execute(['source_reference' => 'trial:' . (string)$intent['installation_uuid']]);
            $issued = $existing->fetch();
            if (!is_array($issued)) {
                $issued = upgrade_trial_installation(
                    $pdo,
                    (string)$intent['installation_uuid'],
                    (string)$intent['target_tier'],
                    'verified-self-service',
                    static fn(string $name, string $email, string $tier): array => issue_activation_key($name, $email, $tier)
                );
            }
            $fulfillment = [
                'licenseId' => (string)$issued['license_id'],
                'licenseTier' => (string)$issued['license_tier'],
                'activationKey' => isset($issued['activation_key_ciphertext'])
                    ? reveal_activation_key($issued)
                    : (string)$issued['activation_key'],
                'maintenanceExpiresAt' => (string)$issued['maintenance_expires_at'],
            ];
        } else {
            $licenseQuery = $pdo->prepare(
                'SELECT row_version,superseded_by_license_id FROM issued_licenses WHERE license_id=:license_id LIMIT 1'
            );
            $licenseQuery->execute(['license_id' => $intent['license_id']]);
            $license = $licenseQuery->fetch();
            if (!is_array($license)) {
                throw new DomainException('The license selected for this upgrade no longer exists.');
            }
            if (!empty($license['superseded_by_license_id'])) {
                $replacement = commerce_reveal_replacement($pdo, (string)$license['superseded_by_license_id']);
                $issued = [
                    'license_id' => $replacement['licenseId'],
                    'license_tier' => $replacement['licenseTier'],
                    'activation_key' => $replacement['activationKey'],
                    'maintenance_expires_at' => $replacement['maintenanceExpiresAt'],
                ];
            } else {
                $result = manage_issued_license(
                    $pdo,
                    'change_tier',
                    (string)$intent['license_id'],
                    (int)$license['row_version'],
                    'verified-self-service',
                    static fn(string $name, string $email, string $tier, string $maintenance): array =>
                        issue_activation_key($name, $email, $tier, $maintenance),
                    (string)$intent['target_tier'],
                    'Verified PayPal self-service upgrade ' . $intent['intent_id']
                );
                if (!is_array($result['issued'] ?? null)) {
                    throw new RuntimeException('The replacement license was not generated.');
                }
                $issued = $result['issued'];
            }
            $fulfillment = [
                'licenseId' => (string)$issued['license_id'],
                'licenseTier' => (string)$issued['license_tier'],
                'activationKey' => (string)$issued['activation_key'],
                'maintenanceExpiresAt' => (string)$issued['maintenance_expires_at'],
            ];
        }

        if (!empty($fulfillment['licenseId'])) {
            $linkCustomer = $pdo->prepare(
                'UPDATE issued_licenses SET customer_id=:customer_id
                 WHERE license_id=:license_id AND (customer_id IS NULL OR customer_id=:customer_id)'
            );
            $linkCustomer->execute([
                'customer_id' => $intent['customer_id'],
                'license_id' => $fulfillment['licenseId'],
            ]);
            if ($linkCustomer->rowCount() < 1) {
                $verifyCustomer = $pdo->prepare(
                    'SELECT customer_id FROM issued_licenses WHERE license_id=:license_id LIMIT 1'
                );
                $verifyCustomer->execute(['license_id' => $fulfillment['licenseId']]);
                if (!hash_equals((string)$intent['customer_id'], (string)$verifyCustomer->fetchColumn())) {
                    throw new RuntimeException('The fulfilled license could not be linked to the verified customer.');
                }
            }
        }

        $pdo->beginTransaction();
        $complete = $pdo->prepare(
            "UPDATE portal_checkout_intents
             SET state='Fulfilled',fulfilled_at=UTC_TIMESTAMP(6),replacement_license_id=:replacement_license_id,
                 maintenance_new_expires_at=:maintenance_new_expires_at
             WHERE intent_id=:intent_id AND state='Captured' AND provider_capture_id=:capture_id"
        );
        $complete->execute([
            'capture_id' => $providerCaptureId,
            'replacement_license_id' => (string)$intent['order_type'] === 'UPGRADE'
                ? ($fulfillment['licenseId'] ?? null)
                : null,
            'maintenance_new_expires_at' => $fulfillment['maintenanceExpiresAt'] ?? null,
            'intent_id' => $intent['intent_id'],
        ]);
        if ($complete->rowCount() !== 1) {
            $pdo->rollBack();
            throw new RuntimeException('The payment was fulfilled but its checkout record requires reconciliation.');
        }
        $purchase = $pdo->prepare(
            'INSERT INTO customer_purchases
                (customer_id,purchase_reference,order_type,license_tier,purchase_status,amount,currency,paid_at)
             VALUES(:customer_id,:reference,:order_type,:tier,\'FULFILLED\',:amount,:currency,:paid_at)
             ON DUPLICATE KEY UPDATE purchase_status=\'FULFILLED\',paid_at=VALUES(paid_at),updated_at=UTC_TIMESTAMP(6)'
        );
        $purchase->execute([
            'customer_id' => $intent['customer_id'],
            'reference' => $sourceReference,
            'order_type' => (string)$intent['order_type'] === 'MAINTENANCE' ? 'MAINTENANCE' : 'LICENSE',
            'tier' => $intent['target_tier'],
            'amount' => $intent['amount'],
            'currency' => $intent['currency'],
            'paid_at' => $captureTime->format('Y-m-d H:i:s.u'),
        ]);
        commerce_event($pdo, (string)$intent['intent_id'], 'FULFILLED', 'Verified PayPal payment fulfilled exactly once.', [
            'providerOrderId' => $providerOrderId,
            'providerCaptureId' => $providerCaptureId,
        ]);
        $pdo->commit();
        try {
            $customer = $pdo->prepare('SELECT display_name FROM customers WHERE customer_id=:customer_id LIMIT 1');
            $customer->execute(['customer_id' => $intent['customer_id']]);
            $displayName = $customer->fetchColumn();
            if (is_string($displayName)) {
                communication_enqueue(
                    $pdo,
                    (string)$intent['customer_id'],
                    'purchase_confirmation',
                    [
                        'customer_name' => $displayName,
                        'license_tier' => (string)$intent['target_tier'],
                        'portal_url' => 'https://userportal.posprinteremulator.com/',
                    ],
                    'purchase:' . $sourceReference
                );
                if ((string)$intent['order_type'] !== 'MAINTENANCE') {
                    communication_enqueue(
                        $pdo,
                        (string)$intent['customer_id'],
                        'activation_ready',
                        [
                            'customer_name' => $displayName,
                            'license_tier' => (string)$intent['target_tier'],
                            'portal_url' => 'https://userportal.posprinteremulator.com/',
                        ],
                        'activation-ready:' . $sourceReference
                    );
                }
            }
        } catch (Throwable $exception) {
            // Payment fulfillment remains authoritative when an optional delivery template is unavailable.
            error_log('POS Printer Emulator purchase communication not queued: ' . get_class($exception));
        }
        commerce_response(['ok' => true, 'idempotent' => false, 'intentId' => (string)$intent['intent_id']] + $fulfillment);
    }

    if ($action === 'record-reversal') {
        $providerOrderId = trim((string)($body['providerOrderId'] ?? ''));
        $reason = trim((string)($body['reason'] ?? 'Provider reversal requires review.'));
        $state = strtolower(trim((string)($body['reversalType'] ?? ''))) === 'refund' ? 'Refunded' : 'ChargebackReview';
        if (!hash_equals((string)$intent['provider_order_id'], $providerOrderId) || mb_strlen($reason) > 500) {
            commerce_response(['error' => 'The reversal details are invalid.'], 422);
        }
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            "UPDATE portal_checkout_intents SET state=:state
             WHERE intent_id=:intent_id AND state IN ('Captured','Fulfilled','ChargebackReview')"
        );
        $update->execute(['state' => $state, 'intent_id' => $intent['intent_id']]);
        commerce_event($pdo, (string)$intent['intent_id'], 'PAYMENT_REVERSAL_RECORDED', 'Provider reversal recorded for administrative review.', [
            'state' => $state,
            'reason' => $reason,
        ]);
        $pdo->commit();
        commerce_response(['ok' => true, 'state' => $state]);
    }

    commerce_response(['error' => 'Unsupported action.'], 422);
} catch (DomainException|InvalidArgumentException $exception) {
    commerce_response(['error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator portal commerce failure: ' . get_class($exception));
    commerce_response(['error' => 'The secure checkout service could not complete this request.'], 500);
}
