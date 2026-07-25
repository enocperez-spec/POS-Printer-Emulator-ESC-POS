<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';
require_once dirname(__DIR__, 2) . '/includes/license_management.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function device_unlink_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function device_unlink_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
        substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    device_unlink_response(['error' => 'Not found.'], 404);
}

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    $installationUuid = strtolower(trim((string)($body['installationId'] ?? '')));
    $reason = trim((string)($body['reason'] ?? 'Customer unlinked this computer from the application.'));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installationUuid) ||
        $reason === '' || mb_strlen($reason) > 300) {
        device_unlink_response(['error' => 'Invalid unlink request.'], 422);
    }
    $installationToken = trim((string)($_SERVER['HTTP_X_INSTALLATION_TOKEN'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $installationToken)) {
        device_unlink_response(['error' => 'This computer could not be authenticated.'], 401);
    }

    $pdo = database();
    ensure_self_service_commerce_schema($pdo);
    ensure_license_management_schema($pdo);
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        "SELECT i.id,i.customer_id,b.binding_id,b.license_id
         FROM installations i
         LEFT JOIN license_device_bindings b
           ON b.installation_id=i.id AND b.binding_state='Active'
         WHERE i.installation_uuid=:installation_uuid
           AND i.token_hash=UNHEX(SHA2(:installation_token,256))
         LIMIT 1 FOR UPDATE"
    );
    $query->execute([
        'installation_uuid' => $installationUuid,
        'installation_token' => $installationToken,
    ]);
    $installation = $query->fetch();
    if (!is_array($installation)) {
        $pdo->rollBack();
        device_unlink_response(['error' => 'This computer registration could not be authenticated.'], 401);
    }

    if (!empty($installation['binding_id'])) {
        $transferReference = device_unlink_uuid();
        $release = $pdo->prepare(
            "UPDATE license_device_bindings
             SET binding_state='Deactivated',deactivated_at=UTC_TIMESTAMP(6),
                 deactivation_reason=:reason,transfer_reference=:transfer_reference
             WHERE binding_id=:binding_id AND binding_state='Active'"
        );
        $release->execute([
            'reason' => $reason,
            'transfer_reference' => $transferReference,
            'binding_id' => (string)$installation['binding_id'],
        ]);
        $revision = $pdo->prepare(
            'UPDATE issued_licenses SET entitlement_revision=entitlement_revision+1
             WHERE license_id=:license_id'
        );
        $revision->execute(['license_id' => (string)$installation['license_id']]);
        $event = $pdo->prepare(
            "INSERT INTO license_activation_events(
                customer_id,license_id,installation_id,event_type,outcome,activation_method,event_summary
             ) VALUES(
                :customer_id,:license_id,:installation_id,
                'COMPUTER_UNLINKED','Succeeded','PortalLink',:summary
             )"
        );
        $event->execute([
            'customer_id' => $installation['customer_id'],
            'license_id' => $installation['license_id'],
            'installation_id' => (int)$installation['id'],
            'summary' => $reason,
        ]);
        if (!empty($installation['customer_id'])) {
            $action = $pdo->prepare(
                "INSERT INTO portal_device_actions(customer_id,installation_id,action,reason)
                 VALUES(:customer_id,:installation_id,'Deactivate',:reason)"
            );
            $action->execute([
                'customer_id' => $installation['customer_id'],
                'installation_id' => (int)$installation['id'],
                'reason' => $reason,
            ]);
        }
    }
    $clear = $pdo->prepare(
        "UPDATE installations
         SET license_id=NULL,license_mode='Trial'
         WHERE id=:installation_id"
    );
    $clear->execute(['installation_id' => (int)$installation['id']]);
    $pdo->commit();
    device_unlink_response([
        'state' => 'Unlinked',
        'message' => 'This computer was unlinked. Its license is now available for another approved computer.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Device unlink service failed: ' . get_class($exception));
    device_unlink_response(['error' => 'The licensing service is temporarily unavailable.'], 503);
}
