<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/license_management.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const SUPPORT_MAX_BODY_BYTES = 14_500_000;
const SUPPORT_MAX_ATTACHMENT_BYTES = 5_242_880;
const SUPPORT_MAX_TOTAL_ATTACHMENT_BYTES = 10_485_760;
const SUPPORT_REQUEST_TYPES = ['Bug Report', 'Feature Request', 'License Issue', 'Other Issue'];
const SUPPORT_ATTACHMENT_TYPES = ['image/png', 'image/jpeg', 'text/plain', 'application/zip'];

function support_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function support_body(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > SUPPORT_MAX_BODY_BYTES) support_response(['error' => 'The support request is too large.'], 413);
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '' || strlen($raw) > SUPPORT_MAX_BODY_BYTES) support_response(['error' => 'The support request is empty or too large.'], 413);
    try {
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        support_response(['error' => 'Invalid request.'], 400);
    }
    if (!is_array($body)) support_response(['error' => 'Invalid request.'], 400);
    return $body;
}

function support_value(array $source, string $key, int $maximum, bool $required = true): string
{
    $value = trim((string)($source[$key] ?? ''));
    if (($required && $value === '') || mb_strlen($value) > $maximum) {
        support_response(['error' => 'One or more support request fields are invalid.'], 422);
    }
    return $value;
}

function support_redact(string $value): string
{
    $patterns = [
        '/\bPPE(?:M)?[0-9]*-[A-Za-z0-9_.-]+/i' => '[license key removed]',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[email removed]',
        '/(?<![0-9])(?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9])){3}(?![0-9])/' => '[IP address removed]',
        '/\b[A-Z]:\\[^\r\n\t]+/i' => '[local path removed]',
        '/^([^\r\n]*(?:password|secret|token|credential)[^:=\r\n]*[:=]\s*).+$/im' => '$1[credential removed]',
        '/^([^\r\n]*(?:user(?:name)?)[^:=\r\n]*[:=]\s*).+$/im' => '$1[Windows user removed]',
        '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/' => '[payment number removed]',
    ];
    $redacted = preg_replace(array_keys($patterns), array_values($patterns), $value) ?? '';
    return mb_substr($redacted, 0, 256000);
}

