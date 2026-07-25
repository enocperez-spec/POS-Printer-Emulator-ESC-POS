<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/portal-data.php';

$account = portal_require_account();
$customerId = (string)$account['customer_id'];
$page = (string)($_GET['page'] ?? 'overview');
$allowedPages = ['overview', 'licenses', 'plans', 'billing', 'computers', 'downloads', 'support', 'preferences'];
$page = in_array($page, $allowedPages, true) ? $page : 'overview';
$computerLinkCode = strtoupper(trim((string)($_GET['link'] ?? $_SESSION['computer_link_code'] ?? '')));
$computerLinkCode = preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $computerLinkCode) ? $computerLinkCode : '';
if ($page === 'computers' && $computerLinkCode !== '') {
    $_SESSION['computer_link_code'] = $computerLinkCode;
}
if ($page === 'plans' && portal_purchase_tier($_GET['purchase'] ?? '') !== '') {
    unset($_SESSION['purchase_tier']);
}
if ($page === 'plans') {
    unset($_SESSION['maintenance_return']);
}
if (in_array($page, ['billing', 'plans', 'computers'], true)) {
    unset($_SESSION['portal_return']);
}
$mfaReenrollmentRequired = !empty($account['mfa_reenrollment_required']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mfaReenrollmentRequired && $page !== 'preferences') {
    portal_redirect('/portal.php?page=preferences#mfa');
}
$error = '';
$notice = (string)($_SESSION['portal_notice'] ?? '');
unset($_SESSION['portal_notice']);

function portal_record_consent(string $customerId, string $type, string $state): void
{
    $evidence = implode('|', [$customerId, $type, $state, 'Customer Portal', gmdate('Y-m-d')]);
    $insert = portal_database()->prepare(
        'INSERT INTO customer_consents(customer_id,consent_type,consent_state,policy_version,source,actor,evidence_digest)
         VALUES(:customer_id,:type,:state,\'privacy-2026-07\',\'Customer Portal\',\'Customer\',UNHEX(SHA2(:evidence,256)))'
    );
    $insert->execute([
        'customer_id' => $customerId,
        'type' => $type,
        'state' => $state,
        'evidence' => $evidence,
    ]);
}

function portal_support_reference(): string
{
    return 'SUP-' . strtoupper(bin2hex(random_bytes(6)));
}

function portal_submit_support_backend(string $reference): void
{
    $endpoint = (string)(portal_config()['portal']['support_backend_url'] ?? '');
    $token = (string)(portal_config()['portal']['support_backend_token'] ?? '');
    if (!preg_match('#^https://#', $endpoint) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $token) || !function_exists('curl_init')) {
        return;
    }
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['reference' => $reference], JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) {
        error_log('Customer Portal support handoff deferred for ' . $reference);
    }
}

function portal_checkout_url(string $token): string
{
    $baseUrl = rtrim((string)(portal_config()['portal']['buy_base_url'] ?? ''), '/');
    if (!preg_match('#^https://[A-Za-z0-9.-]+$#', $baseUrl)) {
        throw new RuntimeException('Secure checkout is not configured.');
    }
    return $baseUrl . '/self-service.php?session=' . rawurlencode($token);
}

function portal_resend_activation_backend(string $customerId, string $licenseId): array
{
    $token = trim((string)(portal_config()['portal']['support_backend_token'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $token) || !function_exists('curl_init')) {
        throw new RuntimeException('Secure activation delivery is not configured.');
    }
    $curl = curl_init('https://admin.posprinteremulator.com/api/v1/portal-license-delivery.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(
            ['customerId' => $customerId, 'licenseId' => $licenseId],
            JSON_THROW_ON_ERROR
        ),
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $message = is_array($decoded) ? trim((string)($decoded['error'] ?? '')) : '';
        throw new DomainException($message !== '' ? $message : 'The activation key could not be sent. Try again later.');
    }
    return $decoded;
}

function portal_start_promotion_backend(
    string $customerId,
    ?string $licenseId,
    ?int $installationId,
    string $grantedTier
): array {
    $config = portal_config()['portal'] ?? [];
    $endpoint = (string)($config['promotion_backend_url'] ?? '');
    $token = (string)($config['support_backend_token'] ?? '');
    if (!preg_match('#^https://#', $endpoint) ||
        !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $token) ||
        !function_exists('curl_init')) {
        throw new RuntimeException('The promotional access service is not configured.');
    }
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'customerId' => $customerId,
            'licenseId' => $licenseId,
            'installationId' => $installationId,
            'grantedTier' => $grantedTier,
        ], JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $message = is_array($decoded) ? trim((string)($decoded['error'] ?? '')) : '';
        throw new DomainException($message !== '' ? $message : 'Promotional access could not be started. Try again later.');
    }
    if (!preg_match('/^PPEP1-[A-Za-z0-9_-]+$/', (string)($decoded['entitlementToken'] ?? ''))) {
        throw new RuntimeException('The promotion service returned an invalid entitlement.');
    }
    return $decoded;
}

