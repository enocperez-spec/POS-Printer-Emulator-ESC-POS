<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/license_keys.php';
require __DIR__ . '/includes/license_management.php';
require __DIR__ . '/includes/customer_crm.php';
require __DIR__ . '/includes/purchase_site.php';
require __DIR__ . '/includes/self_service_commerce_schema.php';
require_authentication();
require_admin_capability('licenses.manage');

$pdo = database();
ensure_license_management_schema($pdo);
ensure_self_service_commerce_schema($pdo);
backfill_customer_crm($pdo);
$actor = trim((string)($_SESSION['admin_username'] ?? 'owner')) ?: 'owner';
$syncWarning = '';
$issueToken = is_string($_SESSION['license_issue_token'] ?? null)
    ? (string)$_SESSION['license_issue_token']
    : bin2hex(random_bytes(32));
$_SESSION['license_issue_token'] = $issueToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!hash_equals('yes', (string)($_POST['confirmed'] ?? ''))) {
            throw new InvalidArgumentException('Review and confirm the action before continuing.');
        }

        $result = ['issued' => null, 'message' => ''];
        if ($action === 'promotion_exception') {
            $customerId = strtolower(trim((string)($_POST['customer_id'] ?? '')));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/', $customerId) || $reason === '' || mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('Choose a customer and enter a reason of no more than 500 characters.');
            }
            $customer = $pdo->prepare("SELECT 1 FROM customers WHERE customer_id=:customer_id AND status='Active'");
            $customer->execute(['customer_id' => $customerId]);
            if (!$customer->fetchColumn()) {
                throw new DomainException('The selected active customer was not found.');
            }
            $existing = $pdo->prepare(
                'SELECT 1 FROM portal_promotion_exceptions WHERE customer_id=:customer_id AND consumed_at IS NULL'
            );
            $existing->execute(['customer_id' => $customerId]);
            if ($existing->fetchColumn()) {
                throw new DomainException('This customer already has an unused promotion exception.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO portal_promotion_exceptions(customer_id,reason,created_by)
                 VALUES(:customer_id,:reason,:actor)'
            );
            $insert->execute(['customer_id' => $customerId, 'reason' => $reason, 'actor' => $actor]);
            $result = ['issued' => null, 'message' => 'One additional five-day promotion was authorized and recorded.'];
        } elseif ($action === 'issue_complimentary') {
            $submittedToken = (string)($_POST['issue_token'] ?? '');
            if ($submittedToken === '' || !hash_equals($issueToken, $submittedToken)) {
                throw new DomainException('This entitlement request was already processed or expired. Refresh and review it again.');
            }
            unset($_SESSION['license_issue_token']);
            $customerId = trim((string)($_POST['customer_id'] ?? ''));
            $customerStatement = $pdo->prepare(
                "SELECT customer_id,display_name,canonical_email
                 FROM customers
                 WHERE customer_id=:customer_id AND status='Active'
                   AND email_verified_at IS NOT NULL
                 LIMIT 1"
            );
            $customerStatement->execute(['customer_id' => $customerId]);
            $customer = $customerStatement->fetch();
            if (!$customer) {
                throw new DomainException('Choose an active, email-verified Customer Portal account.');
            }
            $licenseExpiration = normalize_complimentary_expiration(
                (string)($_POST['license_duration'] ?? ''),
                isset($_POST['license_expiration']) ? (string)$_POST['license_expiration'] : null
            );
            $complimentaryReason = canonical_complimentary_reason(
                (string)($_POST['complimentary_reason'] ?? '')
            );
            $complimentaryNote = trim((string)($_POST['complimentary_note'] ?? ''));
            if (mb_strlen($complimentaryNote) > 500) {
                throw new InvalidArgumentException('The internal note must be 500 characters or fewer.');
            }
            $issuedLicense = create_license_entitlement(
                (string)$customer['display_name'],
                (string)$customer['canonical_email'],
                (string)($_POST['license_tier'] ?? 'Pro')
            );
            $issuedLicense['license_source'] = 'Complimentary';
            $issuedLicense['license_expires_at'] = $licenseExpiration;
            $issuedLicense['complimentary_reason'] = $complimentaryReason;
            $issuedLicense['complimentary_note'] = $complimentaryNote;
            $auditReason = $complimentaryReason .
                ($complimentaryNote !== '' ? ': ' . $complimentaryNote : '');
            $pdo->beginTransaction();
            try {
                insert_issued_license(
                    $pdo,
                    $issuedLicense,
                    $actor,
                    'Complimentary',
                    'complimentary:' . (string)$issuedLicense['license_id'],
                    'COMPLIMENTARY_ISSUED',
                    'COMPLIMENTARY_INCLUDED',
                    'Application Maintenance and Support included with this complimentary entitlement.',
                    $auditReason
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            $result = [
                'issued' => $issuedLicense,
                'message' => $issuedLicense['license_tier'] . ' complimentary entitlement created successfully.',
            ];
        } elseif ($action === 'upgrade_trial') {
            if (!hash_equals('yes', (string)($_POST['customer_verified'] ?? ''))) {
                throw new InvalidArgumentException('Verify the customer or payment before assigning a Trial upgrade entitlement.');
            }
            $issuedLicense = upgrade_trial_installation(
                $pdo,
                (string)($_POST['installation_uuid'] ?? ''),
                (string)($_POST['target_tier'] ?? ''),
                $actor,
                'create_license_entitlement'
            );
            $result = [
                'issued' => $issuedLicense,
                'message' => $issuedLicense['license_tier'] . ' account entitlement created. The customer can link the computer from the application.',
            ];
        } else {
            if (in_array($action, ['revoke', 'delete'], true)) {
                $requiredPhrase = strtoupper($action);
                if (!hash_equals($requiredPhrase, strtoupper(trim((string)($_POST['confirmation_phrase'] ?? ''))))) {
                    throw new InvalidArgumentException("Type {$requiredPhrase} to confirm this action.");
                }
            }
            $result = manage_issued_license(
                $pdo,
                $action,
                (string)($_POST['license_id'] ?? ''),
                (int)($_POST['row_version'] ?? 0),
                $actor,
                isset($_POST['target_tier']) ? (string)$_POST['target_tier'] : null,
                isset($_POST['target_customer_id']) ? (string)$_POST['target_customer_id'] : null,
                (string)($_POST['reason'] ?? '')
            );
        }

        $_SESSION['license_flash'] = [
            'type' => 'success',
            'message' => (string)$result['message'],
            'issued' => $result['issued'],
        ];
    } catch (InvalidArgumentException|DomainException $exception) {
        $_SESSION['license_flash'] = ['type' => 'error', 'message' => $exception->getMessage(), 'issued' => null];
    } catch (Throwable $exception) {
        $reference = 'LIC-' . strtoupper(bin2hex(random_bytes(4)));
        error_log(
            "POS Printer Emulator license management failure {$reference}; " .
            'action=' . $action . '; actor=' . $actor . '; ' .
            get_class($exception) . ': ' . $exception->getMessage()
        );
        $_SESSION['license_flash'] = [
            'type' => 'error',
            'message' => $action === 'issue_complimentary'
                ? "The complimentary license could not be created. No changes were saved. " .
                    "Reference {$reference}. Review the recipient, license level, expiration, and reason, then try again."
                : "The license action could not be completed. No partial change was saved. Reference {$reference}.",
            'issued' => null,
        ];
    }
    header('Location: /licenses.php');
    exit;
}

