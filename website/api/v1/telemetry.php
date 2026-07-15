<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

try {
    $body = json_request();
    $action = required_string($body, 'action', 20);
    $installationUuid = required_string($body, 'installationId', 36);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $installationUuid)) {
        throw new InvalidArgumentException('Invalid installationId.');
    }

    $pdo = database();
    if ($action === 'register') {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $statement = $pdo->prepare(
            'INSERT INTO installations
                (installation_uuid, token_hash, customer_name, email_address, app_version, license_mode, license_id)
             VALUES (:uuid, UNHEX(SHA2(:token, 256)), :customer, :email, :version, :mode, :license_id)');
        try {
            $statement->execute([
                'uuid' => strtolower($installationUuid),
                'token' => $token,
                'customer' => required_string($body, 'customerName', 160, true),
                'email' => strtolower(required_string($body, 'emailAddress', 254, true)),
                'version' => required_string($body, 'appVersion', 32),
                'mode' => (($body['licenseMode'] ?? 'Trial') === 'Full') ? 'Full' : 'Trial',
                'license_id' => empty($body['licenseId']) ? null : required_string($body, 'licenseId', 36),
            ]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                json_response(['error' => 'Installation already registered.'], 409);
            }
            throw $exception;
        }
        json_response(['ok' => true, 'token' => $token], 201);
    }

    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $installationToken = (string)($_SERVER['HTTP_X_INSTALLATION_TOKEN'] ?? '');
    if ($installationToken === '' && preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/', $authorization, $matches)) {
        $installationToken = $matches[1];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $installationToken)) {
        json_response(['error' => 'Authentication required.'], 401);
    }

    $find = $pdo->prepare(
        'SELECT id FROM installations
         WHERE installation_uuid = :uuid AND token_hash = UNHEX(SHA2(:token, 256))');
    $find->execute(['uuid' => strtolower($installationUuid), 'token' => $installationToken]);
    $installationId = $find->fetchColumn();
    if ($installationId === false) {
        json_response(['error' => 'Authentication failed.'], 401);
    }

    $event = required_string($body, 'event', 24);
    if (!in_array($event, ['launch', 'print_job', 'activation', 'heartbeat'], true)) {
        throw new InvalidArgumentException('Invalid event.');
    }
    $count = max(1, min(1000, (int)($body['count'] ?? 1)));
    $mode = (($body['licenseMode'] ?? 'Trial') === 'Full') ? 'Full' : 'Trial';
    $licenseId = empty($body['licenseId']) ? null : required_string($body, 'licenseId', 36);
    $launches = $event === 'launch' ? $count : 0;
    $jobs = $event === 'print_job' ? $count : 0;
    $activations = $event === 'activation' ? 1 : 0;

    $pdo->beginTransaction();
    $update = $pdo->prepare(
        'UPDATE installations SET
            customer_name = :customer,
            email_address = :email,
            app_version = :version,
            license_mode = :mode,
            license_id = :license_id,
            last_seen_at = UTC_TIMESTAMP(6),
            last_launch_at = IF(:has_launches = 1, UTC_TIMESTAMP(6), last_launch_at),
            last_print_job_at = IF(:has_jobs = 1, UTC_TIMESTAMP(6), last_print_job_at),
            launch_count = launch_count + :launch_increment,
            print_job_count = print_job_count + :job_increment,
            activation_count = activation_count + :activations
         WHERE id = :id');
    $update->execute([
        'customer' => required_string($body, 'customerName', 160, true),
        'email' => strtolower(required_string($body, 'emailAddress', 254, true)),
        'version' => required_string($body, 'appVersion', 32),
        'mode' => $mode,
        'license_id' => $licenseId,
        'has_launches' => $launches > 0 ? 1 : 0,
        'has_jobs' => $jobs > 0 ? 1 : 0,
        'launch_increment' => $launches,
        'job_increment' => $jobs,
        'activations' => $activations,
        'id' => $installationId,
    ]);

    if ($launches > 0 || $jobs > 0) {
        $daily = $pdo->prepare(
            'INSERT INTO daily_usage (installation_id, usage_date, launch_count, print_job_count)
             VALUES (:id, UTC_DATE(), :launches, :jobs)
             ON DUPLICATE KEY UPDATE
                launch_count = launch_count + VALUES(launch_count),
                print_job_count = print_job_count + VALUES(print_job_count)');
        $daily->execute(['id' => $installationId, 'launches' => $launches, 'jobs' => $jobs]);
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (InvalidArgumentException|JsonException $exception) {
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('POS Printer Emulator telemetry failure: ' . $exception->getMessage());
    json_response(['error' => 'Telemetry could not be recorded.'], 500);
}
