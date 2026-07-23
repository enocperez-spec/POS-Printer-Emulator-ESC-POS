<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/communications.php';
require_authentication();
require_admin_capability('communications.read');

$pdo = database();
ensure_communication_schema($pdo);
$actor = trim((string)($_SESSION['admin_username'] ?? 'owner')) ?: 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin_capability('communications.manage');
    require_recent_admin_authentication('/communications.php');
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    try {
        if (mb_strlen($reason) < 8 || !hash_equals('yes', (string)($_POST['confirmed'] ?? ''))) {
            throw new InvalidArgumentException('Confirm the action and provide a business reason of at least eight characters.');
        }
        if ($action === 'emergency_stop') {
            $paused = (string)($_POST['paused'] ?? '1') === '1';
            $phrase = $paused ? 'PAUSE' : 'RESUME';
            if (!hash_equals($phrase, strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                throw new InvalidArgumentException('Type ' . $phrase . ' to confirm this delivery change.');
            }
            $statement = $pdo->prepare(
                "INSERT INTO communication_settings(setting_key,setting_value,updated_by)
                 VALUES('emergency_stop',:value,:actor)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)"
            );
            $statement->execute(['value' => $paused ? '1' : '0', 'actor' => $actor]);
            crm_record_admin_audit($pdo, null, $paused ? 'COMMUNICATIONS_PAUSED' : 'COMMUNICATIONS_RESUMED', $actor, 'Communications', null, $reason);
            $_SESSION['communications_flash'] = ['type' => 'success', 'message' => $paused ? 'All outbound delivery is paused.' : 'Outbound delivery is enabled. The worker will still enforce every policy and quota check.'];
        } elseif ($action === 'marketing_pause') {
            $paused = (string)($_POST['paused'] ?? '1') === '1';
            $phrase = $paused ? 'PAUSE' : 'RESUME';
            if (!hash_equals($phrase, strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                throw new InvalidArgumentException('Type ' . $phrase . ' to confirm this marketing delivery change.');
            }
            $statement = $pdo->prepare(
                "INSERT INTO communication_settings(setting_key,setting_value,updated_by)
                 VALUES('marketing_pause',:value,:actor)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)"
            );
            $statement->execute(['value' => $paused ? '1' : '0', 'actor' => $actor]);
            crm_record_admin_audit($pdo, null, $paused ? 'MARKETING_PAUSED' : 'MARKETING_RESUMED', $actor, 'Communications', null, $reason);
            $_SESSION['communications_flash'] = ['type' => 'success', 'message' => $paused ? 'Optional marketing delivery is paused. Service messages are unaffected.' : 'Eligible marketing delivery is enabled. Consent and suppression rules still apply.'];
        } elseif ($action === 'template') {
            $key = strtolower(trim((string)($_POST['template_key'] ?? '')));
            $templateIdText = trim((string)($_POST['brevo_template_id'] ?? ''));
            $templateId = $templateIdText === '' ? null : filter_var($templateIdText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cap = filter_var($_POST['frequency_cap_hours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8760]]);
            $enabled = (string)($_POST['enabled'] ?? '') === '1';
            if (!preg_match('/^[a-z0-9_]{3,64}$/', $key) || $templateId === false || $cap === false || ($enabled && $templateId === null)) {
                throw new InvalidArgumentException('Enter a valid provider template ID and frequency cap before enabling this template.');
            }
            $update = $pdo->prepare(
                'UPDATE communication_templates SET brevo_template_id=:template_id,enabled=:enabled,
                 frequency_cap_hours=:cap,updated_by=:actor WHERE template_key=:key'
            );
            $update->bindValue(':template_id', $templateId, $templateId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $update->bindValue(':enabled', $enabled ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(':cap', $cap, PDO::PARAM_INT);
            $update->bindValue(':actor', $actor);
            $update->bindValue(':key', $key);
            $update->execute();
            if ($update->rowCount() !== 1) throw new InvalidArgumentException('The selected template was not found.');
            crm_record_admin_audit($pdo, null, 'COMMUNICATION_TEMPLATE_UPDATED', $actor, 'Communication Template', $key, $reason);
            $_SESSION['communications_flash'] = ['type' => 'success', 'message' => 'The approved template mapping was updated.'];
        } elseif ($action === 'queue_test') {
            $customerId = strtolower(trim((string)($_POST['customer_id'] ?? '')));
            $templateKey = strtolower(trim((string)($_POST['template_key'] ?? '')));
            if (!preg_match('/^[0-9a-f-]{36}$/', $customerId)) {
                throw new InvalidArgumentException('Enter the verified customer ID that should receive the test.');
            }
            $customer = $pdo->prepare('SELECT display_name FROM customers WHERE customer_id=:id LIMIT 1');
            $customer->execute(['id' => $customerId]);
            $name = $customer->fetchColumn();
            if (!is_string($name)) throw new InvalidArgumentException('The selected customer was not found.');
            $messageId = communication_enqueue(
                $pdo,
                $customerId,
                $templateKey,
                communication_test_parameters($templateKey, $name),
                'admin-test:' . $templateKey . ':' . $customerId . ':' . gmdate('YmdHi'),
                null,
                true
            );
            crm_record_admin_audit($pdo, $customerId, 'COMMUNICATION_TEST_QUEUED', $actor, 'Communication', $messageId, $reason);
            $_SESSION['communications_flash'] = ['type' => 'success', 'message' => 'The test message was queued. Policy, quota, and test-allowlist checks still apply.'];
        } elseif (in_array($action, ['retry', 'cancel'], true)) {
            $messageId = strtolower(trim((string)($_POST['message_id'] ?? '')));
            if (!preg_match('/^[0-9a-f-]{36}$/', $messageId)) throw new InvalidArgumentException('The selected message is invalid.');
            if ($action === 'retry') {
                $update = $pdo->prepare(
                    "UPDATE communication_outbox SET state='Deferred',available_at=UTC_TIMESTAMP(6),locked_at=NULL,
                     last_error_code=NULL,last_error_detail=NULL
                     WHERE message_id=:id AND state IN ('Failed','Cancelled')"
                );
            } else {
                $update = $pdo->prepare(
                    "UPDATE communication_outbox SET state='Cancelled',locked_at=NULL,last_error_code='ADMIN_CANCELLED',
                     last_error_detail='Cancelled after administrator review.'
                     WHERE message_id=:id AND state IN ('Pending','Deferred','Failed','DeliveryUnknown')"
                );
            }
            $update->execute(['id' => $messageId]);
            if ($update->rowCount() !== 1) throw new DomainException('The message state changed before this action completed.');
            crm_record_admin_audit($pdo, null, $action === 'retry' ? 'COMMUNICATION_RETRY' : 'COMMUNICATION_CANCELLED', $actor, 'Communication', $messageId, $reason);
            $_SESSION['communications_flash'] = ['type' => 'success', 'message' => $action === 'retry' ? 'The message is ready for one reviewed retry.' : 'The message was cancelled.'];
        } else {
            throw new InvalidArgumentException('Unsupported communications action.');
        }
    } catch (InvalidArgumentException|DomainException $exception) {
        $_SESSION['communications_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    } catch (Throwable $exception) {
        error_log('POS Printer Emulator communications admin action failed: ' . get_class($exception));
        $_SESSION['communications_flash'] = ['type' => 'error', 'message' => 'The action could not be completed safely.'];
    }
    header('Location: /communications.php');
    exit;
}

if ((string)($_GET['export'] ?? '') === 'queue') {
    require_admin_capability('communications.export');
    require_recent_admin_authentication('/communications.php?export=queue');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ppe-communications-' . gmdate('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Message ID', 'Customer ID', 'Template', 'Class', 'State', 'Attempts', 'Provider ID', 'Error code', 'Created UTC', 'Sent UTC']);
    $rows = $pdo->query(
        'SELECT message_id,customer_id,template_key,message_class,state,attempts,provider_message_id,last_error_code,created_at,sent_at
         FROM communication_outbox ORDER BY created_at DESC LIMIT 5000'
    );
    foreach ($rows as $row) fputcsv($output, array_values($row));
    fclose($output);
    exit;
}

$flash = is_array($_SESSION['communications_flash'] ?? null) ? $_SESSION['communications_flash'] : null;
unset($_SESSION['communications_flash']);
$summary = communication_dashboard_summary($pdo);
$templates = $pdo->query('SELECT * FROM communication_templates ORDER BY message_class,essential DESC,display_name')->fetchAll();
$queue = $pdo->query(
    'SELECT o.message_id,o.customer_id,o.template_key,o.message_class,o.essential,o.state,o.attempts,
            o.last_error_code,o.created_at,o.sent_at,c.display_name
     FROM communication_outbox o JOIN customers c ON c.customer_id=o.customer_id
     ORDER BY o.created_at DESC LIMIT 100'
)->fetchAll();
$segments = $pdo->query(
    "SELECT
       COUNT(*) AS active_customers,
       SUM(c.email_verified_at IS NOT NULL) AS verified_customers,
       SUM((SELECT cc.consent_state FROM customer_consents cc WHERE cc.customer_id=c.customer_id AND cc.consent_type='Marketing' ORDER BY cc.id DESC LIMIT 1)='Granted') AS marketing_opted_in,
       SUM(EXISTS(SELECT 1 FROM customer_email_suppressions s WHERE s.customer_id=c.customer_id AND s.active=1)) AS suppressed_customers
     FROM customers c WHERE c.status='Active'"
)->fetch() ?: [];
$delivery = $pdo->query(
    "SELECT
       SUM(event_type='delivered') AS delivered,
       SUM(event_type IN ('opened','unique_opened')) AS opened,
       SUM(event_type IN ('click','clicked','unique_clicked')) AS clicked,
       SUM(event_type IN ('hardbounce','hard_bounce')) AS bounced,
       SUM(event_type IN ('spam','complaint')) AS complaints,
       SUM(event_type='unsubscribed') AS unsubscribed
     FROM communication_delivery_events
     WHERE occurred_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)"
)->fetch() ?: [];
$lifecycle = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM customers WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS registrations,
       (SELECT COUNT(DISTINCT customer_id) FROM installations WHERE customer_id IS NOT NULL AND portal_deactivated_at IS NULL AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS active_customers,
       (SELECT COUNT(DISTINCT customer_id) FROM installations WHERE customer_id IS NOT NULL AND portal_deactivated_at IS NULL AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS inactive_customers,
       (SELECT COUNT(DISTINCT i.customer_id) FROM installations i JOIN issued_licenses l ON l.customer_id=i.customer_id AND l.control_state='Enabled' WHERE i.license_mode='Trial') AS trial_conversions,
       (SELECT COUNT(*) FROM customer_purchases WHERE purchase_status='FULFILLED' AND paid_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS purchases,
       (SELECT COUNT(*) FROM portal_checkout_intents WHERE order_type='UPGRADE' AND state='Fulfilled' AND fulfilled_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS upgrades,
       (SELECT COUNT(*) FROM issued_licenses WHERE control_state='Enabled' AND maintenance_revoked_at IS NULL AND maintenance_expires_at BETWEEN UTC_TIMESTAMP(6) AND DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 DAY)) AS maintenance_due,
       (SELECT COUNT(*) FROM support_requests WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)) AS support_requests,
       (SELECT COUNT(DISTINCT i.customer_id) FROM installations i
          WHERE i.customer_id IS NOT NULL AND i.portal_deactivated_at IS NULL
            AND CONCAT('v',i.app_version)<>(SELECT version_label FROM development_roadmap WHERE item_type='Release' AND status='Released' ORDER BY priority_rank DESC LIMIT 1)) AS outdated_customers"
)->fetch() ?: [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Communications | POS Printer Emulator Admin Portal</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/communications.css?v=20260723-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span><?=e(ucfirst(admin_role()))?> account</span><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span>▥</span>Dashboard</a><a href="/customers.php"><span>◎</span>Customers</a><a href="/licenses.php"><span>◇</span>License Manager</a><a href="/orders.php"><span>▤</span>Purchase Orders</a><a href="/pricing.php"><span>$</span>Purchase Pricing</a><a class="active" href="/communications.php"><span>✉</span>Communications</a><a href="/dev-support.php"><span>⌁</span>Dev Support</a></nav><p>Consent, suppressions, frequency caps, and quota rules are checked again at delivery time.</p></aside>
<main class="communications-main"><div class="page-heading"><div><span class="eyebrow">v0.3.45</span><h1>Customer communications</h1><p>Protected lifecycle email with consent evidence, durable delivery, and a reserved service-message quota.</p></div><?php if(admin_can('communications.export')):?><a class="secondary" href="?export=queue">Export privacy-safe queue CSV</a><?php endif;?></div>
<?php if($flash):?><div class="notice <?=e((string)$flash['type'])?>" role="status"><?=e((string)$flash['message'])?></div><?php endif;?>
<section class="status-banner <?=$summary['emergency_stop']?'paused':'live'?>"><div><strong><?=$summary['emergency_stop']?'Delivery paused':'Delivery active'?></strong><span>Provider <?= $summary['provider_configured'] ? 'configured' : 'not configured' ?> · <?=e((string)$summary['mode'])?> mode</span></div><?php if(admin_can('communications.manage')):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="emergency_stop"><input type="hidden" name="paused" value="<?=$summary['emergency_stop']?'0':'1'?>"><input type="hidden" name="confirmed" value="yes"><input name="reason" minlength="8" maxlength="500" placeholder="Business reason" required><input name="confirmation_phrase" placeholder="Type <?=$summary['emergency_stop']?'RESUME':'PAUSE'?>" required><button><?=$summary['emergency_stop']?'Resume delivery':'Pause delivery'?></button></form><?php endif;?></section>
<section class="status-banner <?=$summary['marketing_pause']?'paused':'live'?>"><div><strong><?=$summary['marketing_pause']?'Marketing paused':'Marketing active'?></strong><span>Service messages continue independently. Optional marketing always requires current consent.</span></div><?php if(admin_can('communications.manage')):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketing_pause"><input type="hidden" name="paused" value="<?=$summary['marketing_pause']?'0':'1'?>"><input type="hidden" name="confirmed" value="yes"><input name="reason" minlength="8" maxlength="500" placeholder="Business reason" required><input name="confirmation_phrase" placeholder="Type <?=$summary['marketing_pause']?'RESUME':'PAUSE'?>" required><button><?=$summary['marketing_pause']?'Resume marketing':'Pause marketing'?></button></form><?php endif;?></section>
<section class="metrics"><?php foreach([
    ['Provider quota',(int)$summary['quota']['provider_used'].' / '.$summary['limits']['provider']],
    ['Automated quota',(int)$summary['quota']['automated_used'].' / '.$summary['limits']['automated']],
    ['Service reserve',$summary['limits']['reserve'].' protected'],
    ['Pending',(int)($summary['states']['Pending']??0)+(int)($summary['states']['Deferred']??0)],
    ['Sent',(int)($summary['states']['Sent']??0)],
    ['Needs review',(int)($summary['states']['Failed']??0)+(int)($summary['states']['DeliveryUnknown']??0)],
] as [$label,$value]):?><article><span><?=e((string)$label)?></span><strong><?=e((string)$value)?></strong></article><?php endforeach;?></section>
<section class="segments"><div><h2>Eligible audience overview</h2><p>Counts are aggregate. A message still receives a fresh customer-level policy check when the worker claims it.</p></div><dl><div><dt>Active</dt><dd><?= (int)($segments['active_customers']??0) ?></dd></div><div><dt>Verified email</dt><dd><?= (int)($segments['verified_customers']??0) ?></dd></div><div><dt>Marketing opt-in</dt><dd><?= (int)($segments['marketing_opted_in']??0) ?></dd></div><div><dt>Suppressed</dt><dd><?= (int)($segments['suppressed_customers']??0) ?></dd></div></dl></section>
<section class="panel"><header><div><h2>Customer lifecycle · last 30 days</h2><p>Aggregate operational counts help prioritize follow-up without exposing receipt contents. Manual uninstall reasons are not collected.</p></div></header><section class="metrics"><?php foreach([
    ['Registrations',(int)($lifecycle['registrations']??0)],
    ['Active customers',(int)($lifecycle['active_customers']??0)],
    ['Inactive customers',(int)($lifecycle['inactive_customers']??0)],
    ['Trial conversions',(int)($lifecycle['trial_conversions']??0)],
    ['Purchases',(int)($lifecycle['purchases']??0)],
    ['Upgrades',(int)($lifecycle['upgrades']??0)],
    ['Maintenance due',(int)($lifecycle['maintenance_due']??0)],
    ['Support requests',(int)($lifecycle['support_requests']??0)],
    ['Outdated versions',(int)($lifecycle['outdated_customers']??0)],
] as [$label,$value]):?><article><span><?=e((string)$label)?></span><strong><?=e((string)$value)?></strong></article><?php endforeach;?></section></section>
<section class="panel"><header><div><h2>Provider events · last 30 days</h2><p>Open and click counts are privacy-limited provider signals and must not be treated as exact measures of customer interest.</p></div></header><section class="metrics"><?php foreach([
    ['Delivered',(int)($delivery['delivered']??0)],
    ['Opened · approximate',(int)($delivery['opened']??0)],
    ['Clicked · approximate',(int)($delivery['clicked']??0)],
    ['Hard bounced',(int)($delivery['bounced']??0)],
    ['Complaints',(int)($delivery['complaints']??0)],
    ['Unsubscribed',(int)($delivery['unsubscribed']??0)],
] as [$label,$value]):?><article><span><?=e((string)$label)?></span><strong><?=e((string)$value)?></strong></article><?php endforeach;?></section></section>
<section class="panel"><header><div><h2>Approved template registry</h2><p>Templates remain disabled until an owner maps an approved Brevo template ID.</p></div></header><div class="template-grid"><?php foreach($templates as $template):?><?php $readOnly=!admin_can('communications.manage'); ?><form method="post" class="template-card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="template"><input type="hidden" name="template_key" value="<?=e((string)$template['template_key'])?>"><input type="hidden" name="confirmed" value="yes"><div><span class="pill <?=strtolower((string)$template['message_class'])?>"><?=e((string)$template['message_class'])?></span><?php if($template['essential']):?><span class="pill essential">Essential</span><?php endif;?></div><h3><?=e((string)$template['display_name'])?></h3><p><?=e((string)$template['description'])?></p><label>Brevo template ID<input name="brevo_template_id" inputmode="numeric" value="<?=e((string)($template['brevo_template_id']??''))?>" <?=$readOnly?'disabled':''?>></label><label>Frequency cap (hours)<input name="frequency_cap_hours" type="number" min="1" max="8760" value="<?= (int)$template['frequency_cap_hours'] ?>" <?=$readOnly?'disabled':''?>></label><label class="check"><input type="checkbox" name="enabled" value="1" <?=$template['enabled']?'checked':''?> <?=$readOnly?'disabled':''?>> Enabled</label><label>Reason<input name="reason" minlength="8" maxlength="500" value="Approved template configuration" required <?=$readOnly?'disabled':''?>></label><button <?=$readOnly?'disabled':''?>>Save mapping</button></form><?php endforeach;?></div></section>
<?php if(admin_can('communications.manage')):?><section class="panel test-panel"><div><h2>Queue a controlled test</h2><p>The recipient must be an active, verified customer. Test mode also requires the address in the protected server allowlist.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="queue_test"><input type="hidden" name="confirmed" value="yes"><label>Customer ID<input name="customer_id" pattern="[0-9a-fA-F-]{36}" required></label><label>Template<select name="template_key"><?php foreach($templates as $template):?><option value="<?=e((string)$template['template_key'])?>"><?=e((string)$template['display_name'])?></option><?php endforeach;?></select></label><label>Reason<input name="reason" minlength="8" maxlength="500" value="Controlled provider verification" required></label><button>Queue test</button></form></section><?php endif;?>
<section class="panel"><header><div><h2>Recent delivery queue</h2><p>No email address, message body, receipt data, activation key, or diagnostic log is shown or exported. DeliveryUnknown items must be reconciled in Brevo before any manual resolution and cannot be retried blindly.</p></div></header><div class="table-wrap"><table><thead><tr><th>Created UTC</th><th>Customer</th><th>Template</th><th>Class</th><th>Status</th><th>Attempts</th><th>Review</th></tr></thead><tbody><?php if(!$queue):?><tr><td colspan="7">No messages are queued.</td></tr><?php endif;?><?php foreach($queue as $message):?><tr><td><?=e((string)$message['created_at'])?></td><td><strong><?=e((string)$message['display_name'])?></strong><small><?=e((string)$message['customer_id'])?></small></td><td><?=e((string)$message['template_key'])?></td><td><?=e((string)$message['message_class'])?></td><td><span class="state <?=strtolower((string)$message['state'])?>"><?=e((string)$message['state'])?></span><?php if($message['last_error_code']):?><small><?=e((string)$message['last_error_code'])?></small><?php endif;?></td><td><?= (int)$message['attempts'] ?></td><td><?php if(admin_can('communications.manage')&&in_array($message['state'],['Failed','Cancelled'],true)):?><form method="post" class="row-action"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="message_id" value="<?=e((string)$message['message_id'])?>"><input type="hidden" name="confirmed" value="yes"><input type="hidden" name="reason" value="Reviewed manual queue retry"><button>Retry</button></form><?php elseif(admin_can('communications.manage')&&in_array($message['state'],['Pending','Deferred'],true)):?><form method="post" class="row-action"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="message_id" value="<?=e((string)$message['message_id'])?>"><input type="hidden" name="confirmed" value="yes"><input type="hidden" name="reason" value="Reviewed queue cancellation"><button>Cancel</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></div></body></html>