$flash = is_array($_SESSION['license_flash'] ?? null) ? $_SESSION['license_flash'] : null;
unset($_SESSION['license_flash']);
$issued = is_array($flash['issued'] ?? null) ? $flash['issued'] : null;
$showDeleted = (string)($_GET['show_deleted'] ?? '') === '1';
$licenseWhere = $showDeleted ? '' : "WHERE l.control_state <> 'Deleted'";
$licenses = $pdo->query(
    "SELECT l.license_id, l.customer_name, l.email_address, l.license_tier,
            l.issued_at, l.control_state, l.deactivated_at, l.revoked_at, l.deleted_at,
            l.superseded_by_license_id, l.license_source, l.source_reference,
            l.license_expires_at, l.complimentary_reason, l.complimentary_note,
            l.maintenance_expires_at, l.maintenance_revoked_at, l.row_version,
            EXISTS(
                SELECT 1 FROM installations i
                WHERE i.license_id = l.license_id AND i.license_mode IN ('Lite', 'Pro', 'Enterprise')
            ) AS activated
     FROM issued_licenses l
     {$licenseWhere}
     ORDER BY l.issued_at DESC
     LIMIT 500"
)->fetchAll();
$trialInstallations = $pdo->query(
    "SELECT installation_uuid, customer_name, email_address, app_version, last_seen_at
     FROM installations
     WHERE license_mode = 'Trial'
       AND customer_name <> ''
       AND email_address <> ''
       AND NOT EXISTS (
           SELECT 1 FROM issued_licenses l
           WHERE l.source_reference = CONCAT('trial:', installations.installation_uuid)
             AND l.control_state IN ('Enabled', 'Deactivated')
       )
     ORDER BY last_seen_at DESC
     LIMIT 200"
)->fetchAll();
$licenseEvents = $pdo->query(
    "SELECT * FROM (
        SELECT license_id,customer_name,event_type,previous_state,new_state,previous_tier,
               new_tier,replacement_license_id,reason,performed_by,created_at
        FROM issued_license_events
        UNION ALL
        SELECT a.license_id,COALESCE(c.display_name,'Unknown customer') customer_name,
               a.event_type,NULL previous_state,a.outcome new_state,NULL previous_tier,
               NULL new_tier,NULL replacement_license_id,a.event_summary reason,
               a.activation_method performed_by,a.created_at
        FROM license_activation_events a
        LEFT JOIN customers c ON c.customer_id=a.customer_id
    ) events
     ORDER BY created_at DESC LIMIT 100"
)->fetchAll();
$maintenanceEvents = $pdo->query(
    'SELECT m.license_id, l.customer_name, m.event_type, m.previous_expires_at, m.new_expires_at,
            m.reason, m.performed_by, m.created_at
     FROM license_maintenance_events m
     LEFT JOIN issued_licenses l ON l.license_id = m.license_id
     ORDER BY m.created_at DESC LIMIT 100'
)->fetchAll();
$promotionCustomers = $pdo->query(
    "SELECT customer_id,display_name,canonical_email FROM customers
     WHERE status='Active' AND email_verified_at IS NOT NULL
     ORDER BY display_name,canonical_email LIMIT 500"
)->fetchAll();
$promotionExceptions = $pdo->query(
    'SELECT e.customer_id,c.display_name,c.canonical_email,e.reason,e.created_by,e.created_at,e.consumed_at
     FROM portal_promotion_exceptions e
     INNER JOIN customers c ON c.customer_id=e.customer_id
     ORDER BY e.created_at DESC LIMIT 100'
)->fetchAll();

