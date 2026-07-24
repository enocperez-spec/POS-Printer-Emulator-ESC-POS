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

    $diagnosticStage = 'record_details';
    $records = [];
    foreach ($matches as $match) {
        $customerId = (string)$match['customer_id'];
        $verification->execute(['customer_id' => $customerId]);
        $reset->execute(['customer_id' => $customerId]);
        $queue->execute(['customer_id' => $customerId]);
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
        ];
    }

    $activeMatches = array_values(array_filter(
        $records,
        static fn(array $record): bool => $record['customerStatus'] === 'Active'
    ));
    portal_access_response(200, [
        'ok' => true,
        'matchCount' => count($records),
        'activeMatchCount' => count($activeMatches),
        'ambiguousActiveMatches' => count($activeMatches) > 1,
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
