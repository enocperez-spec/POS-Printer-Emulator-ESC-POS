<?php
declare(strict_types=1);

function private_config(): array
{
    static $config;
    if ($config === null) {
        $path = dirname(__DIR__) . '/private/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Server configuration is unavailable.');
        }
        $config = require $path;
    }
    return $config;
}

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = private_config()['database'];
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $databaseName = trim((string)($db['name'] ?? ''));
    if ($databaseName === '') {
        $system = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        $available = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $available = array_values(array_filter($available, static fn($name) => !in_array(strtolower((string)$name), $system, true)));
        if (count($available) !== 1) {
            throw new RuntimeException('The database name must be configured explicitly.');
        }
        $databaseName = (string)$available[0];
    }

    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $databaseName)) {
        throw new RuntimeException('The configured database name is invalid.');
    }
    $pdo->exec('USE `' . str_replace('`', '``', $databaseName) . '`');
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function json_request(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('A JSON object is required.');
    }
    return $decoded;
}

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function required_string(array $body, string $name, int $maximum, bool $allowEmpty = false): string
{
    $value = trim((string)($body[$name] ?? ''));
    if ((!$allowEmpty && $value === '') || mb_strlen($value) > $maximum) {
        throw new InvalidArgumentException("Invalid {$name}.");
    }
    return $value;
}

function verify_admin_password(string $username, string $password): bool
{
    $admin = private_config()['admin'];
    if (!hash_equals((string)$admin['username'], $username)) {
        return false;
    }
    $actual = hash_pbkdf2('sha256', $password, base64_decode($admin['salt'], true), (int)$admin['iterations'], 32, true);
    return hash_equals(base64_decode($admin['password_hash'], true), $actual);
}
