<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
require dirname(__DIR__) . '/_geography.php';

function normalize_license_registration(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
}

function resolve_managed_license(
    PDO $pdo,
    mixed $reportedMode,
    ?string $licenseId,
    string $customerName,
    string $emailAddress,
    bool $lock = false
): array
{
    $mode = in_array($reportedMode, ['Lite', 'Pro', 'Enterprise'], true) ? (string)$reportedMode : 'Trial';
    if ($licenseId === null || $licenseId === '') {
        return ['mode' => 'Trial', 'license_id' => null, 'control_state' => 'Trial'];
    }
    $licenseId = strtolower($licenseId);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $licenseId)) {
        throw new InvalidArgumentException('Invalid licenseId.');
    }

    try {
        $find = $pdo->prepare(
            'SELECT license_tier, control_state, customer_name, email_address,
                    maintenance_expires_at, maintenance_revoked_at
             FROM issued_licenses WHERE license_id = :license_id' . ($lock ? ' LOCK IN SHARE MODE' : '')
        );
        $find->execute(['license_id' => $licenseId]);
        $managed = $find->fetch();
    } catch (PDOException $exception) {
        // Keep legacy telemetry available during a staged schema deployment.
        if ((string)$exception->getCode() !== '42S22') {
            throw $exception;
        }
        $managed = false;
    }

    if (!is_array($managed)) {
        // Preserve legacy signed keys that predate the central license ledger.
        return ['mode' => $mode, 'license_id' => $licenseId, 'control_state' => 'Untracked'];
    }
    if (!hash_equals(normalize_license_registration((string)$managed['customer_name']), normalize_license_registration($customerName)) ||
        !hash_equals(normalize_license_registration((string)$managed['email_address']), normalize_license_registration($emailAddress))) {
        return ['mode' => 'Trial', 'license_id' => null, 'control_state' => 'RegistrationMismatch'];
    }
    if ((string)$managed['control_state'] !== 'Enabled') {
        return ['mode' => 'Trial', 'license_id' => null, 'control_state' => (string)$managed['control_state']];
    }
    return [
        'mode' => in_array($managed['license_tier'], ['Lite', 'Pro', 'Enterprise'], true) ? (string)$managed['license_tier'] : $mode,
        'license_id' => $licenseId,
        'control_state' => 'Enabled',
        'maintenance_status' => !empty($managed['maintenance_revoked_at']) ? 'Revoked' :
            (empty($managed['maintenance_expires_at']) || strtotime((string)$managed['maintenance_expires_at'] . ' UTC') < time() ? 'Expired' : 'Active'),
        'maintenance_expires_at' => empty($managed['maintenance_expires_at']) ? null : (string)$managed['maintenance_expires_at'],
    ];
}

