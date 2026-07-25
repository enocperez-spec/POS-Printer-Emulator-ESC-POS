<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

const ACCOUNT_LINK_LIFETIME_MINUTES = 10;

function account_link_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
        substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function account_link_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function account_link_user_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($index = 0; $index < 8; $index++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function account_link_installation(PDO $pdo, array $body): array
{
    $installationUuid = strtolower(required_string($body, 'installationId', 36));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $installationUuid)) {
        throw new InvalidArgumentException('Invalid installationId.');
    }
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $installationToken = (string)($_SERVER['HTTP_X_INSTALLATION_TOKEN'] ?? '');
    if ($installationToken === '' && preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/', $authorization, $matches)) {
        $installationToken = $matches[1];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $installationToken)) {
        json_response(['error' => 'This computer must complete secure installation registration first.'], 401);
    }
    $query = $pdo->prepare(
        'SELECT id,installation_uuid,device_label,app_version,windows_version,portal_deactivated_at
         FROM installations
         WHERE installation_uuid=:installation_uuid
           AND token_hash=UNHEX(SHA2(:installation_token,256))
         LIMIT 1'
    );
    $query->execute([
        'installation_uuid' => $installationUuid,
        'installation_token' => $installationToken,
    ]);
    $installation = $query->fetch();
    if (!is_array($installation)) {
        json_response(['error' => 'This computer registration could not be authenticated.'], 401);
    }
    if (!empty($installation['portal_deactivated_at'])) {
        json_response([
            'error' => 'This computer was deactivated in the Customer Portal. Start again from the replacement computer or contact support.',
        ], 409);
    }
    return $installation;
}

