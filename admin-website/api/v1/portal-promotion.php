<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require dirname(__DIR__, 2) . '/includes/license_keys.php';
require dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function promotion_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function promotion_require_service_token(): void
{
    $authorization = crm_authorization_header();
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
        promotion_response(['error' => 'Authentication required.'], 401);
    }
    $expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
    if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) ||
        !hash_equals($expectedHash, hash('sha256', $match[1]))) {
        usleep(250000);
        promotion_response(['error' => 'Authentication failed.'], 401);
    }
}

function promotion_claim_hash(string $type, string $value): string
{
    return hash('sha256', $type . '|' . strtolower(trim($value)), true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    promotion_response(['error' => 'Not found.'], 404);
}
promotion_require_service_token();

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        promotion_response(['error' => 'Invalid request.'], 400);
    }
    $customerId = strtolower(trim((string)($body['customerId'] ?? '')));
    $licenseId = strtolower(trim((string)($body['licenseId'] ?? '')));
    $installationId = filter_var(
        $body['installationId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $grantedTier = ucfirst(strtolower(trim((string)($body['grantedTier'] ?? 'Enterprise'))));
    if (!preg_match('/^[0-9a-f-]{36}$/', $customerId) ||
        !in_array($grantedTier, ['Lite', 'Pro', 'Enterprise'], true) ||
        (($licenseId === '') === ($installationId === false))) {
        promotion_response(['error' => 'Invalid promotion selection.'], 422);
    }

    $pdo = database();
    ensure_self_service_commerce_schema($pdo);
    $pdo->beginTransaction();
    $accountQuery = $pdo->prepare(
        "SELECT a.customer_id,c.email_verified_at,c.status
         FROM portal_accounts a INNER JOIN customers c ON c.customer_id=a.customer_id
         WHERE a.customer_id=:customer_id FOR UPDATE"
    );
    $accountQuery->execute(['customer_id' => $customerId]);
    $account = $accountQuery->fetch();
    if (!is_array($account) || empty($account['email_verified_at']) || (string)$account['status'] !== 'Active') {
        throw new DomainException('A verified active Customer Portal account is required.');
    }

    $previousTier = 'Trial';
    $subjectType = 'Installation';
    $subjectId = '';
    $ownedLicenseId = null;
    $ownedInstallationId = null;
    if ($licenseId !== '') {
        if (!preg_match('/^[0-9a-f-]{36}$/', $licenseId)) {
            throw new DomainException('The selected license is invalid.');
        }
        $find = $pdo->prepare(
            "SELECT license_id,license_tier FROM issued_licenses
             WHERE license_id=:license_id AND customer_id=:customer_id AND control_state='Enabled' FOR UPDATE"
        );
        $find->execute(['license_id' => $licenseId, 'customer_id' => $customerId]);
        $license = $find->fetch();
        if (!is_array($license)) {
            throw new DomainException('The selected permanent license is not eligible.');
        }
        $previousTier = (string)$license['license_tier'];
        $subjectType = 'License';
        $subjectId = (string)$license['license_id'];
        $ownedLicenseId = $subjectId;
    } else {
        $find = $pdo->prepare(
            "SELECT id,installation_uuid FROM installations
             WHERE id=:id AND customer_id=:customer_id AND license_mode='Trial'
               AND portal_deactivated_at IS NULL FOR UPDATE"
        );
        $find->execute(['id' => $installationId, 'customer_id' => $customerId]);
        $installation = $find->fetch();
        if (!is_array($installation)) {
            throw new DomainException('The selected Trial installation is not eligible.');
        }
        $subjectId = (string)$installation['installation_uuid'];
        $ownedInstallationId = (int)$installation['id'];
    }
    $rank = ['Trial' => 0, 'Lite' => 1, 'Pro' => 2, 'Enterprise' => 3];
    if ($rank[$grantedTier] <= ($rank[$previousTier] ?? 99)) {
        throw new DomainException('Choose a promotional tier above the current permanent tier.');
    }

    $claimValues = [
        'Customer' => $customerId,
        'Account' => $customerId,
        $subjectType => $subjectId,
    ];
    $claimed = false;
    $claimCheck = $pdo->prepare(
        'SELECT 1 FROM portal_promotion_claims WHERE claim_type=:type AND claim_hash=:hash LIMIT 1'
    );
    foreach ($claimValues as $type => $value) {
        $claimCheck->bindValue(':type', $type);
        $claimCheck->bindValue(':hash', promotion_claim_hash($type, $value), PDO::PARAM_LOB);
        $claimCheck->execute();
        if ($claimCheck->fetchColumn()) {
            $claimed = true;
            break;
        }
    }

    $exception = null;
    if ($claimed) {
        $exceptionQuery = $pdo->prepare(
            'SELECT id,reason FROM portal_promotion_exceptions
             WHERE customer_id=:customer_id AND consumed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $exceptionQuery->execute(['customer_id' => $customerId]);
        $exception = $exceptionQuery->fetch();
        if (!is_array($exception)) {
            throw new DomainException('This customer has already used the available five-day promotion.');
        }
    }

    $promotionId = portal_uuid();
    $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $issuedAt->modify('+5 days');
    $token = issue_promotion_token(
        $promotionId,
        $subjectType,
        $subjectId,
        $previousTier,
        $grantedTier,
        $issuedAt,
        $expiresAt
    );
    $insert = $pdo->prepare(
        'INSERT INTO portal_promotions
            (promotion_id,customer_id,license_id,installation_id,exception_id,previous_tier,granted_tier,
             entitlement_token_hash,starts_at,expires_at,created_by,exception_reason)
         VALUES(:promotion_id,:customer_id,:license_id,:installation_id,:exception_id,:previous_tier,:granted_tier,
                :token_hash,:starts_at,:expires_at,\'Customer Portal\',:exception_reason)'
    );
    $insert->bindValue(':promotion_id', $promotionId);
    $insert->bindValue(':customer_id', $customerId);
    $insert->bindValue(':license_id', $ownedLicenseId);
    $insert->bindValue(':installation_id', $ownedInstallationId, $ownedInstallationId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insert->bindValue(':exception_id', is_array($exception) ? (int)$exception['id'] : null, is_array($exception) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $insert->bindValue(':previous_tier', $previousTier);
    $insert->bindValue(':granted_tier', $grantedTier);
    $insert->bindValue(':token_hash', hash('sha256', $token, true), PDO::PARAM_LOB);
    $insert->bindValue(':starts_at', $issuedAt->format('Y-m-d H:i:s.u'));
    $insert->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:s.u'));
    $insert->bindValue(':exception_reason', is_array($exception) ? (string)$exception['reason'] : null);
    $insert->execute();

    $claimInsert = $pdo->prepare(
        'INSERT INTO portal_promotion_claims(promotion_id,claim_type,claim_hash)
         VALUES(:promotion_id,:type,:hash)'
    );
    if (!$claimed) {
        foreach ($claimValues as $type => $value) {
            $claimInsert->bindValue(':promotion_id', $promotionId);
            $claimInsert->bindValue(':type', $type);
            $claimInsert->bindValue(':hash', promotion_claim_hash($type, $value), PDO::PARAM_LOB);
            $claimInsert->execute();
        }
    }
    if (is_array($exception)) {
        $consume = $pdo->prepare(
            'UPDATE portal_promotion_exceptions
             SET consumed_at=UTC_TIMESTAMP(6),consumed_by_promotion_id=:promotion_id
             WHERE id=:id AND consumed_at IS NULL'
        );
        $consume->execute(['promotion_id' => $promotionId, 'id' => $exception['id']]);
    }
    $event = $pdo->prepare(
        "INSERT INTO portal_promotion_events
            (promotion_id,event_type,actor,previous_state,new_state,reason)
         VALUES(:promotion_id,'PROMOTION_STARTED','Customer','Permanent','Active',:reason)"
    );
    $event->execute([
        'promotion_id' => $promotionId,
        'reason' => is_array($exception) ? 'Admin-approved repeat exception.' : 'First verified customer promotion.',
    ]);
    $pdo->commit();
    promotion_response([
        'ok' => true,
        'promotionId' => $promotionId,
        'previousTier' => $previousTier,
        'grantedTier' => $grantedTier,
        'expiresAt' => $expiresAt->format(DATE_ATOM),
        'entitlementToken' => $token,
    ]);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    promotion_response(['error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Portal promotion failed: ' . get_class($exception));
    promotion_response(['error' => 'The promotion could not be started. No entitlement was changed.'], 500);
}
