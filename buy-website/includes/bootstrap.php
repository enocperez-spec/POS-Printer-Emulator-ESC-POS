<?php
declare(strict_types=1);

const BUY_ROOT = __DIR__ . '/..';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

function config(?string $path = null): mixed
{
    static $config;
    if ($config === null) {
        $file = BUY_ROOT . '/private/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('The purchase site has not been configured.');
        }
        $config = require $file;
    }
    if ($path === null) return $config;
    $value = $config;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite:' . BUY_ROOT . '/private/purchases.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        customer_name TEXT NOT NULL,
        email TEXT NOT NULL,
        paypal_order_id TEXT UNIQUE,
        paypal_capture_id TEXT UNIQUE,
        amount TEXT NOT NULL,
        currency TEXT NOT NULL,
        status TEXT NOT NULL,
        activation_key TEXT,
        license_id TEXT,
        created_at TEXT NOT NULL,
        paid_at TEXT,
        approved_at TEXT,
        emailed_at TEXT,
        last_error TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        event TEXT NOT NULL,
        details TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY(order_id) REFERENCES orders(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event TEXT NOT NULL,
        details TEXT,
        created_at TEXT NOT NULL
    )");
    return $db;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) json_response(['error' => 'Invalid request.'], 400);
    return $data;
}

function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = rtrim((string) config('app_url'), '/');
    if ($origin !== '' && !hash_equals($expected, rtrim($origin, '/'))) {
        json_response(['error' => 'This request was rejected.'], 403);
    }
}

function require_admin_api_token(): void
{
    $providedToken = (string)($_SERVER['HTTP_X_PPE_ADMIN_TOKEN'] ?? '');
    $expectedToken = (string)config('admin_api_token');
    if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        json_response(['error' => 'Unauthorized.'], 401);
    }
}

function enforce_rate_limit(string $action, int $limit, int $windowSeconds): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $bucket = hash('sha256', $action . '|' . $ip);
    $now = time();
    db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT hits,reset_at FROM rate_limits WHERE bucket=?'); $q->execute([$bucket]); $row = $q->fetch();
        if (!$row || (int)$row['reset_at'] <= $now) {
            $q = db()->prepare('INSERT INTO rate_limits(bucket,hits,reset_at) VALUES(?,?,?) ON CONFLICT(bucket) DO UPDATE SET hits=excluded.hits,reset_at=excluded.reset_at');
            $q->execute([$bucket, 1, $now + $windowSeconds]);
        } elseif ((int)$row['hits'] >= $limit) {
            db()->rollBack(); json_response(['error' => 'Too many attempts. Please wait and try again.'], 429);
        } else {
            $q = db()->prepare('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?'); $q->execute([$bucket]);
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

function clean_customer(string $value): string
{
    $value = trim((string) preg_replace('/\s+/', ' ', $value));
    if ($value === '' || mb_strlen($value) > 160) throw new InvalidArgumentException('Enter a customer or company name.');
    return $value;
}

function clean_email(string $value): string
{
    $value = strtolower(trim($value));
    if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Enter a valid email address.');
    return $value;
}

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function random_public_id(): string { return strtoupper(bin2hex(random_bytes(8))); }

function license_offer(): array
{
    $offer = [
        'price' => number_format((float) config('license.price'), 2, '.', ''),
        'currency' => strtoupper((string) config('license.currency')),
    ];
    $rows = db()->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('license_price','license_currency')")->fetchAll();
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'license_price') $offer['price'] = $row['setting_value'];
        if ($row['setting_key'] === 'license_currency') $offer['currency'] = $row['setting_value'];
    }
    return $offer;
}

function update_license_offer(string $price, string $currency): array
{
    $price = trim($price);
    $currency = strtoupper(trim($currency));
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $price) || (float)$price < 0.50 || (float)$price > 999999.99) {
        throw new InvalidArgumentException('Enter a price between 0.50 and 999,999.99.');
    }
    if ($currency !== 'USD') throw new InvalidArgumentException('USD is currently the supported checkout currency.');
    $normalized = number_format((float)$price, 2, '.', '');
    $savedAt = now_utc();
    db()->beginTransaction();
    try {
        $q = db()->prepare('INSERT INTO site_settings(setting_key,setting_value,updated_at) VALUES(?,?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=excluded.updated_at');
        $q->execute(['license_price', $normalized, $savedAt]);
        $q->execute(['license_currency', $currency, $savedAt]);
        $audit = db()->prepare('INSERT INTO admin_audit(event,details,created_at) VALUES(?,?,?)');
        $audit->execute(['LICENSE_PRICE_UPDATED', json_encode(['price'=>$normalized,'currency'=>$currency]), $savedAt]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['price'=>$normalized, 'currency'=>$currency];
}

function audit(int $orderId, string $event, ?string $details = null): void
{
    $q = db()->prepare('INSERT INTO audit_log(order_id,event,details,created_at) VALUES(?,?,?,?)');
    $q->execute([$orderId, $event, $details, now_utc()]);
}

function paypal_access_token(): string
{
    $ch = curl_init(rtrim((string) config('paypal.base_url'), '/') . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => config('paypal.client_id') . ':' . config('paypal.secret'),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    $data = json_decode((string) $body, true);
    if ($code !== 200 || !is_array($data) || empty($data['access_token'])) throw new RuntimeException('PayPal authentication failed. ' . $error);
    return $data['access_token'];
}

function paypal_request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array
{
    $headers = ['Authorization: Bearer ' . paypal_access_token(), 'Content-Type: application/json', 'Accept: application/json'];
    if ($requestId) $headers[] = 'PayPal-Request-Id: ' . $requestId;
    $ch = curl_init(rtrim((string) config('paypal.base_url'), '/') . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    $data = json_decode((string) $body, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) throw new RuntimeException('PayPal request failed (' . $code . '). ' . $error);
    return $data;
}

set_exception_handler(function (Throwable $e): void {
    error_log($e->__toString());
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) json_response(['error' => 'The request could not be completed.'], 500);
    http_response_code(500);
    echo '<!doctype html><head><title>Temporarily unavailable</title><link rel="icon" type="image/png" href="/assets/favicon.png"></head><p>The purchase site is temporarily unavailable. Please try again later.</p>';
});
