<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_authentication();

function ensure_dev_support_schema(): void
{
    $pdo = database();
    try {
        $roadmapCount = (int)$pdo->query('SELECT COUNT(*) FROM development_roadmap')->fetchColumn();
        $bugCount = (int)$pdo->query('SELECT COUNT(*) FROM development_bugs')->fetchColumn();
        if ($roadmapCount > 0 && $bugCount > 0) {
            return;
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S02') {
            throw $exception;
        }
        // A missing tracker table is expected on the first deployment.
    }

    $schemaPath = __DIR__ . '/private/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('The Dev Support database schema is unavailable.');
    }
    $statements = array_values(array_filter(
        array_map('trim', explode(';', file_get_contents($schemaPath) ?: '')),
        static fn(string $statement): bool => $statement !== ''
    ));
    if ($statements === []) {
        throw new RuntimeException('The Dev Support database schema is empty.');
    }
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

ensure_dev_support_schema();

// Keep the protected tracker aligned with repository releases after a deployment.
$releaseSync = database()->prepare(
    "INSERT INTO development_roadmap
        (item_key, version_label, item_type, title, status, priority_rank, purpose, planned_scope, priority_reason, completion_criteria, completed_at)
     VALUES
        ('v0.3.16', 'v0.3.16', 'Release', 'In-place receipt export correction', 'Released', 316,
         'Correct the v0.3.15 desktop export failure without delaying the customer fix.',
         'Blob-based Text, Raw, and Capture downloads, native Windows Save dialog, resilient post-startup WebView navigation handling, progress, and errors.',
         'Customers must be able to save receipt artifacts without leaving the selected receipt.',
         'All three formats download, the viewer remains visible, and the desktop no longer shows a ConnectionAborted startup error.', UTC_TIMESTAMP(6)),
        ('v0.3.17', 'v0.3.17', 'Release', 'License tiers and Pro feature gates', 'Released', 317,
         'Establish Trial, Pro, and Enterprise licensing before Enterprise-specific features are introduced.',
         'Tier-aware activation keys, legacy-key compatibility, Pro feature gates for Stored Logos, Printer State, Updates, and Support, telemetry, database migration, and admin issuance.',
         'A stable commercial boundary must precede additional paid and Enterprise functionality.',
         'Trial requests are locked in the UI and APIs while Pro, Enterprise, and legacy paid keys receive the correct access.', UTC_TIMESTAMP(6)),
        ('v0.3.18', 'v0.3.18', 'Release', 'Admin Portal and tier-aware purchase pricing', 'Released', 318,
         'Give the business one clearly named administration area and sell Pro and Enterprise licenses independently.',
         'Admin Portal branding, separate Pro and Enterprise prices, tier-aware PayPal orders, approval, activation-key issuance, email delivery, backward-compatible order migration, and safe private-file deployment filtering.',
         'Commercial license tiers require matching server-controlled purchase pricing and fulfillment.',
         'Both prices save independently and every approved order receives the tier purchased by the customer.', UTC_TIMESTAMP(6)),
        ('v0.3.19', 'v0.3.19', 'Release', 'Printer profiles', 'Next', 319,
         'Model differences between printer configurations explicitly.',
         'Built-in and custom profiles for paper width, dots, code pages, fonts, cutter, drawer, images, barcode and QR features, status behavior, import, and export.',
         'Profiles define behavior before multiple endpoints depend on it.',
         'One capture replayed against two profiles shows deterministic expected capability and rendering differences.', NULL),
        ('v0.3.20', 'v0.3.20', 'Release', 'Multiple printer listeners', 'Planned', 320,
         'Emulate multiple receipt printers from one computer.',
         'Independent listener names, ports, addresses, profiles, state, counters, filtering, conflict detection, firewall setup, and fault isolation.',
         'Multiple listeners reuse the profile model and enable multi-station testing.',
         'Two simultaneous listeners receive jobs, apply different profiles, restart safely, and remain independently controllable.', NULL),
        ('v0.3.21', 'v0.3.21', 'Release', 'Receipt comparison and automated validation', 'Planned', 321,
         'Provide repeatable compatibility and regression testing.',
         'Compare bytes, commands, text, warnings, and rendered output, with saved baselines, ignored dynamic fields, validation suites, and HTML, PDF, and JSON results.',
         'Deterministic captures and profiles are required for meaningful comparisons.',
         'Known-good captures pass, intentional changes fail precisely, and ignored dynamic fields avoid false failures.', NULL),
        ('v0.3.22', 'v0.3.22', 'Release', 'Enhanced support and connection diagnostics', 'Planned', 322,
         'Guide nontechnical customers through connection problems and support collection.',
         'Test the service, listeners, ports, firewall, queues, drivers, viewer, and local and remote connectivity, then create redacted reviewed support packages and offer repair actions.',
         'Diagnostics should understand the completed listener, profile, capture, and comparison system.',
         'Common connection problems are explained without Windows admin tools and a reviewed redacted support package can be produced.', NULL),
        ('v0.3.23', 'v0.3.23', 'Release', 'Guided update installation and restart', 'Planned', 323,
         'Close the application safely before an update replaces installed files, then return the customer to the updated application.',
         'Background installer download; checksum and signature verification; Install and Restart, Install Later, and Cancel choices; active-job drain; listener and service shutdown; external updater process; file-lock wait; state preservation; minimal-prompt installation; automatic relaunch; success confirmation; logs; rollback-safe failure recovery; optional automatic downloads.',
         'A controlled external updater eliminates self-update file locks without unexpected listener downtime or lost customer state.',
         'Install and Restart completes without locked-file errors, relaunches the new version, preserves customer state and data, and leaves the current installation usable after cancellation or failure.', NULL)
     ON DUPLICATE KEY UPDATE
        version_label = VALUES(version_label), item_type = VALUES(item_type), title = VALUES(title),
        status = VALUES(status), priority_rank = VALUES(priority_rank), purpose = VALUES(purpose),
        planned_scope = VALUES(planned_scope), priority_reason = VALUES(priority_reason),
        completion_criteria = VALUES(completion_criteria),
        completed_at = IF(VALUES(status) = 'Released', COALESCE(completed_at, UTC_TIMESTAMP(6)), NULL)"
);
$releaseSync->execute();
database()->prepare(
    "UPDATE development_roadmap SET github_url = ? WHERE item_key = 'v0.3.23' AND (github_url IS NULL OR github_url = '')"
)->execute(['https://github.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/issues/3']);
$bugSync = database()->prepare(
    "INSERT INTO development_bugs
        (bug_key, title, severity, status, affected_versions, target_release, fixed_version, customer_impact,
         expected_behavior, actual_behavior, reproduction_steps, verification, resolved_at)
     VALUES
        ('BUG-005', 'Receipt exports replaced the desktop viewer with a ConnectionAborted error',
         'Medium', 'Released', 'v0.3.15', 'v0.3.16', 'v0.3.16',
         'Pro customers could not save Text, Raw, or Capture files without leaving the desktop receipt viewer.',
         'Selecting an export should open a Save dialog, download the file, and keep the current receipt visible.',
         'Direct attachment links were treated as main-frame WebView navigation and the aborted navigation was displayed as a startup failure.',
         'Select a receipt in the v0.3.15 desktop application and choose Text, Raw, or Capture.',
         'Production viewer build and desktop wrapper build pass; all 45 automated tests pass. Text, Raw, and Capture return the correct attachment types and complete with the viewer URL unchanged, the receipt still visible, and no browser warnings or errors.',
         UTC_TIMESTAMP(6))
     ON DUPLICATE KEY UPDATE
        status = IF(status IN ('Reported', 'Confirmed', 'In progress', 'Fixed locally'), VALUES(status), status),
        target_release = COALESCE(target_release, VALUES(target_release)),
        fixed_version = COALESCE(fixed_version, VALUES(fixed_version)),
        verification = IF(status <> 'Closed - not a bug', VALUES(verification), verification),
        resolved_at = IF(status = 'Released', COALESCE(resolved_at, UTC_TIMESTAMP(6)), resolved_at)"
);
$bugSync->execute();

