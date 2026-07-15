<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Not found.'], 404);
}

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !verify_admin_password((string)($body['username'] ?? ''), (string)($body['password'] ?? ''))) {
        usleep(600000);
        respond(['error' => 'Authentication failed.'], 401);
    }

    $pdo = database();
    if (($body['action'] ?? '') === 'cleanup-license-smoke-test') {
        $licenseId = (string)($body['licenseId'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $licenseId)) {
            throw new InvalidArgumentException('Invalid licenseId.');
        }
        $cleanup = $pdo->prepare("DELETE FROM issued_licenses WHERE license_id = :id AND customer_name = 'Deployment License Test'");
        $cleanup->execute(['id' => strtolower($licenseId)]);
        respond(['ok' => true, 'removed' => $cleanup->rowCount()]);
    }

    $schema = file_get_contents(__DIR__ . '/private/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Schema file is unavailable.');
    }
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    respond(['ok' => true, 'statements' => count($statements)]);
} catch (InvalidArgumentException|JsonException $exception) {
    respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator admin setup failure: ' . $exception->getMessage());
    respond(['error' => 'Database setup failed.'], 500);
}
