<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require_once dirname(__DIR__, 2) . '/includes/data_protection.php';
require dirname(__DIR__, 2) . '/includes/license_keys.php';
require dirname(__DIR__, 2) . '/includes/self_service_commerce_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function desktop_promotion_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function desktop_promotion_claim_hash(string $type, string $value): string
{
    return hash('sha256', $type . '|' . strtolower(trim($value)), true);
}

function desktop_promotion_tiers(string $previousTier): array
{
    return match ($previousTier) {
        'Trial' => ['Lite', 'Pro', 'Enterprise'],
        'Lite' => ['Pro', 'Enterprise'],
        'Pro' => ['Enterprise'],
        default => [],
    };
}

function desktop_promotion_rate_limit(PDO $pdo, string $installationUuid): void
{
    $bucket = hash('sha256', 'desktop-promotion|' . $installationUuid, true);
    $update = $pdo->prepare(
        'INSERT INTO customer_api_rate_limits(bucket_hash,hits,reset_at)
         VALUES(:bucket,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))
         ON DUPLICATE KEY UPDATE
           hits=IF(reset_at<=UTC_TIMESTAMP(6),1,hits+1),
           reset_at=IF(reset_at<=UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),reset_at)'
    );
    $update->bindValue(':bucket', $bucket, PDO::PARAM_LOB);
    $update->execute();
    $read = $pdo->prepare(
        'SELECT hits FROM customer_api_rate_limits WHERE bucket_hash=:bucket LIMIT 1'
    );
    $read->bindValue(':bucket', $bucket, PDO::PARAM_LOB);
    $read->execute();
    if ((int)$read->fetchColumn() > 30) {
        desktop_promotion_response(
            ['error' => 'Too many promotional-trial requests. Please wait a few minutes and try again.'],
            429
        );
    }
}

