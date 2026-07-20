<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/license_keys.php';
require dirname(__DIR__) . '/includes/license_management.php';
require dirname(__DIR__) . '/includes/purchase_site.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function maintenance_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function maintenance_request_body(): array
{
    try {
        $body = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        maintenance_response(['error'=>'Invalid request.'],400);
    }
    if (!is_array($body)) {
        maintenance_response(['error'=>'Invalid request.'],400);
    }
    return $body;
}

function require_maintenance_service_token(): void
{
    $provided = (string)($_SERVER['HTTP_X_PPE_ADMIN_TOKEN'] ?? '');
    $expected = (string)(purchase_site_config()['maintenance_token'] ?? '');
    if ($expected === '' || !hash_equals($expected,$provided)) {
        maintenance_response(['error'=>'Unauthorized.'],401);
    }
}

function enforce_maintenance_refresh_rate_limit(PDO $pdo, int $limit = 60): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = hash('sha256','maintenance-refresh|' . $ip,true);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM maintenance_refresh_rate_limits WHERE reset_at <= UTC_TIMESTAMP(6)');
        $find = $pdo->prepare('SELECT hits, reset_at FROM maintenance_refresh_rate_limits WHERE bucket_hash = :bucket FOR UPDATE');
        $find->bindValue(':bucket',$bucket,PDO::PARAM_LOB);
        $find->execute();
        $row = $find->fetch();
        if (!is_array($row)) {
            $insert = $pdo->prepare('INSERT INTO maintenance_refresh_rate_limits (bucket_hash,hits,reset_at) VALUES (:bucket,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR))');
            $insert->bindValue(':bucket',$bucket,PDO::PARAM_LOB);
            $insert->execute();
        } elseif ((int)$row['hits'] >= $limit) {
            $pdo->rollBack();
            maintenance_response(['error'=>'Too many entitlement refresh attempts. Try again later.'],429);
        } else {
            $update = $pdo->prepare('UPDATE maintenance_refresh_rate_limits SET hits = hits + 1 WHERE bucket_hash = :bucket');
            $update->bindValue(':bucket',$bucket,PDO::PARAM_LOB);
            $update->execute();
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function find_maintenance_license(PDO $pdo, string $licenseId, string $registrationDigest): ?array
{
    $licenseId = canonical_license_uuid($licenseId);
    $registrationDigest = strtolower(trim($registrationDigest));
    if (!preg_match('/^[0-9a-f]{64}$/',$registrationDigest)) {
        throw new InvalidArgumentException('The registration digest is invalid.');
    }
    $query = $pdo->prepare(
        'SELECT license_id, customer_name, email_address, license_tier, control_state,
                maintenance_expires_at, maintenance_revoked_at
         FROM issued_licenses WHERE license_id = :license_id LIMIT 1'
    );
    $query->execute(['license_id'=>$licenseId]);
    $license = $query->fetch();
    if (!is_array($license) ||
        !hash_equals(maintenance_registration_digest((string)$license['customer_name'],(string)$license['email_address']),$registrationDigest)) {
        return null;
    }
    return $license;
}

function maintenance_iso(?string $value): ?string
{
    if ($value === null || trim($value) === '') return null;
    return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    maintenance_response(['error'=>'Method not allowed.'],405);
}

try {
    $body = maintenance_request_body();
    $action = (string)($body['action'] ?? 'refresh');
    if (in_array($action,['apply-renewal','prepare-renewal'],true)) {
        require_maintenance_service_token();
    } elseif ($action !== 'refresh') {
        maintenance_response(['error'=>'Invalid entitlement action.'],422);
    }
    $pdo = database();
    ensure_license_management_schema($pdo);

    if ($action === 'apply-renewal') {
        $result = apply_paid_maintenance_renewal(
            $pdo,
            (string)($body['licenseId'] ?? ''),
            (string)($body['tier'] ?? ''),
            (string)($body['registrationDigest'] ?? ''),
            (string)($body['capturedAt'] ?? ''),
            'renewal:' . (string)($body['orderReference'] ?? ''),
            'verified-paypal-renewal'
        );
        maintenance_response([
            'status'=>'active',
            'serverTime'=>gmdate('Y-m-d\TH:i:s\Z'),
            'licenseId'=>$result['license_id'],
            'tier'=>$result['license_tier'],
            'maintenanceExpiresAt'=>maintenance_iso($result['maintenance_expires_at']),
            'maintenanceToken'=>$result['maintenance_token'],
            'renewalUrl'=>'https://buy.posprinteremulator.com/?product=maintenance&tier=' . rawurlencode((string)$result['license_tier']),
            'idempotent'=>$result['idempotent'],
        ]);
    }

    if ($action === 'refresh') {
        enforce_maintenance_refresh_rate_limit($pdo);
    }

    $licenseId = canonical_license_uuid((string)($body['licenseId'] ?? ''));
    $license = find_maintenance_license($pdo,$licenseId,(string)($body['registrationDigest'] ?? ''));
    if ($license === null) {
        maintenance_response([
            'status'=>'not_found',
            'serverTime'=>gmdate('Y-m-d\TH:i:s\Z'),
            'licenseId'=>$licenseId,
            'tier'=>null,
            'maintenanceExpiresAt'=>null,
            'renewalUrl'=>'https://buy.posprinteremulator.com/?product=maintenance',
        ]);
    }

    $status = maintenance_status($license);
    $tier = (string)$license['license_tier'];
    $expiration = (string)$license['maintenance_expires_at'];
    $response = [
        'status'=>$status,
        'serverTime'=>gmdate('Y-m-d\TH:i:s\Z'),
        'licenseId'=>$licenseId,
        'tier'=>$tier,
        'maintenanceExpiresAt'=>maintenance_iso($expiration),
        'renewalUrl'=>'https://buy.posprinteremulator.com/?product=maintenance&tier=' . rawurlencode($tier),
    ];
    if ($action === 'prepare-renewal') {
        $response['renewalEligible'] = hash_equals('Enabled',(string)$license['control_state']) && empty($license['maintenance_revoked_at']);
    }
    if ($status === 'active') {
        $response['maintenanceToken'] = issue_maintenance_token($licenseId,$tier,$expiration);
    }
    maintenance_response($response);
} catch (InvalidArgumentException $exception) {
    maintenance_response(['error'=>$exception->getMessage()],422);
} catch (DomainException $exception) {
    maintenance_response(['error'=>$exception->getMessage()],409);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator maintenance entitlement failure: ' . $exception->getMessage());
    maintenance_response(['error'=>'The maintenance entitlement could not be refreshed.'],500);
}
