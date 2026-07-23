<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/customer_crm.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function portal_support_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function portal_support_backend_config(): array
{
    $path = dirname(__DIR__, 2) . '/private/support.php';
    if (!is_file($path)) {
        throw new RuntimeException('Support service configuration is unavailable.');
    }
    $config = require $path;
    if (!is_array($config) || empty($config['github_token']) || empty($config['github_repository'])) {
        throw new RuntimeException('Support service configuration is incomplete.');
    }
    return $config;
}

function portal_support_create_issue(array $config, string $title, string $body, array $labels): array
{
    $repository = (string)$config['github_repository'];
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
        throw new RuntimeException('Support repository configuration is invalid.');
    }
    $curl = curl_init('https://api.github.com/repos/' . $repository . '/issues');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            ['title' => $title, 'body' => $body, 'labels' => $labels],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . (string)$config['github_token'],
            'Content-Type: application/json',
            'User-Agent: POS-Printer-Emulator-Customer-Portal',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub issue creation failed.');
    }
    $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['number'], $decoded['html_url'])) {
        throw new RuntimeException('GitHub returned an invalid issue response.');
    }
    return ['number' => (int)$decoded['number'], 'url' => (string)$decoded['html_url']];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portal_support_response(['error' => 'Not found.'], 404);
}
$authorization = crm_authorization_header();
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/', $authorization, $match)) {
    portal_support_response(['error' => 'Authentication required.'], 401);
}
$expectedHash = strtolower(trim((string)(private_config()['service_api']['token_hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash) ||
    !hash_equals($expectedHash, hash('sha256', $match[1]))) {
    usleep(250000);
    portal_support_response(['error' => 'Authentication failed.'], 401);
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $reference = trim((string)($body['reference'] ?? ''));
    if (!preg_match('/^SUP-[A-F0-9]{12}$/', $reference)) {
        portal_support_response(['error' => 'Invalid request.'], 422);
    }
    $pdo = database();
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        "SELECT reference_code,request_type,subject,state,github_issue_number,github_issue_url
         FROM support_requests WHERE reference_code=:reference FOR UPDATE"
    );
    $query->execute(['reference' => $reference]);
    $request = $query->fetch();
    if (!is_array($request)) {
        $pdo->rollBack();
        portal_support_response(['error' => 'Not found.'], 404);
    }
    if ((string)$request['state'] === 'Submitted') {
        $pdo->commit();
        portal_support_response([
            'ok' => true,
            'reference' => $reference,
            'issueNumber' => (int)$request['github_issue_number'],
            'issueUrl' => (string)$request['github_issue_url'],
        ]);
    }
    $label = match ((string)$request['request_type']) {
        'Bug Report' => 'bug',
        'Feature Request' => 'enhancement',
        default => 'support-request',
    };
    $issue = portal_support_create_issue(
        portal_support_backend_config(),
        '[' . $request['request_type'] . '] Customer Portal request ' . $reference,
        "Customer Portal support reference: `{$reference}`\n\nThe subject, contact information, description, replies, diagnostics, and attachments are available only in the protected Admin Portal.",
        ['support-request', $label]
    );
    $update = $pdo->prepare(
        "UPDATE support_requests
         SET state='Submitted',github_issue_number=:number,github_issue_url=:url,submitted_at=UTC_TIMESTAMP(6)
         WHERE reference_code=:reference"
    );
    $update->execute(['number' => $issue['number'], 'url' => $issue['url'], 'reference' => $reference]);
    $pdo->commit();
    try {
        $customer = $pdo->prepare('SELECT customer_id,display_name FROM customers WHERE customer_id=(SELECT customer_id FROM support_requests WHERE reference_code=:reference) LIMIT 1');
        $customer->execute(['reference' => $reference]);
        $customerRow = $customer->fetch();
        if (is_array($customerRow)) {
            communication_enqueue(
                $pdo,
                (string)$customerRow['customer_id'],
                'support_confirmation',
                [
                    'customer_name' => (string)$customerRow['display_name'],
                    'support_reference' => $reference,
                    'support_url' => (string)$issue['url'],
                ],
                'support:' . $reference
            );
        }
    } catch (Throwable $exception) {
        error_log('Customer Portal support confirmation not queued: ' . get_class($exception));
    }
    portal_support_response([
        'ok' => true,
        'reference' => $reference,
        'issueNumber' => $issue['number'],
        'issueUrl' => $issue['url'],
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer Portal support handoff failed: ' . get_class($exception));
    portal_support_response(['error' => 'Support handoff is temporarily unavailable.'], 503);
}