function desktop_promotion_offer(
    array $installation,
    ?array $existing,
    bool $claimed
): array {
    $previousTier = (string)$installation['previous_tier'];
    $eligibleTiers = desktop_promotion_tiers($previousTier);
    $purchaseUrl = 'https://buy.posprinteremulator.com/';
    if ($existing !== null) {
        $expires = new DateTimeImmutable((string)$existing['expires_at'], new DateTimeZone('UTC'));
        $active = (string)$existing['state'] === 'Active' && $expires > new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [
            'state' => $active ? 'Active' : 'Used',
            'eligibleTiers' => [],
            'previousTier' => (string)$existing['previous_tier'],
            'grantedTier' => (string)$existing['granted_tier'],
            'startsAt' => (new DateTimeImmutable((string)$existing['starts_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            'expiresAt' => $expires->format(DATE_ATOM),
            'purchaseUrl' => $purchaseUrl . '?tier=' . rawurlencode((string)$existing['granted_tier']),
            'message' => $active
                ? 'Your Five-Day Promotional Trial is active.'
                : 'Your Five-Day Promotional Trial has already been used. Please select a license edition to continue using the application.',
        ];
    }
    if (empty($installation['email_verified_at']) || empty($installation['portal_customer_id'])) {
        return [
            'state' => 'VerificationRequired',
            'eligibleTiers' => $eligibleTiers,
            'previousTier' => $previousTier,
            'verificationUrl' => 'https://userportal.posprinteremulator.com/',
            'purchaseUrl' => $purchaseUrl,
            'message' => 'Verify your customer email in the Customer Portal before starting the Five-Day Promotional Trial.',
        ];
    }
    if ($claimed) {
        return [
            'state' => 'Used',
            'eligibleTiers' => [],
            'previousTier' => $previousTier,
            'purchaseUrl' => $purchaseUrl,
            'message' => 'Your Five-Day Promotional Trial has already been used. Please select a license edition to continue using the application.',
        ];
    }
    if ($eligibleTiers === []) {
        return [
            'state' => 'NotApplicable',
            'eligibleTiers' => [],
            'previousTier' => $previousTier,
            'purchaseUrl' => $purchaseUrl,
            'message' => 'Enterprise already includes every available feature.',
        ];
    }
    return [
        'state' => 'Eligible',
        'eligibleTiers' => $eligibleTiers,
        'previousTier' => $previousTier,
        'purchaseUrl' => $purchaseUrl,
        'message' => 'Choose one edition to evaluate for five consecutive days. This promotion can be used only once.',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    desktop_promotion_response(['error' => 'Not found.'], 404);
}

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new InvalidArgumentException('Invalid request.');
    }
    $action = strtolower(trim((string)($body['action'] ?? 'status')));
    $installationUuid = strtolower(trim((string)($body['installationId'] ?? '')));
    $installationToken = trim((string)($_SERVER['HTTP_X_INSTALLATION_TOKEN'] ?? ''));
    if (!in_array($action, ['status', 'start'], true) ||
        !preg_match('/^[0-9a-f-]{36}$/', $installationUuid) ||
        !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $installationToken)) {
        desktop_promotion_response(['error' => 'Authentication required.'], 401);
    }

    $pdo = database();
    ensure_self_service_commerce_schema($pdo);
    $findInstallation = $pdo->prepare(
        "SELECT i.id,i.installation_uuid,i.customer_id,i.license_mode,i.license_id,i.portal_deactivated_at,
                c.email_verified_at,c.status customer_status,
                a.customer_id portal_customer_id,
                COALESCE(l.license_tier,'Trial') previous_tier,l.control_state
         FROM installations i
         LEFT JOIN customers c ON c.customer_id=i.customer_id
         LEFT JOIN portal_accounts a ON a.customer_id=i.customer_id
         LEFT JOIN issued_licenses l ON l.license_id=i.license_id AND l.customer_id=i.customer_id
         WHERE i.installation_uuid=:uuid AND i.token_hash=UNHEX(SHA2(:token,256))
         LIMIT 1"
    );
    $findInstallation->execute(['uuid' => $installationUuid, 'token' => $installationToken]);
    $installation = $findInstallation->fetch();
    if (!is_array($installation) || !empty($installation['portal_deactivated_at'])) {
        usleep(250000);
        desktop_promotion_response(['error' => 'Authentication failed.'], 401);
    }
    desktop_promotion_rate_limit($pdo, $installationUuid);
    if (empty($installation['customer_id']) || (string)$installation['customer_status'] !== 'Active') {
        desktop_promotion_response(['error' => 'An active customer registration is required.'], 409);
    }
    if (!empty($installation['license_id']) && (string)$installation['control_state'] !== 'Enabled') {
        desktop_promotion_response(['error' => 'The permanent license is not eligible for promotional access.'], 409);
    }

    $subjectType = empty($installation['license_id']) ? 'Installation' : 'License';
    $subjectId = empty($installation['license_id'])
        ? (string)$installation['installation_uuid']
        : (string)$installation['license_id'];
    $customerId = (string)$installation['customer_id'];

    $existingQuery = $pdo->prepare(
        'SELECT promotion_id,previous_tier,granted_tier,state,starts_at,expires_at
         FROM portal_promotions WHERE customer_id=:customer_id
         ORDER BY created_at DESC LIMIT 1'
    );
    $existingQuery->execute(['customer_id' => $customerId]);
    $existing = $existingQuery->fetch();
    $claimQuery = $pdo->prepare(
        "SELECT 1 FROM portal_promotion_claims
         WHERE claim_type='Customer' AND claim_hash=:hash LIMIT 1"
    );
    $claimQuery->bindValue(':hash', desktop_promotion_claim_hash('Customer', $customerId), PDO::PARAM_LOB);
    $claimQuery->execute();
    $claimed = (bool)$claimQuery->fetchColumn();
    $hasException = false;
    if ($claimed) {
        $exceptionAvailable = $pdo->prepare(
            'SELECT 1 FROM portal_promotion_exceptions
             WHERE customer_id=:customer_id AND consumed_at IS NULL LIMIT 1'
        );
        $exceptionAvailable->execute(['customer_id' => $customerId]);
        $hasException = (bool)$exceptionAvailable->fetchColumn();
    }
    $offer = desktop_promotion_offer(
        $installation,
        is_array($existing) && !$hasException ? $existing : null,
        $claimed && !$hasException
    );

    if ($action === 'status') {
        desktop_promotion_response(['ok' => true] + $offer);
    }

    $grantedTier = ucfirst(strtolower(trim((string)($body['grantedTier'] ?? ''))));
    $requestId = strtolower(trim((string)($body['requestId'] ?? '')));
    if (!in_array($grantedTier, ['Lite', 'Pro', 'Enterprise'], true) ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $requestId)) {
        desktop_promotion_response(['error' => 'Choose an available promotional edition.'], 422);
    }

    $pdo->beginTransaction();
    $customerLock = $pdo->prepare(
        'SELECT customer_id,email_verified_at,status FROM customers WHERE customer_id=:id FOR UPDATE'
    );
    $customerLock->execute(['id' => $customerId]);
    $lockedCustomer = $customerLock->fetch();
    if (!is_array($lockedCustomer) || empty($lockedCustomer['email_verified_at']) ||
        (string)$lockedCustomer['status'] !== 'Active') {
        throw new DomainException('A verified active customer account is required.');
    }

    $retryQuery = $pdo->prepare(
        'SELECT promotion_id,customer_id,license_id,installation_id,previous_tier,granted_tier,
                state,starts_at,expires_at,entitlement_token_ciphertext,entitlement_token_nonce,entitlement_token_tag
         FROM portal_promotions WHERE promotion_id=:id FOR UPDATE'
    );
    $retryQuery->execute(['id' => $requestId]);
    $retry = $retryQuery->fetch();
    if (is_array($retry)) {
        $matchesSubject = ($subjectType === 'License' && (string)$retry['license_id'] === $subjectId) ||
            ($subjectType === 'Installation' && (int)$retry['installation_id'] === (int)$installation['id']);
        if ((string)$retry['customer_id'] !== $customerId || !$matchesSubject ||
            (string)$retry['granted_tier'] !== $grantedTier) {
            throw new DomainException('This promotional request identifier is already in use.');
        }
        $token = reveal_promotion_token($retry);
        $pdo->commit();
        desktop_promotion_response([
            'ok' => true,
            'promotionId' => $requestId,
            'previousTier' => (string)$retry['previous_tier'],
            'grantedTier' => (string)$retry['granted_tier'],
            'startsAt' => (new DateTimeImmutable((string)$retry['starts_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            'expiresAt' => (new DateTimeImmutable((string)$retry['expires_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            'entitlementToken' => $token,
        ]);
    }
    if (($offer['state'] ?? '') !== 'Eligible' ||
        !in_array($grantedTier, $offer['eligibleTiers'], true)) {
        throw new DomainException((string)$offer['message']);
    }

    $claimValues = [
        'Customer' => $customerId,
        'Account' => $customerId,
        $subjectType => $subjectId,
    ];
    $checkClaim = $pdo->prepare(
        'SELECT 1 FROM portal_promotion_claims WHERE claim_type=:type AND claim_hash=:hash LIMIT 1'
    );
    $alreadyClaimed = false;
    foreach ($claimValues as $type => $value) {
        $checkClaim->bindValue(':type', $type);
        $checkClaim->bindValue(':hash', desktop_promotion_claim_hash($type, $value), PDO::PARAM_LOB);
        $checkClaim->execute();
        $alreadyClaimed = $alreadyClaimed || (bool)$checkClaim->fetchColumn();
    }
    $exception = null;
    if ($alreadyClaimed) {
        $exceptionQuery = $pdo->prepare(
            'SELECT id,reason FROM portal_promotion_exceptions
             WHERE customer_id=:customer_id AND consumed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $exceptionQuery->execute(['customer_id' => $customerId]);
        $exception = $exceptionQuery->fetch();
        if (!is_array($exception)) {
            throw new DomainException('Your Five-Day Promotional Trial has already been used. Please select a license edition to continue using the application.');
        }
    }

    $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $issuedAt->modify('+5 days');
    $token = issue_promotion_token(
        $requestId,
        $subjectType,
        $subjectId,
        (string)$installation['previous_tier'],
        $grantedTier,
        $issuedAt,
        $expiresAt
    );
    $protected = protect_promotion_token($token);
    $insert = $pdo->prepare(
        'INSERT INTO portal_promotions
            (promotion_id,customer_id,license_id,installation_id,exception_id,previous_tier,granted_tier,
             entitlement_token_hash,entitlement_token_ciphertext,entitlement_token_nonce,entitlement_token_tag,
             starts_at,expires_at,created_by,exception_reason)
         VALUES(:promotion_id,:customer_id,:license_id,:installation_id,:exception_id,:previous_tier,:granted_tier,
                :token_hash,:token_ciphertext,:token_nonce,:token_tag,:starts_at,:expires_at,
                \'Desktop Application\',:exception_reason)'
    );
    $licenseId = $subjectType === 'License' ? $subjectId : null;
    $installationId = $subjectType === 'Installation' ? (int)$installation['id'] : null;
    $insert->bindValue(':promotion_id', $requestId);
    $insert->bindValue(':customer_id', $customerId);
    $insert->bindValue(':license_id', $licenseId);
    $insert->bindValue(':installation_id', $installationId, $installationId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insert->bindValue(':exception_id', is_array($exception) ? (int)$exception['id'] : null, is_array($exception) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $insert->bindValue(':previous_tier', (string)$installation['previous_tier']);
    $insert->bindValue(':granted_tier', $grantedTier);
    $insert->bindValue(':token_hash', hash('sha256', $token, true), PDO::PARAM_LOB);
    $insert->bindValue(':token_ciphertext', $protected['ciphertext'], PDO::PARAM_LOB);
    $insert->bindValue(':token_nonce', $protected['nonce'], PDO::PARAM_LOB);
    $insert->bindValue(':token_tag', $protected['tag'], PDO::PARAM_LOB);
    $insert->bindValue(':starts_at', $issuedAt->format('Y-m-d H:i:s.u'));
    $insert->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:s.u'));
    $insert->bindValue(':exception_reason', is_array($exception) ? (string)$exception['reason'] : null);
    $insert->execute();

    if (!$alreadyClaimed) {
        $claimInsert = $pdo->prepare(
            'INSERT INTO portal_promotion_claims(promotion_id,claim_type,claim_hash)
             VALUES(:promotion_id,:type,:hash)'
        );
        foreach ($claimValues as $type => $value) {
            $claimInsert->bindValue(':promotion_id', $requestId);
            $claimInsert->bindValue(':type', $type);
            $claimInsert->bindValue(':hash', desktop_promotion_claim_hash($type, $value), PDO::PARAM_LOB);
            $claimInsert->execute();
        }
    }
    if (is_array($exception)) {
        $consume = $pdo->prepare(
            'UPDATE portal_promotion_exceptions
             SET consumed_at=UTC_TIMESTAMP(6),consumed_by_promotion_id=:promotion_id
             WHERE id=:id AND consumed_at IS NULL'
        );
        $consume->execute(['promotion_id' => $requestId, 'id' => $exception['id']]);
    }
    $event = $pdo->prepare(
        "INSERT INTO portal_promotion_events
            (promotion_id,event_type,actor,previous_state,new_state,reason)
         VALUES(:promotion_id,'PROMOTION_STARTED','Desktop Application','Permanent','Active',:reason)"
    );
    $event->execute([
        'promotion_id' => $requestId,
        'reason' => is_array($exception) ? 'Admin-approved repeat exception.' : 'First verified customer promotion.',
    ]);
    $pdo->commit();

    desktop_promotion_response([
        'ok' => true,
        'promotionId' => $requestId,
        'previousTier' => (string)$installation['previous_tier'],
        'grantedTier' => $grantedTier,
        'startsAt' => $issuedAt->format(DATE_ATOM),
        'expiresAt' => $expiresAt->format(DATE_ATOM),
        'entitlementToken' => $token,
    ]);
} catch (InvalidArgumentException $exception) {
    desktop_promotion_response(['error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    desktop_promotion_response(['error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Desktop promotion failed: ' . get_class($exception));
    desktop_promotion_response(['error' => 'The promotional trial could not be started. No license was changed.'], 500);
}
