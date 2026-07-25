<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function communications_migration_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    communications_migration_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    communications_migration_response(['error' => 'Authentication failed.'], 401);
}

try {
    $pdo = database();
    ensure_communication_schema($pdo);
    $migration = $pdo->prepare('INSERT IGNORE INTO development_migrations(migration_key) VALUES(:migration_key)');
    $migration->execute(['migration_key' => 'consent-aware-communications-v0.3.45']);
    $pdo->exec("UPDATE development_roadmap SET status='Released',completed_at=COALESCE(completed_at,UTC_TIMESTAMP(6)) WHERE item_key='v0.3.45'");
    $repairKey = 'trial-guidance-mapping-validation-20260723';
    $repairCheck = $pdo->prepare('SELECT 1 FROM development_migrations WHERE migration_key=:migration_key');
    $repairCheck->execute(['migration_key' => $repairKey]);
    $repairStatus = 'already_complete';
    if ($repairCheck->fetchColumn() === false) {
        $template = $pdo->prepare(
            'SELECT brevo_template_id,preview_brevo_template_id,preview_verified_at,
                    preview_warnings_json,mapping_status,mapping_test_sent_at
             FROM communication_templates WHERE template_key=:template_key LIMIT 1'
        );
        $template->execute(['template_key' => 'trial_guidance']);
        $trialGuidance = $template->fetch();
        if (!is_array($trialGuidance) || (int)($trialGuidance['brevo_template_id'] ?? 0) < 1) {
            throw new DomainException('Trial guidance must have a Brevo template ID before it can be validated.');
        }
        $previewWarnings = json_decode((string)($trialGuidance['preview_warnings_json'] ?? ''), true);
        $ready = (string)($trialGuidance['mapping_status'] ?? '') === 'Mapped'
            && !empty($trialGuidance['mapping_test_sent_at'])
            && (int)($trialGuidance['preview_brevo_template_id'] ?? 0) === (int)$trialGuidance['brevo_template_id']
            && !empty($trialGuidance['preview_verified_at'])
            && is_array($previewWarnings)
            && $previewWarnings === [];
        if (!$ready) {
            $repair = communication_create_and_map_template(
                $pdo,
                'trial_guidance',
                'deployment-automation',
                'Validate and test the approved Trial guidance mapping before activation'
            );
            if (($repair['status'] ?? '') === 'failed') {
                throw new DomainException((string)($repair['error'] ?? 'Trial guidance validation failed.'));
            }
            $repairStatus = (string)($repair['status'] ?? 'revalidated');
        } else {
            $repairStatus = 'mapping_already_ready';
        }
        $enable = $pdo->prepare(
            "UPDATE communication_templates
             SET enabled=1,updated_by='deployment-automation'
             WHERE template_key='trial_guidance'
               AND mapping_status='Mapped'
               AND mapping_test_sent_at IS NOT NULL
               AND preview_verified_at IS NOT NULL
               AND preview_brevo_template_id=brevo_template_id
               AND preview_warnings_json='[]'"
        );
        $enable->execute();
        $enabled = $pdo->query(
            "SELECT enabled FROM communication_templates WHERE template_key='trial_guidance'"
        )->fetchColumn();
        if ((int)$enabled !== 1) {
            throw new DomainException('Trial guidance did not satisfy every activation requirement.');
        }
        crm_record_admin_audit(
            $pdo,
            null,
            'COMMUNICATION_TEMPLATE_ENABLED',
            'deployment-automation',
            'Communication Template',
            'trial_guidance',
            'Validated, test-sent, and enabled after repairing the existing-ID activation workflow'
        );
        $migration->execute(['migration_key' => $repairKey]);
    }
    $senderMigrationKey = 'tag-based-template-senders-20260724';
    $senderUpdated = 0;
    $senderVerified = 0;
    $mappedTemplates = $pdo->query(
        'SELECT template_key,brevo_template_id
         FROM communication_templates
         WHERE brevo_template_id IS NOT NULL
         ORDER BY template_key'
    )->fetchAll();
    foreach ($mappedTemplates as $mappedTemplate) {
        $result = communication_sync_brevo_template_sender(
            $pdo,
            (string)$mappedTemplate['template_key'],
            (int)$mappedTemplate['brevo_template_id'],
            'deployment-automation'
        );
        $senderVerified++;
        if (!empty($result['changed'])) {
            $senderUpdated++;
        }
    }
    $migration->execute(['migration_key' => $senderMigrationKey]);
    $summary = communication_dashboard_summary($pdo);
    communications_migration_response([
        'ok' => true,
        'migration' => 'consent-aware-communications-v0.3.45',
        'trialGuidance' => $repairStatus,
        'senderPolicy' => [
            'updated' => $senderUpdated,
            'verified' => $senderVerified,
            'service' => 'info@buy.posprinteremulator.com',
            'sales' => 'sales@buy.posprinteremulator.com',
        ],
        'providerConfigured' => $summary['provider_configured'],
        'deliveryPaused' => $summary['emergency_stop'],
        'marketingPaused' => $summary['marketing_pause'],
    ]);
} catch (Throwable $exception) {
    error_log('POS Printer Emulator communications migration failed: ' . get_class($exception));
    communications_migration_response([
        'error' => 'The communications migration could not be completed.',
        'detail' => communication_sanitize_provider_error($exception->getMessage()),
    ], 500);
}
