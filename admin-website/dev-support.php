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
    "UPDATE development_roadmap
     SET status = 'Released', resolved_at = COALESCE(resolved_at, UTC_TIMESTAMP(6))
     WHERE item_key = 'v0.3.15' AND status IN ('Next', 'Planned', 'In progress')"
);
$releaseSync->execute();
$nextSync = database()->prepare(
    "UPDATE development_roadmap
     SET status = 'Next'
     WHERE item_key = 'v0.3.16' AND status = 'Planned'"
);
$nextSync->execute();

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
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator</span></a><form method="post" action="/logout.php" class="logout-form"><span>Owner Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a class="active" href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>GitHub and Dev Support statuses must stay aligned.</p></aside>
<main class="dev-support-main"><div class="page-heading"><div><h1>Dev Support</h1><p>Track product releases and defects from one protected owner workspace.</p></div></div>
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
