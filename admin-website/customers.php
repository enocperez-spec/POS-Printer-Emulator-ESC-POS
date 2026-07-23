<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/customer_crm.php';
require_authentication();
require_admin_capability('customers.read');

$pdo = database();
$migration = backfill_customer_crm($pdo);
$actor = trim((string)($_SESSION['admin_username'] ?? 'owner')) ?: 'owner';
$flash = '';
$flashType = 'success';

function crm_customer(PDO $pdo, string $customerId): array
{
    $query = $pdo->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM installations i WHERE i.customer_id=c.customer_id) installation_count,
                (SELECT COUNT(*) FROM issued_licenses l WHERE l.customer_id=c.customer_id AND l.control_state<>'Deleted') license_count,
                (SELECT COUNT(*) FROM support_requests s WHERE s.customer_id=c.customer_id) support_count
         FROM customers c WHERE c.customer_id=:id LIMIT 1"
    );
    $query->execute(['id' => strtolower($customerId)]);
    $customer = $query->fetch();
    if (!is_array($customer)) {
        throw new InvalidArgumentException('The selected customer no longer exists.');
    }
    return $customer;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'export') {
            require_admin_capability('customers.export');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 8 || !hash_equals('yes', (string)($_POST['confirmed'] ?? ''))) {
                throw new InvalidArgumentException('Confirm the export and provide a business reason of at least eight characters.');
            }
            crm_record_admin_audit($pdo, null, 'CUSTOMER_EXPORT', $actor, 'Customer Export', null, $reason);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pos-printer-emulator-customers-' . gmdate('Ymd-His') . '.csv"');
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Customer ID', 'Name', 'Email', 'Verified UTC', 'Status', 'License tier', 'Maintenance expiration UTC', 'Application version', 'Last seen UTC']);
            $rows = $pdo->query(
                "SELECT c.customer_id,c.display_name,c.canonical_email,c.email_verified_at,c.status,
                        COALESCE((SELECT l.license_tier FROM issued_licenses l WHERE l.customer_id=c.customer_id AND l.control_state<>'Deleted' ORDER BY FIELD(l.license_tier,'Trial','Lite','Pro','Enterprise') DESC,l.issued_at DESC LIMIT 1),'Trial') license_tier,
                        (SELECT MAX(l.maintenance_expires_at) FROM issued_licenses l WHERE l.customer_id=c.customer_id AND l.control_state<>'Deleted') maintenance_expires_at,
                        (SELECT i.app_version FROM installations i WHERE i.customer_id=c.customer_id ORDER BY i.last_seen_at DESC,i.id DESC LIMIT 1) app_version,
                        (SELECT MAX(i.last_seen_at) FROM installations i WHERE i.customer_id=c.customer_id) last_seen_at
                 FROM customers c ORDER BY c.updated_at DESC LIMIT 5000"
            );
            foreach ($rows as $row) {
                fputcsv($output, array_values($row));
            }
            fclose($output);
            exit;
        }

        require_admin_capability('customers.consent');
        $customerId = strtolower(trim((string)($_POST['customer_id'] ?? '')));
        $customer = crm_customer($pdo, $customerId);
        if (!hash_equals('yes', (string)($_POST['confirmed'] ?? ''))) {
            throw new InvalidArgumentException('Review and confirm the customer action.');
        }
        if ($action === 'consent') {
            crm_record_consent(
                $pdo, $customerId, (string)($_POST['consent_type'] ?? ''), (string)($_POST['consent_state'] ?? ''),
                (string)($_POST['policy_version'] ?? ''), 'Admin Portal', $actor
            );
            crm_record_admin_audit($pdo, $customerId, 'CONSENT_RECORDED', $actor, 'Customer', $customerId, 'Append-only consent evidence recorded.');
            $flash = 'The consent evidence was recorded without changing earlier entries.';
        } elseif ($action === 'verify_email') {
            if ((string)$customer['canonical_email'] === '' || !hash_equals('VERIFIED', strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                throw new InvalidArgumentException('Type VERIFIED after independently confirming that the customer controls this email address.');
            }
            $update = $pdo->prepare('UPDATE customers SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP(6)) WHERE customer_id=:id');
            $update->execute(['id' => $customerId]);
            crm_record_admin_audit($pdo, $customerId, 'EMAIL_VERIFIED', $actor, 'Customer', $customerId, 'Ownership independently verified by an authorized administrator.');
            $flash = 'The email address is now marked verified.';
        } elseif ($action === 'merge') {
            $targetId = strtolower(trim((string)($_POST['target_customer_id'] ?? '')));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($targetId === $customerId || mb_strlen($reason) < 8 || !hash_equals('MERGE', strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                throw new InvalidArgumentException('Choose a different target, provide a reason, and type MERGE.');
            }
            $target = crm_customer($pdo, $targetId);
            if ((string)$customer['status'] !== 'Active' || (string)$target['status'] !== 'Active') {
                throw new InvalidArgumentException('Only active customer records can be merged.');
            }
            $pdo->beginTransaction();
            foreach (['installations', 'issued_licenses', 'support_requests', 'customer_purchases'] as $table) {
                $move = $pdo->prepare("UPDATE {$table} SET customer_id=:target WHERE customer_id=:source");
                $move->execute(['target' => $targetId, 'source' => $customerId]);
            }
            $merge = $pdo->prepare("UPDATE customers SET status='Merged',merged_into_customer_id=:target WHERE customer_id=:source AND status='Active'");
            $merge->execute(['target' => $targetId, 'source' => $customerId]);
            if ($merge->rowCount() !== 1) {
                throw new DomainException('The source customer changed before the merge completed.');
            }
            $history = $pdo->prepare('INSERT INTO customer_merge_history(source_customer_id,target_customer_id,reason,actor) VALUES(:source,:target,:reason,:actor)');
            $history->execute(['source' => $customerId, 'target' => $targetId, 'reason' => mb_substr($reason, 0, 500), 'actor' => $actor]);
            crm_record_admin_audit($pdo, $targetId, 'CUSTOMER_MERGED', $actor, 'Customer', $customerId, $reason);
            $pdo->commit();
            $customerId = $targetId;
            $flash = 'The reviewed records were merged. A permanent merge history entry was retained.';
        } else {
            throw new InvalidArgumentException('Unsupported customer action.');
        }
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => $flash];
        header('Location: /customers.php?customer=' . rawurlencode($customerId));
        exit;
    } catch (InvalidArgumentException|DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        header('Location: /customers.php' . (!empty($customerId) ? '?customer=' . rawurlencode($customerId) : ''));
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('POS Printer Emulator CRM action failed: ' . $exception->getMessage());
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'The customer action could not be completed. No partial change was saved.'];
        header('Location: /customers.php');
        exit;
    }
}

