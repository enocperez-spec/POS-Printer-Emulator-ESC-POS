<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';

portal_session_start();

function portal_csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || strlen($_SESSION['csrf']) < 40) {
        $_SESSION['csrf'] = portal_token(32);
    }
    return $_SESSION['csrf'];
}

function portal_require_csrf(): void
{
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals(portal_csrf_token(), $provided)) {
        http_response_code(400);
        exit('This form expired. Reload the page and try again.');
    }
}

function portal_password_is_valid(string $password): bool
{
    return strlen($password) >= 12 &&
        strlen($password) <= 200 &&
        preg_match('/[A-Z]/', $password) === 1 &&
        preg_match('/[a-z]/', $password) === 1 &&
        preg_match('/\d/', $password) === 1;
}

function portal_account_by_email(string $email): ?array
{
    $query = portal_database()->prepare(
        "SELECT a.*,c.display_name,c.canonical_email,c.status customer_status
         FROM portal_accounts a
         INNER JOIN customers c ON c.customer_id=a.customer_id
         WHERE c.email_hash=UNHEX(SHA2(:email,256)) AND c.status='Active'
         ORDER BY a.created_at
         LIMIT 2"
    );
    $query->execute(['email' => $email]);
    $rows = $query->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

function portal_create_session(array $account): void
{
    session_regenerate_id(true);
    $sessionId = session_id();
    $customerId = (string)$account['customer_id'];
    $revision = (int)$account['session_revision'];
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $expires = (new DateTimeImmutable('+12 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $insert = portal_database()->prepare(
        'INSERT INTO portal_sessions(session_id_hash,customer_id,session_revision,user_agent_hash,expires_at,reauthenticated_at)
         VALUES(UNHEX(SHA2(:session_id,256)),:customer_id,:revision,UNHEX(SHA2(:agent,256)),:expires,UTC_TIMESTAMP(6))'
    );
    $insert->execute([
        'session_id' => $sessionId,
        'customer_id' => $customerId,
        'revision' => $revision,
        'agent' => $agent,
        'expires' => $expires,
    ]);
    $_SESSION = [
        'csrf' => portal_token(32),
        'customer_id' => $customerId,
        'session_revision' => $revision,
        'last_rotation' => time(),
        'last_activity' => time(),
        'mfa_pending' => !empty($account['mfa_enabled']),
        'authenticated' => empty($account['mfa_enabled']),
    ];
}

function portal_current_account(bool $allowMfaPending = false): ?array
{
    $customerId = (string)($_SESSION['customer_id'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $customerId)) {
        return null;
    }
    if (!$allowMfaPending && empty($_SESSION['authenticated'])) {
        return null;
    }
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity < time() - 1800) {
        portal_logout(false);
        return null;
    }
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $query = portal_database()->prepare(
        "SELECT a.*,c.display_name,c.canonical_email,c.email_verified_at,c.status customer_status,s.reauthenticated_at
         FROM portal_accounts a
         INNER JOIN customers c ON c.customer_id=a.customer_id
         INNER JOIN portal_sessions s ON s.customer_id=a.customer_id
             AND s.session_id_hash=UNHEX(SHA2(:session_id,256))
         WHERE a.customer_id=:customer_id
           AND a.session_revision=:revision
           AND s.session_revision=a.session_revision
           AND s.user_agent_hash=UNHEX(SHA2(:agent,256))
           AND s.revoked_at IS NULL
           AND s.expires_at>UTC_TIMESTAMP(6)
           AND c.status='Active'
         LIMIT 1"
    );
    $query->execute([
        'session_id' => session_id(),
        'customer_id' => $customerId,
        'revision' => (int)($_SESSION['session_revision'] ?? 0),
        'agent' => $agent,
    ]);
    $account = $query->fetch();
    if (!is_array($account)) {
        portal_logout(false);
        return null;
    }
    $_SESSION['last_activity'] = time();
    if ((int)($_SESSION['last_rotation'] ?? 0) < time() - 600) {
        $oldSession = session_id();
        session_regenerate_id(true);
        $rotate = portal_database()->prepare(
            'UPDATE portal_sessions
             SET session_id_hash=UNHEX(SHA2(:new_id,256)),last_seen_at=UTC_TIMESTAMP(6)
             WHERE session_id_hash=UNHEX(SHA2(:old_id,256)) AND customer_id=:customer_id'
        );
        $rotate->execute(['new_id' => session_id(), 'old_id' => $oldSession, 'customer_id' => $customerId]);
        $_SESSION['last_rotation'] = time();
    } else {
        $touch = portal_database()->prepare(
            'UPDATE portal_sessions SET last_seen_at=UTC_TIMESTAMP(6)
             WHERE session_id_hash=UNHEX(SHA2(:session_id,256))'
        );
        $touch->execute(['session_id' => session_id()]);
    }
    return $account;
}

function portal_require_account(): array
{
    $account = portal_current_account();
    if (!is_array($account)) {
        portal_redirect('/index.php');
    }
    return $account;
}

function portal_logout(bool $redirect = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        try {
            $revoke = portal_database()->prepare(
                'UPDATE portal_sessions SET revoked_at=UTC_TIMESTAMP(6)
                 WHERE session_id_hash=UNHEX(SHA2(:session_id,256))'
            );
            $revoke->execute(['session_id' => session_id()]);
        } catch (Throwable) {
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
    if ($redirect) {
        portal_redirect('/index.php');
    }
}

function portal_verify_password_login(string $email, string $password): array
{
    if (!portal_rate_limit(portal_request_bucket('login'), 10, 900)) {
        return [false, 'Sign-in is temporarily unavailable. Wait a few minutes and try again.'];
    }
    $account = portal_account_by_email($email);
    $dummyHash = '$2y$12$0g9IarLKoZkQJ5e7N7SMMu0u4xdp6M/GFMcF5ONNQvVJwe4cQH0H2';
    $hash = is_array($account) ? (string)$account['password_hash'] : $dummyHash;
    $valid = password_verify($password, $hash);
    if (!is_array($account) || !$valid) {
        if (is_array($account)) {
            $failure = portal_database()->prepare(
                'UPDATE portal_accounts
                 SET failed_login_count=failed_login_count+1,
                     locked_until=CASE WHEN failed_login_count+1>=5 THEN DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE) ELSE locked_until END
                 WHERE customer_id=:customer_id'
            );
            $failure->execute(['customer_id' => $account['customer_id']]);
        }
        usleep(250000);
        return [false, 'The email address or password was not recognized.'];
    }
    if (!empty($account['locked_until']) && strtotime((string)$account['locked_until']) > time()) {
        return [false, 'Sign-in is temporarily unavailable. Wait a few minutes and try again.'];
    }
    $update = portal_database()->prepare(
        'UPDATE portal_accounts
         SET failed_login_count=0,locked_until=NULL,last_login_at=UTC_TIMESTAMP(6)
         WHERE customer_id=:customer_id'
    );
    $update->execute(['customer_id' => $account['customer_id']]);
    portal_create_session($account);
    portal_audit((string)$account['customer_id'], 'Portal Sign In', 'Customer password accepted.');
    return [true, ''];
}

function portal_request_enrollment(string $email): void
{
    if (!portal_rate_limit(portal_request_bucket('enrollment'), 5, 3600)) {
        return;
    }
    $query = portal_database()->prepare(
        "SELECT c.customer_id,c.canonical_email
         FROM customers c
         LEFT JOIN portal_accounts a ON a.customer_id=c.customer_id
         WHERE c.email_hash=UNHEX(SHA2(:email,256)) AND c.status='Active' AND a.customer_id IS NULL
         ORDER BY c.created_at
         LIMIT 2"
    );
    $query->execute(['email' => $email]);
    $matches = $query->fetchAll();
    // A matching email alone must never merge or expose two separate customer identities.
    // Ambiguous records remain private until an administrator verifies and resolves ownership.
    if (count($matches) !== 1) {
        return;
    }
    $customer = $matches[0];
    $token = portal_token();
    $insert = portal_database()->prepare(
        'INSERT INTO customer_email_verifications(customer_id,email_hash,token_hash,expires_at)
         VALUES(:customer_id,UNHEX(SHA2(:email,256)),UNHEX(SHA2(:token,256)),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 MINUTE))'
    );
    $insert->execute(['customer_id' => $customer['customer_id'], 'email' => $email, 'token' => $token]);
    $link = portal_configured_base_url() . '/verify.php?purpose=enroll&token=' . rawurlencode($token);
    portal_queue_mail(
        (string)$customer['customer_id'],
        (string)$customer['canonical_email'],
        'Portal Enrollment',
        'Verify your POS Printer Emulator customer account',
        "Use this one-time link within 30 minutes to create your Customer Portal password:\n\n{$link}\n\nIf you did not request this, no action is required.",
        ['customer_name' => (string)$customer['display_name'], 'verification_url' => $link]
    );
}