function ensure_support_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_request_rate_limits (
            bucket_hash BINARY(32) PRIMARY KEY,
            hits INT UNSIGNED NOT NULL,
            reset_at DATETIME(6) NOT NULL,
            KEY ix_support_rate_reset (reset_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_requests (
            reference_code VARCHAR(32) PRIMARY KEY,
            license_id CHAR(36) NOT NULL,
            request_type ENUM('Bug Report','Feature Request','License Issue','Other Issue') NOT NULL,
            subject VARCHAR(160) NOT NULL,
            contact_name VARCHAR(160) NOT NULL,
            contact_email VARCHAR(254) NOT NULL,
            private_diagnostics MEDIUMTEXT NULL,
            github_issue_number BIGINT UNSIGNED NULL,
            github_issue_url VARCHAR(500) NULL,
            state ENUM('Pending','Submitted') NOT NULL DEFAULT 'Pending',
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            submitted_at DATETIME(6) NULL,
            KEY ix_support_license_created (license_id,created_at),
            KEY ix_support_state_created (state,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_request_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference_code VARCHAR(32) NOT NULL,
            file_name VARCHAR(120) NOT NULL,
            content_type VARCHAR(64) NOT NULL,
            content MEDIUMBLOB NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT fk_support_attachment_request FOREIGN KEY (reference_code)
              REFERENCES support_requests(reference_code) ON DELETE CASCADE,
            KEY ix_support_attachment_reference (reference_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function enforce_support_rate_limit(PDO $pdo, string $licenseId, int $limit = 10): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    foreach (['ip|' . $ip, 'license|' . $licenseId] as $identity) {
        $bucket = hash('sha256', 'support-request|' . $identity, true);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM support_request_rate_limits WHERE reset_at <= UTC_TIMESTAMP(6)');
            $find = $pdo->prepare('SELECT hits FROM support_request_rate_limits WHERE bucket_hash = :bucket FOR UPDATE');
            $find->bindValue(':bucket', $bucket, PDO::PARAM_LOB);
            $find->execute();
            $hits = $find->fetchColumn();
            if ($hits === false) {
                $insert = $pdo->prepare('INSERT INTO support_request_rate_limits (bucket_hash,hits,reset_at) VALUES (:bucket,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR))');
                $insert->bindValue(':bucket', $bucket, PDO::PARAM_LOB);
                $insert->execute();
            } elseif ((int)$hits >= $limit) {
                $pdo->rollBack();
                support_response(['error' => 'Too many support requests. Try again later.'], 429);
            } else {
                $update = $pdo->prepare('UPDATE support_request_rate_limits SET hits = hits + 1 WHERE bucket_hash = :bucket');
                $update->bindValue(':bucket', $bucket, PDO::PARAM_LOB);
                $update->execute();
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

function authenticate_support_license(PDO $pdo, string $licenseId, string $registrationDigest): array
{
    $licenseId = canonical_license_uuid($licenseId);
    if (!preg_match('/^[0-9a-f]{64}$/', $registrationDigest)) support_response(['error' => 'Unauthorized.'], 401);
    $query = $pdo->prepare('SELECT license_id, customer_name, email_address, license_tier, control_state, maintenance_expires_at, maintenance_revoked_at FROM issued_licenses WHERE license_id = :id LIMIT 1');
    $query->execute(['id' => $licenseId]);
    $license = $query->fetch();
    if (!is_array($license) ||
        !hash_equals(maintenance_registration_digest((string)$license['customer_name'], (string)$license['email_address']), $registrationDigest) ||
        !in_array((string)$license['license_tier'], ['Lite', 'Pro', 'Enterprise'], true) ||
        maintenance_status($license) !== 'active') {
        support_response(['error' => 'Active Application Maintenance and Support is required.'], 403);
    }
    return $license;
}

function support_config(): array
{
    $path = dirname(__DIR__) . '/private/support.php';
    if (!is_file($path)) throw new RuntimeException('Support service configuration is unavailable.');
    $config = require $path;
    if (!is_array($config) || empty($config['github_token']) || empty($config['github_repository'])) {
        throw new RuntimeException('Support service configuration is incomplete.');
    }
    return $config;
}

function create_github_issue(array $config, string $title, string $body, array $labels): array
{
    $repository = (string)$config['github_repository'];
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) throw new RuntimeException('The support repository configuration is invalid.');
    $payload = json_encode(['title' => $title, 'body' => $body, 'labels' => $labels], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $curl = curl_init('https://api.github.com/repos/' . $repository . '/issues');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . (string)$config['github_token'],
            'Content-Type: application/json',
            'User-Agent: POS-Printer-Emulator-Support-Service',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $responseBody = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($responseBody === false || $status < 200 || $status >= 300) {
        error_log('POS Printer Emulator GitHub support request failed: HTTP ' . $status . ($error !== '' ? ' ' . $error : ''));
        throw new RuntimeException('The GitHub issue could not be created.');
    }
    $result = json_decode((string)$responseBody, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($result) || !isset($result['number'], $result['html_url'])) throw new RuntimeException('GitHub returned an invalid issue response.');
    return ['number' => (int)$result['number'], 'url' => (string)$result['html_url']];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') support_response(['error' => 'Method not allowed.'], 405);
$https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
if (!$https) support_response(['error' => 'HTTPS is required.'], 426);

try {
    $body = support_body();
    $reference = support_value($body, 'reference', 32);
    if (!preg_match('/^PPE-[0-9]{8}-[A-F0-9]{8}$/', $reference)) support_response(['error' => 'Invalid support reference.'], 422);
    $request = is_array($body['request'] ?? null) ? $body['request'] : [];
    $requestType = support_value($request, 'requestType', 32);
    if (!in_array($requestType, SUPPORT_REQUEST_TYPES, true)) support_response(['error' => 'Invalid request type.'], 422);
    $subject = support_redact(support_value($request, 'subject', 160));
    $description = support_redact(support_value($request, 'description', 8000));
    $steps = support_redact(support_value($request, 'stepsToReproduce', 8000, false));
    $expected = support_redact(support_value($request, 'expectedBehavior', 8000, false));
    $actual = support_redact(support_value($request, 'actualBehavior', 8000, false));
    $contactName = support_value($request, 'contactName', 160);
    $contactEmail = support_value($request, 'contactEmail', 254);
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) support_response(['error' => 'Invalid contact email address.'], 422);
    $licenseId = strtolower(support_value($body, 'licenseId', 36));
    $registrationDigest = strtolower(support_value($body, 'registrationDigest', 64));

    $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];
    if (count($attachments) > 3) support_response(['error' => 'Too many attachments.'], 422);
    $decodedAttachments = [];
    $attachmentTotal = 0;
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) support_response(['error' => 'Invalid attachment.'], 422);
        $fileName = basename(support_value($attachment, 'fileName', 120));
        $contentType = support_value($attachment, 'contentType', 64);
        if (!in_array($contentType, SUPPORT_ATTACHMENT_TYPES, true)) support_response(['error' => 'Invalid attachment type.'], 422);
        $content = base64_decode((string)($attachment['content'] ?? ''), true);
        if ($content === false || strlen($content) <= 0 || strlen($content) > SUPPORT_MAX_ATTACHMENT_BYTES) support_response(['error' => 'Invalid attachment size.'], 422);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $contentMatches = match ($extension) {
            'png' => $contentType === 'image/png' && str_starts_with($content, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => $contentType === 'image/jpeg' && str_starts_with($content, "\xff\xd8\xff"),
            'zip' => $contentType === 'application/zip' && str_starts_with($content, "PK\x03\x04"),
            'txt', 'log' => $contentType === 'text/plain' && !str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8'),
            default => false,
        };
        if (!$contentMatches) support_response(['error' => 'Attachment content does not match its allowed file type.'], 422);
        $attachmentTotal += strlen($content);
        if ($attachmentTotal > SUPPORT_MAX_TOTAL_ATTACHMENT_BYTES) support_response(['error' => 'Attachments are too large.'], 422);
        $decodedAttachments[] = compact('fileName', 'contentType', 'content');
    }

    $pdo = database();
    ensure_license_management_schema($pdo);
    ensure_support_schema($pdo);
    $license = authenticate_support_license($pdo, $licenseId, $registrationDigest);
    enforce_support_rate_limit($pdo, $licenseId);

    $existing = $pdo->prepare('SELECT state, github_issue_number, github_issue_url FROM support_requests WHERE reference_code = :reference LIMIT 1');
    $existing->execute(['reference' => $reference]);
    $existingRow = $existing->fetch();
    if (is_array($existingRow) && $existingRow['state'] === 'Submitted') {
        support_response(['reference' => $reference, 'state' => 'Submitted', 'message' => 'This support request was already submitted.', 'issueNumber' => (int)$existingRow['github_issue_number'], 'issueUrl' => (string)$existingRow['github_issue_url']]);
    }

    $diagnostics = support_redact((string)($body['diagnostics'] ?? ''));
    if (!is_array($existingRow)) {
        $insert = $pdo->prepare('INSERT INTO support_requests (reference_code,license_id,request_type,subject,contact_name,contact_email,private_diagnostics) VALUES (:reference,:license_id,:request_type,:subject,:contact_name,:contact_email,:diagnostics)');
        $insert->execute(['reference' => $reference, 'license_id' => $licenseId, 'request_type' => $requestType, 'subject' => $subject, 'contact_name' => $contactName, 'contact_email' => $contactEmail, 'diagnostics' => $diagnostics === '' ? null : $diagnostics]);
        if ($decodedAttachments !== []) {
            $attachmentInsert = $pdo->prepare('INSERT INTO support_request_attachments (reference_code,file_name,content_type,content) VALUES (:reference,:file_name,:content_type,:content)');
            foreach ($decodedAttachments as $attachment) {
                $attachmentInsert->bindValue(':reference', $reference);
                $attachmentInsert->bindValue(':file_name', $attachment['fileName']);
                $attachmentInsert->bindValue(':content_type', $attachment['contentType']);
                $attachmentInsert->bindValue(':content', $attachment['content'], PDO::PARAM_LOB);
                $attachmentInsert->execute();
            }
        }
    }

    $label = match ($requestType) {
        'Bug Report' => 'bug',
        'Feature Request' => 'enhancement',
        'License Issue' => 'license',
        default => 'support',
    };
    $issueBody = "## Support request\n\n" .
        "**Reference:** `{$reference}`  \n**Type:** {$requestType}  \n**Application:** " . support_redact((string)($body['applicationVersion'] ?? 'Unknown')) .
        "  \n**License tier:** " . support_redact((string)($body['licenseTier'] ?? $license['license_tier'])) .
        "  \n**Windows:** " . support_redact((string)($body['windowsVersion'] ?? 'Unknown')) .
        "  \n**Printer listeners:** " . support_redact((string)($body['listenerSummary'] ?? 'Not provided')) . "\n\n" .
        "## Description\n\n{$description}\n\n" .
        ($steps !== '' ? "## Steps to reproduce\n\n{$steps}\n\n" : '') .
        ($expected !== '' ? "## Expected behavior\n\n{$expected}\n\n" : '') .
        ($actual !== '' ? "## Actual behavior\n\n{$actual}\n\n" : '') .
        "---\nCustomer contact information, diagnostics, and " . count($decodedAttachments) . " attachment(s) are retained privately in the Admin Portal under reference `{$reference}`.";

    $issue = create_github_issue(support_config(), "[{$requestType}] {$subject}", $issueBody, ['support-request', $label]);
    $update = $pdo->prepare("UPDATE support_requests SET state='Submitted', github_issue_number=:number, github_issue_url=:url, submitted_at=UTC_TIMESTAMP(6) WHERE reference_code=:reference");
    $update->execute(['number' => $issue['number'], 'url' => $issue['url'], 'reference' => $reference]);
    support_response(['reference' => $reference, 'state' => 'Submitted', 'message' => 'Your support request was submitted securely.', 'issueNumber' => $issue['number'], 'issueUrl' => $issue['url']]);
} catch (InvalidArgumentException $exception) {
    support_response(['error' => 'Invalid license information.'], 401);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator support request failure: ' . $exception->getMessage());
    support_response(['error' => 'The support request service is temporarily unavailable.'], 503);
}