$sessionFlash = is_array($_SESSION['crm_flash'] ?? null) ? $_SESSION['crm_flash'] : null;
unset($_SESSION['crm_flash']);
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$tier = in_array($_GET['tier'] ?? '', ['Trial', 'Lite', 'Pro', 'Enterprise'], true) ? (string)$_GET['tier'] : '';
$verified = in_array($_GET['verified'] ?? '', ['yes', 'no'], true) ? (string)$_GET['verified'] : '';
$maintenance = in_array($_GET['maintenance'] ?? '', ['Active','Expired','Revoked','NotApplicable'], true) ? (string)$_GET['maintenance'] : '';
$activity = in_array($_GET['activity'] ?? '', ['30','inactive','never'], true) ? (string)$_GET['activity'] : '';
$marketing = in_array($_GET['marketing'] ?? '', ['Granted','Denied','Withdrawn','Not Asked'], true) ? (string)$_GET['marketing'] : '';
$supportFilter = in_array($_GET['support'] ?? '', ['yes','no'], true) ? (string)$_GET['support'] : '';
$availableVersions = $pdo->query("SELECT DISTINCT app_version FROM installations WHERE app_version<>'' ORDER BY app_version DESC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
$version = in_array((string)($_GET['version'] ?? ''), $availableVersions, true) ? (string)$_GET['version'] : '';
$duplicatesOnly = (string)($_GET['duplicates'] ?? '') === '1';
$where = ["c.status='Active'"];
$parameters = [];
if ($q !== '') {
    $where[] = '(c.display_name LIKE :query OR c.canonical_email LIKE :query OR c.customer_id=:exact)';
    $parameters['query'] = '%' . $q . '%';
    $parameters['exact'] = strtolower($q);
}
if ($tier !== '') {
    if ($tier === 'Trial') {
        $where[] = 'NOT EXISTS(SELECT 1 FROM issued_licenses lt WHERE lt.customer_id=c.customer_id AND lt.control_state<>\'Deleted\')';
    } else {
        $where[] = 'EXISTS(SELECT 1 FROM issued_licenses lt WHERE lt.customer_id=c.customer_id AND lt.license_tier=:tier AND lt.control_state<>\'Deleted\')';
        $parameters['tier'] = $tier;
    }
}
if ($verified !== '') {
    $where[] = $verified === 'yes' ? 'c.email_verified_at IS NOT NULL' : 'c.email_verified_at IS NULL';
}
if ($maintenance !== '') {
    if ($maintenance === 'NotApplicable') $where[] = "NOT EXISTS(SELECT 1 FROM issued_licenses lm WHERE lm.customer_id=c.customer_id AND lm.control_state<>'Deleted')";
    elseif ($maintenance === 'Revoked') $where[] = 'EXISTS(SELECT 1 FROM issued_licenses lm WHERE lm.customer_id=c.customer_id AND lm.maintenance_revoked_at IS NOT NULL)';
    elseif ($maintenance === 'Active') $where[] = "EXISTS(SELECT 1 FROM issued_licenses lm WHERE lm.customer_id=c.customer_id AND lm.control_state='Enabled' AND lm.maintenance_revoked_at IS NULL AND lm.maintenance_expires_at>=UTC_TIMESTAMP(6))";
    else $where[] = "EXISTS(SELECT 1 FROM issued_licenses lm WHERE lm.customer_id=c.customer_id AND lm.maintenance_revoked_at IS NULL AND lm.maintenance_expires_at<UTC_TIMESTAMP(6))";
}
if ($activity === '30') $where[] = 'EXISTS(SELECT 1 FROM installations ia WHERE ia.customer_id=c.customer_id AND ia.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY))';
elseif ($activity === 'inactive') $where[] = 'EXISTS(SELECT 1 FROM installations ia WHERE ia.customer_id=c.customer_id) AND NOT EXISTS(SELECT 1 FROM installations ia WHERE ia.customer_id=c.customer_id AND ia.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY))';
elseif ($activity === 'never') $where[] = 'NOT EXISTS(SELECT 1 FROM installations ia WHERE ia.customer_id=c.customer_id)';
if ($version !== '') { $where[] = 'EXISTS(SELECT 1 FROM installations iv WHERE iv.customer_id=c.customer_id AND iv.app_version=:version)'; $parameters['version']=$version; }
if ($marketing !== '') {
    if ($marketing === 'Not Asked') $where[] = "NOT EXISTS(SELECT 1 FROM customer_consents cm WHERE cm.customer_id=c.customer_id AND cm.consent_type='Marketing')";
    else { $where[] = "(SELECT cm.consent_state FROM customer_consents cm WHERE cm.customer_id=c.customer_id AND cm.consent_type='Marketing' ORDER BY cm.id DESC LIMIT 1)=:marketing"; $parameters['marketing']=$marketing; }
}
if ($supportFilter !== '') $where[] = ($supportFilter==='yes'?'EXISTS':'NOT EXISTS') . '(SELECT 1 FROM support_requests sr WHERE sr.customer_id=c.customer_id)';
if ($duplicatesOnly) {
    $where[] = 'c.canonical_email<>\'\' AND EXISTS(SELECT 1 FROM customers d WHERE d.email_hash=c.email_hash AND d.customer_id<>c.customer_id AND d.status=\'Active\')';
}
$list = $pdo->prepare(
    "SELECT c.customer_id,c.display_name,c.canonical_email,c.email_verified_at,c.updated_at,
            COALESCE((SELECT l.license_tier FROM issued_licenses l WHERE l.customer_id=c.customer_id AND l.control_state<>'Deleted' ORDER BY FIELD(l.license_tier,'Trial','Lite','Pro','Enterprise') DESC, l.issued_at DESC LIMIT 1),'Trial') license_tier,
            (SELECT COUNT(*) FROM installations i WHERE i.customer_id=c.customer_id) installation_count,
            (SELECT MAX(i.last_seen_at) FROM installations i WHERE i.customer_id=c.customer_id) last_seen_at,
            (SELECT COUNT(*) FROM customers d WHERE d.email_hash=c.email_hash AND d.customer_id<>c.customer_id AND d.status='Active' AND c.canonical_email<>'') duplicate_count
     FROM customers c WHERE " . implode(' AND ', $where) . ' ORDER BY c.updated_at DESC LIMIT 250'
);
$list->execute($parameters);
$customers = $list->fetchAll();
$selectedId = strtolower((string)($_GET['customer'] ?? ($customers[0]['customer_id'] ?? '')));
$selected = null;
$licenses = $installations = $supportRequests = $purchases = $consents = $events = [];
if ($selectedId !== '') {
    try {
        $selected = crm_customer($pdo, $selectedId);
        $statement = $pdo->prepare("SELECT license_id,license_tier,control_state,activation_key_ending,maintenance_expires_at,issued_at FROM issued_licenses WHERE customer_id=:id AND control_state<>'Deleted' ORDER BY issued_at DESC");
        $statement->execute(['id' => $selectedId]); $licenses = $statement->fetchAll();
        $statement = $pdo->prepare('SELECT installation_uuid,app_version,license_mode,maintenance_status,last_seen_at,launch_count,print_job_count FROM installations WHERE customer_id=:id ORDER BY last_seen_at DESC');
        $statement->execute(['id' => $selectedId]); $installations = $statement->fetchAll();
        $statement = $pdo->prepare('SELECT reference_code,request_type,subject,state,created_at FROM support_requests WHERE customer_id=:id ORDER BY created_at DESC LIMIT 25');
        $statement->execute(['id' => $selectedId]); $supportRequests = $statement->fetchAll();
        $statement = $pdo->prepare('SELECT purchase_reference,order_type,license_tier,purchase_status,amount,currency,paid_at FROM customer_purchases WHERE customer_id=:id ORDER BY COALESCE(paid_at,updated_at) DESC LIMIT 25');
        $statement->execute(['id' => $selectedId]); $purchases = $statement->fetchAll();
        $statement = $pdo->prepare('SELECT consent_type,consent_state,policy_version,source,actor,recorded_at FROM customer_consents WHERE customer_id=:id ORDER BY recorded_at DESC LIMIT 50');
        $statement->execute(['id' => $selectedId]); $consents = $statement->fetchAll();
        $statement = $pdo->prepare('SELECT event_type,source,event_summary,occurred_at FROM customer_events WHERE customer_id=:id ORDER BY occurred_at DESC LIMIT 50');
        $statement->execute(['id' => $selectedId]); $events = $statement->fetchAll();
    } catch (InvalidArgumentException) {
        $selected = null;
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customers | POS Printer Emulator Admin Portal</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/customers.css?v=20260723-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span><?= e(ucfirst(admin_role())) ?> account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a class="active" href="/customers.php"><span aria-hidden="true">◎</span>Customers</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>Receipt contents and activation keys are excluded from customer views and exports.</p></aside>
<main class="crm-main"><div class="page-heading"><div><h1>Customers</h1><p>Verified ownership, licenses, installations, support, and consent in one auditable record.</p></div><?php if(admin_can('customers.export')):?><button class="secondary" type="button" data-open-dialog="export-dialog">Export CSV</button><?php endif;?></div>
<?php if($sessionFlash):?><div class="crm-flash <?=e((string)$sessionFlash['type'])?>" role="status"><?=e((string)$sessionFlash['message'])?></div><?php endif;?>
<form class="crm-filters" method="get">
<label>Search<input name="q" value="<?=e($q)?>" placeholder="Name, email, or customer ID"></label>
<label>License<select name="tier"><option value="">All tiers</option><?php foreach(['Trial','Lite','Pro','Enterprise'] as $option):?><option value="<?=$option?>" <?=$tier===$option?'selected':''?>><?=$option?></option><?php endforeach;?></select></label>
<label>Maintenance<select name="maintenance"><option value="">Any status</option><?php foreach(['Active','Expired','Revoked','NotApplicable'] as $option):?><option value="<?=$option?>" <?=$maintenance===$option?'selected':''?>><?=$option==='NotApplicable'?'Not applicable':$option?></option><?php endforeach;?></select></label>
<label>App version<select name="version"><option value="">Any version</option><?php foreach($availableVersions as $option):?><option value="<?=e((string)$option)?>" <?=$version===$option?'selected':''?>><?=e((string)$option)?></option><?php endforeach;?></select></label>
<label>Activity<select name="activity"><option value="">Any activity</option><option value="30" <?=$activity==='30'?'selected':''?>>Seen in 30 days</option><option value="inactive" <?=$activity==='inactive'?'selected':''?>>Inactive 30+ days</option><option value="never" <?=$activity==='never'?'selected':''?>>Never connected</option></select></label>
<label>Email status<select name="verified"><option value="">Any status</option><option value="yes" <?=$verified==='yes'?'selected':''?>>Verified</option><option value="no" <?=$verified==='no'?'selected':''?>>Not verified</option></select></label>
<label>Marketing consent<select name="marketing"><option value="">Any decision</option><?php foreach(['Granted','Denied','Withdrawn','Not Asked'] as $option):?><option value="<?=$option?>" <?=$marketing===$option?'selected':''?>><?=$option?></option><?php endforeach;?></select></label>
<label>Support history<select name="support"><option value="">Any status</option><option value="yes" <?=$supportFilter==='yes'?'selected':''?>>Has requests</option><option value="no" <?=$supportFilter==='no'?'selected':''?>>No requests</option></select></label>
<label class="check"><input type="checkbox" name="duplicates" value="1" <?=$duplicatesOnly?'checked':''?>> Duplicate candidates</label>
<button>Apply filters</button><a class="clear-filters" href="/customers.php">Clear</a>
</form>
<div class="crm-workspace"><section class="customer-list" aria-label="Customer records"><?php if(!$customers):?><div class="empty">No customers match these filters.</div><?php endif;?><?php foreach($customers as $customer):?><a class="customer-row <?=$selectedId===$customer['customer_id']?'selected':''?>" href="?<?=http_build_query(array_filter(['q'=>$q,'tier'=>$tier,'maintenance'=>$maintenance,'version'=>$version,'activity'=>$activity,'verified'=>$verified,'marketing'=>$marketing,'support'=>$supportFilter,'duplicates'=>$duplicatesOnly?'1':'','customer'=>$customer['customer_id']],fn($v)=>$v!==''))?>"><div><strong><?=e((string)$customer['display_name'])?></strong><span><?=e(crm_mask_email((string)$customer['canonical_email']))?></span></div><div><span class="tier <?=strtolower((string)$customer['license_tier'])?>"><?=e((string)$customer['license_tier'])?></span><small><?= (int)$customer['installation_count'] ?> installation<?= (int)$customer['installation_count']===1?'':'s' ?></small></div><?php if((int)$customer['duplicate_count']>0):?><em>Review duplicate</em><?php endif;?></a><?php endforeach;?></section>
<section class="customer-detail"><?php if(!$selected):?><div class="empty"><h2>Select a customer</h2><p>Choose a record to review its linked activity.</p></div><?php else:?><header><div><span>Customer record</span><h2><?=e((string)$selected['display_name'])?></h2><code><?=e((string)$selected['customer_id'])?></code></div><span class="verification <?=empty($selected['email_verified_at'])?'unverified':'verified'?>"><?=empty($selected['email_verified_at'])?'Not verified':'Verified'?></span></header>
<dl class="identity"><div><dt>Email</dt><dd><?=e((string)$selected['canonical_email']?:'Not provided')?></dd></div><div><dt>Installations</dt><dd><?=count($installations)?></dd></div><div><dt>Licenses</dt><dd><?=count($licenses)?></dd></div><div><dt>Purchases</dt><dd><?=count($purchases)?></dd></div><div><dt>Support requests</dt><dd><?=count($supportRequests)?></dd></div></dl>
<div class="detail-grid"><section><h3>Licenses</h3><?php if(!$licenses):?><p>No paid license is linked.</p><?php else:?><ul><?php foreach($licenses as $license):?><li><strong><?=e((string)$license['license_tier'])?> · <?=e((string)$license['control_state'])?></strong><span>Key ending ••••<?=e((string)$license['activation_key_ending'])?> · <?=e((string)$license['license_id'])?></span></li><?php endforeach;?></ul><?php endif;?></section><section><h3>Installations</h3><?php if(!$installations):?><p>No installation is linked.</p><?php else:?><ul><?php foreach($installations as $installation):?><li><strong><?=e((string)$installation['license_mode'])?> · v<?=e((string)$installation['app_version'])?></strong><span><?=e((string)$installation['installation_uuid'])?> · last seen <?=e((string)$installation['last_seen_at'])?> UTC</span></li><?php endforeach;?></ul><?php endif;?></section><section><h3>Purchases</h3><?php if(!$purchases):?><p>No verified purchase is linked.</p><?php else:?><ul><?php foreach($purchases as $purchase):?><li><strong><?=e((string)$purchase['license_tier'])?> <?=e(strtolower((string)$purchase['order_type']))?> · <?=e((string)$purchase['purchase_status'])?></strong><span><?=e((string)$purchase['purchase_reference'])?> · <?=e((string)$purchase['amount'].' '.(string)$purchase['currency'])?></span></li><?php endforeach;?></ul><?php endif;?></section></div>
<details open><summary>Consent ledger</summary><div class="ledger"><?php if(!$consents):?><p>No consent decision has been recorded.</p><?php else:?><table><thead><tr><th>Purpose</th><th>Decision</th><th>Policy</th><th>Source</th><th>Recorded UTC</th></tr></thead><tbody><?php foreach($consents as $consent):?><tr><td><?=e((string)$consent['consent_type'])?></td><td><?=e((string)$consent['consent_state'])?></td><td><?=e((string)$consent['policy_version'])?></td><td><?=e((string)$consent['source'])?></td><td><?=e((string)$consent['recorded_at'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?><?php if(admin_can('customers.consent')):?><form method="post" class="consent-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="consent"><input type="hidden" name="customer_id" value="<?=e((string)$selected['customer_id'])?>"><input type="hidden" name="confirmed" value="yes"><label>Purpose<select name="consent_type"><?php foreach(CRM_CONSENT_TYPES as $value):?><option><?=e($value)?></option><?php endforeach;?></select></label><label>Decision<select name="consent_state"><?php foreach(CRM_CONSENT_STATES as $value):?><option><?=e($value)?></option><?php endforeach;?></select></label><label>Policy version<input name="policy_version" maxlength="40" value="privacy-2026-07" required></label><button>Record evidence</button></form><?php endif;?></div></details>
<details><summary>Lifecycle activity</summary><div class="timeline"><?php foreach($events as $event):?><article><strong><?=e((string)$event['event_type'])?></strong><p><?=e((string)$event['event_summary'])?></p><small><?=e((string)$event['source'])?> · <?=e((string)$event['occurred_at'])?> UTC</small></article><?php endforeach;?><?php if(!$events):?><p>No lifecycle events are recorded.</p><?php endif;?></div></details>
<?php if(admin_can('customers.consent')):?><details><summary>Verified ownership and duplicate controls</summary><div class="sensitive-actions"><?php if(empty($selected['email_verified_at'])):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify_email"><input type="hidden" name="customer_id" value="<?=e((string)$selected['customer_id'])?>"><input type="hidden" name="confirmed" value="yes"><h3>Mark email verified</h3><p>Use only after independently confirming that this customer controls the address.</p><label>Type VERIFIED<input name="confirmation_phrase" autocomplete="off" required></label><button>Mark verified</button></form><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="merge"><input type="hidden" name="customer_id" value="<?=e((string)$selected['customer_id'])?>"><input type="hidden" name="confirmed" value="yes"><h3>Merge reviewed duplicate</h3><p>This is never automatic. Linked records move to the target and permanent merge history is retained.</p><label>Target customer ID<input name="target_customer_id" pattern="[0-9a-fA-F-]{36}" required></label><label>Business reason<input name="reason" minlength="8" maxlength="500" required></label><label>Type MERGE<input name="confirmation_phrase" autocomplete="off" required></label><button class="danger">Merge customer</button></form></div></details><?php endif;?>
<?php endif;?></section></div></main></div>
<?php if(admin_can('customers.export')):?><dialog id="export-dialog"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="export"><h2>Export customer records?</h2><p>The export contains personal information. It excludes activation keys, receipt contents, diagnostic attachments, and private-network addresses.</p><label>Business reason<input name="reason" minlength="8" maxlength="500" required></label><label class="check"><input type="checkbox" name="confirmed" value="yes" required> I will store and share this export securely.</label><div><button type="button" data-close-dialog>Cancel</button><button>Download CSV</button></div></form></dialog><?php endif;?><script src="assets/customers.js?v=20260723-1" defer></script></body></html>
