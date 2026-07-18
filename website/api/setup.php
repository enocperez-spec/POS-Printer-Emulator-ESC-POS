<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Not found.'], 404);
}

try {
    $body = json_request();
    if (!verify_admin_password((string)($body['username'] ?? ''), (string)($body['password'] ?? ''))) {
        usleep(500000);
        json_response(['error' => 'Authentication failed.'], 401);
    }

    $pdo = database();
    if (($body['action'] ?? '') === 'cleanup-smoke-test') {
        $installationId = required_string($body, 'installationId', 36);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $installationId)) {
            throw new InvalidArgumentException('Invalid installationId.');
        }
        $cleanup = $pdo->prepare("DELETE FROM installations WHERE installation_uuid = :uuid AND customer_name = 'Deployment Smoke Test'");
        $cleanup->execute(['uuid' => strtolower($installationId)]);
        json_response(['ok' => true, 'removed' => $cleanup->rowCount()]);
    }

    $schema = file_get_contents(dirname(__DIR__) . '/private/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Schema file is unavailable.');
    }

    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    json_response(['ok' => true, 'statements' => count($statements)]);
} catch (InvalidArgumentException|JsonException $exception) {
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator setup failure: ' . $exception->getMessage());
    json_response(['error' => 'Database setup failed.'], 500);
}
