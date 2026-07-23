<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_authentication();

$pdo = database();
$error = '';
$requests = [];
$selected = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'reply') {
        require_csrf();
        $reference = trim((string)($_POST['reference'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if (!preg_match('/^SUP-[A-F0-9]{12}$/', $reference) || $message === '' || mb_strlen($message) > 5000) {
            throw new DomainException('Enter a support reply of no more than 5,000 characters.');
        }
        $requestOwner = $pdo->prepare('SELECT customer_id FROM support_requests WHERE reference_code=:reference LIMIT 1');
        $requestOwner->execute(['reference' => $reference]);
        $replyCustomerId = $requestOwner->fetchColumn();
        if (!is_string($replyCustomerId) || $replyCustomerId === '') {
            throw new DomainException('That Customer Portal request is unavailable.');
        }
        $reply = $pdo->prepare(
            "INSERT INTO portal_support_replies(reference_code,customer_id,message,author_type)
             VALUES(:reference,:customer_id,:message,'Support')"
        );
        $reply->execute(['reference' => $reference, 'customer_id' => $replyCustomerId, 'message' => $message]);
        header('Location: /support-requests.php?request=' . rawurlencode($reference), true, 303);
        exit;
    }

    if (isset($_GET['attachment'])) {
        $attachmentId = filter_var($_GET['attachment'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($attachmentId === false) {
            http_response_code(400);
            exit('Invalid attachment.');
        }
        $query = $pdo->prepare('SELECT file_name, content_type, content FROM support_request_attachments WHERE id = :id LIMIT 1');
        $query->execute(['id' => $attachmentId]);
        $attachment = $query->fetch();
        if (!is_array($attachment)) {
            http_response_code(404);
            exit('Attachment not found.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9_. -]/', '_', basename((string)$attachment['file_name'])) ?: 'attachment';
        header('Content-Type: ' . (string)$attachment['content_type']);
        header('Content-Disposition: attachment; filename="' . addcslashes($safeName, "\"\\") . '"');
        header('Content-Length: ' . strlen((string)$attachment['content']));
        header('Cache-Control: private, no-store');
        echo $attachment['content'];
        exit;
    }

    $requests = $pdo->query(
        "SELECT r.reference_code, r.customer_id, r.license_id, r.request_type, r.subject, r.contact_name,
                r.contact_email, r.private_diagnostics, r.github_issue_number, r.github_issue_url,
                r.state, r.created_at, r.submitted_at,
                (SELECT COUNT(*) FROM support_request_attachments a WHERE a.reference_code=r.reference_code) attachment_count
         FROM support_requests r ORDER BY r.created_at DESC LIMIT 250"
    )->fetchAll();
    $requestedReference = (string)($_GET['request'] ?? '');
    $selected = $requests[0] ?? null;
    foreach ($requests as $candidate) {
        if ($requestedReference !== '' && hash_equals((string)$candidate['reference_code'], $requestedReference)) {
            $selected = $candidate;
            break;
        }
    }
    if (is_array($selected)) {
        $attachmentQuery = $pdo->prepare('SELECT id, file_name, content_type, OCTET_LENGTH(content) size FROM support_request_attachments WHERE reference_code = :reference ORDER BY id');
        $attachmentQuery->execute(['reference' => $selected['reference_code']]);
        $selected['attachments'] = $attachmentQuery->fetchAll();
        $replyQuery = $pdo->prepare(
            'SELECT author_type,message,created_at FROM portal_support_replies
             WHERE reference_code=:reference AND customer_id=:customer_id ORDER BY created_at'
        );
        $replyQuery->execute(['reference' => $selected['reference_code'], 'customer_id' => $selected['customer_id']]);
        $selected['replies'] = $replyQuery->fetchAll();
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('POS Printer Emulator Support Requests page failure: ' . $exception->getMessage());
    $error = 'Support requests are not available yet. Deploy the v0.3.33 database schema and try again.';
}

function support_size(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, $bytes / 1024), 1) . ' KB';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Support Requests | POS Printer Emulator Admin Portal</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/dev-support.css?v=20260715-1"><link rel="stylesheet" href="assets/support-requests.css?v=20260721-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/customers.php"><span aria-hidden="true">◎</span>Customers</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/communications.php"><span aria-hidden="true">✉</span>Communications</a><a class="active" href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>Customer contact details and diagnostics stay private in this portal.</p></aside>
<main class="dev-support-main"><div class="page-heading"><div><h1>Dev Support</h1><p>Review customer-submitted support requests and their private diagnostic material.</p></div></div>
<?php if ($error !== ''): ?><div class="dev-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<nav class="dev-tabs" aria-label="Dev Support sections"><a href="/dev-support.php?tab=releases">Release Tracker</a><a href="/dev-support.php?tab=bugs">Bug Tracker</a><a class="active" href="/support-requests.php" aria-current="page">Support Requests <span><?= count($requests) ?></span></a></nav>
<div class="support-workspace">
<section class="support-request-list" aria-label="Support requests">
<?php if ($requests === []): ?><div class="support-empty"><strong>No support requests yet</strong><p>New in-app submissions will appear here.</p></div><?php endif; ?>
<?php foreach ($requests as $request): ?><a class="support-request-row <?= is_array($selected) && $selected['reference_code'] === $request['reference_code'] ? 'selected' : '' ?>" href="?request=<?= urlencode((string)$request['reference_code']) ?>"><div><strong><?= e((string)$request['subject']) ?></strong><span><?= e((string)$request['request_type']) ?> · <?= e((string)$request['reference_code']) ?></span></div><em class="<?= strtolower((string)$request['state']) ?>"><?= e((string)$request['state']) ?></em><small><?= e((string)$request['created_at']) ?> UTC</small></a><?php endforeach; ?>
</section>
<section class="support-request-detail">
<?php if (is_array($selected)): ?><header><div><span><?= e((string)$selected['request_type']) ?></span><h2><?= e((string)$selected['subject']) ?></h2><code><?= e((string)$selected['reference_code']) ?></code></div><?php if ($selected['github_issue_url']): ?><a href="<?= e((string)$selected['github_issue_url']) ?>" target="_blank" rel="noopener">Open GitHub issue #<?= (int)$selected['github_issue_number'] ?> ↗</a><?php endif; ?></header>
<dl><div><dt>Customer</dt><dd><?= e((string)$selected['contact_name']) ?></dd></div><div><dt>Email</dt><dd><a href="mailto:<?= e((string)$selected['contact_email']) ?>"><?= e((string)$selected['contact_email']) ?></a></dd></div><div><dt>License ID</dt><dd><code><?= e((string)$selected['license_id']) ?></code></dd></div><div><dt>Status</dt><dd><?= e((string)$selected['state']) ?></dd></div></dl>
<section class="private-material"><h3>Private attachments</h3><?php if (($selected['attachments'] ?? []) === []): ?><p>No attachments were included.</p><?php else: ?><ul><?php foreach ($selected['attachments'] as $attachment): ?><li><span><?= e((string)$attachment['file_name']) ?> <small><?= e((string)$attachment['content_type']) ?> · <?= e(support_size((int)$attachment['size'])) ?></small></span><a href="?attachment=<?= (int)$attachment['id'] ?>">Download</a></li><?php endforeach; ?></ul><?php endif; ?></section>
<details class="private-diagnostics"><summary>Redacted diagnostic log</summary><?php if (trim((string)$selected['private_diagnostics']) === ''): ?><p>No diagnostic log was included.</p><?php else: ?><pre><?= e((string)$selected['private_diagnostics']) ?></pre><?php endif; ?></details>
<?php if (!empty($selected['customer_id'])): ?><section class="private-material"><h3>Customer Portal conversation</h3><?php if (($selected['replies'] ?? []) === []): ?><p>No replies yet.</p><?php else: ?><ul><?php foreach ($selected['replies'] as $reply): ?><li><span><strong><?= e((string)$reply['author_type']) ?></strong> · <?= e((string)$reply['created_at']) ?> UTC<br><?= nl2br(e((string)$reply['message'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="reference" value="<?= e((string)$selected['reference_code']) ?>"><label for="support-reply">Reply to customer</label><textarea id="support-reply" name="message" rows="5" maxlength="5000" required></textarea><button type="submit">Add private reply</button></form></section><?php endif; ?>
<?php else: ?><div class="support-empty"><strong>Select a support request</strong><p>Private contact information and diagnostics appear here.</p></div><?php endif; ?>
</section></div></main></div></body></html>