function portal_request_password_reset(string $email): void
{
    if (!portal_rate_limit(portal_request_bucket('password-reset'), 5, 3600)) {
        return;
    }
    $account = portal_account_by_email($email);
    if (!is_array($account)) {
        return;
    }
    $token = portal_token();
    $insert = portal_database()->prepare(
        'INSERT INTO portal_password_resets(customer_id,token_hash,expires_at)
         VALUES(:customer_id,UNHEX(SHA2(:token,256)),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 MINUTE))'
    );
    $insert->execute(['customer_id' => $account['customer_id'], 'token' => $token]);
    $link = portal_configured_base_url() . '/verify.php?purpose=reset&token=' . rawurlencode($token);
    portal_queue_mail(
        (string)$account['customer_id'],
        (string)$account['canonical_email'],
        'Password Reset',
        'Reset your POS Printer Emulator Customer Portal password',
        "Use this one-time link within 30 minutes to reset your Customer Portal password:\n\n{$link}\n\nIf you did not request this, no action is required.",
        ['customer_name' => (string)$account['display_name'], 'reset_url' => $link]
    );
}

function portal_verification_record(string $purpose, string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $token)) {
        return null;
    }
    if ($purpose === 'enroll') {
        $query = portal_database()->prepare(
            'SELECT v.id,v.customer_id,c.display_name,c.canonical_email
             FROM customer_email_verifications v
             INNER JOIN customers c ON c.customer_id=v.customer_id
             LEFT JOIN portal_accounts a ON a.customer_id=v.customer_id
             WHERE v.token_hash=UNHEX(SHA2(:token,256)) AND v.used_at IS NULL
               AND v.expires_at>UTC_TIMESTAMP(6) AND c.status=\'Active\' AND a.customer_id IS NULL
             LIMIT 1'
        );
    } else {
        $query = portal_database()->prepare(
            'SELECT r.id,r.customer_id,c.display_name,c.canonical_email
             FROM portal_password_resets r
             INNER JOIN customers c ON c.customer_id=r.customer_id
             WHERE r.token_hash=UNHEX(SHA2(:token,256)) AND r.used_at IS NULL
               AND r.expires_at>UTC_TIMESTAMP(6) AND c.status=\'Active\'
             LIMIT 1'
        );
    }
    $query->execute(['token' => $token]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function portal_set_password_from_token(string $purpose, string $token, string $password): bool
{
    if (!portal_password_is_valid($password)) {
        return false;
    }
    $record = portal_verification_record($purpose, $token);
    if (!is_array($record)) {
        return false;
    }
    $pdo = portal_database();
    $pdo->beginTransaction();
    try {
        if ($purpose === 'enroll') {
            $insert = $pdo->prepare(
                'INSERT INTO portal_accounts(customer_id,password_hash)
                 VALUES(:customer_id,:password_hash)'
            );
            $insert->execute([
                'customer_id' => $record['customer_id'],
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ]);
            $used = $pdo->prepare(
                'UPDATE customer_email_verifications SET used_at=UTC_TIMESTAMP(6) WHERE id=:id AND used_at IS NULL'
            );
            $used->execute(['id' => $record['id']]);
            $verified = $pdo->prepare(
                'UPDATE customers SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP(6)) WHERE customer_id=:customer_id'
            );
            $verified->execute(['customer_id' => $record['customer_id']]);
            $event = 'Portal Enrollment';
            $summary = 'Verified customer created a portal account.';
        } else {
            $update = $pdo->prepare(
                'UPDATE portal_accounts
                 SET password_hash=:password_hash,password_changed_at=UTC_TIMESTAMP(6),
                     session_revision=session_revision+1,failed_login_count=0,locked_until=NULL
                 WHERE customer_id=:customer_id'
            );
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                'customer_id' => $record['customer_id'],
            ]);
            $used = $pdo->prepare(
                'UPDATE portal_password_resets SET used_at=UTC_TIMESTAMP(6) WHERE id=:id AND used_at IS NULL'
            );
            $used->execute(['id' => $record['id']]);
            $revoke = $pdo->prepare(
                'UPDATE portal_sessions SET revoked_at=UTC_TIMESTAMP(6)
                 WHERE customer_id=:customer_id AND revoked_at IS NULL'
            );
            $revoke->execute(['customer_id' => $record['customer_id']]);
            $event = 'Portal Password Reset';
            $summary = 'Customer reset the portal password; existing sessions were revoked.';
        }
        $pdo->commit();
        portal_audit((string)$record['customer_id'], $event, $summary);
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function portal_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $encoded;
}

