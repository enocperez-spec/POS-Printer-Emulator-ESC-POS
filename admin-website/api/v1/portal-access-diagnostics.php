<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function portal_access_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function portal_access_brevo_events(string $email): array
{
    $config = communication_config();
    $apiKey = trim((string)($config['brevo_api_key'] ?? ''));
    $apiBase = rtrim((string)($config['brevo_api_base'] ?? ''), '/');
    $parts = parse_url($apiBase);
    if ($apiKey === '' || !function_exists('curl_init') || !is_array($parts) ||
        strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== 'api.brevo.com') {
        throw new RuntimeException('Brevo delivery diagnostics are not configured.');
    }

    $query = http_build_query([
        'email' => $email,
        'limit' => 20,
        'sort' => 'desc',
    ], '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($apiBase . '/smtp/statistics/events?' . $query);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'api-key: ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $networkError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException($networkError !== '' ? $networkError : 'Brevo did not return a response.');
    }
    $decoded = trim($body) === '' ? [] : json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Brevo delivery diagnostics returned HTTP ' . $status . '.');
    }

    $events = [];
    foreach (is_array($decoded['events'] ?? null) ? $decoded['events'] : [] as $event) {
        if (!is_array($event)) continue;
        $events[] = [
            'event' => (string)($event['event'] ?? ''),
            'date' => (string)($event['date'] ?? ''),
            'subject' => (string)($event['subject'] ?? ''),
            'messageId' => (string)($event['messageId'] ?? ''),
            'reason' => (string)($event['reason'] ?? ''),
        ];
    }
    return $events;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portal_access_response(404, ['error' => 'Not found.']);
}
if (!communication_service_authorized()) {
    usleep(250000);
    portal_access_response(401, ['error' => 'Authentication failed.']);
}

