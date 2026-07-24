<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/portal-data.php';

$account = portal_require_account();
$customerId = (string)$account['customer_id'];
$page = (string)($_GET['page'] ?? 'overview');
$allowedPages = ['overview', 'licenses', 'plans', 'computers', 'downloads', 'support', 'preferences'];
$page = in_array($page, $allowedPages, true) ? $page : 'overview';
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
                'SELECT id,installation_uuid,portal_deactivated_at
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
            $actionInsert = $pdo->prepare(
                'INSERT INTO portal_device_actions(customer_id,installation_id,action,reason)
                 VALUES(:customer_id,:installation_id,\'Deactivate\',:reason)'
            );
            $actionInsert->execute(['customer_id' => $customerId, 'installation_id' => $installationId, 'reason' => $reason]);
            $pdo->commit();
            portal_audit($customerId, 'Portal Device Deactivated', 'Customer deactivated an old computer.', (string)$installation['installation_uuid']);
            $notice = 'The selected computer was deactivated. Open the application on the replacement computer to activate it.';
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
            if (!in_array($orderType, ['MAINTENANCE', 'UPGRADE'], true) ||
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

            if ($licenseId !== '') {
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
                 SET mfa_secret_ciphertext=:ciphertext,mfa_secret_nonce=:nonce,mfa_secret_tag=:tag,mfa_enabled=1
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
$maintenanceReminder = portal_maintenance_reminder(
    is_array($primaryLicense) ? (string)($primaryLicense['maintenance_expires_at'] ?? '') : null
);
$installations = $snapshot['installations'];
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
<body class="portal-page">
<header class="portal-header">
  <button class="mobile-menu" type="button" data-menu-toggle aria-expanded="false" aria-controls="portal-nav"><span></span><span></span><span></span><span class="sr-only">Open navigation</span></button>
  <a class="portal-brand" href="/portal.php"><img src="/assets/product-icon.png" width="40" height="40" alt=""><strong>POS Printer Emulator</strong></a>
  <div class="header-actions"><a href="https://www.posprinteremulator.com/documentation">Help</a><span><?= portal_e((string)$account['display_name']) ?></span><form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><button class="text-button" type="submit">Sign out</button></form></div>
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
        <a class="button primary" href="/portal.php?page=downloads">Download latest version</a>
      </section>
    <?php else: ?>
      <section class="license-hero trial"><div><span class="hero-symbol">T</span><div><h2>Trial License</h2><p>Install the latest version and activate when you are ready.</p></div></div><a class="button primary" href="/portal.php?page=downloads">View downloads</a></section>
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
        <section class="side-panel"><h2>Recent purchases &amp; maintenance</h2><?php foreach (array_slice($snapshot['purchases'], 0, 4) as $purchase): ?><div class="summary-row"><span><?= portal_e(portal_date($purchase['paid_at'])) ?><small><?= portal_e((string)$purchase['order_type']) ?> · <?= portal_e((string)$purchase['license_tier']) ?></small></span><strong><?= portal_e((string)$purchase['currency']) ?> <?= number_format((float)$purchase['amount'], 2) ?></strong></div><?php endforeach; ?><?php if ($snapshot['purchases'] === []): ?><p>No purchase history is linked yet.</p><?php endif; ?></section>
        <section class="side-panel"><h2>Communication preferences</h2><div class="summary-row"><span>Product news</span><strong><?= ($consentMap['Marketing'] ?? '') === 'Granted' ? 'Enabled' : 'Disabled' ?></strong></div><div class="summary-row"><span>Product analytics</span><strong><?= ($consentMap['Product Analytics'] ?? '') === 'Granted' ? 'Enabled' : 'Disabled' ?></strong></div><a href="/portal.php?page=preferences">Manage preferences</a></section>
      </aside>
    </div>
  <?php elseif ($page === 'licenses'): ?>
    <div class="page-heading"><div><h1>Licenses</h1><p>Activation keys remain masked after protected delivery.</p></div><a class="button primary" href="/portal.php?page=plans">Plans &amp; maintenance</a></div>
    <section class="data-section"><div class="table-wrap"><table><thead><tr><th>Masked key</th><th>Tier</th><th>Status</th><th>Issued</th><th>Maintenance through</th><th>Listeners</th></tr></thead><tbody><?php foreach ($licenses as $license): ?><tr><td><?= portal_masked_license($license) ?></td><td><?= portal_e((string)$license['license_tier']) ?></td><td><?= portal_e((string)$license['control_state']) ?></td><td><?= portal_e(portal_date($license['issued_at'])) ?></td><td><?= portal_e(portal_date($license['maintenance_expires_at'])) ?></td><td><?= portal_e(portal_listener_allowance((string)$license['license_tier'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="info-band"><h2>Permanent license, optional annual maintenance</h2><p>Your purchased version keeps working when maintenance expires. Use Plans &amp; maintenance to renew coverage or move to a higher listener level. No subscription is created.</p></section>
  <?php elseif ($page === 'plans'): ?>
    <div class="page-heading"><div><p class="eyebrow">Licenses</p><h1>Plans &amp; maintenance</h1><p>Compare listener capacity, upgrade an owned license, or renew optional annual coverage.</p></div></div>
    <section class="reauth-panel commerce-reauth"><h2>Confirm before purchasing</h2><p>Enter your Customer Portal password. Confirmation remains valid for five minutes and is required before creating a secure PayPal checkout.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
    <?php $rank = ['Trial' => 0, 'Lite' => 1, 'Pro' => 2, 'Enterprise' => 3]; $currentTier = is_array($primaryLicense) ? (string)$primaryLicense['license_tier'] : 'Trial'; ?>
    <div class="plan-grid">
      <?php foreach (['Lite' => '1 printer listener', 'Pro' => 'Up to 2 printer listeners', 'Enterprise' => 'Multiple listeners · 15 recommended maximum'] as $tier => $capacity): ?>
        <article class="plan-card <?= $tier === 'Enterprise' ? 'featured' : '' ?> <?= $tier === $currentTier ? 'current-license' : '' ?>">
          <div><span class="plan-kicker"><?= $tier === $currentTier ? 'Your current license' : ($tier === 'Enterprise' ? 'Maximum flexibility' : 'License') ?></span><h2><?= portal_e($tier) ?></h2><p><?= portal_e($capacity) ?></p></div>
          <ul><li>Unlimited emulated print jobs</li><li>Receipt history and paid tools</li><li>One year of updates and support included</li></ul>
          <?php if ($rank[$tier] > $rank[$currentTier]): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="prepare-checkout"><input type="hidden" name="order_type" value="UPGRADE"><input type="hidden" name="target_tier" value="<?= portal_e($tier) ?>">
              <?php if (is_array($primaryLicense)): ?><input type="hidden" name="license_id" value="<?= portal_e((string)$primaryLicense['license_id']) ?>"><?php else: ?><?php $trial = array_values(array_filter($installations, static fn(array $row): bool => $row['license_mode'] === 'Trial' && empty($row['portal_deactivated_at'])))[0] ?? null; ?><?php if (is_array($trial)): ?><input type="hidden" name="installation_id" value="<?= (int)$trial['id'] ?>"><?php endif; ?><?php endif; ?>
              <button class="button <?= $tier === 'Enterprise' ? 'primary' : 'secondary' ?>" type="submit" <?= !is_array($primaryLicense) && !isset($trial) ? 'disabled' : '' ?>>Upgrade to <?= portal_e($tier) ?></button>
            </form>
          <?php else: ?><span class="current-plan"><?= $tier === $currentTier ? '✓ Current license' : 'Included below your current license' ?></span><?php endif; ?>
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
  <?php elseif ($page === 'computers'): ?>
    <div class="page-heading"><div><h1>Computers</h1><p>Deactivate an old computer only when you no longer use POS Printer Emulator on it.</p></div></div>
    <section class="reauth-panel"><h2>Confirm sensitive actions</h2><p>Enter your Customer Portal password. Confirmation remains valid for five minutes.</p><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="reauthenticate"><label><span class="sr-only">Password</span><input type="password" name="password" autocomplete="current-password" placeholder="Portal password" required></label><button class="button secondary" type="submit">Confirm password</button></form></section>
    <section class="data-section"><div class="table-wrap"><table><thead><tr><th>Computer</th><th>Version</th><th>First seen</th><th>Last seen</th><th>Action</th></tr></thead><tbody><?php foreach ($installations as $installation): ?><tr><td><?= portal_e((string)($installation['device_label'] ?: 'Computer ' . substr((string)$installation['installation_uuid'], -6))) ?><small class="block"><?= portal_e((string)($installation['windows_version'] ?: 'Windows device')) ?></small></td><td><?= portal_e((string)$installation['app_version']) ?></td><td><?= portal_e(portal_date($installation['first_seen_at'])) ?></td><td><?= portal_e(portal_datetime($installation['last_seen_at'])) ?></td><td><?php if ($installation['portal_deactivated_at']): ?><span>Deactivated <?= portal_e(portal_date($installation['portal_deactivated_at'])) ?></span><?php else: ?><form method="post" class="device-action" data-confirm="Deactivate this computer? The application on it may lose server access."><input type="hidden" name="csrf" value="<?= portal_e(portal_csrf_token()) ?>"><input type="hidden" name="action" value="deactivate-device"><input type="hidden" name="installation_id" value="<?= (int)$installation['id'] ?>"><label><span class="sr-only">Reason</span><input name="reason" maxlength="300" placeholder="Reason for transfer" required></label><button class="danger-link" type="submit">Deactivate</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php elseif ($page === 'downloads'): ?>
    <div class="page-heading"><div><h1>Downloads</h1><p>Download the official POS Printer Emulator release from GitHub.</p></div></div>
    <section class="download-panel"><div><h2>POS Printer Emulator v0.3.45</h2><p>Windows 11 Pro · x64 · self-contained installer</p></div><a class="button primary" href="https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/download/v0.3.45/POSPrinterEmulatorSetup-0.3.45-win-x64.exe" rel="noopener">Download installer</a></section>
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
        <?php elseif ($pendingMfaSecret === ''): ?>
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
