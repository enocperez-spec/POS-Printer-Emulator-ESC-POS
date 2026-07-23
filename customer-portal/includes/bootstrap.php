<?php
declare(strict_types=1);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function portal_config(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }
    $path = dirname(__DIR__) . '/private/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('The Customer Portal is not configured.');
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('The Customer Portal configuration is invalid.');
    }
    $config = $loaded;
    return $config;
}

function portal_database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $database = portal_config()['database'] ?? [];
    $name = trim((string)($database['name'] ?? ''));
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=utf8mb4',
        (string)($database['host'] ?? ''),
        (int)($database['port'] ?? 3306)
    );
    $pdo = new PDO($dsn, (string)($database['username'] ?? ''), (string)($database['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($name === '') {
        $systemDatabases = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        $available = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $available = array_values(array_filter(
            $available,
            static fn($databaseName): bool =>
                !in_array(strtolower((string)$databaseName), $systemDatabases, true)
        ));
        if (count($available) !== 1) {
            throw new RuntimeException('The database name must be configured explicitly.');
        }
        $name = (string)$available[0];
    }

    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $name)) {
        throw new RuntimeException('The configured database name is invalid.');
    }
    $pdo->exec('USE `' . str_replace('`', '``', $name) . '`');
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function portal_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('PPECUSTOMER');
    session_set_cookie_params([
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function portal_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_redirect(string $path): never
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $path = '/';
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function portal_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254 ? $email : '';
}

function portal_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function portal_hash(string $value): string
{
    return hash('sha256', $value, true);
}

function portal_request_bucket(string $purpose): string
{
    $source = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $purpose . '|' . hash('sha256', $source);
}

function portal_rate_limit(string $bucket, int $limit, int $windowSeconds): bool
{
    $pdo = portal_database();
    $hash = portal_hash($bucket);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT hits, reset_at FROM portal_rate_limits WHERE bucket_hash=:hash FOR UPDATE');
        $query->bindValue(':hash', $hash, PDO::PARAM_LOB);
        $query->execute();
        $row = $query->fetch();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!is_array($row) || new DateTimeImmutable((string)$row['reset_at'], new DateTimeZone('UTC')) <= $now) {
            $resetAt = $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s.u');
            $upsert = $pdo->prepare(
                'INSERT INTO portal_rate_limits(bucket_hash,hits,reset_at)
                 VALUES(:hash,1,:reset_at)
                 ON DUPLICATE KEY UPDATE hits=1,reset_at=VALUES(reset_at)'
            );
            $upsert->bindValue(':hash', $hash, PDO::PARAM_LOB);
            $upsert->bindValue(':reset_at', $resetAt);
            $upsert->execute();
            $allowed = true;
        } else {
            $hits = (int)$row['hits'] + 1;
            $update = $pdo->prepare('UPDATE portal_rate_limits SET hits=:hits WHERE bucket_hash=:hash');
            $update->bindValue(':hits', $hits, PDO::PARAM_INT);
            $update->bindValue(':hash', $hash, PDO::PARAM_LOB);
            $update->execute();
            $allowed = $hits <= $limit;
        }
        $pdo->commit();
        return $allowed;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function portal_configured_base_url(): string
{
    $url = rtrim((string)(portal_config()['portal']['base_url'] ?? ''), '/');
    if (!preg_match('#^https://[A-Za-z0-9.-]+$#', $url)) {
        throw new RuntimeException('The Customer Portal base URL is invalid.');
    }
    return $url;
}

function portal_audit(string $customerId, string $type, string $summary, ?string $reference = null): void
{
    $statement = portal_database()->prepare(
        'INSERT INTO customer_events(customer_id,event_type,source,source_reference,actor,event_summary)
         VALUES(:customer_id,:event_type,\'Customer Portal\',:reference,\'Customer\',:summary)'
    );
    $statement->execute([
        'customer_id' => $customerId,
        'event_type' => mb_substr($type, 0, 64),
        'reference' => $reference === null ? null : mb_substr($reference, 0, 96),
        'summary' => mb_substr($summary, 0, 500),
    ]);
}

function portal_encryption_key(): string
{
    $encoded = (string)(portal_config()['portal']['encryption_key'] ?? '');
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || strlen($decoded) !== 32) {
        throw new RuntimeException('The portal encryption key is unavailable.');
    }
    return $decoded;
}

function portal_encrypt_secret(string $plaintext): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', portal_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The protected value could not be encrypted.');
    }
    return [$ciphertext, $nonce, $tag];
}

function portal_decrypt_secret(string $ciphertext, string $nonce, string $tag): string
{
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', portal_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($plaintext)) {
        throw new RuntimeException('The protected value could not be decrypted.');
    }
    return $plaintext;
}
