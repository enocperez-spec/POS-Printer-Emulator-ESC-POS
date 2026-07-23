<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function crm_api_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    crm_api_response(['error' => 'Not found.'], 404);
}

$authorization = crm_authorization_header();
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
    crm_api_response(['error' => 'Authentication required.'], 401);
}
$providedToken = $match[1];
$expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) || !hash_equals($expectedHash, hash('sha256', $providedToken))) {
    usleep(250000);
    crm_api_response(['error' => 'Authentication failed.'], 401);
}

$pdo = database();
ensure_customer_crm_schema($pdo);
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$bucket = hash('sha256', 'crm-v1|' . $providedToken . '|' . $remote, true);
$pdo->beginTransaction();
try {
    $find = $pdo->prepare('SELECT hits,reset_at FROM customer_api_rate_limits WHERE bucket_hash=:bucket FOR UPDATE');
    $find->bindValue('bucket', $bucket, PDO::PARAM_LOB);
    $find->execute();
    $limit = $find->fetch();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $reset = is_array($limit) ? new DateTimeImmutable((string)$limit['reset_at'], new DateTimeZone('UTC')) : $now;
    $hits = is_array($limit) && $reset > $now ? (int)$limit['hits'] : 0;
    if ($hits >= 300) {
        $pdo->commit();
        header('Retry-After: ' . max(1, $reset->getTimestamp() - $now->getTimestamp()));
        crm_api_response(['error' => 'Rate limit exceeded.'], 429);
    }
    $save = $pdo->prepare(
        'INSERT INTO customer_api_rate_limits(bucket_hash,hits,reset_at) VALUES(:bucket,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE))
         ON DUPLICATE KEY UPDATE hits=IF(reset_at>UTC_TIMESTAMP(6),hits+1,1),reset_at=IF(reset_at>UTC_TIMESTAMP(6),reset_at,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE))'
    );
    $save->bindValue('bucket', $bucket, PDO::PARAM_LOB);
    $save->execute();
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('POS Printer Emulator CRM API rate-limit failure: ' . $exception->getMessage());
    crm_api_response(['error' => 'Service temporarily unavailable.'], 503);
}

$customerId = strtolower(trim((string)($_GET['id'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $customerId)) {
    crm_api_response(['error' => 'Not found.'], 404);
}
$query = $pdo->prepare(
    "SELECT c.customer_id,c.display_name,c.canonical_email,c.email_verified_at,c.status,c.merged_into_customer_id,
            (SELECT COUNT(*) FROM installations i WHERE i.customer_id=c.customer_id) installation_count,
            (SELECT COUNT(*) FROM issued_licenses l WHERE l.customer_id=c.customer_id AND l.control_state<>'Deleted') license_count,
            (SELECT COUNT(*) FROM support_requests s WHERE s.customer_id=c.customer_id) support_count
     FROM customers c WHERE c.customer_id=:id LIMIT 1"
);
$query->execute(['id' => $customerId]);
$customer = $query->fetch();
if (!is_array($customer)) {
    crm_api_response(['error' => 'Not found.'], 404);
}
$licenses = $pdo->prepare("SELECT license_id,license_tier,control_state,activation_key_ending,maintenance_expires_at FROM issued_licenses WHERE customer_id=:id AND control_state<>'Deleted' ORDER BY issued_at DESC");
$licenses->execute(['id' => $customerId]);
$consent = $pdo->prepare(
    'SELECT consent_type,consent_state,policy_version,recorded_at FROM customer_consents cc
     WHERE customer_id=:id AND id=(SELECT MAX(latest.id) FROM customer_consents latest WHERE latest.customer_id=cc.customer_id AND latest.consent_type=cc.consent_type)'
);
$consent->execute(['id' => $customerId]);
crm_api_response([
    'apiVersion' => '2026-07-23',
    'customer' => [
        'id' => $customer['customer_id'], 'displayName' => $customer['display_name'],
        'email' => $customer['canonical_email'], 'emailVerified' => !empty($customer['email_verified_at']),
        'status' => $customer['status'], 'mergedIntoCustomerId' => $customer['merged_into_customer_id'],
        'installationCount' => (int)$customer['installation_count'], 'licenseCount' => (int)$customer['license_count'],
        'supportRequestCount' => (int)$customer['support_count'],
    ],
    'licenses' => array_map(static fn(array $license): array => [
        'id' => $license['license_id'], 'tier' => $license['license_tier'], 'state' => $license['control_state'],
        'keyEnding' => $license['activation_key_ending'], 'maintenanceExpiresAt' => $license['maintenance_expires_at'],
    ], $licenses->fetchAll()),
    'consent' => $consent->fetchAll(),
]);