$roadmapStatuses = ['Released', 'Next', 'Planned', 'In progress', 'Deferred'];
$bugStatuses = ['Reported', 'Confirmed', 'In progress', 'Fixed locally', 'Released', 'Deferred', 'Closed - not a bug'];
$severities = ['Critical', 'High', 'Medium', 'Low'];
$tab = ($_GET['tab'] ?? '') === 'bugs' ? 'bugs' : 'releases';
$notice = '';
$error = '';

function clean_field(string $name, int $maximum, bool $required = true): string
{
    $value = trim((string)($_POST[$name] ?? ''));
    if ($required && $value === '') {
        throw new InvalidArgumentException('Complete all required bug fields.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maximum) {
        throw new InvalidArgumentException('One or more fields are longer than allowed.');
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update-roadmap') {
            $tab = 'releases';
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $status = (string)($_POST['status'] ?? '');
            if ($id === false || !in_array($status, $roadmapStatuses, true)) {
                throw new InvalidArgumentException('Select a valid roadmap status.');
            }
            $statement = database()->prepare(
                "UPDATE development_roadmap
                 SET status = :status,
                     completed_at = CASE WHEN :released_status = 'Released' THEN COALESCE(completed_at, UTC_TIMESTAMP(6)) ELSE NULL END
                 WHERE id = :id"
            );
            $statement->execute(['status' => $status, 'released_status' => $status, 'id' => $id]);
            $notice = 'Roadmap status updated. Remember to make the matching change in GitHub.';
        } elseif ($action === 'add-bug') {
            $tab = 'bugs';
            $title = clean_field('title', 220);
            $severity = clean_field('severity', 16);
            if (!in_array($severity, $severities, true)) {
                throw new InvalidArgumentException('Select a valid severity.');
            }
            $affected = clean_field('affected_versions', 160, false);
            $target = clean_field('target_release', 32, false);
            $impact = clean_field('customer_impact', 5000);
            $expected = clean_field('expected_behavior', 5000);
            $actual = clean_field('actual_behavior', 5000);
            $steps = clean_field('reproduction_steps', 10000);
            $pdo = database();
            $pdo->beginTransaction();
            try {
                $nextNumber = (int)$pdo->query(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(bug_key, 5) AS UNSIGNED)), 0) + 1
                     FROM development_bugs FOR UPDATE"
                )->fetchColumn();
                $bugKey = sprintf('BUG-%03d', $nextNumber);
                $insert = $pdo->prepare(
                    'INSERT INTO development_bugs
                        (bug_key, title, severity, status, affected_versions, target_release,
                         customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification)
                     VALUES
                        (:bug_key, :title, :severity, \'Reported\', :affected_versions, :target_release,
                         :customer_impact, :expected_behavior, :actual_behavior, :reproduction_steps, \'\')'
                );
                $insert->execute([
                    'bug_key' => $bugKey,
                    'title' => $title,
                    'severity' => $severity,
                    'affected_versions' => $affected,
                    'target_release' => $target === '' ? null : $target,
                    'customer_impact' => $impact,
                    'expected_behavior' => $expected,
                    'actual_behavior' => $actual,
                    'reproduction_steps' => $steps,
                ]);
                $pdo->commit();
                $notice = $bugKey . ' was recorded. Create the matching GitHub issue and add its URL during triage.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } elseif ($action === 'update-bug') {
            $tab = 'bugs';
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $status = (string)($_POST['status'] ?? '');
            $severity = (string)($_POST['severity'] ?? '');
            $target = clean_field('target_release', 32, false);
            $fixed = clean_field('fixed_version', 32, false);
            $verification = clean_field('verification', 5000, false);
            $githubUrl = clean_field('github_url', 500, false);
            if ($id === false || !in_array($status, $bugStatuses, true) || !in_array($severity, $severities, true)) {
                throw new InvalidArgumentException('Select a valid bug status and severity.');
            }
            if ($githubUrl !== '' && filter_var($githubUrl, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Enter a valid GitHub issue URL.');
            }
            $resolved = in_array($status, ['Released', 'Closed - not a bug'], true);
            $statement = database()->prepare(
                'UPDATE development_bugs
                 SET status = :status, severity = :severity, target_release = :target_release,
                     fixed_version = :fixed_version, verification = :verification, github_url = :github_url,
                     resolved_at = CASE WHEN :is_resolved = 1 THEN COALESCE(resolved_at, UTC_TIMESTAMP(6)) ELSE NULL END
                 WHERE id = :id'
            );
            $statement->execute([
                'status' => $status,
                'severity' => $severity,
                'target_release' => $target === '' ? null : $target,
                'fixed_version' => $fixed === '' ? null : $fixed,
                'verification' => $verification,
                'github_url' => $githubUrl === '' ? null : $githubUrl,
                'is_resolved' => $resolved ? 1 : 0,
                'id' => $id,
            ]);
            $notice = 'Bug record updated. Remember to make the matching change in GitHub.';
        } else {
            throw new InvalidArgumentException('The requested Dev Support action is not valid.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('POS Printer Emulator Dev Support failure: ' . $exception->getMessage());
        $error = 'Dev Support could not save the change. Confirm the database schema is current and try again.';
    }
}

$roadmap = database()->query(
    'SELECT id, item_key, version_label, item_type, title, status, priority_rank, purpose,
            planned_scope, priority_reason, completion_criteria, github_url, completed_at, updated_at
     FROM development_roadmap ORDER BY priority_rank'
)->fetchAll();
$bugs = database()->query(
    'SELECT id, bug_key, title, severity, status, affected_versions, target_release, fixed_version,
            customer_impact, expected_behavior, actual_behavior, reproduction_steps, verification,
            github_url, resolved_at, created_at, updated_at
     FROM development_bugs ORDER BY CAST(SUBSTRING(bug_key, 5) AS UNSIGNED) DESC'
)->fetchAll();

$releasedItems = array_values(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Release' && $item['status'] === 'Released'));
$releasedCount = count($releasedItems);
$currentRelease = $releasedCount > 0 ? (string)$releasedItems[$releasedCount - 1]['version_label'] : '—';
$scheduledCount = count(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Release' && $item['status'] !== 'Released'));
$backlogCount = count(array_filter($roadmap, static fn(array $item): bool => $item['item_type'] === 'Backlog'));
$openBugCount = count(array_filter($bugs, static fn(array $bug): bool => !in_array($bug['status'], ['Released', 'Closed - not a bug'], true)));

function lines(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', $value) ?: [])));
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dev Support | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/dev-support.css?v=20260715-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a class="active" href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>GitHub and Dev Support statuses must stay aligned.</p></aside>
<main class="dev-support-main"><div class="page-heading"><div><h1>Dev Support</h1><p>Track product releases and defects from the protected Admin Portal.</p></div></div>
<?php if ($notice !== ''): ?><div class="dev-notice" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="dev-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<nav class="dev-tabs" aria-label="Dev Support sections"><a class="<?= $tab === 'releases' ? 'active' : '' ?>" href="?tab=releases" aria-current="<?= $tab === 'releases' ? 'page' : 'false' ?>">Release Tracker <span><?= $scheduledCount ?></span></a><a class="<?= $tab === 'bugs' ? 'active' : '' ?>" href="?tab=bugs" aria-current="<?= $tab === 'bugs' ? 'page' : 'false' ?>">Bug Tracker <span><?= $openBugCount ?></span></a></nav>

<?php if ($tab === 'releases'): ?>
<section class="dev-metrics" aria-label="Release totals"><article><span>Current release</span><strong><?= e($currentRelease) ?></strong></article><article><span>Released</span><strong><?= $releasedCount ?></strong></article><article><span>Scheduled</span><strong><?= $scheduledCount ?></strong></article><article><span>Backlog</span><strong><?= $backlogCount ?></strong></article></section>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow">Delivery plan</span><h2>Scheduled releases</h2></div><p>Update GitHub and this status together.</p></div><div class="roadmap-list">
<?php foreach ($roadmap as $item): if ($item['item_type'] !== 'Release' || $item['status'] === 'Released') continue; ?>
<article class="roadmap-card"><header><div><span class="item-key"><?= e((string)$item['version_label']) ?></span><h3><?= e($item['title']) ?></h3></div><span class="tracker-status <?= e(strtolower(str_replace(' ', '-', $item['status']))) ?>"><?= e($item['status']) ?></span></header><p class="purpose"><?= e($item['purpose']) ?></p><details><summary>Detailed scope and completion criteria</summary><h4>Planned scope</h4><ul><?php foreach (lines($item['planned_scope']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><h4>Why this priority</h4><p><?= e($item['priority_reason']) ?></p><h4>Complete when</h4><p><?= e($item['completion_criteria']) ?></p></details><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-roadmap"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><label>Status<select name="status"><?php foreach ($roadmapStatuses as $status): ?><option <?= $item['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Save status</button></form></article>
<?php endforeach; ?></div></section>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow backlog">Prioritized</span><h2>Future backlog</h2></div><p>Version numbers are assigned after the order is approved.</p></div><div class="roadmap-list backlog-list">
<?php foreach ($roadmap as $item): if ($item['item_type'] !== 'Backlog') continue; ?>
<article class="roadmap-card"><header><div><span class="item-key">Priority <?= (int)$item['priority_rank'] - 1000 ?></span><h3><?= e($item['title']) ?></h3></div><span class="tracker-status <?= e(strtolower(str_replace(' ', '-', $item['status']))) ?>"><?= e($item['status']) ?></span></header><p class="purpose"><?= e($item['purpose']) ?></p><details><summary>Detailed scope and priority reason</summary><h4>Proposed scope</h4><ul><?php foreach (lines($item['planned_scope']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><h4>Why this priority</h4><p><?= e($item['priority_reason']) ?></p><h4>Complete when</h4><p><?= e($item['completion_criteria']) ?></p></details><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-roadmap"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><label>Status<select name="status"><?php foreach ($roadmapStatuses as $status): ?><option <?= $item['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Save status</button></form></article>
<?php endforeach; ?></div></section>
<section class="tracker-section completed-releases"><div class="section-heading"><div><span class="eyebrow released">History</span><h2>Completed releases</h2></div><p>Detailed public notes remain in CHANGELOG.md.</p></div><div class="release-history"><?php foreach (array_reverse($roadmap) as $item): if ($item['item_type'] !== 'Release' || $item['status'] !== 'Released') continue; ?><article><span><?= e((string)$item['version_label']) ?></span><div><strong><?= e($item['title']) ?></strong><p><?= e($item['planned_scope']) ?></p></div><b>Released</b></article><?php endforeach; ?></div></section>

<?php else: ?>
<section class="dev-metrics bug-metrics" aria-label="Bug totals"><article><span>Open bugs</span><strong><?= $openBugCount ?></strong></article><?php foreach ($severities as $severity): $count = count(array_filter($bugs, static fn(array $bug): bool => $bug['severity'] === $severity && !in_array($bug['status'], ['Released', 'Closed - not a bug'], true))); ?><article class="severity-<?= strtolower($severity) ?>"><span><?= e($severity) ?></span><strong><?= $count ?></strong></article><?php endforeach; ?></section>
<details class="new-bug-panel" <?= $error !== '' && ($_POST['action'] ?? '') === 'add-bug' ? 'open' : '' ?>><summary>＋ Record a new bug</summary><form method="post" class="new-bug-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add-bug"><label class="wide">Bug title<input name="title" maxlength="220" required value="<?= e((string)($_POST['action'] ?? '') === 'add-bug' ? (string)($_POST['title'] ?? '') : '') ?>"></label><label>Severity<select name="severity"><?php foreach ($severities as $severity): ?><option><?= e($severity) ?></option><?php endforeach; ?></select></label><label>Affected versions<input name="affected_versions" maxlength="160" placeholder="Example: v0.3.14"></label><label>Target release<input name="target_release" maxlength="32" placeholder="Example: v0.3.15"></label><label class="wide">Customer impact<textarea name="customer_impact" rows="3" required></textarea></label><label class="wide">Expected behavior<textarea name="expected_behavior" rows="3" required></textarea></label><label class="wide">Actual behavior<textarea name="actual_behavior" rows="3" required></textarea></label><label class="wide">Reproduction steps<textarea name="reproduction_steps" rows="5" required placeholder="1.&#10;2.&#10;3."></textarea></label><button type="submit">Record bug</button></form></details>
<section class="tracker-section"><div class="section-heading"><div><span class="eyebrow bug">Defect register</span><h2>Bug records</h2></div><p>Never paste activation keys, customer data, private logs, or receipt contents.</p></div><div class="bug-list">
<?php foreach ($bugs as $bug): ?>
<article class="bug-card"><header><div><span class="item-key"><?= e($bug['bug_key']) ?></span><h3><?= e($bug['title']) ?></h3></div><div class="bug-badges"><span class="severity <?= e(strtolower($bug['severity'])) ?>"><?= e($bug['severity']) ?></span><span class="tracker-status <?= e(strtolower(str_replace([' ', '-'], ['', ''], $bug['status']))) ?>"><?= e(str_replace(' - ', ' — ', $bug['status'])) ?></span></div></header><div class="bug-summary"><div><span>Affected</span><strong><?= e($bug['affected_versions'] ?: 'Not recorded') ?></strong></div><div><span>Target</span><strong><?= e($bug['target_release'] ?: 'Unassigned') ?></strong></div><div><span>Fixed in</span><strong><?= e($bug['fixed_version'] ?: '—') ?></strong></div></div><details><summary>Report and verification details</summary><h4>Customer impact</h4><p><?= nl2br(e($bug['customer_impact'])) ?></p><h4>Expected behavior</h4><p><?= nl2br(e($bug['expected_behavior'])) ?></p><h4>Actual behavior</h4><p><?= nl2br(e($bug['actual_behavior'])) ?></p><h4>Reproduction steps</h4><p><?= nl2br(e($bug['reproduction_steps'])) ?></p></details><form method="post" class="bug-update-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update-bug"><input type="hidden" name="id" value="<?= (int)$bug['id'] ?>"><label>Status<select name="status"><?php foreach ($bugStatuses as $status): ?><option value="<?= e($status) ?>" <?= $bug['status'] === $status ? 'selected' : '' ?>><?= e(str_replace(' - ', ' — ', $status)) ?></option><?php endforeach; ?></select></label><label>Severity<select name="severity"><?php foreach ($severities as $severity): ?><option <?= $bug['severity'] === $severity ? 'selected' : '' ?>><?= e($severity) ?></option><?php endforeach; ?></select></label><label>Target release<input name="target_release" maxlength="32" value="<?= e((string)($bug['target_release'] ?? '')) ?>"></label><label>Fixed version<input name="fixed_version" maxlength="32" value="<?= e((string)($bug['fixed_version'] ?? '')) ?>"></label><label class="wide">Verification<textarea name="verification" rows="3"><?= e($bug['verification']) ?></textarea></label><label class="wide">GitHub issue URL<input type="url" name="github_url" maxlength="500" value="<?= e((string)($bug['github_url'] ?? '')) ?>" placeholder="https://github.com/..."></label><button type="submit">Save bug</button><?php if ($bug['github_url']): ?><a class="github-link" href="<?= e($bug['github_url']) ?>" target="_blank" rel="noopener">Open GitHub issue ↗</a><?php endif; ?></form></article>
<?php endforeach; ?></div></section>
<?php endif; ?>
</main></div></body></html>