function account_link_event(
    PDO $pdo,
    ?string $customerId,
    ?string $licenseId,
    int $installationId,
    string $linkId,
    string $eventType,
    string $outcome,
    string $summary
): void {
    $insert = $pdo->prepare(
        'INSERT INTO license_activation_events
            (customer_id,license_id,installation_id,link_id,event_type,outcome,activation_method,event_summary)
         VALUES(:customer_id,:license_id,:installation_id,:link_id,:event_type,:outcome,\'PortalLink\',:summary)'
    );
    $insert->execute([
        'customer_id' => $customerId,
        'license_id' => $licenseId,
        'installation_id' => $installationId,
        'link_id' => $linkId,
        'event_type' => $eventType,
        'outcome' => $outcome,
        'summary' => $summary,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

try {
    $body = json_request();
    $action = strtolower(required_string($body, 'action', 20));
    if (!in_array($action, ['start', 'status', 'complete'], true)) {
        throw new InvalidArgumentException('Invalid action.');
    }
    $pdo = database();
    $installation = account_link_installation($pdo, $body);
    $installationId = (int)$installation['id'];

    if ($action === 'start') {
        $linkId = account_link_uuid();
        $requestToken = account_link_token();
        $userCode = account_link_user_code();
        $pdo->beginTransaction();
        $recentRequestCount = $pdo->prepare(
            'SELECT COUNT(*)
             FROM portal_computer_link_requests
             WHERE installation_id=:installation_id
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)'
        );
        $recentRequestCount->execute(['installation_id' => $installationId]);
        if ((int)$recentRequestCount->fetchColumn() >= 10) {
            $pdo->rollBack();
            json_response([
                'error' => 'Too many computer-link requests were created recently. Wait and try again.',
            ], 429);
        }
        $expirePrevious = $pdo->prepare(
            "UPDATE portal_computer_link_requests
             SET status='Expired'
             WHERE installation_id=:installation_id AND status='Pending'"
        );
        $expirePrevious->execute(['installation_id' => $installationId]);
        $insert = $pdo->prepare(
            'INSERT INTO portal_computer_link_requests
                (link_id,installation_id,request_token_hash,user_code_hash,expires_at)
             VALUES(:link_id,:installation_id,UNHEX(SHA2(:request_token,256)),
                    UNHEX(SHA2(:user_code,256)),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))'
        );
        $insert->execute([
            'link_id' => $linkId,
            'installation_id' => $installationId,
            'request_token' => $requestToken,
            'user_code' => str_replace('-', '', $userCode),
        ]);
        account_link_event(
            $pdo,
            null,
            null,
            $installationId,
            $linkId,
            'COMPUTER_LINK_REQUESTED',
            'Succeeded',
            'The desktop application requested a temporary Customer Portal computer link.'
        );
        $pdo->commit();
        json_response([
            'state' => 'Pending',
            'linkId' => $linkId,
            'requestToken' => $requestToken,
            'userCode' => $userCode,
            'expiresInSeconds' => ACCOUNT_LINK_LIFETIME_MINUTES * 60,
            'verificationUrl' => 'https://userportal.posprinteremulator.com/index.php?return=computers&link=' .
                rawurlencode($userCode),
            'message' => 'Sign in to the Customer Portal and approve this computer within 10 minutes.',
        ], 201);
    }

    $linkId = strtolower(required_string($body, 'linkId', 36));
    $requestToken = required_string($body, 'requestToken', 64);
    if (!preg_match('/^[0-9a-f-]{36}$/', $linkId) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $requestToken)) {
        throw new InvalidArgumentException('The computer-link request is invalid.');
    }
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        "SELECT r.link_id,r.status,r.expires_at,r.approved_customer_id,r.selected_license_id,
                c.display_name,c.canonical_email,
                l.license_tier,l.control_state,l.maintenance_expires_at,
                b.binding_id,b.binding_state
         FROM portal_computer_link_requests r
         LEFT JOIN customers c ON c.customer_id=r.approved_customer_id
         LEFT JOIN issued_licenses l ON l.license_id=r.selected_license_id
         LEFT JOIN license_device_bindings b
           ON b.license_id=r.selected_license_id AND b.installation_id=r.installation_id
              AND b.binding_state='Active'
         WHERE r.link_id=:link_id AND r.installation_id=:installation_id
           AND r.request_token_hash=UNHEX(SHA2(:request_token,256))
         FOR UPDATE"
    );
    $query->execute([
        'link_id' => $linkId,
        'installation_id' => $installationId,
        'request_token' => $requestToken,
    ]);
    $link = $query->fetch();
    if (!is_array($link)) {
        $pdo->rollBack();
        json_response(['error' => 'This computer-link request was not recognized. Start a new request.'], 404);
    }
    if ((string)$link['status'] === 'Pending' && strtotime((string)$link['expires_at'] . ' UTC') <= time()) {
        $expire = $pdo->prepare("UPDATE portal_computer_link_requests SET status='Expired' WHERE link_id=:link_id");
        $expire->execute(['link_id' => $linkId]);
        account_link_event(
            $pdo, null, null, $installationId, $linkId, 'COMPUTER_LINK_EXPIRED', 'Rejected',
            'The temporary computer-link request expired before portal approval.'
        );
        $link['status'] = 'Expired';
    }
    if ($action === 'complete') {
        if ((string)$link['status'] !== 'Approved' || empty($link['binding_id'])) {
            $pdo->rollBack();
            json_response(['error' => 'This computer link has not been approved.'], 409);
        }
        $complete = $pdo->prepare(
            "UPDATE portal_computer_link_requests
             SET status='Consumed',consumed_at=UTC_TIMESTAMP(6)
             WHERE link_id=:link_id AND status='Approved'"
        );
        $complete->execute(['link_id' => $linkId]);
        account_link_event(
            $pdo,
            (string)$link['approved_customer_id'],
            (string)$link['selected_license_id'],
            $installationId,
            $linkId,
            'ACCOUNT_LICENSE_ACTIVATED',
            'Succeeded',
            'The approved account license was saved by the linked desktop application.'
        );
        $pdo->commit();
        json_response(['state' => 'Consumed', 'message' => 'This computer is linked and activated.']);
    }

    $pdo->commit();
    $state = (string)$link['status'];
    if ($state !== 'Approved') {
        json_response([
            'state' => $state,
            'message' => match ($state) {
                'Pending' => 'Waiting for approval in the Customer Portal.',
                'Rejected' => 'The customer rejected this computer-link request.',
                'Expired' => 'This code expired. Start a new computer-link request.',
                'Consumed' => 'This computer-link request was already completed.',
                default => 'This computer-link request is unavailable.',
            },
        ]);
    }
    if ((string)$link['control_state'] !== 'Enabled' || (string)$link['binding_state'] !== 'Active') {
        json_response([
            'state' => 'Rejected',
            'message' => 'The selected license is no longer eligible. Return to the Customer Portal and choose another license.',
        ], 409);
    }
    json_response([
        'state' => 'Approved',
        'customerName' => (string)$link['display_name'],
        'emailAddress' => (string)$link['canonical_email'],
        'licenseId' => (string)$link['selected_license_id'],
        'licenseTier' => (string)$link['license_tier'],
        'maintenanceExpiresAt' => (string)$link['maintenance_expires_at'],
        'message' => 'The Customer Portal approved this computer and selected an eligible license.',
    ]);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Account link error: ' . get_class($exception));
    json_response(['error' => 'The secure computer-link service is temporarily unavailable. Try again later.'], 503);
}
