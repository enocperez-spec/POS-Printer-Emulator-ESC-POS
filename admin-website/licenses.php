<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/license_keys.php';
require_authentication();

$issued = null;
$error = '';
$customerName = '';
$emailAddress = '';
$licenseTier = 'Pro';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $customerName = (string)($_POST['customer_name'] ?? '');
    $emailAddress = (string)($_POST['email_address'] ?? '');
    $licenseTier = (string)($_POST['license_tier'] ?? 'Pro');
    try {
        $issued = issue_activation_key($customerName, $emailAddress, $licenseTier);
        $insert = database()->prepare(
            'INSERT INTO issued_licenses
                (license_id, customer_name, email_address, license_tier, activation_key, issued_at, created_by)
             VALUES (:license_id, :customer_name, :email_address, :license_tier, :activation_key, :issued_at, :created_by)'
        );
        $insert->execute($issued + ['created_by' => 'owner']);
        $customerName = $issued['customer_name'];
        $emailAddress = $issued['email_address'];
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
        $issued = null;
    } catch (Throwable $exception) {
        error_log('POS Printer Emulator license issue failure: ' . $exception->getMessage());
        $error = 'The activation key could not be generated. Please try again.';
        $issued = null;
    }
}

$licenses = database()->query(
    "SELECT l.license_id, l.customer_name, l.email_address, l.license_tier, l.activation_key, l.issued_at, l.revoked_at,
            EXISTS(
                SELECT 1 FROM installations i
                WHERE i.license_id = l.license_id AND i.license_mode IN ('Pro', 'Enterprise')
            ) AS activated
     FROM issued_licenses l
     ORDER BY l.issued_at DESC
     LIMIT 500"
)->fetchAll();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>License Manager | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/licenses.css?v=20260714-2"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a class="active" href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a><a href="https://posprinteremulator.com/privacy.html"><span aria-hidden="true">⚙</span>Settings</a></nav><p>The private signing key stays protected on the server.</p></aside>
<main class="license-main"><div class="page-heading"><div><h1>License Manager</h1><p>Generate signed Pro and Enterprise keys and track issued licenses.</p></div></div>
<section class="generator-panel">
<div class="generator-form"><span class="eyebrow">Paid licenses</span><h2>Generate customer key</h2><p>Enter the customer details exactly as they appear in the desktop application.</p>
<?php if ($error !== ''): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Customer or company name<input name="customer_name" maxlength="160" value="<?= e($customerName) ?>" required></label><label>Email address<input type="email" name="email_address" maxlength="254" value="<?= e($emailAddress) ?>" required></label><label>License level<select name="license_tier"><option value="Pro" <?= $licenseTier === 'Pro' ? 'selected' : '' ?>>Pro</option><option value="Enterprise" <?= $licenseTier === 'Enterprise' ? 'selected' : '' ?>>Enterprise</option></select></label><button class="primary-button" type="submit"><span aria-hidden="true">＋</span> Generate activation key</button></form></div>
<div class="key-result <?= $issued === null ? 'waiting' : 'ready' ?>">
<?php if ($issued === null): ?><div class="waiting-content"><span class="key-symbol" aria-hidden="true">◇</span><h2>Activation key</h2><p>A signed key will appear here after the customer details are validated.</p></div>
<?php else: ?><div class="success-heading"><span aria-hidden="true">✓</span><div><strong><?= e($issued['license_tier']) ?> activation key generated</strong><small><?= e($issued['issued_at']) ?> UTC</small></div></div><dl><div><dt>Customer</dt><dd><?= e($issued['customer_name']) ?></dd></div><div><dt>Email</dt><dd><?= e($issued['email_address']) ?></dd></div><div><dt>License ID</dt><dd><?= e($issued['license_id']) ?></dd></div></dl><label class="key-label">Activation key<textarea id="generated-key" rows="4" readonly><?= e($issued['activation_key']) ?></textarea></label><button type="button" class="copy-key" data-copy-target="generated-key">Copy activation key</button><p class="key-note">The customer enters this key in Settings → License. No reinstall is required.</p><?php endif; ?>
</div></section>
<section class="table-panel license-table"><div class="table-toolbar"><div><h2>Issued licenses</h2><p><?= count($licenses) ?> recorded keys</p></div><div><label class="search"><span aria-hidden="true">⌕</span><input id="license-search" type="search" placeholder="Search customers or email"></label><label><span class="sr-only">Status filter</span><select id="status-filter"><option value="all">All statuses</option><option value="Activated">Activated</option><option value="Issued">Issued</option><option value="Revoked">Revoked</option></select></label></div></div><div class="table-scroll"><table><thead><tr><th>Customer</th><th>Email</th><th>Level</th><th>Issued (UTC)</th><th>License ID</th><th>Status</th><th>Key</th></tr></thead><tbody id="license-rows">
<?php foreach ($licenses as $license): $status = $license['revoked_at'] !== null ? 'Revoked' : ((int)$license['activated'] === 1 ? 'Activated' : 'Issued'); ?><tr data-status="<?= e($status) ?>"><td><?= e($license['customer_name']) ?></td><td><?= e($license['email_address']) ?></td><td><?= e($license['license_tier']) ?></td><td><?= e((new DateTimeImmutable($license['issued_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td class="mono"><?= e($license['license_id']) ?></td><td><span class="license-status <?= strtolower($status) ?>"><?= e($status) ?></span></td><td><button type="button" class="table-copy" data-key="<?= e($license['activation_key']) ?>">Copy</button></td></tr><?php endforeach; ?>
<?php if (!$licenses): ?><tr class="empty-row"><td colspan="7">No activation keys have been issued yet.</td></tr><?php endif; ?></tbody></table></div><footer><span id="license-count">Showing <?= count($licenses) ?> licenses</span></footer></section>
</main></div><script src="assets/licenses.js?v=20260714-2"></script></body></html>
