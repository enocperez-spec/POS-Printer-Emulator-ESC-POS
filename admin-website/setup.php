<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/license_management.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
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
        ensure_license_management_schema($pdo);
        $licenseId = (string)($body['licenseId'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $licenseId)) {
            throw new InvalidArgumentException('Invalid licenseId.');
        }
        $pdo->beginTransaction();
        $cleanupEvents = $pdo->prepare("DELETE FROM issued_license_events WHERE license_id = :id AND customer_name = 'Deployment License Test'");
        $cleanupEvents->execute(['id' => strtolower($licenseId)]);
        $cleanup = $pdo->prepare("DELETE FROM issued_licenses WHERE license_id = :id AND customer_name = 'Deployment License Test'");
        $cleanup->execute(['id' => strtolower($licenseId)]);
        $pdo->commit();
        respond(['ok' => true, 'removed' => $cleanup->rowCount()]);
    }

    $schema = file_get_contents(__DIR__ . '/private/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Schema file is unavailable.');
    }
    $statements = split_sql_statements($schema);
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    $licenseModeColumn = $pdo->query("SHOW COLUMNS FROM installations LIKE 'license_mode'")->fetch();
    if ($licenseModeColumn && str_contains((string)$licenseModeColumn['Type'], "'Full'")) {
        $pdo->exec("ALTER TABLE installations MODIFY license_mode ENUM('Trial', 'Full', 'Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Trial'");
        $pdo->exec("UPDATE installations SET license_mode = 'Pro' WHERE license_mode = 'Full'");
        $licenseModeColumn = $pdo->query("SHOW COLUMNS FROM installations LIKE 'license_mode'")->fetch();
    }
    if ($licenseModeColumn && !str_contains((string)$licenseModeColumn['Type'], "'Lite'")) {
        $pdo->exec("ALTER TABLE installations MODIFY license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Trial'");
    } elseif ($licenseModeColumn && str_contains((string)$licenseModeColumn['Type'], "'Full'")) {
        $pdo->exec("ALTER TABLE installations MODIFY license_mode ENUM('Trial', 'Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Trial'");
    }
    $tierColumn = $pdo->query("SHOW COLUMNS FROM issued_licenses LIKE 'license_tier'")->fetch();
    if (!$tierColumn) {
        $pdo->exec("ALTER TABLE issued_licenses ADD COLUMN license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro' AFTER email_address");
    } elseif (!str_contains((string)$tierColumn['Type'], "'Lite'")) {
        $pdo->exec("ALTER TABLE issued_licenses MODIFY license_tier ENUM('Pro', 'Enterprise', 'Lite') NOT NULL DEFAULT 'Pro'");
    }
    ensure_license_management_schema($pdo);
    respond(['ok' => true, 'statements' => count($statements)]);
} catch (InvalidArgumentException|JsonException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('POS Printer Emulator admin setup failure: ' . $exception->getMessage());
    respond(['error' => 'Database setup failed.'], 500);
}