function resolve_maintenance_telemetry(array $body, array $managedLicense): array
{
    if (($managedLicense['mode'] ?? 'Trial') === 'Trial') {
        return ['status'=>'NotApplicable','expires_at'=>null];
    }
    if (isset($managedLicense['maintenance_status'])) {
        return [
            'status'=>(string)$managedLicense['maintenance_status'],
            'expires_at'=>$managedLicense['maintenance_expires_at'] ?? null,
        ];
    }
    $status=(string)($body['maintenanceStatus']??'NotApplicable');
    if(!in_array($status,['NotApplicable','Active','Expired','Revoked'],true)){
        throw new InvalidArgumentException('Invalid maintenanceStatus.');
    }
    $expiresAt=null;
    if(isset($body['maintenanceExpiresAt'])&&$body['maintenanceExpiresAt']!==null&&trim((string)$body['maintenanceExpiresAt'])!==''){
        try{$expiresAt=(new DateTimeImmutable((string)$body['maintenanceExpiresAt']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
        catch(Throwable){throw new InvalidArgumentException('Invalid maintenanceExpiresAt.');}
    }
    return ['status'=>$status,'expires_at'=>$expiresAt];
}

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
    ensure_geography_storage($pdo);
    if ($action === 'register') {
        $customerName = required_string($body, 'customerName', 160, true);
        $emailAddress = strtolower(required_string($body, 'emailAddress', 254, true));
        $appVersion = required_string($body, 'appVersion', 32);
        $submittedLicenseId = empty($body['licenseId']) ? null : required_string($body, 'licenseId', 36);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $geography = request_geography();
        $pdo->beginTransaction();
        try {
            $managedLicense = resolve_managed_license(
                $pdo,
                $body['licenseMode'] ?? 'Trial',
                $submittedLicenseId,
                $customerName,
                $emailAddress,
                true
            );
            $maintenance=resolve_maintenance_telemetry($body,$managedLicense);
            $statement = $pdo->prepare(
                'INSERT INTO installations
                    (installation_uuid, token_hash, customer_name, email_address, app_version, license_mode, license_id,
                     maintenance_status, maintenance_expires_at, country_code, region_code, geo_updated_at)
                 VALUES (:uuid, UNHEX(SHA2(:token, 256)), :customer, :email, :version, :mode, :license_id,
                         :maintenance_status, :maintenance_expires_at, :country_code, :region_code, UTC_TIMESTAMP(6))');
            $statement->execute([
                'uuid' => strtolower($installationUuid),
                'token' => $token,
                'customer' => $customerName,
                'email' => $emailAddress,
                'version' => $appVersion,
                'mode' => $managedLicense['mode'],
                'license_id' => $managedLicense['license_id'],
                'maintenance_status'=>$maintenance['status'],
                'maintenance_expires_at'=>$maintenance['expires_at'],
                'country_code' => $geography['country_code'],
                'region_code' => $geography['region_code'],
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$exception->getCode() === '23000') {
                json_response(['error' => 'Installation already registered.'], 409);
            }
            throw $exception;
        }
        json_response([
            'ok' => true,
            'token' => $token,
            'serverLicenseMode' => $managedLicense['mode'],
            'licenseControlState' => $managedLicense['control_state'],
            'serverMaintenanceStatus' => $maintenance['status'],
            'serverMaintenanceExpiresAt' => $maintenance['expires_at'],
        ], 201);
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
        'SELECT id, country_code, region_code, geo_updated_at FROM installations
         WHERE installation_uuid = :uuid AND token_hash = UNHEX(SHA2(:token, 256))');
    $find->execute(['uuid' => strtolower($installationUuid), 'token' => $installationToken]);
    $installation = $find->fetch();
    if (!is_array($installation)) {
        json_response(['error' => 'Authentication failed.'], 401);
    }
    $installationId = (int)$installation['id'];

    $event = required_string($body, 'event', 24);
    if (!in_array($event, ['launch', 'print_job', 'activation', 'heartbeat'], true)) {
        throw new InvalidArgumentException('Invalid event.');
    }
    $count = max(1, min(1000, (int)($body['count'] ?? 1)));
    $customerName = required_string($body, 'customerName', 160, true);
    $emailAddress = strtolower(required_string($body, 'emailAddress', 254, true));
    $appVersion = required_string($body, 'appVersion', 32);
    $submittedLicenseId = empty($body['licenseId']) ? null : required_string($body, 'licenseId', 36);
    $launches = $event === 'launch' ? $count : 0;
    $jobs = $event === 'print_job' ? $count : 0;
    $activations = $event === 'activation' ? 1 : 0;
    $geography = [
        'country_code' => normalize_country_code($installation['country_code'] ?? ''),
        'region_code' => normalize_region_code($installation['region_code'] ?? '', normalize_country_code($installation['country_code'] ?? '')),
    ];
    $refreshGeography = should_refresh_geography($geography['country_code'], $installation['geo_updated_at'] ?? null);
    if ($refreshGeography) {
        $resolvedGeography = request_geography();
        if ($resolvedGeography['country_code'] !== UNKNOWN_COUNTRY_CODE || $geography['country_code'] === UNKNOWN_COUNTRY_CODE) {
            $geography = $resolvedGeography;
        }
    }

    $pdo->beginTransaction();
    $managedLicense = resolve_managed_license(
        $pdo,
        $body['licenseMode'] ?? 'Trial',
        $submittedLicenseId,
        $customerName,
        $emailAddress,
        true
    );
    $maintenance=resolve_maintenance_telemetry($body,$managedLicense);
    $mode = $managedLicense['mode'];
    $licenseId = $managedLicense['license_id'];
    $update = $pdo->prepare(
        'UPDATE installations SET
            customer_name = :customer,
            email_address = :email,
            app_version = :version,
            license_mode = :mode,
            license_id = :license_id,
            maintenance_status = :maintenance_status,
            maintenance_expires_at = :maintenance_expires_at,
            country_code = :country_code,
            region_code = :region_code,
            geo_updated_at = IF(:refresh_geography = 1, UTC_TIMESTAMP(6), geo_updated_at),
            last_seen_at = UTC_TIMESTAMP(6),
            last_launch_at = IF(:has_launches = 1, UTC_TIMESTAMP(6), last_launch_at),
            last_print_job_at = IF(:has_jobs = 1, UTC_TIMESTAMP(6), last_print_job_at),
            launch_count = launch_count + :launch_increment,
            print_job_count = print_job_count + :job_increment,
            activation_count = activation_count + :activations
         WHERE id = :id');
    $update->execute([
        'customer' => $customerName,
        'email' => $emailAddress,
        'version' => $appVersion,
        'mode' => $mode,
        'license_id' => $licenseId,
        'maintenance_status'=>$maintenance['status'],
        'maintenance_expires_at'=>$maintenance['expires_at'],
        'country_code' => $geography['country_code'],
        'region_code' => $geography['region_code'],
        'refresh_geography' => $refreshGeography ? 1 : 0,
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
    json_response([
        'ok' => true,
        'serverLicenseMode' => $managedLicense['mode'],
        'licenseControlState' => $managedLicense['control_state'],
        'serverMaintenanceStatus' => $maintenance['status'],
        'serverMaintenanceExpiresAt' => $maintenance['expires_at'],
    ]);
} catch (InvalidArgumentException|JsonException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('POS Printer Emulator telemetry failure: ' . $exception->getMessage());
    json_response(['error' => 'Telemetry could not be recorded.'], 500);
}
