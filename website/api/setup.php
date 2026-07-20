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
    json_response(['ok' => true, 'statements' => count($statements)]);
} catch (InvalidArgumentException|JsonException $exception) {
    json_response(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator setup failure: ' . $exception->getMessage());
    json_response(['error' => 'Database setup failed.'], 500);
}
