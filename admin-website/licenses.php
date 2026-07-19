<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/license_keys.php';
require __DIR__ . '/includes/license_management.php';
require __DIR__ . '/includes/purchase_site.php';
require_authentication();

$pdo = database();
ensure_license_management_schema($pdo);
$actor = trim((string)($_SESSION['admin_username'] ?? 'owner')) ?: 'owner';
$syncWarning = '';
$issueToken = is_string($_SESSION['license_issue_token'] ?? null)
    ? (string)$_SESSION['license_issue_token']
    : bin2hex(random_bytes(32));
$_SESSION['license_issue_token'] = $issueToken;

try {
    $purchaseCursor = 0;
    for ($page = 0; $page < 50; $page++) {
        $purchaseResponse = purchase_site_request('/api/admin-licenses.php?cursor=' . $purchaseCursor);
        sync_purchase_licenses($pdo, is_array($purchaseResponse['licenses'] ?? null) ? $purchaseResponse['licenses'] : []);
        $nextCursor = $purchaseResponse['nextCursor'] ?? null;
        if (!is_int($nextCursor) && !is_numeric($nextCursor)) {
            break;
        }
        $nextCursor = (int)$nextCursor;
        if ($nextCursor <= $purchaseCursor) {
            throw new RuntimeException('The Buy website returned an invalid license cursor.');
        }
        if ($page === 49) {
            throw new RuntimeException('Purchase-license synchronization exceeded its safe page limit.');
        }
        $purchaseCursor = $nextCursor;
    }
} catch (Throwable $exception) {
    error_log('POS Printer Emulator purchase license sync failure: ' . $exception->getMessage());
    $syncWarning = 'Purchase licenses could not be refreshed. Existing License Manager records are still available.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!hash_equals('yes', (string)($_POST['confirmed'] ?? ''))) {
            throw new InvalidArgumentException('Review and confirm the action before continuing.');
        }

        $result = ['issued' => null, 'message' => ''];
        if ($action === 'issue') {
            $submittedToken = (string)($_POST['issue_token'] ?? '');
            if ($submittedToken === '' || !hash_equals($issueToken, $submittedToken)) {
                throw new DomainException('This key request was already processed or expired. Refresh and review it again.');
            }
            unset($_SESSION['license_issue_token']);
            $issuedLicense = issue_activation_key(
                (string)($_POST['customer_name'] ?? ''),
                (string)($_POST['email_address'] ?? ''),
                (string)($_POST['license_tier'] ?? 'Pro')
            );
            $pdo->beginTransaction();
            try {
                insert_issued_license($pdo, $issuedLicense, $actor);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            $result = [
                'issued' => $issuedLicense,
                'message' => $issuedLicense['license_tier'] . ' activation key generated.',
            ];
        } elseif ($action === 'upgrade_trial') {
            if (!hash_equals('yes', (string)($_POST['customer_verified'] ?? ''))) {
                throw new InvalidArgumentException('Verify the customer or payment before issuing a Trial upgrade key.');
            }
            $issuedLicense = upgrade_trial_installation(
                $pdo,
                (string)($_POST['installation_uuid'] ?? ''),
                (string)($_POST['target_tier'] ?? ''),
                $actor,
                'issue_activation_key'
            );
            $result = [
                'issued' => $issuedLicense,
                'message' => $issuedLicense['license_tier'] . ' upgrade key generated. The customer must enter it in the application.',
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
                'issue_activation_key',
                isset($_POST['target_tier']) ? (string)$_POST['target_tier'] : null,
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
        error_log('POS Printer Emulator license management failure: ' . $exception->getMessage());
        $_SESSION['license_flash'] = [
            'type' => 'error',
            'message' => 'The license action could not be completed. No partial change was saved.',
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
    "SELECT l.license_id, l.customer_name, l.email_address, l.license_tier, l.activation_key,
            l.issued_at, l.control_state, l.deactivated_at, l.revoked_at, l.deleted_at,
            l.superseded_by_license_id, l.license_source, l.source_reference, l.row_version,
            EXISTS(
                SELECT 1 FROM installations i
                WHERE i.license_id = l.license_id AND i.license_mode IN ('Pro', 'Enterprise')
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
    'SELECT license_id, customer_name, event_type, previous_state, new_state, previous_tier,
            new_tier, replacement_license_id, reason, performed_by, created_at
     FROM issued_license_events ORDER BY created_at DESC LIMIT 100'
)->fetchAll();

$licenseStatus = static function (array $license): string {
    $state = (string)$license['control_state'];
    if ($state !== 'Enabled') {
        return $state;
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
  <link rel="stylesheet" href="assets/licenses.css?v=20260718-1">
  <link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1">
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a>
    <form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form>
  </header>
  <aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a class="active" href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a><a href="https://posprinteremulator.com/privacy.html"><span aria-hidden="true">⚙</span>Settings</a></nav><p>The private signing key stays protected on the server.</p></aside>
  <main class="license-main">
    <div class="page-heading"><div><h1>License Manager</h1><p>Issue, replace, deactivate, revoke, and audit Pro and Enterprise licenses.</p></div></div>

    <?php if ($flash !== null): ?><div class="license-flash <?= e((string)$flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e((string)$flash['message']) ?></div><?php endif; ?>
    <?php if ($syncWarning !== ''): ?><div class="license-flash warning" role="status"><?= e($syncWarning) ?></div><?php endif; ?>
    <div class="offline-notice"><strong>Offline-license behavior</strong><span>Tier changes generate a replacement key that the customer must enter. Portal deactivation, revocation, or deletion does not erase an activation key already stored by v0.3.21; full remote enforcement remains planned with an outage-safe offline grace period.</span></div>

    <section class="generator-panel">
      <div class="generator-form"><span class="eyebrow">Paid licenses</span><h2>Generate customer key</h2><p>Enter the customer details exactly as they appear in the desktop application.</p>
        <form method="post" autocomplete="off" id="license-issue-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="issue">
          <input type="hidden" name="issue_token" value="<?= e($issueToken) ?>">
          <label>Customer or company name<input name="customer_name" maxlength="160" required></label>
          <label>Email address<input type="email" name="email_address" maxlength="254" required></label>
          <label>License level<select name="license_tier"><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label>
          <label class="confirmation-check"><input type="checkbox" name="confirmed" value="yes" required><span>I confirm the customer information and license level are correct.</span></label>
          <button class="primary-button" type="submit"><span aria-hidden="true">＋</span> Generate activation key</button>
        </form>
      </div>
      <div class="key-result <?= $issued === null ? 'waiting' : 'ready' ?>">
        <?php if ($issued === null): ?><div class="waiting-content"><span class="key-symbol" aria-hidden="true">◇</span><h2>Activation key</h2><p>A signed key or replacement key will appear here after the action is confirmed.</p></div>
        <?php else: ?><div class="success-heading"><span aria-hidden="true">✓</span><div><strong><?= e((string)$issued['license_tier']) ?> activation key generated</strong><small><?= e((string)$issued['issued_at']) ?> UTC</small></div></div><dl><div><dt>Customer</dt><dd><?= e((string)$issued['customer_name']) ?></dd></div><div><dt>Email</dt><dd><?= e((string)$issued['email_address']) ?></dd></div><div><dt>License ID</dt><dd><?= e((string)$issued['license_id']) ?></dd></div></dl><label class="key-label">Activation key<textarea id="generated-key" rows="4" readonly><?= e((string)$issued['activation_key']) ?></textarea></label><button type="button" class="copy-key" data-copy-target="generated-key">Copy activation key</button><p class="key-note">Send this key securely. The customer enters it in Settings → License; no reinstall is required.</p><?php endif; ?>
      </div>
    </section>

    <section class="table-panel license-table">
      <div class="table-toolbar"><div><h2>Issued licenses</h2><p><?= count($licenses) ?> recorded keys<?= $showDeleted ? ', including deleted' : '' ?></p></div><div><label class="search"><span aria-hidden="true">⌕</span><span class="sr-only">Search issued licenses</span><input id="license-search" type="search" placeholder="Search customers, email, or ID"></label><label><span class="sr-only">Status filter</span><select id="status-filter"><option value="all">All statuses</option><option value="Activated">Activated</option><option value="Issued">Issued</option><option value="Deactivated">Deactivated</option><option value="Revoked">Revoked</option><?php if ($showDeleted): ?><option value="Deleted">Deleted</option><?php endif; ?></select></label><a class="history-toggle" href="<?= $showDeleted ? '/licenses.php' : '/licenses.php?show_deleted=1' ?>"><?= $showDeleted ? 'Hide deleted' : 'Show deleted' ?></a></div></div>
      <div class="table-scroll"><table><thead><tr><th scope="col">Customer</th><th scope="col">Email</th><th scope="col">Level</th><th scope="col">Source</th><th scope="col">Issued (UTC)</th><th scope="col">License ID</th><th scope="col">Status</th><th scope="col">Key</th><th scope="col">Actions</th></tr></thead><tbody id="license-rows">
      <?php foreach ($licenses as $license): $status = $licenseStatus($license); ?><tr data-status="<?= e($status) ?>"><td><?= e((string)$license['customer_name']) ?></td><td><?= e((string)$license['email_address']) ?></td><td><?= e((string)$license['license_tier']) ?></td><td><?= e((string)$license['license_source']) ?></td><td><?= e((new DateTimeImmutable((string)$license['issued_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td class="mono"><?= e((string)$license['license_id']) ?></td><td><span class="license-status <?= strtolower(e($status)) ?>"><?= e($status) ?></span></td><td><?php if ((string)$license['control_state'] === 'Enabled'): ?><button type="button" class="table-copy" data-key="<?= e((string)$license['activation_key']) ?>">Copy</button><?php else: ?>—<?php endif; ?></td><td><?php if ($status !== 'Deleted'): ?><button type="button" class="manage-license" data-license-id="<?= e((string)$license['license_id']) ?>" data-customer="<?= e((string)$license['customer_name']) ?>" data-email="<?= e((string)$license['email_address']) ?>" data-tier="<?= e((string)$license['license_tier']) ?>" data-status="<?= e($status) ?>" data-control-state="<?= e((string)$license['control_state']) ?>" data-row-version="<?= (int)$license['row_version'] ?>">Manage</button><?php else: ?><span class="muted-action">Archived</span><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$licenses): ?><tr class="empty-row"><td colspan="9">No activation keys match this view.</td></tr><?php endif; ?></tbody></table></div><footer><span id="license-count" aria-live="polite">Showing <?= count($licenses) ?> licenses</span></footer>
    </section>

    <section class="table-panel license-table trial-table">
      <div class="table-toolbar"><div><h2>Trial installations</h2><p>Registration details are self-reported and are not proof of purchase. Verify the customer before issuing a key.</p></div><div><label class="search"><span aria-hidden="true">⌕</span><span class="sr-only">Search Trial installations</span><input id="trial-search" type="search" placeholder="Search Trial customers"></label></div></div>
      <div class="table-scroll"><table><thead><tr><th scope="col">Customer</th><th scope="col">Email</th><th scope="col">Version</th><th scope="col">Verification</th><th scope="col">Last seen (UTC)</th><th scope="col">Installation ID</th><th scope="col">Action</th></tr></thead><tbody id="trial-rows">
      <?php foreach ($trialInstallations as $trial): ?><tr><td><?= e((string)$trial['customer_name']) ?></td><td><?= e((string)$trial['email_address']) ?></td><td><?= e((string)$trial['app_version']) ?></td><td><span class="license-status deactivated">Unverified</span></td><td><?= e((new DateTimeImmutable((string)$trial['last_seen_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td class="mono"><?= e((string)$trial['installation_uuid']) ?></td><td><button type="button" class="manage-license trial-upgrade" data-installation-id="<?= e((string)$trial['installation_uuid']) ?>" data-customer="<?= e((string)$trial['customer_name']) ?>" data-email="<?= e((string)$trial['email_address']) ?>">Upgrade</button></td></tr><?php endforeach; ?>
      <?php if (!$trialInstallations): ?><tr class="empty-row"><td colspan="7">No registered Trial installations are currently available.</td></tr><?php endif; ?></tbody></table></div><footer><span id="trial-count" aria-live="polite">Showing <?= count($trialInstallations) ?> Trial installations</span></footer>
    </section>

    <section class="table-panel license-table audit-table">
      <div class="table-toolbar"><div><h2>Recent license activity</h2><p>Activation keys are never written to this audit history.</p></div></div>
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
    <dl class="license-summary"><div><dt>Customer</dt><dd id="manage-customer"></dd></div><div><dt>Email</dt><dd id="manage-email"></dd></div><div><dt>License ID</dt><dd id="manage-license-id" class="mono"></dd></div><div><dt>Current level</dt><dd id="manage-tier"></dd></div><div><dt>Status</dt><dd id="manage-status"></dd></div></dl>
    <div class="tier-action"><label>Replacement license level<select id="manage-target-tier"><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label><button type="button" class="dialog-action" data-prepare-action="change_tier">Change license type</button></div>
    <div class="lifecycle-actions"><button type="button" class="dialog-action" data-prepare-action="deactivate">Deactivate</button><button type="button" class="dialog-action" data-prepare-action="reactivate">Reactivate</button><button type="button" class="dialog-action danger" data-prepare-action="revoke">Revoke</button><button type="button" class="dialog-action danger-outline" data-prepare-action="delete">Delete</button></div>
  </section>
  <section id="trial-manage-view" hidden>
    <p id="trial-dialog-description">Generate a signed paid key for this Trial installation. The customer must enter the key in the application.</p>
    <dl class="license-summary"><div><dt>Customer</dt><dd id="trial-customer"></dd></div><div><dt>Email</dt><dd id="trial-email"></dd></div><div><dt>Current level</dt><dd>Trial</dd></div><div><dt>Installation ID</dt><dd id="trial-installation-id" class="mono"></dd></div></dl>
    <div class="tier-action"><label>New license level<select id="trial-target-tier"><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></label><button type="button" class="dialog-action" data-prepare-action="upgrade_trial">Review upgrade</button></div>
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
      <label id="verification-field" class="confirmation-check" hidden><input type="checkbox" name="customer_verified" id="customer-verified" value="yes"><span>I independently verified this customer or payment. Telemetry registration alone is not proof of purchase.</span></label>
      <label id="reason-field" hidden>Reason<textarea name="reason" id="action-reason" maxlength="500" rows="3"></textarea><small>Required for revocation and deletion. Do not include activation keys.</small></label>
      <label id="phrase-field" hidden>Type <strong id="required-phrase"></strong> to continue<input name="confirmation_phrase" id="action-confirmation-phrase" autocomplete="off"></label>
      <div class="confirmation-buttons"><button type="button" class="secondary-button" data-dialog-close>Cancel</button><button type="submit" class="confirm-button" id="confirm-submit">Confirm</button></div>
    </form>
  </section>
</dialog>
<script src="assets/licenses.js?v=20260718-1"></script>
</body>
</html>
