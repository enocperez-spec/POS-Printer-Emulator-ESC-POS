<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function communications_template_sync_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    communications_template_sync_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    communications_template_sync_response(['error' => 'Authentication failed.'], 401);
}

try {
    $body = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $templateKey = strtolower(trim((string)($body['template_key'] ?? '')));
    $sendTest = ($body['send_test'] ?? false) === true;
    if (!preg_match('/^[a-z0-9_]{3,64}$/', $templateKey)) {
        throw new InvalidArgumentException('A valid approved template key is required.');
    }

    $pdo = database();
    ensure_communication_schema($pdo);
    $statement = $pdo->prepare(
        'SELECT brevo_template_id,message_class,enabled
         FROM communication_templates WHERE template_key=:key LIMIT 1'
    );
    $statement->execute(['key' => $templateKey]);
    $template = $statement->fetch();
    if (!is_array($template) || (int)($template['brevo_template_id'] ?? 0) < 1) {
        throw new DomainException('The approved registry entry is not mapped to Brevo.');
    }

    $templateId = (int)$template['brevo_template_id'];
    $blueprint = communication_template_blueprint($templateKey);
    $sender = communication_template_sender(
        $pdo,
        $templateKey,
        (string)$template['message_class']
    );
    $active = (int)($template['enabled'] ?? 0) === 1;
    communication_brevo_request('PUT', '/smtp/templates/' . $templateId, [
        'sender' => ['name' => $sender['name'], 'email' => $sender['email']],
        'subject' => (string)$blueprint['subject'],
        'templateName' => (string)$blueprint['template_name'],
        'htmlContent' => (string)$blueprint['html'],
        'isActive' => $active,
        'tag' => (string)$blueprint['tag'],
    ]);
    $verified = communication_brevo_request('GET', '/smtp/templates/' . $templateId);
    $validationCopy = $verified;
    $validationCopy['isActive'] = false;
    $warnings = communication_validate_brevo_template(
        $validationCopy,
        $blueprint,
        $sender['email']
    );
    if ($warnings !== []) {
        throw new DomainException(implode(' ', $warnings));
    }
    if ((bool)($verified['isActive'] ?? false) !== $active) {
        throw new DomainException('Brevo did not retain the registry activation state.');
    }

    $update = $pdo->prepare(
        "UPDATE communication_templates SET
         preview_brevo_template_id=:template_id,preview_verified_at=UTC_TIMESTAMP(6),
         preview_warnings_json='[]',mapping_status='Mapped',mapping_error_detail=NULL,
         mapping_validated_at=UTC_TIMESTAMP(6),updated_by='service-sync'
         WHERE template_key=:key AND brevo_template_id=:mapped_id"
    );
    $update->execute([
        'template_id' => $templateId,
        'key' => $templateKey,
        'mapped_id' => $templateId,
    ]);
    crm_record_admin_audit(
        $pdo,
        null,
        'COMMUNICATION_TEMPLATE_SYNCHRONIZED',
        'service-sync',
        'Communication Template',
        $templateKey . ':' . $templateId,
        'Synchronized approved branding and content through the protected deployment workflow.'
    );
    $testSent = false;
    if ($sendTest) {
        $config = communication_config();
        $allowlist = array_values(array_filter(
            array_map('trim', is_array($config['test_allowlist'] ?? null) ? $config['test_allowlist'] : []),
            static fn(string $email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
        if ($allowlist === []) {
            throw new DomainException('A verified sandbox test recipient is required.');
        }
        $parameters = array_replace(
            communication_test_parameters($templateKey, 'POS Printer Emulator Email Test'),
            communication_global_parameters($pdo)
        );
        if ($templateKey === 'purchase_confirmation') {
            $parameters = array_replace(
                $parameters,
                communication_purchase_confirmation_parameters(
                    'MAINTENANCE',
                    'Pro',
                    'https://userportal-sandbox.posprinteremulator.com/'
                ),
                [
                'invoice_number' => 'PPE-INV-TEST-' . gmdate('Ymd-His'),
                'invoice_date' => gmdate('F j, Y'),
                'invoice_description' => 'POS Printer Emulator Pro Annual Maintenance and Support Renewal',
                'invoice_amount' => '19.99',
                'invoice_currency' => 'USD',
                'payment_status' => 'Test - No Charge',
                'transaction_reference' => 'SANDBOX-LOGO-TEST',
                ]
            );
        }
        $payload = [
            'to' => [['email' => $allowlist[0], 'name' => 'POS Printer Emulator Test Recipient']],
            'templateId' => $templateId,
            'params' => communication_validate_parameters($parameters),
            'tags' => ['ppe', 'template-branding-test'],
        ];
        $attachment = communication_invoice_attachment($templateKey, $payload['params']);
        if (is_array($attachment)) {
            $payload['attachment'] = [$attachment];
        }
        $testResult = communication_brevo_request('POST', '/smtp/email', $payload);
        if (trim((string)($testResult['messageId'] ?? '')) === '') {
            throw new RuntimeException('Brevo did not confirm the controlled branding test.');
        }
        $testSent = true;
        crm_record_admin_audit(
            $pdo,
            null,
            'COMMUNICATION_TEMPLATE_TEST_SENT',
            'service-sync',
            'Communication Template',
            $templateKey . ':' . $templateId,
            'Sent a controlled sandbox branding and attachment verification message.'
        );
        $testVerified = $pdo->prepare(
            "UPDATE communication_templates
             SET mapping_test_sent_at=UTC_TIMESTAMP(6),updated_by='service-sync'
             WHERE template_key=:key AND brevo_template_id=:template_id"
        );
        $testVerified->execute(['key' => $templateKey, 'template_id' => $templateId]);
    }
    $requeue = $pdo->prepare(
        "UPDATE communication_outbox
         SET state='Pending',available_at=UTC_TIMESTAMP(6),last_error_code=NULL,
             last_error_detail=NULL,locked_at=NULL
         WHERE template_key=:template_key
           AND state='Deferred'
           AND last_error_code='TEMPLATE_PREVIEW_REQUIRED'"
    );
    $requeue->execute(['template_key' => $templateKey]);
    communications_template_sync_response([
        'ok' => true,
        'template_key' => $templateKey,
        'template_id' => $templateId,
        'active' => $active,
        'test_sent' => $testSent,
        'requeued' => $requeue->rowCount(),
        'warnings' => [],
    ]);
} catch (InvalidArgumentException | DomainException $exception) {
    communications_template_sync_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator template synchronization failed: ' . get_class($exception));
    communications_template_sync_response(['error' => 'The approved template could not be synchronized safely.'], 500);
}
