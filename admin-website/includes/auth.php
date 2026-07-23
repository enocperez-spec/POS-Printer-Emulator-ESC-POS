<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

session_name('PPEOWNER');
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();

function require_authentication(): void
{
    if (empty($_SESSION['password_verified'])) {
        header('Location: /login.php');
        exit;
    }
    if (empty($_SESSION['two_factor_verified'])) {
        header('Location: ' . (two_factor_secret() === null ? '/two-factor-setup.php' : '/two-factor.php'));
        exit;
    }
}

function require_password_authentication(): void
{
    if (empty($_SESSION['password_verified'])) {
        header('Location: /login.php');
        exit;
    }
}

function admin_role(): string
{
    $configured = strtolower(trim((string)(private_config()['admin']['role'] ?? 'owner')));
    return in_array($configured, ['owner', 'support', 'analyst'], true) ? $configured : 'owner';
}

function admin_can(string $capability): bool
{
    $capabilities = [
        'owner' => ['customers.read', 'customers.export', 'customers.consent', 'customers.audit', 'licenses.manage', 'pricing.manage'],
        'support' => ['customers.read'],
        'analyst' => [],
    ];
    return in_array($capability, $capabilities[admin_role()] ?? [], true);
}

function require_admin_capability(string $capability): void
{
    if (!admin_can($capability)) {
        http_response_code(403);
        exit('This Admin Portal account is not authorized for that action.');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf'];
}

function require_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('The form expired. Reload the page and try again.');
    }
}

function two_factor_path(): string
{
    return dirname(__DIR__) . '/private/two-factor.json';
}

function two_factor_secret(): ?string
{
    $path = two_factor_path();
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path) ?: '', true);
    $secret = is_array($data) ? (string)($data['secret'] ?? '') : '';
    return preg_match('/^[A-Z2-7]{32}$/', $secret) ? $secret : null;
}

function generate_two_factor_secret(): string
{
    return base32_encode(random_bytes(20));
}

function save_two_factor_secret(string $secret): void
{
    if (!preg_match('/^[A-Z2-7]{32}$/', $secret)) {
        throw new InvalidArgumentException('The authenticator secret is invalid.');
    }
    $json = json_encode(['version' => 1, 'secret' => $secret, 'enrolled_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
    if (file_put_contents(two_factor_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('Two-factor authentication could not be saved.');
    }
    @chmod(two_factor_path(), 0600);
}

function verify_totp(string $secret, string $code, ?int $time = null): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }
    $counter = intdiv($time ?? time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        if (hash_equals(totp_at_counter($secret, $counter + $offset), $code)) {
            return true;
        }
    }
    return false;
}

function totp_at_counter(string $secret, int $counter): string
{
    $key = base32_decode($secret);
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function base32_encode(string $bytes): string
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

function base32_decode(string $value): string
{
    $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $bits = '';
    foreach (str_split(strtoupper($value)) as $character) {
        if (!isset($alphabet[$character])) {
            throw new InvalidArgumentException('The authenticator secret is invalid.');
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
