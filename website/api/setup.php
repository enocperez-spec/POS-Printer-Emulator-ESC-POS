<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Not found.'], 404);
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $escaped = false;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $buffer .= $character;
            }
            continue;
        }

        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }

        if ($quote !== null) {
            $buffer .= $character;
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $buffer .= $character;
        } elseif ($character === '#' || ($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2])))) {
            $lineComment = true;
            if ($character === '-') {
                $index++;
            }
        } elseif ($character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
        } elseif ($character === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        } else {
            $buffer .= $character;
        }
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('The protected schema contains an incomplete SQL statement.');
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
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

    $statements = split_sql_statements($schema);
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    $installationColumns=[];
    foreach($pdo->query('SHOW COLUMNS FROM installations')->fetchAll() as $column){
        $installationColumns[(string)$column['Field']]=true;
    }
    if(!isset($installationColumns['maintenance_status'])){
        $pdo->exec("ALTER TABLE installations ADD COLUMN maintenance_status ENUM('NotApplicable','Active','Expired','Revoked') NOT NULL DEFAULT 'NotApplicable' AFTER license_id");
    }else{
        $maintenanceStatusColumn=$pdo->query("SHOW COLUMNS FROM installations LIKE 'maintenance_status'")->fetch();
        if($maintenanceStatusColumn&&!str_contains((string)$maintenanceStatusColumn['Type'],"'Revoked'")){
            $pdo->exec("ALTER TABLE installations MODIFY maintenance_status ENUM('NotApplicable','Active','Expired','Revoked') NOT NULL DEFAULT 'NotApplicable'");
        }
    }
    if(!isset($installationColumns['maintenance_expires_at'])){
        $pdo->exec('ALTER TABLE installations ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER maintenance_status');
    }
    if(!isset($installationColumns['country_code'])){
        $pdo->exec("ALTER TABLE installations ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT 'ZZ' AFTER maintenance_expires_at");
    }
    if(!isset($installationColumns['region_code'])){
        $pdo->exec("ALTER TABLE installations ADD COLUMN region_code VARCHAR(8) NOT NULL DEFAULT '' AFTER country_code");
    }
    if(!isset($installationColumns['geo_updated_at'])){
        $pdo->exec('ALTER TABLE installations ADD COLUMN geo_updated_at DATETIME(6) NULL AFTER region_code');
    }
    $installationIndexes=[];
    foreach($pdo->query('SHOW INDEX FROM installations')->fetchAll() as $index){
        $installationIndexes[(string)$index['Key_name']]=true;
    }
    if(!isset($installationIndexes['ix_installations_geography'])){
        $pdo->exec('CREATE INDEX ix_installations_geography ON installations (country_code, region_code)');
    }
    $licenseColumns=[];
    foreach($pdo->query('SHOW COLUMNS FROM issued_licenses')->fetchAll() as $column){
        $licenseColumns[(string)$column['Field']]=true;
    }
    if(!isset($licenseColumns['maintenance_expires_at'])){
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN maintenance_expires_at DATETIME(6) NULL AFTER source_reference');
    }
    if(!isset($licenseColumns['maintenance_revoked_at'])){
        $pdo->exec('ALTER TABLE issued_licenses ADD COLUMN maintenance_revoked_at DATETIME(6) NULL AFTER maintenance_expires_at');
    }
    $pdo->exec("UPDATE issued_licenses SET maintenance_expires_at='2027-07-19 23:59:59.000000' WHERE maintenance_expires_at IS NULL");
    $pdo->exec(
        "INSERT IGNORE INTO license_maintenance_events
            (license_id,event_type,new_expires_at,source_reference,reason,performed_by,created_at)
         SELECT license_id,'LEGACY_GRANDFATHERED',maintenance_expires_at,CONCAT('grandfather:',license_id),
                'Existing paid license granted maintenance through July 19, 2027.','schema-migration',UTC_TIMESTAMP(6)
         FROM issued_licenses WHERE maintenance_expires_at='2027-07-19 23:59:59.000000'"
    );
    json_response(['ok' => true, 'statements' => count($statements)]);
} catch (InvalidArgumentException|JsonException $exception) {
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator setup failure: ' . $exception->getMessage());
    json_response(['error' => 'Database setup failed.'], 500);
}