function portal_base32_decode(string $value): string
{
    $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $bits = '';
    foreach (str_split(strtoupper($value)) as $character) {
        if (!isset($alphabet[$character])) {
            return '';
        }
        $bits .= str_pad(decbin($alphabet[$character]), 5, '0', STR_PAD_LEFT);
    }
    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }
    return $decoded;
}

function portal_totp(string $secret, int $counter): string
{
    $key = portal_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function portal_verify_totp(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }
    $counter = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        if (hash_equals(portal_totp($secret, $counter + $offset), $code)) {
            return true;
        }
    }
    return false;
}

function portal_mfa_secret(array $account): string
{
    if (empty($account['mfa_secret_ciphertext']) || empty($account['mfa_secret_nonce']) || empty($account['mfa_secret_tag'])) {
        return '';
    }
    return portal_decrypt_secret(
        (string)$account['mfa_secret_ciphertext'],
        (string)$account['mfa_secret_nonce'],
        (string)$account['mfa_secret_tag']
    );
}

function portal_complete_mfa(string $code): bool
{
    $account = portal_current_account(true);
    if (!is_array($account) || empty($account['mfa_enabled'])) {
        return false;
    }
    $valid = portal_verify_totp(portal_mfa_secret($account), $code);
    if (!$valid) {
        $normalizedRecoveryCode = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))) ?? '';
        $hash = portal_hash($normalizedRecoveryCode);
        $query = portal_database()->prepare(
            'SELECT id FROM portal_recovery_codes
             WHERE customer_id=:customer_id AND code_hash=:code_hash AND used_at IS NULL LIMIT 1'
        );
        $query->bindValue(':customer_id', $account['customer_id']);
        $query->bindValue(':code_hash', $hash, PDO::PARAM_LOB);
        $query->execute();
        $id = $query->fetchColumn();
        if ($id !== false) {
            $use = portal_database()->prepare('UPDATE portal_recovery_codes SET used_at=UTC_TIMESTAMP(6) WHERE id=:id');
            $use->execute(['id' => $id]);
            $valid = true;
        }
    }
    if ($valid) {
        $_SESSION['authenticated'] = true;
        $_SESSION['mfa_pending'] = false;
        portal_audit((string)$account['customer_id'], 'Portal MFA', 'Customer completed two-step verification.');
    }
    return $valid;
}

function portal_recently_reauthenticated(array $account): bool
{
    $time = !empty($account['reauthenticated_at']) ? strtotime((string)$account['reauthenticated_at']) : 0;
    return $time >= time() - 300;
}

function portal_reauthenticate(array $account, string $password): bool
{
    if (!password_verify($password, (string)$account['password_hash'])) {
        return false;
    }
    $update = portal_database()->prepare(
        'UPDATE portal_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)
         WHERE session_id_hash=UNHEX(SHA2(:session_id,256)) AND customer_id=:customer_id'
    );
    $update->execute(['session_id' => session_id(), 'customer_id' => $account['customer_id']]);
    return true;
}