function portal_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
        substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($mfaReenrollmentRequired && !in_array($action, ['start-mfa', 'confirm-mfa'], true)) {
            throw new DomainException('Configure two-factor authentication before using other Customer Portal features.');
        }
        if ($action === 'profile') {
            $name = trim((string)($_POST['display_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) {
                throw new DomainException('Enter a customer or company name.');
            }
            $update = portal_database()->prepare(
                'UPDATE customers SET display_name=:name WHERE customer_id=:customer_id AND status=\'Active\''
            );
            $update->execute(['name' => $name, 'customer_id' => $customerId]);
            portal_audit($customerId, 'Portal Profile Updated', 'Customer updated the account display name.');
            $notice = 'Your account name was updated.';
        } elseif ($action === 'preferences') {
            portal_record_consent($customerId, 'Marketing', isset($_POST['marketing']) ? 'Granted' : 'Withdrawn');
            portal_record_consent($customerId, 'Product Analytics', isset($_POST['analytics']) ? 'Granted' : 'Withdrawn');
            portal_audit($customerId, 'Portal Preferences Updated', 'Customer updated optional communication and analytics preferences.');
            $notice = 'Your preferences were saved.';
        } elseif ($action === 'reauthenticate') {
            if (!portal_reauthenticate($account, (string)($_POST['password'] ?? ''))) {
                throw new DomainException('The password was not accepted.');
            }
            $notice = 'Your identity was confirmed for sensitive account actions.';
        } elseif ($action === 'approve-computer-link') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh)) {
                throw new DomainException('Confirm your password first. Reauthentication is valid for five minutes.');
            }
            $submittedCode = strtoupper(trim((string)($_POST['link_code'] ?? '')));
            $submittedCode = preg_replace('/[^A-Z0-9]/', '', $submittedCode) ?? '';
            $licenseId = strtolower(trim((string)($_POST['license_id'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{8}$/', $submittedCode) ||
                !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $licenseId)) {
                throw new DomainException('Enter a valid computer link code and choose an eligible license.');
            }
            $pdo = portal_database();
            $pdo->beginTransaction();
            $findLink = $pdo->prepare(
                'SELECT r.link_id,r.installation_id,r.status,r.expires_at,r.selected_license_id,
                        i.installation_uuid,i.device_label,i.app_version,i.windows_version
                 FROM portal_computer_link_requests r
                 INNER JOIN installations i ON i.id=r.installation_id
                 WHERE r.user_code_hash=UNHEX(SHA2(:code,256))
                 LIMIT 1 FOR UPDATE'
            );
            $findLink->execute(['code' => $submittedCode]);
            $linkRequest = $findLink->fetch();
            if (!is_array($linkRequest)) {
                throw new DomainException('That computer link code was not found. Start a new request from the application.');
            }
            if ((string)$linkRequest['status'] !== 'Pending') {
                throw new DomainException('That computer link code has already been used or canceled.');
            }
            if (strtotime((string)$linkRequest['expires_at'] . ' UTC') <= time()) {
                $expire = $pdo->prepare(
                    'UPDATE portal_computer_link_requests SET status=\'Expired\' WHERE link_id=:link_id'
                );
                $expire->execute(['link_id' => $linkRequest['link_id']]);
                $pdo->commit();
                throw new DomainException('That computer link code expired. Start a new request from the application.');
            }
            if (!empty($linkRequest['selected_license_id']) &&
                !hash_equals(strtolower((string)$linkRequest['selected_license_id']), $licenseId)) {
                throw new DomainException('This link request is for the license identified by the backup activation key.');
            }
            $findLicense = $pdo->prepare(
                'SELECT license_id,license_tier,control_state,maintenance_expires_at,customer_id
                 FROM issued_licenses
                 WHERE license_id=:license_id AND (customer_id=:customer_id OR customer_id IS NULL)
                 LIMIT 1 FOR UPDATE'
            );
            $findLicense->execute(['license_id' => $licenseId, 'customer_id' => $customerId]);
            $license = $findLicense->fetch();
            if (!is_array($license) || (string)$license['control_state'] !== 'Enabled') {
                throw new DomainException('That license is not eligible or is owned by another Customer Portal account.');
            }
            $activationMethod = empty($license['customer_id']) ? 'ActivationKeyClaim' : 'PortalLink';
            if (empty($license['customer_id'])) {
                $claimLicense = $pdo->prepare(
                    'UPDATE issued_licenses
                     SET customer_id=:customer_id,customer_name=:customer_name,email_address=:email_address,
                         row_version=row_version+1
                     WHERE license_id=:license_id AND customer_id IS NULL'
                );
                $claimLicense->execute([
                    'customer_id' => $customerId,
                    'customer_name' => $account['display_name'],
                    'email_address' => $account['canonical_email'],
                    'license_id' => $licenseId,
                ]);
                if ($claimLicense->rowCount() !== 1) {
                    throw new DomainException('The activation key could not be claimed. Refresh and try again.');
                }
            }
            $activeBinding = $pdo->prepare(
                'SELECT binding_id,installation_id
                 FROM license_device_bindings
                 WHERE license_id=:license_id AND binding_state=\'Active\'
                 FOR UPDATE'
            );
            $activeBinding->execute(['license_id' => $licenseId]);
            $activeBindings = $activeBinding->fetchAll();
            foreach ($activeBindings as $binding) {
                if ((int)$binding['installation_id'] !== (int)$linkRequest['installation_id']) {
                    throw new DomainException('That license is active on another computer. Deactivate it first, then approve this request.');
                }
            }
            $otherBinding = $pdo->prepare(
                'SELECT binding_id
                 FROM license_device_bindings
                 WHERE installation_id=:installation_id AND binding_state=\'Active\' AND license_id<>:license_id
                 LIMIT 1 FOR UPDATE'
            );
            $otherBinding->execute([
                'installation_id' => $linkRequest['installation_id'],
                'license_id' => $licenseId,
            ]);
            if ($otherBinding->fetchColumn() !== false) {
                throw new DomainException('This computer is already assigned to another license. Deactivate that assignment first.');
            }
            if ($activeBindings === []) {
                $bindingId = portal_uuid();
                $insertBinding = $pdo->prepare(
                    'INSERT INTO license_device_bindings(
                        binding_id,license_id,customer_id,installation_id,binding_state,activation_method,activated_at
                     ) VALUES(
                        :binding_id,:license_id,:customer_id,:installation_id,\'Active\',:activation_method,UTC_TIMESTAMP(6)
                     )'
                );
                $insertBinding->execute([
                    'binding_id' => $bindingId,
                    'license_id' => $licenseId,
                    'customer_id' => $customerId,
                    'installation_id' => $linkRequest['installation_id'],
                    'activation_method' => $activationMethod,
                ]);
            }
            $updateInstallation = $pdo->prepare(
                'UPDATE installations
                 SET customer_id=:customer_id,license_id=:license_id,license_mode=:license_tier,
                     maintenance_status=CASE
                       WHEN :maintenance_expires IS NULL THEN \'NotApplicable\'
                       WHEN :maintenance_expires_2>=UTC_TIMESTAMP(6) THEN \'Active\'
                       ELSE \'Expired\'
                     END,
                     maintenance_expires_at=:maintenance_expires_3,portal_deactivated_at=NULL
                 WHERE id=:installation_id'
            );
            $updateInstallation->execute([
                'customer_id' => $customerId,
                'license_id' => $licenseId,
                'license_tier' => $license['license_tier'],
                'maintenance_expires' => $license['maintenance_expires_at'],
                'maintenance_expires_2' => $license['maintenance_expires_at'],
                'maintenance_expires_3' => $license['maintenance_expires_at'],
                'installation_id' => $linkRequest['installation_id'],
            ]);
            $approve = $pdo->prepare(
                'UPDATE portal_computer_link_requests
                 SET status=\'Approved\',approved_customer_id=:customer_id,selected_license_id=:license_id,
                     approved_at=UTC_TIMESTAMP(6)
                 WHERE link_id=:link_id'
            );
            $approve->execute([
                'customer_id' => $customerId,
                'license_id' => $licenseId,
                'link_id' => $linkRequest['link_id'],
            ]);
            $activationEvent = $pdo->prepare(
                'INSERT INTO license_activation_events(
                    customer_id,license_id,installation_id,link_id,event_type,outcome,activation_method,event_summary
                 ) VALUES(
                    :customer_id,:license_id,:installation_id,:link_id,
                    :event_type,\'Succeeded\',:activation_method,:summary
                 )'
            );
            $activationEvent->execute([
                'customer_id' => $customerId,
                'license_id' => $licenseId,
                'installation_id' => $linkRequest['installation_id'],
                'link_id' => $linkRequest['link_id'],
                'event_type' => $activationMethod === 'ActivationKeyClaim'
                    ? 'ACTIVATION_KEY_CLAIMED'
                    : 'COMPUTER_LINK_APPROVED',
                'activation_method' => $activationMethod,
                'summary' => $activationMethod === 'ActivationKeyClaim'
                    ? 'Customer claimed a backup activation key with a verified portal account and approved the computer.'
                    : 'Customer approved a verified portal account link for a computer.',
            ]);
            $pdo->commit();
            unset($_SESSION['computer_link_code']);
            $computerLinkCode = '';
            portal_audit(
                $customerId,
                'Portal Computer Link Approved',
                'Customer linked a computer and selected an eligible license.',
                (string)$linkRequest['installation_uuid']
            );
            $notice = 'Computer approved. Return to POS Printer Emulator; activation will finish automatically.';
        } elseif ($action === 'deactivate-device') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh)) {
                throw new DomainException('Confirm your password first. Reauthentication is valid for five minutes.');
            }
            $installationId = filter_var($_POST['installation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($installationId === false || $reason === '' || mb_strlen($reason) > 300) {
                throw new DomainException('Choose an eligible computer and provide a short reason.');
            }
            $pdo = portal_database();
            $pdo->beginTransaction();
            $find = $pdo->prepare(
                'SELECT id,installation_uuid,license_id,portal_deactivated_at
                 FROM installations WHERE id=:id AND customer_id=:customer_id FOR UPDATE'
            );
            $find->execute(['id' => $installationId, 'customer_id' => $customerId]);
            $installation = $find->fetch();
            if (!is_array($installation) || !empty($installation['portal_deactivated_at'])) {
                throw new DomainException('That computer is not eligible for deactivation.');
            }
            $cooldown = $pdo->prepare(
                'SELECT COUNT(*) FROM portal_device_actions
                 WHERE customer_id=:customer_id AND created_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)'
            );
            $cooldown->execute(['customer_id' => $customerId]);
            if ((int)$cooldown->fetchColumn() >= 2) {
                throw new DomainException('For account protection, no more than two computers can be deactivated within 24 hours.');
            }
            $deactivate = $pdo->prepare(
                'UPDATE installations SET portal_deactivated_at=UTC_TIMESTAMP(6) WHERE id=:id AND customer_id=:customer_id'
            );
            $deactivate->execute(['id' => $installationId, 'customer_id' => $customerId]);
            $transferReference = portal_uuid();
            $releaseBinding = $pdo->prepare(
                'UPDATE license_device_bindings
                 SET binding_state=\'Deactivated\',deactivated_at=UTC_TIMESTAMP(6),
                     deactivation_reason=:reason,transfer_reference=:transfer_reference
                 WHERE installation_id=:installation_id AND customer_id=:customer_id AND binding_state=\'Active\''
            );
            $releaseBinding->execute([
                'reason' => $reason,
                'transfer_reference' => $transferReference,
                'installation_id' => $installationId,
                'customer_id' => $customerId,
            ]);
            if (!empty($installation['license_id'])) {
                $deactivationEvent = $pdo->prepare(
                    'INSERT INTO license_activation_events(
                        customer_id,license_id,installation_id,event_type,outcome,activation_method,event_summary
                     ) VALUES(
                        :customer_id,:license_id,:installation_id,
                        \'COMPUTER_DEACTIVATED\',\'Succeeded\',\'PortalLink\',:summary
                     )'
                );
                $deactivationEvent->execute([
                    'customer_id' => $customerId,
                    'license_id' => $installation['license_id'],
                    'installation_id' => $installationId,
                    'summary' => 'Customer deactivated a computer and released its license assignment.',
                ]);
            }
            $actionInsert = $pdo->prepare(
                'INSERT INTO portal_device_actions(customer_id,installation_id,action,reason)
                 VALUES(:customer_id,:installation_id,\'Deactivate\',:reason)'
            );
            $actionInsert->execute(['customer_id' => $customerId, 'installation_id' => $installationId, 'reason' => $reason]);
            $pdo->commit();
            portal_audit($customerId, 'Portal Device Deactivated', 'Customer deactivated an old computer.', (string)$installation['installation_uuid']);
            $notice = 'The selected computer was deactivated. Open the application on the replacement computer to activate it.';
        } elseif ($action === 'resend-activation') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh)) {
                throw new DomainException('Confirm your password first. Reauthentication is valid for five minutes.');
            }
            if (!portal_rate_limit('activation-resend|' . $customerId, 5, 3600)) {
                portal_audit(
                    $customerId,
                    'Activation Resend Restricted',
                    'Activation-key resend was rate limited in the Customer Portal.'
                );
                throw new DomainException('Too many resend requests were made. Wait before trying again.');
            }
            $licenseId = trim((string)($_POST['license_id'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/i', $licenseId)) {
                throw new DomainException('Choose a valid license.');
            }
            portal_resend_activation_backend($customerId, $licenseId);
            portal_audit(
                $customerId,
                'Activation Resend Requested',
                'Customer requested delivery to the verified account email.',
                $licenseId
            );
            $notice = 'The activation key was sent to your verified email address.';
        } elseif ($action === 'prepare-checkout') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh)) {
                throw new DomainException('Confirm your password first. Reauthentication is valid for five minutes.');
            }
            if (!portal_rate_limit('checkout|' . $customerId, 10, 3600)) {
                throw new DomainException('Too many checkout sessions were started. Wait and try again.');
            }
            $orderType = strtoupper(trim((string)($_POST['order_type'] ?? '')));
            $targetTier = ucfirst(strtolower(trim((string)($_POST['target_tier'] ?? ''))));
            if (!in_array($orderType, ['MAINTENANCE', 'UPGRADE', 'LICENSE'], true) ||
                !in_array($targetTier, ['Lite', 'Pro', 'Enterprise'], true)) {
                throw new DomainException('Choose a valid license change.');
            }
            $tierRank = ['Trial' => 0, 'Lite' => 1, 'Pro' => 2, 'Enterprise' => 3];
            $licenseId = trim((string)($_POST['license_id'] ?? ''));
            $installationId = filter_var(
                $_POST['installation_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $pdo = portal_database();
            $currentTier = 'Trial';
            $previousMaintenance = null;
            $ownedLicenseId = null;
            $ownedInstallationId = null;

            if ($orderType === 'LICENSE') {
                if ($licenseId !== '' || $installationId !== false) {
                    throw new DomainException('A new license purchase cannot replace an existing license.');
                }
                $findCurrent = $pdo->prepare(
                    "SELECT license_tier
                     FROM issued_licenses
                     WHERE customer_id=:customer_id AND control_state='Enabled'
                     ORDER BY issued_at DESC LIMIT 1"
                );
                $findCurrent->execute(['customer_id' => $customerId]);
                $existingTier = $findCurrent->fetchColumn();
                if (is_string($existingTier) && isset($tierRank[$existingTier])) {
                    $currentTier = $existingTier;
                }
            } elseif ($licenseId !== '') {
                if (!preg_match('/^[0-9a-f-]{36}$/i', $licenseId)) {
                    throw new DomainException('The selected license is invalid.');
                }
                $find = $pdo->prepare(
                    "SELECT license_id,license_tier,control_state,maintenance_expires_at,maintenance_revoked_at
                     FROM issued_licenses
                     WHERE license_id=:license_id AND customer_id=:customer_id
                     LIMIT 1"
                );
                $find->execute(['license_id' => $licenseId, 'customer_id' => $customerId]);
                $license = $find->fetch();
                if (!is_array($license) || (string)$license['control_state'] !== 'Enabled') {
                    throw new DomainException('The selected permanent license is not eligible.');
                }
                if (!empty($license['maintenance_revoked_at'])) {
                    throw new DomainException('Maintenance access was administratively revoked. Contact support before purchasing.');
                }
                $currentTier = (string)$license['license_tier'];
                $previousMaintenance = $license['maintenance_expires_at'];
                $ownedLicenseId = (string)$license['license_id'];
                if ($orderType === 'MAINTENANCE' && $targetTier !== $currentTier) {
                    throw new DomainException('Maintenance must match the permanent license level.');
                }
                if ($orderType === 'UPGRADE' && $tierRank[$targetTier] <= $tierRank[$currentTier]) {
                    throw new DomainException('Choose a license level above the current permanent license.');
                }
            } else {
                if ($orderType !== 'UPGRADE' || $installationId === false) {
                    throw new DomainException('Choose the Trial installation to upgrade.');
                }
                $find = $pdo->prepare(
                    "SELECT id,installation_uuid,license_mode,portal_deactivated_at
                     FROM installations
                     WHERE id=:id AND customer_id=:customer_id
                     LIMIT 1"
                );
                $find->execute(['id' => $installationId, 'customer_id' => $customerId]);
                $installation = $find->fetch();
                if (!is_array($installation) || (string)$installation['license_mode'] !== 'Trial' ||
                    !empty($installation['portal_deactivated_at'])) {
                    throw new DomainException('The selected Trial installation is not eligible.');
                }
                $ownedInstallationId = (int)$installation['id'];
            }

            $token = portal_token();
            $intentId = portal_uuid();
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO portal_checkout_intents
                    (intent_id,customer_id,license_id,installation_id,checkout_token_hash,order_type,
                     current_tier,target_tier,maintenance_previous_expires_at,expires_at)
                 VALUES(:intent_id,:customer_id,:license_id,:installation_id,UNHEX(SHA2(:token,256)),:order_type,
                        :current_tier,:target_tier,:maintenance_previous_expires_at,
                        DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 MINUTE))'
            );
            $insert->execute([
                'intent_id' => $intentId,
                'customer_id' => $customerId,
                'license_id' => $ownedLicenseId,
                'installation_id' => $ownedInstallationId,
                'token' => $token,
                'order_type' => $orderType,
                'current_tier' => $currentTier,
                'target_tier' => $targetTier,
                'maintenance_previous_expires_at' => $previousMaintenance,
            ]);
            $event = $pdo->prepare(
                'INSERT INTO portal_checkout_events(intent_id,event_type,actor,event_summary)
                 VALUES(:intent_id,\'PREPARED\',\'Customer\',\'Verified customer prepared a short-lived checkout session.\')'
            );
            $event->execute(['intent_id' => $intentId]);
            $pdo->commit();
            portal_audit(
                $customerId,
                'Portal Checkout Prepared',
                "{$orderType} checkout prepared from {$currentTier} to {$targetTier}.",
                $intentId
            );
            header('Location: ' . portal_checkout_url($token), true, 303);
            exit;
        } elseif ($action === 'start-promotion') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh)) {
                throw new DomainException('Confirm your password first. Reauthentication is valid for five minutes.');
            }
            if (!portal_rate_limit('promotion|' . $customerId, 3, 86400)) {
                throw new DomainException('Too many promotion requests were made. Wait and try again.');
            }
            $targetTier = ucfirst(strtolower(trim((string)($_POST['target_tier'] ?? 'Enterprise'))));
            if (!in_array($targetTier, ['Lite', 'Pro', 'Enterprise'], true)) {
                throw new DomainException('Choose a valid promotional tier.');
            }
            $licenseId = trim((string)($_POST['license_id'] ?? ''));
            $installationId = filter_var(
                $_POST['installation_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $delivery = portal_start_promotion_backend(
                $customerId,
                $licenseId !== '' ? $licenseId : null,
                $installationId !== false ? (int)$installationId : null,
                $targetTier
            );
            $_SESSION['promotion_delivery'] = $delivery;
            portal_audit(
                $customerId,
                'Portal Promotion Started',
                "Customer started temporary {$targetTier} access.",
                (string)$delivery['promotionId']
            );
            header('Location: /portal.php?page=plans', true, 303);
            exit;
        } elseif ($action === 'support-request') {
            $type = (string)($_POST['request_type'] ?? '');
            $subject = trim((string)($_POST['subject'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $types = ['Bug Report', 'Feature Request', 'License Issue', 'Other Issue'];
            if (!in_array($type, $types, true) || $subject === '' || mb_strlen($subject) > 160 || $description === '' || mb_strlen($description) > 8000) {
                throw new DomainException('Complete the request type, subject, and description.');
            }
            if (!portal_rate_limit('support|' . $customerId, 5, 86400)) {
                throw new DomainException('You have reached the daily support-request limit. You can reply to an existing request instead.');
            }
            $snapshotForSupport = portal_customer_snapshot($customerId);
            $licenseId = (string)($snapshotForSupport['licenses'][0]['license_id'] ?? '');
            if ($licenseId === '') {
                $licenseId = '00000000-0000-0000-0000-000000000000';
            }
            $reference = portal_support_reference();
            $supportPdo = portal_database();
            $supportPdo->beginTransaction();
            $insert = $supportPdo->prepare(
                'INSERT INTO support_requests
                    (reference_code,customer_id,license_id,request_type,subject,contact_name,contact_email,private_diagnostics)
                 VALUES(:reference,:customer_id,:license_id,:type,:subject,:name,:email,:details)'
            );
            $insert->execute([
                'reference' => $reference,
                'customer_id' => $customerId,
                'license_id' => $licenseId,
                'type' => $type,
                'subject' => $subject,
                'name' => $account['display_name'],
                'email' => $account['canonical_email'],
                'details' => "Customer Portal submission\n\n" . $description,
            ]);
            if (isset($_FILES['attachment']) && is_array($_FILES['attachment']) &&
                (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int)$_FILES['attachment']['error'] !== UPLOAD_ERR_OK || (int)$_FILES['attachment']['size'] > 2 * 1024 * 1024) {
                    throw new DomainException('The attachment could not be accepted. Use one file no larger than 2 MB.');
                }
                $tmp = (string)$_FILES['attachment']['tmp_name'];
                $content = file_get_contents($tmp);
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $allowed = ['image/png', 'image/jpeg', 'text/plain', 'application/pdf', 'application/zip'];
                if (!is_string($content) || !in_array($mime, $allowed, true)) {
                    throw new DomainException('The attachment type is not allowed.');
                }
                $attachment = $supportPdo->prepare(
                    'INSERT INTO support_request_attachments(reference_code,file_name,content_type,content)
                     VALUES(:reference,:name,:type,:content)'
                );
                $attachment->bindValue(':reference', $reference);
                $attachment->bindValue(':name', mb_substr(basename((string)$_FILES['attachment']['name']), 0, 120));
                $attachment->bindValue(':type', $mime);
                $attachment->bindValue(':content', $content, PDO::PARAM_LOB);
                $attachment->execute();
            }
            $supportPdo->commit();
            portal_submit_support_backend($reference);
            portal_audit($customerId, 'Portal Support Request', 'Customer submitted support request ' . $reference . '.', $reference);
            $notice = 'Support request ' . $reference . ' was saved. You can return to this page to follow its status.';
        } elseif ($action === 'support-reply') {
            $reference = trim((string)($_POST['reference'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            if (!preg_match('/^SUP-[A-F0-9-]{8,24}$/', $reference) || $message === '' || mb_strlen($message) > 5000) {
                throw new DomainException('Enter a reply of no more than 5,000 characters.');
            }
            $owned = portal_database()->prepare(
                'SELECT 1 FROM support_requests WHERE reference_code=:reference AND customer_id=:customer_id'
            );
            $owned->execute(['reference' => $reference, 'customer_id' => $customerId]);
            if (!$owned->fetchColumn()) {
                throw new DomainException('That support request is unavailable.');
            }
            $reply = portal_database()->prepare(
                'INSERT INTO portal_support_replies(reference_code,customer_id,message,author_type)
                 VALUES(:reference,:customer_id,:message,\'Customer\')'
            );
            $reply->execute(['reference' => $reference, 'customer_id' => $customerId, 'message' => $message]);
            portal_audit($customerId, 'Portal Support Reply', 'Customer replied to support request ' . $reference . '.', $reference);
            $notice = 'Your reply was added to ' . $reference . '.';
        } elseif ($action === 'retry-support') {
            $reference = trim((string)($_POST['reference'] ?? ''));
            if (!preg_match('/^SUP-[A-F0-9]{12}$/', $reference) ||
                !portal_rate_limit('support-handoff|' . $customerId, 10, 3600)) {
                throw new DomainException('The secure submission cannot be retried right now. Wait a few minutes and try again.');
            }
            $owned = portal_database()->prepare(
                "SELECT 1 FROM support_requests
                 WHERE reference_code=:reference AND customer_id=:customer_id AND state='Pending'"
            );
            $owned->execute(['reference' => $reference, 'customer_id' => $customerId]);
            if (!$owned->fetchColumn()) {
                throw new DomainException('That support request is no longer waiting for submission.');
            }
            portal_submit_support_backend($reference);
            portal_audit($customerId, 'Portal Support Retry', 'Customer retried secure submission for ' . $reference . '.', $reference);
            $notice = 'The secure submission was retried. Refresh this request shortly to see its current status.';
        } elseif ($action === 'start-mfa') {
            $_SESSION['pending_mfa_secret'] = portal_base32_encode(random_bytes(20));
            $notice = 'Scan or enter the setup key, then verify one code to turn on two-step verification.';
        } elseif ($action === 'confirm-mfa') {
            $secret = (string)($_SESSION['pending_mfa_secret'] ?? '');
            if ($secret === '' || !portal_verify_totp($secret, (string)($_POST['code'] ?? ''))) {
                throw new DomainException('The authenticator code was not accepted.');
            }
            [$ciphertext, $nonce, $tag] = portal_encrypt_secret($secret);
            $pdo = portal_database();
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE portal_accounts
                 SET mfa_secret_ciphertext=:ciphertext,mfa_secret_nonce=:nonce,mfa_secret_tag=:tag,mfa_enabled=1,
                     mfa_reenrollment_required=0
                 WHERE customer_id=:customer_id'
            );
            $update->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
            $update->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
            $update->bindValue(':tag', $tag, PDO::PARAM_LOB);
            $update->bindValue(':customer_id', $customerId);
            $update->execute();
            $delete = $pdo->prepare('DELETE FROM portal_recovery_codes WHERE customer_id=:customer_id');
            $delete->execute(['customer_id' => $customerId]);
            $codes = [];
            $insert = $pdo->prepare(
                'INSERT INTO portal_recovery_codes(customer_id,code_hash) VALUES(:customer_id,:code_hash)'
            );
            for ($i = 0; $i < 8; $i++) {
                $code = strtoupper(bin2hex(random_bytes(5)));
                $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
                $hash = portal_hash(str_replace('-', '', end($codes)));
                $insert->bindValue(':customer_id', $customerId);
                $insert->bindValue(':code_hash', $hash, PDO::PARAM_LOB);
                $insert->execute();
            }
            $pdo->commit();
            unset($_SESSION['pending_mfa_secret']);
            $_SESSION['new_recovery_codes'] = $codes;
            portal_audit($customerId, 'Portal MFA Enabled', 'Customer enabled two-step verification.');
            $notice = 'Two-step verification is enabled. Save the recovery codes now; they will not be shown again.';
        } elseif ($action === 'disable-mfa') {
            if (!hash_equals('DISABLE', strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                throw new DomainException('Type DISABLE to confirm that you understand the security impact.');
            }
            if (!portal_rate_limit('mfa-disable|' . $customerId, 5, 3600)) {
                throw new DomainException('Too many two-factor authentication changes were attempted. Wait and try again.');
            }
            if (!portal_security_template_ready(portal_database(), 'mfa_disabled_notification')) {
                throw new DomainException(
                    'Two-factor authentication cannot be disabled until the security-notification service is available.'
                );
            }
            portal_disable_mfa(
                $account,
                (string)($_POST['password'] ?? ''),
                (string)($_POST['second_factor'] ?? '')
            );
            portal_audit(
                $customerId,
                'Portal MFA Disabled',
                'Customer disabled two-factor authentication; every portal session was revoked.'
            );
            portal_queue_mail(
                $customerId,
                (string)$account['canonical_email'],
                'MFA Disabled',
                'Two-factor authentication was disabled',
                "Two-factor authentication was disabled for your POS Printer Emulator Customer Portal account.\n\n"
                    . "All existing portal sessions were signed out. If you did not make this change, submit a support request immediately.",
                [
                    'customer_name' => (string)$account['display_name'],
                    'event_label' => 'disabled by you',
                    'portal_url' => portal_configured_base_url() . '/index.php',
                ]
            );
            portal_logout(false);
            portal_redirect('/index.php?security=mfa-disabled');
        } elseif ($action === 'export') {
            $snapshot = portal_customer_snapshot($customerId);
            portal_audit($customerId, 'Portal Data Export', 'Customer downloaded a privacy-safe account export.');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="pos-printer-emulator-account-' . gmdate('Ymd') . '.json"');
            echo json_encode(portal_customer_export($snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        } elseif ($action === 'close-account') {
            $fresh = portal_current_account();
            if (!is_array($fresh) || !portal_recently_reauthenticated($fresh) || (string)($_POST['confirmation'] ?? '') !== 'CLOSE') {
                throw new DomainException('Confirm your password, type CLOSE, and try again.');
            }
            $pdo = portal_database();
            $pdo->beginTransaction();
            $close = $pdo->prepare('UPDATE customers SET status=\'Closed\' WHERE customer_id=:customer_id AND status=\'Active\'');
            $close->execute(['customer_id' => $customerId]);
            $suppress = $pdo->prepare(
                "INSERT INTO customer_email_suppressions(customer_id,email_hash,reason,source,actor)
                 VALUES(:customer_id,UNHEX(SHA2(:email,256)),'Account Closed','Customer Portal','Customer')"
            );
            $suppress->execute(['customer_id' => $customerId, 'email' => $account['canonical_email']]);
            $revision = $pdo->prepare('UPDATE portal_accounts SET session_revision=session_revision+1 WHERE customer_id=:customer_id');
            $revision->execute(['customer_id' => $customerId]);
            $pdo->commit();
            portal_audit($customerId, 'Portal Account Closed', 'Customer closed portal access; permanent license evidence was retained.');
            portal_logout();
        }
    } catch (DomainException $exception) {
        if (portal_database()->inTransaction()) {
            portal_database()->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (portal_database()->inTransaction()) {
            portal_database()->rollBack();
        }
        error_log('Customer Portal action failed: ' . get_class($exception));
        $error = 'The request could not be completed. No changes were applied.';
    }
    $account = portal_require_account();
}

$snapshot = portal_customer_snapshot($customerId);
$licenses = $snapshot['licenses'];
$primaryLicense = $licenses[0] ?? null;
$primaryActiveLicense = portal_primary_active_license($licenses);
$maintenanceReminder = portal_maintenance_reminder(
    is_array($primaryLicense) ? (string)($primaryLicense['maintenance_expires_at'] ?? '') : null
);
$installations = $snapshot['installations'];
$pendingComputerLink = null;
if ($computerLinkCode !== '') {
    $compactLinkCode = str_replace('-', '', $computerLinkCode);
    $linkQuery = portal_database()->prepare(
        'SELECT r.link_id,r.status,r.expires_at,r.selected_license_id,
                i.installation_uuid,i.device_label,i.app_version,i.windows_version,
                l.license_tier AS requested_license_tier,l.control_state AS requested_license_state,
                l.customer_id AS requested_license_customer_id,
                l.activation_key_ending AS requested_key_ending
         FROM portal_computer_link_requests r
         INNER JOIN installations i ON i.id=r.installation_id
         LEFT JOIN issued_licenses l ON l.license_id=r.selected_license_id
         WHERE r.user_code_hash=UNHEX(SHA2(:code,256))
         LIMIT 1'
    );
    $linkQuery->execute(['code' => $compactLinkCode]);
    $candidateLink = $linkQuery->fetch();
    if (is_array($candidateLink)) {
        $pendingComputerLink = $candidateLink;
    }
}
$primaryInstallation = portal_primary_installation($installations);
$latestRelease = portal_latest_release();
$versionStatus = portal_version_status(
    is_array($primaryInstallation) ? (string)($primaryInstallation['app_version'] ?? '') : null,
    is_array($latestRelease) ? (string)$latestRelease['currentVersion'] : null
);
$maintenanceActive = portal_has_active_maintenance($primaryActiveLicense);
$consentMap = [];
foreach ($snapshot['consents'] as $consent) {
    $consentMap[(string)$consent['consent_type']] = (string)$consent['consent_state'];
}
$recoveryCodes = $_SESSION['new_recovery_codes'] ?? [];
unset($_SESSION['new_recovery_codes']);
$pendingMfaSecret = (string)($_SESSION['pending_mfa_secret'] ?? '');
$mfaProvisioningUri = $pendingMfaSecret === '' ? '' :
    'otpauth://totp/' . rawurlencode('POS Printer Emulator:' . (string)$account['canonical_email'])
    . '?secret=' . rawurlencode($pendingMfaSecret)
    . '&issuer=' . rawurlencode('POS Printer Emulator')
    . '&algorithm=SHA1&digits=6&period=30';
$portalCssVersion = (string)(filemtime(__DIR__ . '/assets/portal.css') ?: 1);
$portalScriptVersion = (string)(filemtime(__DIR__ . '/assets/portal.js') ?: 1);
$qrScriptVersion = (string)(filemtime(__DIR__ . '/assets/vendor/qrcodejs/qrcode.min.js') ?: 1);
$promotionDelivery = $_SESSION['promotion_delivery'] ?? null;
unset($_SESSION['promotion_delivery']);

function portal_nav_icon(string $name): string
{
    $paths = [
        'overview' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M9 20v-6h6v6"/>',
        'licenses' => '<path d="m20 13-7 7-9-9V4h7z"/><circle cx="8.5" cy="8.5" r="1.2"/>',
        'plans' => '<path d="M4 5h16v14H4z"/><path d="M4 9h16M8 15h3"/>',
        'billing' => '<path d="M4 3h16v18l-2.5-1.5L15 21l-3-1.5L9 21l-2.5-1.5L4 21z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'computers' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'downloads' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 19v2h16v-2"/>',
        'support' => '<path d="M21 12a8 8 0 0 1-8 8H6l-3 2 1-5a8 8 0 1 1 17-5Z"/>',
        'preferences' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.8 1.8 0 0 0 .4 2l.1.1-2.8 2.8-.1-.1a1.8 1.8 0 0 0-2-.4 1.8 1.8 0 0 0-1 1.7V21h-4v-.1a1.8 1.8 0 0 0-1-1.7 1.8 1.8 0 0 0-2 .4l-.1.1-2.8-2.8.1-.1a1.8 1.8 0 0 0 .4-2A1.8 1.8 0 0 0 3 14H3v-4h.1a1.8 1.8 0 0 0 1.7-1 1.8 1.8 0 0 0-.4-2l-.1-.1 2.8-2.8.1.1a1.8 1.8 0 0 0 2 .4A1.8 1.8 0 0 0 10 3V3h4v.1a1.8 1.8 0 0 0 1 1.7 1.8 1.8 0 0 0 2-.4l.1-.1 2.8 2.8-.1.1a1.8 1.8 0 0 0-.4 2A1.8 1.8 0 0 0 21 10h.1v4H21a1.8 1.8 0 0 0-1.6 1Z"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= portal_e(ucfirst($page)) ?> | POS Printer Emulator Customer Portal</title>
  <link rel="icon" href="/assets/favicon.png" type="image/png">
  <link rel="stylesheet" href="/assets/portal.css?v=<?= portal_e($portalCssVersion) ?>">
  <script src="/assets/vendor/qrcodejs/qrcode.min.js?v=<?= portal_e($qrScriptVersion) ?>" defer></script>
  <script src="/assets/portal.js?v=<?= portal_e($portalScriptVersion) ?>" defer></script>
</head>
<body
  class="portal-page"
  data-session-idle-timeout="<?= PORTAL_IDLE_TIMEOUT_SECONDS ?>"
  data-session-heartbeat="<?= PORTAL_ACTIVITY_HEARTBEAT_SECONDS ?>"
>
<header class="portal-header">
  <button class="mobile-menu" type="button" data-menu-toggle aria-expanded="false" aria-controls="portal-nav"><span></span><span></span><span></span><span class="sr-only">Open navigation</span></button>
  <a class="portal-brand" href="/portal.php"><img src="/assets/product-icon.png" width="40" height="40" alt=""><strong>POS Printer Emulator</strong></a>
  <div class="header-actions"><a href="https://www.posprinteremulator.com/documentation">Help</a><span><?= portal_e((string)$account['display_name']) ?></span><form method="post" action="/logout.php" data-session-logout-form><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><button class="text-button" type="submit">Sign out</button></form></div>
</header>
<aside class="portal-sidebar" id="portal-nav">
  <nav aria-label="Customer Portal">
    <?php foreach ($allowedPages as $item): ?>
      <a href="/portal.php?page=<?= $item ?>" <?= $page === $item ? 'aria-current="page"' : '' ?>><?= portal_nav_icon($item) ?><span><?= portal_e(ucfirst($item)) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <p>Your privacy matters. Receipt contents and full activation keys are never shown here.</p>
</aside>
<main class="portal-main" id="main-content">
  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= portal_e($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="alert success" role="status"><?= portal_e($notice) ?></div><?php endif; ?>

  <?php if ($page === 'overview'): ?>
    <h1>Welcome back, <?= portal_e(portal_customer_display_name((string)($account['display_name'] ?? ''))) ?></h1>
    <?php if (is_array($primaryLicense)): ?>
      <section class="license-hero">
        <div><span class="hero-symbol" aria-hidden="true">✓</span><div><h2><?= portal_e((string)$primaryLicense['license_tier']) ?> License</h2><p class="status active">● <?= portal_e(portal_license_status_label((string)$primaryLicense['control_state'])) ?></p><p><span class="maintenance-label">Maintenance and Support Until:</span> <strong><?= portal_e(portal_long_date($primaryLicense['maintenance_expires_at'])) ?></strong></p></div></div>
        <?php if ($versionStatus['updateAvailable'] === true && $maintenanceActive): ?>
          <a class="button update-download" href="/portal.php?page=downloads">Download Latest Version</a>
        <?php elseif (!$maintenanceActive): ?>
          <a class="button renewal" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a>
        <?php else: ?>
          <a class="button primary" href="/portal.php?page=downloads">View downloads</a>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="license-hero trial"><div><span class="hero-symbol">T</span><div><h2>Trial License</h2><p>Install the latest version and activate when you are ready.</p></div></div><a class="button primary" href="/portal.php?page=downloads">View downloads</a></section>
    <?php endif; ?>
    <?php if (is_array($latestRelease) && $versionStatus['updateAvailable'] === true): ?>
      <section class="release-notification update-available overview-release" role="status" aria-labelledby="overview-new-version-title">
        <div class="release-notification-heading"><span class="release-icon" aria-hidden="true">↑</span><div><p class="release-kicker">Software update</p><h2 id="overview-new-version-title">New version available!</h2><p>Installed: <strong>v<?= portal_e((string)$versionStatus['installedVersion']) ?></strong> · Latest: <strong>v<?= portal_e((string)$versionStatus['latestVersion']) ?></strong> · <strong><?= (int)$versionStatus['versionsBehind'] ?> <?= (int)$versionStatus['versionsBehind'] === 1 ? 'version' : 'versions' ?> behind</strong></p></div></div>
        <div class="release-actions">
          <?php if ($maintenanceActive): ?><a class="button update-download" href="/portal.php?page=downloads">Download Latest Version</a><?php else: ?><a class="button renewal" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a><?php endif; ?>
          <a class="release-notes-link" href="<?= portal_e($latestRelease['releaseNotesUrl']) ?>" target="_blank" rel="noopener">Release notes · <?= portal_e(portal_long_date($latestRelease['releaseDate'])) ?></a>
        </div>
      </section>
    <?php elseif (is_array($latestRelease) && $versionStatus['updateAvailable'] === false): ?>
      <section class="release-current-summary" role="status"><span aria-hidden="true">✓</span><strong>Your software is up to date.</strong><span>v<?= portal_e((string)$versionStatus['installedVersion']) ?></span></section>
    <?php endif; ?>
    <?php if ($maintenanceReminder['state'] === 'expiring'): ?>
      <section class="maintenance-reminder expiring" role="status" aria-labelledby="maintenance-reminder-title">
        <div>
          <h2 id="maintenance-reminder-title">Maintenance and Support renewal reminder</h2>
          <p>Your Maintenance and Support coverage expires on <strong><?= portal_e($maintenanceReminder['expirationDate']) ?></strong>. You have <strong><?= (int)$maintenanceReminder['daysRemaining'] ?> <?= (int)$maintenanceReminder['daysRemaining'] === 1 ? 'day' : 'days' ?> remaining</strong>. Renew now to continue receiving software updates and technical support.</p>
        </div>
        <a class="button primary" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a>
      </section>
    <?php elseif ($maintenanceReminder['state'] === 'expired'): ?>
      <section class="maintenance-reminder expired" role="alert" aria-labelledby="maintenance-expired-title">
        <div>
          <h2 id="maintenance-expired-title">Maintenance and Support expired</h2>
          <p>Your Maintenance and Support coverage expired on <strong><?= portal_e($maintenanceReminder['expirationDate']) ?></strong>. Renew to restore access to software updates and technical support.</p>
        </div>
        <a class="button primary" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a>
      </section>
    <?php endif; ?>
    <div class="overview-grid">
      <div class="main-column">
        <section class="data-section"><header><h2>Licenses</h2><a href="/portal.php?page=licenses">View details</a></header><div class="table-wrap"><table><thead><tr><th>License</th><th>Status</th><th>Tier</th><th>Listener allowance</th><th>Maintenance and Support Until</th></tr></thead><tbody><?php foreach (array_slice($licenses, 0, 3) as $license): ?><tr><td><?= portal_masked_license($license) ?></td><td><span class="status-dot"></span><?= portal_e(portal_license_status_label((string)$license['control_state'])) ?></td><td><?= portal_e((string)$license['license_tier']) ?></td><td><?= portal_e(portal_listener_allowance((string)$license['license_tier'])) ?></td><td><?= portal_e(portal_long_date($license['maintenance_expires_at'])) ?></td></tr><?php endforeach; ?><?php if ($licenses === []): ?><tr><td colspan="5">No paid licenses are linked to this customer record.</td></tr><?php endif; ?></tbody></table></div></section>
        <section class="data-section"><header><h2>Installed computers</h2><a href="/portal.php?page=computers">Manage computers</a></header><div class="table-wrap"><table><thead><tr><th>Computer</th><th>Windows</th><th>App version</th><th>Last seen</th><th>Status</th></tr></thead><tbody><?php foreach (array_slice($installations, 0, 5) as $installation): ?><tr><td><?= portal_e((string)($installation['device_label'] ?: 'Computer ' . substr((string)$installation['installation_uuid'], -6))) ?></td><td><?= portal_e((string)($installation['windows_version'] ?: 'Windows device')) ?></td><td><?= portal_e((string)$installation['app_version']) ?></td><td><?= portal_e(portal_datetime($installation['last_seen_at'])) ?></td><td><?= $installation['portal_deactivated_at'] ? 'Deactivated' : 'Active' ?></td></tr><?php endforeach; ?><?php if ($installations === []): ?><tr><td colspan="5">No computers have checked in yet.</td></tr><?php endif; ?></tbody></table></div></section>
        <section class="data-section"><header><h2>Recent support requests</h2><a href="/portal.php?page=support">View all</a></header><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Subject</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach (array_slice($snapshot['support'], 0, 4) as $request): ?><tr><td><code><?= portal_e((string)$request['reference_code']) ?></code></td><td><?= portal_e((string)$request['subject']) ?></td><td><?= portal_e((string)$request['state']) ?></td><td><?= portal_e(portal_date($request['created_at'])) ?></td></tr><?php endforeach; ?><?php if ($snapshot['support'] === []): ?><tr><td colspan="4">No support requests yet.</td></tr><?php endif; ?></tbody></table></div></section>
      </div>
      <aside class="side-column">
        <?php if (empty($account['mfa_enabled'])): ?><section class="side-panel security"><h2>Secure your account</h2><p>Two-step verification is optional and strongly recommended.</p><a class="button secondary" href="/portal.php?page=preferences#mfa">Enable two-step verification</a></section><?php endif; ?>
        <section class="side-panel"><h2>Recent purchases &amp; maintenance</h2><?php foreach (array_slice($snapshot['purchases'], 0, 4) as $purchase): ?><div class="summary-row"><span><?= portal_e(portal_date($purchase['paid_at'])) ?><small><?= portal_e(portal_purchase_type_label($purchase)) ?> · <?= portal_e((string)$purchase['license_tier']) ?></small></span><strong><?= portal_e((string)$purchase['currency']) ?> <?= number_format((float)$purchase['amount'], 2) ?></strong></div><?php endforeach; ?><?php if ($snapshot['purchases'] === []): ?><p>No purchase history is linked yet.</p><?php else: ?><a class="side-panel-link" href="/portal.php?page=billing">View purchase &amp; billing history</a><?php endif; ?></section>
        <section class="side-panel"><h2>Communication preferences</h2><div class="summary-row"><span>Product news</span><strong><?= ($consentMap['Marketing'] ?? '') === 'Granted' ? 'Enabled' : 'Disabled' ?></strong></div><div class="summary-row"><span>Product analytics</span><strong><?= ($consentMap['Product Analytics'] ?? '') === 'Granted' ? 'Enabled' : 'Disabled' ?></strong></div><a href="/portal.php?page=preferences">Manage preferences</a></section>
      </aside>
    </div>
  <?php elseif ($page === 'licenses'): ?>
    <div class="page-heading"><div><h1>Licenses</h1><p>Activation keys remain masked in the portal and can only be resent to your verified email while Maintenance and Support is active.</p></div><a class="button primary" href="/portal.php?page=plans">Plans &amp; maintenance</a></div>
    <section class="reauth-panel"><h2>Confirm before resending</h2><p>Enter your Customer Portal password. Confirmation remains valid for five minutes and is required before an activation key can be emailed.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
    <section class="data-section"><div class="table-wrap"><table><thead><tr><th>Masked key</th><th>Tier</th><th>Status</th><th>Issued</th><th>Maintenance and Support Until</th><th>Listeners</th><th>Activation delivery</th></tr></thead><tbody><?php foreach ($licenses as $license): ?><?php $licenseMaintenanceActive = portal_has_active_maintenance($license); ?><tr><td><?= portal_masked_license($license) ?></td><td><?= portal_e((string)$license['license_tier']) ?></td><td><?= portal_e(portal_license_status_label((string)$license['control_state'])) ?></td><td><?= portal_e(portal_date($license['issued_at'])) ?></td><td><strong><?= portal_e(portal_long_date($license['maintenance_expires_at'])) ?></strong></td><td><?= portal_e(portal_listener_allowance((string)$license['license_tier'])) ?></td><td class="license-delivery-cell"><?php if ($licenseMaintenanceActive): ?><form method="post"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="resend-activation"><input type="hidden" name="license_id" value="<?= portal_e((string)$license['license_id']) ?>"><button class="button secondary compact" type="submit">Resend Activation Key</button></form><small>Sent only to your verified account email.</small><?php else: ?><button class="button secondary compact" type="button" disabled>Resend Activation Key</button><div class="maintenance-required"><strong>Maintenance and Support renewal required</strong><span>Your coverage expired on <?= portal_e(portal_long_date($license['maintenance_expires_at'])) ?>. Renew before this activation key can be resent.</span><a href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a></div><?php endif; ?></td></tr><?php endforeach; ?><?php if ($licenses === []): ?><tr><td colspan="7">No paid licenses are linked to this customer record.</td></tr><?php endif; ?></tbody></table></div></section>
    <section class="info-band"><h2>POS Printer Emulator License with optional annual maintenance</h2><p>Your purchased version keeps working when maintenance expires. Use Plans &amp; maintenance to renew coverage, upgrade a license, or purchase an additional license. No subscription is created.</p></section>
  <?php elseif ($page === 'plans'): ?>
    <?php
      $rank = ['Trial' => 0, 'Lite' => 1, 'Pro' => 2, 'Enterprise' => 3];
      $currentTier = is_array($primaryLicense) ? (string)$primaryLicense['license_tier'] : 'Trial';
      $selectedPurchaseTier = ucfirst(strtolower(trim((string)($_GET['purchase'] ?? ''))));
      $selectedPurchaseTier = isset($rank[$selectedPurchaseTier]) && $selectedPurchaseTier !== 'Trial'
          ? $selectedPurchaseTier
          : '';
      $catalogPath = __DIR__ . '/assets/license-catalog.json';
      $licenseCatalog = is_file($catalogPath)
          ? json_decode((string)file_get_contents($catalogPath), true)
          : null;
      $listenerLabel = static function (string $tier) use ($licenseCatalog): string {
          $count = (int)($licenseCatalog['licenses'][$tier]['listenerLimit'] ?? 0);
          return $count === 1 ? '1 printer listener' : 'Up to ' . $count . ' printer listeners';
      };
      $tierFeatures = [
          'Lite' => [$listenerLabel('Lite'), 'Unlimited external POS print jobs', 'Full local history and watermark-free previews', 'Copy Receipt as Image'],
          'Pro' => [$listenerLabel('Pro'), 'Everything in Lite', 'Capture, import, and replay tools', 'Expanded multi-listener testing'],
          'Enterprise' => [$listenerLabel('Enterprise'), 'Everything in Pro', 'Standard Development Diagnostics Report', 'Advanced Diagnostics Package'],
      ];
    ?>
    <div class="page-heading"><div><p class="eyebrow">Licenses</p><h1>Plans &amp; maintenance</h1><p>Compare features, purchase a first or additional license, upgrade an owned license, or renew optional annual coverage.</p></div></div>
    <?php if ($selectedPurchaseTier !== ''): ?><section class="purchase-selection" role="status"><strong><?= portal_e($selectedPurchaseTier) ?> selected</strong><span>Sign-in preserved your selection. Confirm your password below, then continue with the appropriate purchase option.</span></section><?php endif; ?>
    <section class="reauth-panel commerce-reauth"><h2>Confirm before purchasing</h2><p>Enter your Customer Portal password. Confirmation remains valid for five minutes and is required before creating a secure PayPal checkout.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
    <div class="plan-grid">
      <?php foreach ($tierFeatures as $tier => $features): ?>
        <?php
          $isCurrent = $tier === $currentTier;
          $isSelected = $tier === $selectedPurchaseTier;
          $isUpgrade = is_array($primaryLicense) && $rank[$tier] > $rank[$currentTier];
          $orderType = $isUpgrade ? 'UPGRADE' : 'LICENSE';
          $buttonLabel = $isUpgrade
              ? 'Upgrade to ' . $tier
              : (is_array($primaryLicense) ? 'Buy Additional ' . $tier . ' License' : 'Purchase ' . $tier . ' License');
        ?>
        <article class="plan-card <?= $tier === 'Enterprise' ? 'featured' : '' ?> <?= $isCurrent ? 'current-license' : '' ?> <?= $isSelected ? 'selected-purchase' : '' ?>">
          <div><span class="plan-kicker"><?= $isCurrent ? 'Your current license' : ($isSelected ? 'Your selected license' : ($tier === 'Enterprise' ? 'Maximum flexibility' : 'POS Printer Emulator License')) ?></span><h2><?= portal_e($tier) ?></h2><p><?= portal_e($features[0]) ?></p></div>
          <ul><?php foreach (array_slice($features, 1) as $feature): ?><li><?= portal_e($feature) ?></li><?php endforeach; ?><li>One year of Maintenance and Support included</li></ul>
          <?php if (!$isCurrent || $isSelected): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="prepare-checkout"><input type="hidden" name="order_type" value="<?= portal_e($orderType) ?>"><input type="hidden" name="target_tier" value="<?= portal_e($tier) ?>">
              <?php if ($isUpgrade): ?><input type="hidden" name="license_id" value="<?= portal_e((string)$primaryLicense['license_id']) ?>"><?php endif; ?>
              <button class="button <?= $isSelected || $tier === 'Enterprise' ? 'primary' : 'secondary' ?>" type="submit"><?= portal_e($buttonLabel) ?></button>
            </form>
          <?php else: ?><span class="current-plan">✓ Current license</span><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <section class="maintenance-panel" id="maintenance-renewal">
      <div><span class="plan-kicker">Optional annual coverage</span><h2>Application Maintenance &amp; Support</h2><p>Renewing adds one year of updates and technical support. The software and purchased features remain permanent if coverage expires.</p></div>
      <?php if (is_array($primaryLicense)): ?><div class="maintenance-action"><span>Maintenance and Support Until: <strong><?= portal_e(portal_long_date($primaryLicense['maintenance_expires_at'])) ?></strong></span><form method="post"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="prepare-checkout"><input type="hidden" name="order_type" value="MAINTENANCE"><input type="hidden" name="target_tier" value="<?= portal_e((string)$primaryLicense['license_tier']) ?>"><input type="hidden" name="license_id" value="<?= portal_e((string)$primaryLicense['license_id']) ?>"><button class="button primary" type="submit">Renew Maintenance and Support</button></form></div><?php else: ?><p>Maintenance is included when you purchase a license.</p><?php endif; ?>
    </section>
    <?php if (is_array($promotionDelivery)): ?>
      <section class="promotion-delivery" role="status">
        <div><span class="plan-kicker">Promotion ready</span><h2><?= portal_e((string)$promotionDelivery['grantedTier']) ?> access through <?= portal_e(portal_date((string)$promotionDelivery['expiresAt'])) ?></h2><p>Copy this delivery key. In POS Printer Emulator, open <strong>Settings → License</strong>, paste it under Five-day promotional access, and choose Start promotional access.</p></div>
        <textarea readonly rows="5" aria-label="Promotional access key"><?= portal_e((string)$promotionDelivery['entitlementToken']) ?></textarea>
        <button class="button secondary" type="button" data-copy-promotion>Copy promotional key</button>
      </section>
    <?php endif; ?>
    <section class="promotion-panel">
      <div><span class="plan-kicker">Try before upgrading</span><h2>Five-Day Promotional Trial</h2><p>Choose Lite, Pro, or Enterprise inside the installed application. The licensing server checks eligibility and activates the selected edition automatically—there is no key to copy or paste.</p></div>
      <?php if ($currentTier === 'Enterprise'): ?><span class="current-plan">Enterprise already includes every feature</span>
      <?php elseif ($installations !== []): ?><div class="maintenance-action"><span>Open <strong>Settings → License</strong> in POS Printer Emulator.</span><a class="button secondary" href="https://www.posprinteremulator.com/documentation#license">View trial instructions</a></div>
      <?php else: ?><button class="button secondary disabled" type="button" disabled>Add an active installation first</button><?php endif; ?>
    </section>
  <?php elseif ($page === 'billing'): ?>
    <?php
      $purchaseCount = count($snapshot['purchases']);
      $paidPurchaseCount = count(array_filter(
          $snapshot['purchases'],
          static fn(array $purchase): bool => portal_purchase_status_label((string)$purchase['purchase_status']) === 'Paid'
      ));
      $licensePurchaseCount = count(array_filter(
          $snapshot['purchases'],
          static fn(array $purchase): bool => (string)($purchase['checkout_order_type'] ?? $purchase['order_type']) !== 'MAINTENANCE'
      ));
      $renewalCount = $purchaseCount - $licensePurchaseCount;
    ?>
    <div class="page-heading"><div><p class="eyebrow">Account records</p><h1>Purchase &amp; Billing</h1><p>Review licenses, upgrades, Maintenance and Support renewals, payment status, and downloadable receipts.</p></div><a class="button primary" href="/portal.php?page=plans">Purchase or renew</a></div>
    <section class="billing-summary" aria-label="Billing summary">
      <article><span>Total transactions</span><strong><?= $purchaseCount ?></strong></article>
      <article><span>Paid transactions</span><strong><?= $paidPurchaseCount ?></strong></article>
      <article><span>License purchases &amp; upgrades</span><strong><?= $licensePurchaseCount ?></strong></article>
      <article><span>Maintenance renewals</span><strong><?= $renewalCount ?></strong></article>
    </section>
    <section class="data-section billing-history">
      <header><div><h2>Transaction history</h2><p>Payment credentials and complete activation keys are never displayed.</p></div></header>
      <?php if ($snapshot['purchases'] === []): ?>
        <div class="billing-empty"><h3>No transactions yet</h3><p>License purchases and Maintenance and Support renewals will appear here after payment is completed.</p><a class="button primary" href="/portal.php?page=plans">View licenses</a></div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Date</th><th>Purchase</th><th>Associated license</th><th>Status</th><th>Amount</th><th>Transaction reference</th><th>Receipt</th></tr></thead>
          <tbody>
          <?php foreach ($snapshot['purchases'] as $purchase): ?>
            <?php $purchaseStatus = portal_purchase_status_label((string)$purchase['purchase_status']); ?>
            <tr>
              <td><?= portal_e(portal_datetime($purchase['paid_at'] ?? $purchase['updated_at'])) ?></td>
              <td><strong><?= portal_e(portal_purchase_type_label($purchase)) ?></strong><small class="block"><?= portal_e((string)$purchase['license_tier']) ?> edition</small></td>
              <td><?= portal_e(portal_purchase_license_label($purchase)) ?><?php if (!empty($purchase['license_control_state'])): ?><small class="block"><?= portal_e(portal_license_status_label((string)$purchase['license_control_state'])) ?></small><?php endif; ?></td>
              <td><span class="payment-status <?= portal_e(strtolower($purchaseStatus)) ?>"><?= portal_e($purchaseStatus) ?></span></td>
              <td><strong><?= portal_e((string)$purchase['currency']) ?> <?= number_format((float)$purchase['amount'], 2) ?></strong></td>
              <td><code><?= portal_e(portal_purchase_display_reference($purchase)) ?></code></td>
              <td><a class="button secondary compact" href="/receipt.php?reference=<?= rawurlencode((string)$purchase['purchase_reference']) ?>">View receipt</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>
  <?php elseif ($page === 'computers'): ?>
    <div class="page-heading"><div><h1>Computers</h1><p>Link, review, and deactivate computers associated with your verified account.</p></div></div>
    <section class="data-section computer-link-panel">
      <header><div><p class="section-kicker">Account-based activation</p><h2>Link this computer</h2></div></header>
      <?php if ($computerLinkCode === ''): ?>
        <p>Open <strong>Settings → License</strong> in POS Printer Emulator and select <strong>Link This Computer</strong>. Enter the temporary code shown by the application below.</p>
        <form method="get" class="inline-form computer-code-form">
          <input type="hidden" name="page" value="computers">
          <label>Temporary computer code
            <input name="link" inputmode="text" autocomplete="one-time-code" maxlength="9" pattern="[A-Za-z0-9]{4}-[A-Za-z0-9]{4}" placeholder="ABCD-1234" required>
          </label>
          <button class="button primary" type="submit">Review computer</button>
        </form>
      <?php elseif (!is_array($pendingComputerLink)): ?>
        <div class="inline-alert error"><strong>Code not found.</strong> Start a new link request from the application and enter the new code.</div>
        <a class="button secondary" href="/portal.php?page=computers">Enter another code</a>
      <?php elseif ((string)$pendingComputerLink['status'] !== 'Pending' || strtotime((string)$pendingComputerLink['expires_at'] . ' UTC') <= time()): ?>
        <div class="inline-alert error"><strong>This code is no longer available.</strong> Link codes expire after ten minutes and can only be used once.</div>
        <a class="button secondary" href="/portal.php?page=computers">Enter another code</a>
      <?php else: ?>
        <div class="computer-link-summary">
          <div><span>Code</span><strong class="link-code"><?= portal_e($computerLinkCode) ?></strong></div>
          <div><span>Computer</span><strong><?= portal_e((string)($pendingComputerLink['device_label'] ?: 'Windows computer')) ?></strong></div>
          <div><span>Application</span><strong>v<?= portal_e((string)($pendingComputerLink['app_version'] ?: 'Unknown')) ?></strong></div>
          <div><span>Expires</span><strong><?= portal_e(portal_datetime($pendingComputerLink['expires_at'])) ?></strong></div>
        </div>
        <?php
          $eligibleLinkLicenses = [];
          if (!empty($pendingComputerLink['selected_license_id'])) {
              $requestedOwner = (string)($pendingComputerLink['requested_license_customer_id'] ?? '');
              if ((string)$pendingComputerLink['requested_license_state'] === 'Enabled' &&
                  ($requestedOwner === '' || $requestedOwner === $customerId)) {
                  $eligibleLinkLicenses[] = [
                      'license_id' => $pendingComputerLink['selected_license_id'],
                      'license_tier' => $pendingComputerLink['requested_license_tier'],
                      'control_state' => $pendingComputerLink['requested_license_state'],
                      'activation_key_ending' => $pendingComputerLink['requested_key_ending'],
                  ];
              }
          } else {
              $eligibleLinkLicenses = array_values(array_filter(
                  $licenses,
                  static fn(array $license): bool => (string)$license['control_state'] === 'Enabled'
              ));
          }
        ?>
        <?php if ($eligibleLinkLicenses === []): ?>
          <div class="inline-alert"><strong>No eligible license is available.</strong> The key may belong to another account, or you may need to purchase a license.</div>
          <a class="button primary" href="/portal.php?page=plans">View purchase options</a>
        <?php else: ?>
          <form method="post" class="computer-link-approval" data-confirm="Approve this computer and assign the selected license?">
            <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
            <input type="hidden" name="action" value="approve-computer-link">
            <input type="hidden" name="link_code" value="<?= portal_e($computerLinkCode) ?>">
            <label><?= !empty($pendingComputerLink['selected_license_id']) ? 'Backup key license to claim' : 'License to activate' ?>
              <select name="license_id" required>
                <?php foreach ($eligibleLinkLicenses as $license): ?>
                  <option value="<?= portal_e((string)$license['license_id']) ?>">
                    <?= portal_e((string)$license['license_tier']) ?> · key ending <?= portal_e((string)($license['activation_key_ending'] ?: '—')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <p class="form-help">Approving links this specific computer to your verified account. One license cannot be active on more than one computer.</p>
            <button class="button primary" type="submit">Approve and link computer</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="reauth-panel"><h2>Confirm sensitive actions</h2><p>Enter your Customer Portal password. Confirmation remains valid for five minutes.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
    <section class="data-section"><div class="table-wrap"><table><thead><tr><th>Computer</th><th>Version</th><th>First seen</th><th>Last seen</th><th>Action</th></tr></thead><tbody><?php foreach ($installations as $installation): ?><tr><td><?= portal_e((string)($installation['device_label'] ?: 'Computer ' . substr((string)$installation['installation_uuid'], -6))) ?><small class="block"><?= portal_e((string)($installation['windows_version'] ?: 'Windows device')) ?></small></td><td><?= portal_e((string)$installation['app_version']) ?></td><td><?= portal_e(portal_date($installation['first_seen_at'])) ?></td><td><?= portal_e(portal_datetime($installation['last_seen_at'])) ?></td><td><?php if ($installation['portal_deactivated_at']): ?><span>Deactivated <?= portal_e(portal_date($installation['portal_deactivated_at'])) ?></span><?php else: ?><form method="post" class="device-action" data-confirm="Deactivate this computer? The application on it may lose server access."><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="deactivate-device"><input type="hidden" name="installation_id" value="<?= (int)$installation['id'] ?>"><label><span class="sr-only">Reason</span><input name="reason" maxlength="300" placeholder="Reason for transfer" required></label><button class="danger-link" type="submit">Deactivate</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php elseif ($page === 'downloads'): ?>
    <div class="page-heading"><div><h1>Downloads</h1><p>Review your installed version and access the official self-contained Windows release.</p></div></div>
    <?php if (!is_array($latestRelease)): ?>
      <section class="release-notification unavailable" role="status">
        <div><span class="release-icon" aria-hidden="true">i</span><div><h2>Release information is temporarily unavailable</h2><p>Refresh this page in a few minutes. Your installed software is not affected.</p></div></div>
      </section>
    <?php elseif ($versionStatus['updateAvailable'] === true): ?>
      <section class="release-notification update-available" role="status" aria-labelledby="new-version-title">
        <div class="release-notification-heading"><span class="release-icon" aria-hidden="true">↑</span><div><p class="release-kicker">Software update</p><h2 id="new-version-title">New version available!</h2><p>You are <strong><?= (int)$versionStatus['versionsBehind'] ?> <?= (int)$versionStatus['versionsBehind'] === 1 ? 'version' : 'versions' ?> behind</strong>.</p></div></div>
        <dl class="release-version-grid">
          <div><dt>Installed version</dt><dd>v<?= portal_e((string)$versionStatus['installedVersion']) ?></dd></div>
          <div><dt>Latest version</dt><dd>v<?= portal_e((string)$versionStatus['latestVersion']) ?></dd></div>
          <div><dt>Released</dt><dd><?= portal_e(portal_long_date($latestRelease['releaseDate'])) ?></dd></div>
        </dl>
        <div class="release-actions">
          <?php if ($maintenanceActive): ?>
            <a class="button update-download" href="<?= portal_e($latestRelease['downloadUrl']) ?>" rel="noopener">Download Latest Version</a>
          <?php else: ?>
            <a class="button renewal" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a>
          <?php endif; ?>
          <a class="release-notes-link" href="<?= portal_e($latestRelease['releaseNotesUrl']) ?>" target="_blank" rel="noopener">View release notes</a>
        </div>
        <?php if (!$maintenanceActive): ?><p class="release-eligibility-note">Version v<?= portal_e((string)$versionStatus['latestVersion']) ?> is available. Renew Maintenance and Support to restore update-download access.</p><?php endif; ?>
      </section>
    <?php elseif ($versionStatus['updateAvailable'] === false): ?>
      <section class="release-notification up-to-date" role="status">
        <div class="release-notification-heading"><span class="release-icon" aria-hidden="true">✓</span><div><p class="release-kicker">Version status</p><h2>Your software is up to date.</h2><p>Installed version: <strong>v<?= portal_e((string)$versionStatus['installedVersion']) ?></strong></p></div></div>
        <div class="release-actions"><a class="release-notes-link" href="<?= portal_e($latestRelease['releaseNotesUrl']) ?>" target="_blank" rel="noopener">View release notes</a></div>
      </section>
    <?php else: ?>
      <section class="release-notification unavailable" role="status">
        <div class="release-notification-heading"><span class="release-icon" aria-hidden="true">i</span><div><h2>Latest release: v<?= portal_e((string)$latestRelease['currentVersion']) ?></h2><p>No active installation has reported its version yet.</p></div></div>
        <dl class="release-version-grid"><div><dt>Released</dt><dd><?= portal_e(portal_long_date($latestRelease['releaseDate'])) ?></dd></div></dl>
        <div class="release-actions"><a class="release-notes-link" href="<?= portal_e($latestRelease['releaseNotesUrl']) ?>" target="_blank" rel="noopener">View release notes</a></div>
      </section>
    <?php endif; ?>
    <section class="download-panel"><div><h2>POS Printer Emulator <?= is_array($latestRelease) ? 'v' . portal_e((string)$latestRelease['currentVersion']) : 'for Windows' ?></h2><p>Windows 11 Pro · x64 · self-contained installer</p></div><?php if (is_array($latestRelease) && $maintenanceActive): ?><a class="button <?= $versionStatus['updateAvailable'] === true ? 'update-download' : 'primary' ?>" href="<?= portal_e($latestRelease['downloadUrl']) ?>" rel="noopener"><?= $versionStatus['updateAvailable'] === true ? 'Download Latest Version' : 'Download installer' ?></a><?php elseif (is_array($primaryLicense)): ?><a class="button renewal" href="/portal.php?page=plans#maintenance-renewal">Renew Maintenance and Support</a><?php else: ?><a class="button secondary" href="https://buy.posprinteremulator.com/" rel="noopener">View License Options</a><?php endif; ?></section>
    <section class="info-band"><h2>Update eligibility</h2><p>All customers can see when a newer version exists. Paid update downloads and assisted support follow the maintenance date shown on the Licenses page.</p></section>
  <?php elseif ($page === 'support'): ?>
    <div class="page-heading"><div><h1>Support</h1><p>Submit a private support request without exposing receipt data or activation keys.</p></div></div>
    <section class="form-panel"><h2>Submit a support request</h2><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="support-request"><label>Request type<select name="request_type" required><option>Bug Report</option><option>Feature Request</option><option>License Issue</option><option>Other Issue</option></select></label><label>Subject<input name="subject" maxlength="160" required></label><label class="wide">Detailed description<textarea name="description" rows="7" maxlength="8000" required></textarea></label><label class="wide">Optional attachment <span>PNG, JPG, TXT, PDF, or ZIP · maximum 2 MB</span><input type="file" name="attachment" accept=".png,.jpg,.jpeg,.txt,.pdf,.zip"></label><div class="wide form-actions"><p>Do not include activation keys, passwords, payment details, or customer receipt content.</p><button class="button primary" type="submit">Submit support request</button></div></form></section>
    <section class="data-section"><header><h2>Your support history</h2></header><?php foreach ($snapshot['support'] as $request): ?><details class="support-item"><summary><span><code><?= portal_e((string)$request['reference_code']) ?></code><strong><?= portal_e((string)$request['subject']) ?></strong></span><span><?= portal_e((string)$request['state']) ?> · <?= portal_e(portal_date($request['created_at'])) ?></span></summary><div class="conversation"><?php foreach ($snapshot['supportReplies'] as $reply): ?><?php if ($reply['reference_code'] === $request['reference_code']): ?><article class="<?= strtolower((string)$reply['author_type']) ?>"><header><?= portal_e((string)$reply['author_type']) ?> · <?= portal_e(portal_datetime($reply['created_at'])) ?></header><p><?= nl2br(portal_e((string)$reply['message'])) ?></p></article><?php endif; ?><?php endforeach; ?></div><?php if ($request['state'] === 'Pending'): ?><form method="post" class="support-retry"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="retry-support"><input type="hidden" name="reference" value="<?= portal_e((string)$request['reference_code']) ?>"><p>This request is saved privately but is still waiting for secure submission.</p><button class="button secondary" type="submit">Retry secure submission</button></form><?php endif; ?><form method="post" class="support-reply"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="support-reply"><input type="hidden" name="reference" value="<?= portal_e((string)$request['reference_code']) ?>"><label>Reply<textarea name="message" rows="4" maxlength="5000" required></textarea></label><button class="button secondary" type="submit">Add reply</button><?php if ($request['github_issue_url']): ?><a href="<?= portal_e((string)$request['github_issue_url']) ?>" rel="noopener">View public issue #<?= (int)$request['github_issue_number'] ?></a><?php endif; ?></form></details><?php endforeach; ?><?php if ($snapshot['support'] === []): ?><p class="empty-state">You have not submitted any support requests.</p><?php endif; ?></section>
  <?php elseif ($page === 'preferences'): ?>
    <div class="page-heading"><div><h1>Preferences &amp; security</h1><p>Manage your contact name, optional consent, account protection, and privacy actions.</p></div></div>
    <div class="settings-stack">
      <section class="form-panel"><h2>Account details</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="profile"><label>Customer or company name<input name="display_name" maxlength="160" value="<?= portal_e((string)$account['display_name']) ?>" required></label><label>Verified email address<input value="<?= portal_e((string)$account['canonical_email']) ?>" disabled></label><div class="wide form-actions"><button class="button primary" type="submit">Save account details</button></div></form></section>
      <section class="form-panel"><h2>Communication &amp; privacy</h2><form method="post" class="choice-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="preferences"><label><input type="checkbox" name="marketing" <?= ($consentMap['Marketing'] ?? '') === 'Granted' ? 'checked' : '' ?>><span><strong>Product news and offers</strong><small>Optional email about new features and offers.</small></span></label><label><input type="checkbox" name="analytics" <?= ($consentMap['Product Analytics'] ?? '') === 'Granted' ? 'checked' : '' ?>><span><strong>Privacy-safe product analytics</strong><small>Aggregate usage only. Never receipt text, raw bytes, screenshots, or private IP addresses.</small></span></label><button class="button primary" type="submit">Save preferences</button></form></section>
      <section class="data-section"><header><h2>Consent history</h2></header><div class="table-wrap"><table><thead><tr><th>Preference</th><th>Decision</th><th>Policy</th><th>Source</th><th>Recorded</th></tr></thead><tbody><?php foreach ($snapshot['consentHistory'] as $consent): ?><tr><td><?= portal_e((string)$consent['consent_type']) ?></td><td><?= portal_e((string)$consent['consent_state']) ?></td><td><?= portal_e((string)$consent['policy_version']) ?></td><td><?= portal_e((string)$consent['source']) ?></td><td><?= portal_e(portal_datetime($consent['recorded_at'])) ?></td></tr><?php endforeach; ?><?php if ($snapshot['consentHistory'] === []): ?><tr><td colspan="5">No preference decisions have been recorded yet.</td></tr><?php endif; ?></tbody></table></div></section>
      <section class="form-panel" id="mfa">
        <h2>Two-step verification</h2>
        <?php if (!empty($account['mfa_enabled'])): ?>
          <p class="status active">● Enabled</p>
          <p>Your authenticator app is required after password sign-in.</p>
          <details class="mfa-disable-panel">
            <summary>Disable two-factor authentication</summary>
            <div>
              <p class="security-warning">This reduces account security and signs out every Customer Portal session.</p>
              <form method="post" class="mfa-disable-form" data-confirm="Disable two-factor authentication and sign out every Customer Portal session?">
                <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="disable-mfa">
                <label>Account password<input type="password" name="password" autocomplete="current-password" required></label>
                <label>Authenticator or recovery code<input name="second_factor" autocomplete="one-time-code" maxlength="20" required></label>
                <label>Type DISABLE to confirm<input name="confirmation_phrase" autocomplete="off" required></label>
                <button class="button danger" type="submit">Disable two-factor authentication</button>
              </form>
            </div>
          </details>
        <?php elseif ($pendingMfaSecret === ''): ?>
          <?php if ($mfaReenrollmentRequired): ?><div class="alert error" role="alert"><strong>Two-factor authentication setup is required.</strong> An administrator reset your previous setup. Configure a new authenticator before continuing.</div><?php endif; ?>
          <p>Add an authenticator app for stronger protection.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
            <input type="hidden" name="action" value="start-mfa">
            <button class="button secondary" type="submit">Start two-step setup</button>
          </form>
        <?php else: ?>
          <div class="mfa-setup-grid">
            <div class="mfa-qr-card">
              <h3>Scan with your authenticator app</h3>
              <div class="mfa-qr-code" data-mfa-qr data-provisioning-uri="<?= portal_e($mfaProvisioningUri) ?>" role="img" aria-label="Authenticator setup QR code"></div>
              <p>Open your authenticator app, add an account, and scan this QR code.</p>
              <p class="mfa-qr-error" data-mfa-qr-error hidden>The QR code could not be displayed. Use the setup key instead.</p>
              <noscript><p>JavaScript is required to display the QR code. Use the setup key instead.</p></noscript>
            </div>
            <div class="mfa-manual-setup">
              <h3>Or enter the setup key</h3>
              <p>Use this key if your authenticator app cannot scan the QR code:</p>
              <code class="setup-key"><?= portal_e($pendingMfaSecret) ?></code>
              <form method="post" class="mfa-confirm-form">
                <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm-mfa">
                <label>Six-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
                <button class="button primary" type="submit">Verify and enable</button>
              </form>
            </div>
          </div>
        <?php endif; ?>
        <?php if (is_array($recoveryCodes) && $recoveryCodes !== []): ?>
          <div class="recovery-codes" role="status">
            <h3>Save these one-time recovery codes</h3>
            <ul><?php foreach ($recoveryCodes as $code): ?><li><code><?= portal_e((string)$code) ?></code></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
      </section>
      <section class="reauth-panel"><h2>Confirm sensitive actions</h2><p>Enter your Customer Portal password before closing portal access. Confirmation remains valid for five minutes.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
      <section class="danger-zone"><h2>Privacy actions</h2><form method="post"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="export"><button class="button secondary" type="submit">Download my account data</button></form><p>Closing portal access does not cancel or delete a permanent software license or required transaction records.</p><form method="post" class="close-account-form" data-confirm="Close Customer Portal access? Your permanent software license will remain, but you will be signed out."><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="close-account"><label>Type CLOSE after confirming your password<input name="confirmation" autocomplete="off" required></label><button class="button danger" type="submit">Close portal account</button></form></section>
    </div>
  <?php endif; ?>
</main>
<footer class="portal-footer"><span>Local tools for better receipt testing.</span><nav><a href="https://www.posprinteremulator.com/privacy.html">Privacy</a><a href="https://www.posprinteremulator.com/documentation">Documentation</a></nav></footer>
</body>
</html>
