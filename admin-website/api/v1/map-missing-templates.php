<?php
declare(strict_types=1);

require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST is required.']);
    exit;
}
if (!communication_service_authorized()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

try {
    @set_time_limit(120);
    $pdo = database();
    ensure_communication_schema($pdo);
    $keys = array_map(
        'strval',
        $pdo->query(
            'SELECT template_key FROM communication_templates
             WHERE brevo_template_id IS NULL ORDER BY template_key'
        )->fetchAll(PDO::FETCH_COLUMN)
    );
    $summary = ['mapped' => 0, 'failed' => 0, 'already_mapped' => 0];
    $results = [];
    foreach ($keys as $key) {
        $result = communication_create_and_map_template(
            $pdo,
            $key,
            'protected-template-mapper',
            'Approved missing Brevo template mapping'
        );
        $status = (string)($result['status'] ?? 'failed');
        $summary[$status] = ($summary[$status] ?? 0) + 1;
        $results[] = [
            'template_key' => $key,
            'status' => $status,
            'template_id' => isset($result['template_id']) ? (int)$result['template_id'] : null,
            'error' => isset($result['error']) ? (string)$result['error'] : null,
        ];
    }
    echo json_encode([
        'ok' => true,
        'processed' => count($keys),
        'summary' => $summary,
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Protected Brevo template mapping failed: ' . get_class($exception));
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'The protected template mapping workflow could not complete safely.',
    ]);
}
