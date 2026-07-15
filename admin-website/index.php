<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_authentication();

$requestedDays = (int)($_GET['days'] ?? 30);
$days = in_array($requestedDays, [7, 30, 90], true) ? $requestedDays : 30;
$pdo = database();
$summary = $pdo->query(
    "SELECT
        SUM(license_mode = 'Trial') AS trials,
        SUM(license_mode = 'Full') AS full_licenses,
        COUNT(*) AS installations
     FROM installations"
)->fetch();
$totalsStatement = $pdo->prepare(
    'SELECT COALESCE(SUM(launch_count), 0) AS launches, COALESCE(SUM(print_job_count), 0) AS jobs
     FROM daily_usage WHERE usage_date >= UTC_DATE() - INTERVAL :days DAY');
$totalsStatement->bindValue('days', $days - 1, PDO::PARAM_INT);
$totalsStatement->execute();
$totals = $totalsStatement->fetch();

$usageStatement = $pdo->prepare(
    'SELECT usage_date, SUM(launch_count) AS launches, SUM(print_job_count) AS jobs
     FROM daily_usage WHERE usage_date >= UTC_DATE() - INTERVAL :days DAY
     GROUP BY usage_date ORDER BY usage_date');
$usageStatement->bindValue('days', $days - 1, PDO::PARAM_INT);
$usageStatement->execute();
$usageByDate = [];
foreach ($usageStatement->fetchAll() as $row) {
    $usageByDate[$row['usage_date']] = ['launches' => (int)$row['launches'], 'jobs' => (int)$row['jobs']];
}
$series = [];
$start = new DateTimeImmutable('today -' . ($days - 1) . ' days', new DateTimeZone('UTC'));
for ($index = 0; $index < $days; $index++) {
    $date = $start->modify("+{$index} days")->format('Y-m-d');
    $series[] = ['date' => $date] + ($usageByDate[$date] ?? ['launches' => 0, 'jobs' => 0]);
}

$installations = $pdo->query(
    'SELECT installation_uuid, customer_name, email_address, app_version, license_mode, license_id,
            last_seen_at, launch_count, print_job_count
     FROM installations ORDER BY last_seen_at DESC LIMIT 500'
)->fetchAll();

$metric = static fn($value): string => number_format((int)$value);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>License &amp; Usage | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/admin-overrides.css?v=20260714-2"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator</span></a><form method="post" action="/logout.php" class="logout-form"><span>Owner Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a class="active" href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="https://posprinteremulator.com/privacy.html"><span aria-hidden="true">⚙</span>Settings</a></nav><p>No receipt contents<br>are collected.</p></aside>
<main id="dashboard"><div class="page-heading"><h1>License &amp; Usage</h1><form method="get"><label><span class="sr-only">Date range</span><select id="date-range" name="days"><option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option><option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option><option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option></select></label></form></div>
<section class="metrics" aria-label="Summary">
<article class="trial"><svg class="metric-icon" viewBox="0 0 48 48" aria-hidden="true"><circle cx="22" cy="14" r="8"/><path d="M8 39v-5c0-7 6-11 14-11s14 4 14 11v5M36 29v12m-5-5 5 5 5-5"/></svg><div><span>Trial installations</span><strong><?= $metric($summary['trials'] ?? 0) ?></strong></div></article>
<article class="full"><svg class="metric-icon" viewBox="0 0 48 48" aria-hidden="true"><path d="M11 5h18l9 9v11M29 5v10h9M16 24h10M16 31h7"/><circle cx="35" cy="35" r="9"/><path d="m31 35 3 3 6-7"/></svg><div><span>Active licenses</span><strong><?= $metric($summary['full_licenses'] ?? 0) ?></strong></div></article>
<article><svg class="metric-icon" viewBox="0 0 48 48" aria-hidden="true"><path d="M27 7c7-3 12-2 14-1 1 3 2 8-1 15L27 34l-9-9L27 7Z"/><circle cx="32" cy="15" r="3"/><path d="m18 25-9 2 6-10 7-3M27 34l-2 9 10-6 3-8M15 34l-5 5"/></svg><div><span>Application launches</span><strong><?= $metric($totals['launches'] ?? 0) ?></strong></div></article>
<article><svg class="metric-icon" viewBox="0 0 48 48" aria-hidden="true"><path d="M11 5h26v36l-5-3-5 3-5-3-5 3-6-3V5Z"/><path d="M17 15h14M17 22h14M17 29h9"/></svg><div><span>Emulated print jobs</span><strong><?= $metric($totals['jobs'] ?? 0) ?></strong></div></article>
</section>
<section class="visuals"><article class="chart-panel"><div class="panel-title"><h2>Usage activity</h2><span>Last <?= $days ?> days</span></div><div class="legend"><span class="launches">Application launches</span><span class="jobs">Emulated print jobs</span></div><svg id="usage-chart" viewBox="0 0 760 260" role="img" aria-label="Application launches and emulated print jobs over time"></svg></article>
<article class="distribution"><h2>License distribution</h2><div class="donut-wrap"><div class="donut <?= (int)($summary['installations'] ?? 0) === 0 ? 'empty' : '' ?>" style="--full: <?= (int)($summary['installations'] ?? 0) > 0 ? round(((int)$summary['full_licenses'] / (int)$summary['installations']) * 100, 2) : 0 ?>%"><div><strong><?= $metric($summary['installations'] ?? 0) ?></strong><span>installations</span></div></div><ul><li><i class="full-dot"></i><span>Full</span><strong><?= $metric($summary['full_licenses'] ?? 0) ?></strong></li><li><i class="trial-dot"></i><span>Trial</span><strong><?= $metric($summary['trials'] ?? 0) ?></strong></li></ul></div></article></section>
<section class="table-panel" id="installations"><div class="table-toolbar"><h2>Installations</h2><div><label class="search"><span aria-hidden="true">⌕</span><input id="customer-search" type="search" placeholder="Search customers"></label><label><span class="sr-only">License filter</span><select id="license-filter"><option value="all">All licenses</option><option value="Full">Full</option><option value="Trial">Trial</option></select></label></div></div><div class="table-scroll"><table><thead><tr><th>Company</th><th>Email</th><th>Version</th><th>License</th><th>Last seen (UTC)</th><th>Launches</th><th>Print jobs</th></tr></thead><tbody id="installation-rows">
<?php foreach ($installations as $installation): ?><tr data-license="<?= htmlspecialchars($installation['license_mode']) ?>"><td><?= htmlspecialchars($installation['customer_name'] ?: 'Unregistered') ?></td><td><?= htmlspecialchars($installation['email_address'] ?: '—') ?></td><td><?= htmlspecialchars($installation['app_version']) ?></td><td><span class="status <?= strtolower($installation['license_mode']) ?>"><?= htmlspecialchars($installation['license_mode']) ?></span></td><td><?= htmlspecialchars((new DateTimeImmutable($installation['last_seen_at'], new DateTimeZone('UTC')))->format('M j, Y g:i A')) ?></td><td><?= $metric($installation['launch_count']) ?></td><td><?= $metric($installation['print_job_count']) ?></td></tr><?php endforeach; ?>
<?php if (!$installations): ?><tr class="empty-row"><td colspan="7">No installations have reported usage yet.</td></tr><?php endif; ?></tbody></table></div><footer><span id="visible-count">Showing <?= count($installations) ?> installations</span></footer></section></main></div>
<script id="usage-data" type="application/json"><?= json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><script src="assets/admin.js?v=20260714-2"></script></body></html>
