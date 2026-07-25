<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/license_keys.php';
require_once dirname(__DIR__, 2) . '/includes/data_protection.php';
require_once dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';
require_once dirname(__DIR__, 2) . '/includes/license_management.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function device_entitlement_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    device_entitlement_response(['error' => 'Not found.'], 404);
}
try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    $installationUuid = strtolower(trim((string)($body['installationId'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installationUuid)) {
        device_entitlement_response(['error' => 'Invalid entitlement request.'], 422);
    }
    $installationToken = trim((string)($_SERVER['HTTP_X_INSTALLATION_TOKEN'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $installationToken)) {
        device_entitlement_response(['error' => 'This computer could not be authenticated.'], 401);
    }

    $pdo = database();
    ensure_self_service_commerce_schema($pdo);
    ensure_license_management_schema($pdo);
    $query = $pdo->prepare(
        "SELECT i.id AS installation_id,i.installation_uuid,i.customer_id,
                c.display_name,c.canonical_email,
                l.license_id,l.license_tier,l.control_state,l.maintenance_expires_at,
                l.entitlement_revision,b.binding_id,b.binding_state
         FROM installations i
         INNER JOIN customers c ON c.customer_id=i.customer_id
         LEFT JOIN license_device_bindings b
           ON b.installation_id=i.id AND b.binding_state='Active'
         LEFT JOIN issued_licenses l
           ON l.license_id=b.license_id AND l.customer_id=b.customer_id
         WHERE i.installation_uuid=:installation_uuid
           AND i.token_hash=UNHEX(SHA2(:installation_token,256))
           AND c.email_verified_at IS NOT NULL
         LIMIT 1"
    );
    $query->execute([
        'installation_uuid' => $installationUuid,
        'installation_token' => $installationToken,
    ]);
    $row = $query->fetch();
    if (!is_array($row)) {
        device_entitlement_response([
            'error' => 'This computer is not linked to a verified Customer Portal account.',
            'state' => 'Revoked',
        ], 409);
    }

    $deviceToken = null;
    $paidLicenseActive = !empty($row['license_id']) &&
        (string)$row['control_state'] === 'Enabled' &&
        (string)$row['binding_state'] === 'Active';
    if ($paidLicenseActive) {
        $deviceToken = issue_device_entitlement(
            (string)$row['license_id'],
            (string)$row['customer_id'],
            (string)$row['installation_uuid'],
            (string)$row['license_tier'],
            (string)$row['maintenance_expires_at'],
            max(1, (int)$row['entitlement_revision'])
        );
    }

    $promotionQuery = $pdo->prepare(
        "SELECT entitlement_token_ciphertext,entitlement_token_nonce,entitlement_token_tag
         FROM portal_promotions
         WHERE customer_id=:customer_id
           AND state='Active'
           AND starts_at<=UTC_TIMESTAMP(6)
           AND expires_at>UTC_TIMESTAMP(6)
           AND (
             installation_id=:installation_id
             OR (:license_id_check IS NOT NULL AND license_id=:license_id_match)
           )
         ORDER BY starts_at DESC
         LIMIT 1"
    );
    $promotionQuery->bindValue(':customer_id', (string)$row['customer_id']);
    $promotionQuery->bindValue(':installation_id', (int)$row['installation_id'], PDO::PARAM_INT);
    $promotionQuery->bindValue(
        ':license_id_check',
        $paidLicenseActive ? (string)$row['license_id'] : null,
        $paidLicenseActive ? PDO::PARAM_STR : PDO::PARAM_NULL
    );
    $promotionQuery->bindValue(
        ':license_id_match',
        $paidLicenseActive ? (string)$row['license_id'] : null,
        $paidLicenseActive ? PDO::PARAM_STR : PDO::PARAM_NULL
    );
    $promotionQuery->execute();
    $promotion = $promotionQuery->fetch();
    $promotionToken = is_array($promotion) ? reveal_promotion_token($promotion) : null;

    if ($deviceToken === null && $promotionToken === null) {
        device_entitlement_response([
            'error' => 'No active paid or promotional license is assigned to this computer.',
            'state' => 'Trial',
        ], 409);
    }

    device_entitlement_response([
        'state' => 'Active',
        'deviceEntitlement' => $deviceToken,
        'promotionEntitlement' => $promotionToken,
        'licenseId' => $paidLicenseActive ? (string)$row['license_id'] : null,
        'licenseTier' => $paidLicenseActive ? (string)$row['license_tier'] : 'Trial',
        'maintenanceExpiresAt' => $paidLicenseActive ? (string)$row['maintenance_expires_at'] : null,
        'customerName' => (string)$row['display_name'],
        'emailAddress' => (string)$row['canonical_email'],
        'revision' => $paidLicenseActive ? max(1, (int)$row['entitlement_revision']) : null,
    ]);
} catch (Throwable $exception) {
    error_log('Device entitlement service failed: ' . get_class($exception));
    device_entitlement_response(['error' => 'The licensing service is temporarily unavailable.'], 503);
}