$diagnosticStage = 'request';
try {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
    $email = crm_normalize_email((string)($body['email'] ?? ''));
    if ($email === '') {
        portal_access_response(400, ['error' => 'A valid email address is required.']);
    }

    $diagnosticStage = 'database';
    $pdo = database();
    ensure_communication_schema($pdo);
    $diagnosticStage = 'customer_lookup';
    $customers = $pdo->prepare(
        "SELECT c.customer_id,c.status,c.email_verified_at,c.merged_into_customer_id,
                a.customer_id AS portal_account_id,a.locked_until,a.failed_login_count,
                a.last_login_at,a.password_changed_at
         FROM customers c
         LEFT JOIN portal_accounts a ON a.customer_id=c.customer_id
         WHERE c.email_hash=UNHEX(SHA2(:email,256))
         ORDER BY c.created_at"
    );
    $customers->execute(['email' => $email]);
    $matches = $customers->fetchAll();

    $diagnosticStage = 'portal_queries';
    $verification = $pdo->prepare(
        'SELECT requested_at,expires_at,used_at
         FROM customer_email_verifications
         WHERE customer_id=:customer_id
         ORDER BY requested_at DESC LIMIT 1'
    );
    $reset = $pdo->prepare(
        'SELECT requested_at,expires_at,used_at
         FROM portal_password_resets
         WHERE customer_id=:customer_id
         ORDER BY requested_at DESC LIMIT 1'
    );
    $queue = $pdo->prepare(
        "SELECT o.template_key,o.state,o.attempts,o.last_error_code,o.last_error_detail,o.created_at,o.sent_at,
                (SELECT d.event_type FROM communication_delivery_events d
                 WHERE d.message_id=o.message_id ORDER BY d.occurred_at DESC,d.id DESC LIMIT 1) latest_delivery_event,
                (SELECT d.occurred_at FROM communication_delivery_events d
                 WHERE d.message_id=o.message_id ORDER BY d.occurred_at DESC,d.id DESC LIMIT 1) latest_delivery_at
         FROM communication_outbox o
         WHERE o.customer_id=:customer_id
           AND o.template_key IN ('email_verification','password_recovery')
         ORDER BY o.created_at DESC LIMIT 10"
    );
    $licenses = $pdo->prepare(
        "SELECT license_tier,control_state,maintenance_expires_at
         FROM issued_licenses
         WHERE customer_id=:customer_id
         ORDER BY issued_at DESC"
    );
    $purchases = $pdo->prepare(
        "SELECT p.purchase_status,p.order_type,p.license_tier,
                i.order_type AS checkout_order_type,
                COALESCE(i.replacement_license_id,i.license_id) AS associated_license_id,
                i.provider_order_id
         FROM customer_purchases p
         LEFT JOIN portal_checkout_intents i
           ON p.purchase_reference=CONCAT('portal:',i.intent_id) AND i.customer_id=p.customer_id
         WHERE p.customer_id=:customer_id
         ORDER BY p.paid_at DESC,p.updated_at DESC LIMIT 100"
    );
    $licenseEvents = $pdo->prepare(
        "SELECT event_type,event_summary,source_reference,occurred_at
         FROM customer_events
         WHERE customer_id=:customer_id
           AND event_type IN (
             'Portal Computer Link Approved','Portal Device Deactivated',
             'Portal Checkout Prepared','Portal Promotion Started'
           )
         ORDER BY occurred_at DESC LIMIT 20"
    );
    $suppressions = $pdo->prepare(
        "SELECT reason,source,created_at
         FROM customer_email_suppressions
         WHERE customer_id=:customer_id AND active=1
         ORDER BY created_at DESC"
    );

    $diagnosticStage = 'record_details';
    $records = [];
    foreach ($matches as $match) {
        $customerId = (string)$match['customer_id'];
        $verification->execute(['customer_id' => $customerId]);
        $reset->execute(['customer_id' => $customerId]);
        $queue->execute(['customer_id' => $customerId]);
        $licenses->execute(['customer_id' => $customerId]);
        $purchases->execute(['customer_id' => $customerId]);
        $purchaseRecords = $purchases->fetchAll();
        $licenseEvents->execute(['customer_id' => $customerId]);
        $suppressions->execute(['customer_id' => $customerId]);
        $records[] = [
            'customerId' => $customerId,
            'customerStatus' => (string)$match['status'],
            'mergedIntoCustomerId' => $match['merged_into_customer_id'],
            'emailVerified' => !empty($match['email_verified_at']),
            'portalAccountExists' => !empty($match['portal_account_id']),
            'accountLocked' => !empty($match['locked_until']) &&
                strtotime((string)$match['locked_until']) > time(),
            'lockedUntil' => $match['locked_until'],
            'failedLoginCount' => (int)($match['failed_login_count'] ?? 0),
            'lastLoginAt' => $match['last_login_at'],
            'passwordChangedAt' => $match['password_changed_at'],
            'latestVerification' => $verification->fetch() ?: null,
            'latestPasswordReset' => $reset->fetch() ?: null,
            'securityEmailQueue' => $queue->fetchAll(),
            'licenses' => $licenses->fetchAll(),
            'billing' => [
                'purchaseCount' => count($purchaseRecords),
                'mappedPurchaseCount' => count(array_filter(
                    $purchaseRecords,
                    static fn(array $purchase): bool =>
                        !empty($purchase['provider_order_id']) || !empty($purchase['associated_license_id'])
                )),
                'records' => $purchaseRecords,
            ],
            'accountLicenseEvents' => $licenseEvents->fetchAll(),
            'activeEmailSuppressions' => $suppressions->fetchAll(),
        ];
    }

    $activeMatches = array_values(array_filter(
        $records,
        static fn(array $record): bool => $record['customerStatus'] === 'Active'
    ));
    $providerEvents = [];
    $providerDiagnosticError = null;
    try {
        $providerEvents = portal_access_brevo_events($email);
    } catch (Throwable $providerException) {
        $providerDiagnosticError = 'Brevo delivery events could not be retrieved.';
        error_log('Portal access Brevo diagnostics failed: ' . get_class($providerException));
    }
    portal_access_response(200, [
        'ok' => true,
        'matchCount' => count($records),
        'activeMatchCount' => count($activeMatches),
        'ambiguousActiveMatches' => count($activeMatches) > 1,
        'providerEvents' => $providerEvents,
        'providerDiagnosticError' => $providerDiagnosticError,
        'records' => $records,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Portal access diagnostics failed at ' . $diagnosticStage . ': ' . get_class($exception));
    portal_access_response(500, [
        'error' => 'Portal access diagnostics could not be completed safely.',
        'stage' => $diagnosticStage,
        'type' => get_class($exception),
    ]);
}