$licenseStatus = static function (array $license): string {
    $state = (string)$license['control_state'];
    if ($state !== 'Enabled') {
        return $state;
    }
    if (license_entitlement_expired($license)) {
        return 'Expired';
    }
    return (int)$license['activated'] === 1 ? 'Activated' : 'Issued';
};
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>License Manager | POS Printer Emulator</title>
  <link rel="icon" type="image/png" href="assets/favicon.png">
  <link rel="stylesheet" href="assets/admin.css?v=20260714-2">
  <link rel="stylesheet" href="assets/licenses.css?v=20260731-complimentary">
  <link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1">
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a>
    <form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form>
  </header>
  <aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/customers.php"><span aria-hidden="true">◎</span>Customers</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a class="active" href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/communications.php"><span aria-hidden="true">✉</span>Communications</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a><a href="https://posprinteremulator.com/privacy.html"><span aria-hidden="true">⚙</span>Settings</a></nav><p>The private signing key stays protected on the server.</p></aside>
  <main class="license-main">
    <div class="page-heading"><div><h1>License Manager</h1><p>Manage permanent licenses and optional annual Application Maintenance and Support.</p></div></div>

    <?php if ($flash !== null): ?><div class="license-flash <?= e((string)$flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e((string)$flash['message']) ?></div><?php endif; ?>
    <?php if ($syncWarning !== ''): ?><div class="license-flash warning" role="status"><?= e($syncWarning) ?></div><?php endif; ?>
    <div class="offline-notice"><strong>Account-based licensing</strong><span>License and maintenance changes increment the entitlement revision and synchronize to registered computers. Deactivation and revocation release or block the affected device assignment while preserving audit history.</span></div>

    <section class="generator-panel">
      <div class="generator-form"><span class="eyebrow complimentary">Complimentary licenses</span><h2>Create complimentary entitlement</h2><p>Issue a no-charge license to an active, email-verified Customer Portal account. This does not create a purchase or affect revenue reporting.</p>
        <form method="post" autocomplete="off" id="license-issue-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="issue_complimentary">
          <input type="hidden" name="issue_token" value="<?= e($issueToken) ?>">
          <label>Recipient name or company and email<select name="customer_id" required><option value="">Choose a verified customer account</option><?php foreach ($promotionCustomers as $customer): ?><option value="<?=e((string)$customer['customer_id'])?>"><?=e((string)$customer['display_name'])?> · <?=e((string)$customer['canonical_email'])?></option><?php endforeach; ?></select><small>The recipient links a computer through the same secure Customer Portal workflow used by paid customers.</small></label>
          <label>License level<select name="license_tier"><option value="Lite">Lite</option><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label>
          <label>License term<select name="license_duration" id="complimentary-duration"><option value="permanent">Permanent license</option><option value="expires">Expires on a selected date</option></select></label>
          <label id="complimentary-expiration-field" hidden>Expiration date<input type="date" name="license_expiration" id="complimentary-expiration" min="<?= e((new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d')) ?>"><small>The entitlement remains available through 11:59 PM UTC on this date.</small></label>
          <label>Internal reason<select name="complimentary_reason" required><option value="">Choose a reason</option><option value="Promotional">Promotional</option><option value="Partner">Partner</option><option value="Evaluation">Evaluation</option><option value="Referral">Referral</option><option value="Other">Other</option></select></label>
          <label>Internal note (optional)<textarea name="complimentary_note" maxlength="500" rows="3" placeholder="Add context for the audit record. Do not include passwords or activation keys."></textarea></label>
          <label class="confirmation-check"><input type="checkbox" name="confirmed" value="yes" required><span>I confirm the recipient, license level, term, and reason are correct and understand that this creates a complimentary entitlement without a paid order.</span></label>
          <button class="primary-button" type="submit"><span aria-hidden="true">＋</span> Create complimentary entitlement</button>
        </form>
      </div>
      <div class="key-result <?= $issued === null ? 'waiting' : 'ready' ?>">
        <?php if ($issued === null): ?><div class="waiting-content"><span class="key-symbol" aria-hidden="true">◇</span><h2>Complimentary entitlement</h2><p>The complimentary license will appear here after it is assigned to a verified customer account.</p></div>
        <?php else: ?><div class="success-heading"><span aria-hidden="true">✓</span><div><strong><?= e((string)$issued['license_tier']) ?> complimentary entitlement created</strong><small><?= e((string)$issued['issued_at']) ?> UTC</small></div></div><dl><div><dt>Recipient</dt><dd><?= e((string)$issued['customer_name']) ?></dd></div><div><dt>Verified account</dt><dd><?= e((string)$issued['email_address']) ?></dd></div><div><dt>License ID</dt><dd><?= e((string)$issued['license_id']) ?></dd></div><div><dt>Reason</dt><dd><?= e((string)($issued['complimentary_reason'] ?? '—')) ?></dd></div><div><dt>License term</dt><dd><?= empty($issued['license_expires_at']) ? 'Permanent' : 'Expires ' . e((string)$issued['license_expires_at']) . ' UTC' ?></dd></div></dl><p class="key-note">No purchase was created. The recipient can now link a computer in the application and approve this license in the Customer Portal.</p><?php endif; ?>
      </div>
    </section>

    <section class="table-panel license-table">
      <div class="table-toolbar"><div><h2>Promotional access exceptions</h2><p>Authorize one additional five-day promotion only after reviewing the customer’s history. Every exception is confirmed and audited.</p></div></div>
      <form method="post" class="generator-form" data-confirm="Authorize one additional five-day promotion for this customer?">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="promotion_exception"><input type="hidden" name="confirmed" value="yes">
        <label>Customer<select name="customer_id" required><option value="">Choose a verified customer</option><?php foreach ($promotionCustomers as $customer): ?><option value="<?=e((string)$customer['customer_id'])?>"><?=e((string)$customer['display_name'])?> · <?=e((string)$customer['canonical_email'])?></option><?php endforeach; ?></select></label>
        <label>Administrative reason<textarea name="reason" maxlength="500" rows="3" required placeholder="Why is a repeat promotion appropriate?"></textarea></label>
        <label class="confirmation-check"><input type="checkbox" required><span>I reviewed this exception and understand it permits one additional promotional entitlement.</span></label>
        <button class="primary-button" type="submit">Authorize one additional promotion</button>
      </form>
      <div class="table-scroll"><table><thead><tr><th>Customer</th><th>Email</th><th>Reason</th><th>Created by</th><th>Created</th><th>Status</th></tr></thead><tbody><?php foreach($promotionExceptions as $exception): ?><tr><td><?=e((string)$exception['display_name'])?></td><td><?=e((string)$exception['canonical_email'])?></td><td><?=e((string)$exception['reason'])?></td><td><?=e((string)$exception['created_by'])?></td><td><?=e((string)$exception['created_at'])?></td><td><?=empty($exception['consumed_at'])?'Available':'Consumed'?></td></tr><?php endforeach; ?><?php if(!$promotionExceptions): ?><tr><td colspan="6">No promotional exceptions have been authorized.</td></tr><?php endif; ?></tbody></table></div>
    </section>

    <section class="table-panel license-table">
      <div class="table-toolbar"><div><h2>License entitlements</h2><p><?= count($licenses) ?> account licenses<?= $showDeleted ? ', including deleted' : '' ?></p></div><div><label class="search"><span aria-hidden="true">⌕</span><span class="sr-only">Search issued licenses</span><input id="license-search" type="search" placeholder="Search customers, email, or ID"></label><label><span class="sr-only">Status filter</span><select id="status-filter"><option value="all">All statuses</option><option value="Activated">Activated</option><option value="Issued">Issued</option><option value="Expired">Expired</option><option value="Deactivated">Deactivated</option><option value="Revoked">Revoked</option><?php if ($showDeleted): ?><option value="Deleted">Deleted</option><?php endif; ?></select></label><a class="history-toggle" href="<?= $showDeleted ? '/licenses.php' : '/licenses.php?show_deleted=1' ?>"><?= $showDeleted ? 'Hide deleted' : 'Show deleted' ?></a></div></div>
      <div class="table-scroll"><table><thead><tr><th scope="col">Customer</th><th scope="col">Account email</th><th scope="col">Level</th><th scope="col">Source / term</th><th scope="col">Issued (UTC)</th><th scope="col">License ID</th><th scope="col">License</th><th scope="col">Maintenance</th><th scope="col">Computer</th><th scope="col">Actions</th></tr></thead><tbody id="license-rows">
      <?php foreach ($licenses as $license): $status = $licenseStatus($license); $maintenanceStatus=maintenance_status($license); ?><tr data-status="<?= e($status) ?>"><td><?= e((string)$license['customer_name']) ?></td><td><?= e((string)$license['email_address']) ?></td><td><?= e((string)$license['license_tier']) ?></td><td><span class="license-source <?= strtolower(e((string)$license['license_source'])) ?>"><?= e((string)$license['license_source']) ?></span><?php if ((string)$license['license_source'] === 'Complimentary'): ?><small><?= e((string)($license['complimentary_reason'] ?? 'Other')) ?> · <?= empty($license['license_expires_at']) ? 'Permanent' : 'Expires ' . e((new DateTimeImmutable((string)$license['license_expires_at'], new DateTimeZone('UTC')))->format('M j, Y')) ?></small><?php endif; ?></td><td><?= e((new DateTimeImmutable((string)$license['issued_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td class="mono"><?= e((string)$license['license_id']) ?></td><td><span class="license-status <?= strtolower(e($status)) ?>"><?= e($status) ?></span></td><td><span class="maintenance-status <?= e($maintenanceStatus) ?>"><?= e(ucfirst($maintenanceStatus)) ?></span><small><?= e((new DateTimeImmutable((string)$license['maintenance_expires_at'],new DateTimeZone('UTC')))->format('M j, Y')) ?></small></td><td><?= !empty($license['activated']) ? 'Registered' : 'Available' ?></td><td><?php if ($status !== 'Deleted'): ?><button type="button" class="manage-license" data-license-id="<?= e((string)$license['license_id']) ?>" data-customer="<?= e((string)$license['customer_name']) ?>" data-email="<?= e((string)$license['email_address']) ?>" data-tier="<?= e((string)$license['license_tier']) ?>" data-status="<?= e($status) ?>" data-control-state="<?= e((string)$license['control_state']) ?>" data-maintenance-status="<?= e($maintenanceStatus) ?>" data-maintenance-expires="<?= e((string)$license['maintenance_expires_at']) ?>" data-row-version="<?= (int)$license['row_version'] ?>">Manage</button><?php else: ?><span class="muted-action">Archived</span><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$licenses): ?><tr class="empty-row"><td colspan="10">No license entitlements match this view.</td></tr><?php endif; ?></tbody></table></div><footer><span id="license-count" aria-live="polite">Showing <?= count($licenses) ?> licenses</span></footer>
    </section>

    <section class="table-panel license-table audit-table">
      <div class="table-toolbar"><div><h2>Recent maintenance activity</h2><p>Renewals and manual maintenance actions do not change the permanent license.</p></div></div>
      <div class="table-scroll"><table><thead><tr><th>Time (UTC)</th><th>Customer</th><th>Event</th><th>Previous</th><th>New coverage</th><th>License ID</th><th>Performed by</th><th>Reason</th></tr></thead><tbody>
      <?php foreach($maintenanceEvents as $event):?><tr><td><?=e((new DateTimeImmutable((string)$event['created_at'],new DateTimeZone('UTC')))->format('M j, Y g:i A'))?></td><td><?=e((string)($event['customer_name']??'—'))?></td><td><?=e(str_replace('_',' ',(string)$event['event_type']))?></td><td><?=e((string)($event['previous_expires_at']??'—'))?></td><td><?=e((string)($event['new_expires_at']??'—'))?></td><td class="mono"><?=e((string)$event['license_id'])?></td><td><?=e((string)$event['performed_by'])?></td><td><?=e((string)($event['reason']??'—'))?></td></tr><?php endforeach;?><?php if(!$maintenanceEvents):?><tr class="empty-row"><td colspan="8">No maintenance actions have been recorded yet.</td></tr><?php endif;?></tbody></table></div>
    </section>

    <section class="table-panel license-table trial-table">
      <div class="table-toolbar"><div><h2>Trial installations</h2><p>Only upgrade a computer after its verified Customer Portal account and purchase have been confirmed.</p></div><div><label class="search"><span aria-hidden="true">⌕</span><span class="sr-only">Search Trial installations</span><input id="trial-search" type="search" placeholder="Search Trial customers"></label></div></div>
      <div class="table-scroll"><table><thead><tr><th scope="col">Customer</th><th scope="col">Email</th><th scope="col">Version</th><th scope="col">Verification</th><th scope="col">Last seen (UTC)</th><th scope="col">Installation ID</th><th scope="col">Action</th></tr></thead><tbody id="trial-rows">
      <?php foreach ($trialInstallations as $trial): ?><tr><td><?= e((string)$trial['customer_name']) ?></td><td><?= e((string)$trial['email_address']) ?></td><td><?= e((string)$trial['app_version']) ?></td><td><span class="license-status deactivated">Unverified</span></td><td><?= e((new DateTimeImmutable((string)$trial['last_seen_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td class="mono"><?= e((string)$trial['installation_uuid']) ?></td><td><button type="button" class="manage-license trial-upgrade" data-installation-id="<?= e((string)$trial['installation_uuid']) ?>" data-customer="<?= e((string)$trial['customer_name']) ?>" data-email="<?= e((string)$trial['email_address']) ?>">Upgrade</button></td></tr><?php endforeach; ?>
      <?php if (!$trialInstallations): ?><tr class="empty-row"><td colspan="7">No registered Trial installations are currently available.</td></tr><?php endif; ?></tbody></table></div><footer><span id="trial-count" aria-live="polite">Showing <?= count($trialInstallations) ?> Trial installations</span></footer>
    </section>

    <section class="table-panel license-table audit-table">
      <div class="table-toolbar"><div><h2>Recent license activity</h2><p>Every entitlement and device change is retained in the audit history.</p></div></div>
      <div class="table-scroll"><table><thead><tr><th>Time (UTC)</th><th>Customer</th><th>Event</th><th>Change</th><th>License ID</th><th>Performed by</th><th>Reason</th></tr></thead><tbody>
      <?php foreach ($licenseEvents as $event): ?><tr><td><?= e((new DateTimeImmutable((string)$event['created_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td><?= e((string)$event['customer_name']) ?></td><td><?= e(str_replace('_', ' ', (string)$event['event_type'])) ?></td><td><?= e(trim(((string)($event['previous_tier'] ?? '')) . ' ' . ((string)($event['previous_state'] ?? '')) . ' → ' . ((string)($event['new_tier'] ?? '')) . ' ' . ((string)($event['new_state'] ?? '')))) ?></td><td class="mono"><?= e((string)$event['license_id']) ?></td><td><?= e((string)$event['performed_by']) ?></td><td><?= e((string)($event['reason'] ?? '—')) ?></td></tr><?php endforeach; ?>
      <?php if (!$licenseEvents): ?><tr class="empty-row"><td colspan="7">No license management actions have been recorded yet.</td></tr><?php endif; ?></tbody></table></div>
    </section>
  </main>
</div>

<dialog class="license-dialog" id="license-dialog" aria-labelledby="license-dialog-title" aria-describedby="license-dialog-description">
  <div class="dialog-header"><div><span class="eyebrow">License controls</span><h2 id="license-dialog-title">Manage license</h2></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close">×</button></div>
  <section id="license-manage-view">
    <p id="license-dialog-description">Review the selected customer and choose an action.</p>
    <dl class="license-summary"><div><dt>Customer</dt><dd id="manage-customer"></dd></div><div><dt>Email</dt><dd id="manage-email"></dd></div><div><dt>License ID</dt><dd id="manage-license-id" class="mono"></dd></div><div><dt>Current level</dt><dd id="manage-tier"></dd></div><div><dt>License status</dt><dd id="manage-status"></dd></div><div><dt>Maintenance</dt><dd><span id="manage-maintenance-status"></span><small id="manage-maintenance-expires"></small></dd></div></dl>
    <div class="tier-action"><label>Replacement license level<select id="manage-target-tier"><option value="Lite">Lite</option><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label><button type="button" class="dialog-action" data-prepare-action="change_tier">Change license type</button></div>
    <div class="tier-action"><label>Verified customer account<select id="manage-target-customer"><option value="">Choose a customer</option><?php foreach ($promotionCustomers as $customer): ?><option value="<?=e((string)$customer['customer_id'])?>"><?=e((string)$customer['display_name'])?> · <?=e((string)$customer['canonical_email'])?></option><?php endforeach; ?></select></label><button type="button" class="dialog-action" data-prepare-action="reassign_customer">Reassign license</button></div>
    <div class="lifecycle-actions"><button type="button" class="dialog-action" data-prepare-action="deactivate">Deactivate</button><button type="button" class="dialog-action" data-prepare-action="reactivate">Reactivate</button><button type="button" class="dialog-action danger" data-prepare-action="revoke">Revoke</button><button type="button" class="dialog-action danger-outline" data-prepare-action="delete">Delete</button></div>
    <div class="maintenance-actions"><strong>Application Maintenance and Support</strong><p>These controls affect updates and support only. The permanent license and purchased features continue working.</p><div><button type="button" class="dialog-action" data-prepare-action="extend_maintenance">Extend one year</button><button type="button" class="dialog-action danger-outline" data-prepare-action="revoke_maintenance">Revoke maintenance</button><button type="button" class="dialog-action" data-prepare-action="restore_maintenance">Restore maintenance</button></div></div>
  </section>
  <section id="trial-manage-view" hidden>
    <p id="trial-dialog-description">Assign a paid entitlement to this verified customer account and registered computer.</p>
    <dl class="license-summary"><div><dt>Customer</dt><dd id="trial-customer"></dd></div><div><dt>Email</dt><dd id="trial-email"></dd></div><div><dt>Current level</dt><dd>Trial</dd></div><div><dt>Installation ID</dt><dd id="trial-installation-id" class="mono"></dd></div></dl>
    <div class="tier-action"><label>New license level<select id="trial-target-tier"><option value="Lite">Lite</option><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label><button type="button" class="dialog-action" data-prepare-action="upgrade_trial">Review upgrade</button></div>
  </section>
  <section id="license-confirm-view" hidden>
    <button type="button" class="dialog-back" data-dialog-back>← Back</button><h3 id="confirm-title">Confirm action</h3><p id="confirm-description"></p>
    <div class="confirm-subject"><strong id="confirm-customer"></strong><span id="confirm-license"></span></div>
    <form method="post" id="license-action-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="confirmed" value="yes">
      <input type="hidden" name="action" id="action-name">
      <input type="hidden" name="license_id" id="action-license-id">
      <input type="hidden" name="installation_uuid" id="action-installation-id">
      <input type="hidden" name="row_version" id="action-row-version">
      <input type="hidden" name="target_tier" id="action-target-tier">
      <input type="hidden" name="target_customer_id" id="action-target-customer">
      <label id="verification-field" class="confirmation-check" hidden><input type="checkbox" name="customer_verified" id="customer-verified" value="yes"><span>I independently verified this customer or payment. Telemetry registration alone is not proof of purchase.</span></label>
      <label id="reason-field" hidden>Reason<textarea name="reason" id="action-reason" maxlength="500" rows="3"></textarea><small>Required for revocation and deletion.</small></label>
      <label id="phrase-field" hidden>Type <strong id="required-phrase"></strong> to continue<input name="confirmation_phrase" id="action-confirmation-phrase" autocomplete="off"></label>
      <div class="confirmation-buttons"><button type="button" class="secondary-button" data-dialog-close>Cancel</button><button type="submit" class="confirm-button" id="confirm-submit">Confirm</button></div>
    </form>
  </section>
</dialog>
<script src="assets/licenses.js?v=20260731-complimentary"></script>
</body>
</html>
