<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/purchase_site.php';
require_authentication();

$allowedStatuses = ['PAID_AWAITING_APPROVAL','EMAILED','EMAIL_FAILED'];
$status = (string)($_GET['status'] ?? 'PAID_AWAITING_APPROVAL');
if (!in_array($status,$allowedStatuses,true)) $status = 'PAID_AWAITING_APPROVAL';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'approve');
        purchase_site_request('/api/admin-orders.php','POST',['order'=>(string)($_POST['order']??''),'action'=>$action]);
        $_SESSION['order_flash'] = $action === 'retry_email' ? 'The activation email was sent.' : 'The order was approved and the activation key was emailed.';
        header('Location: /orders.php?status=' . urlencode($action === 'retry_email' ? 'EMAILED' : 'PAID_AWAITING_APPROVAL'));
        exit;
    } catch (Throwable $exception) {
        error_log('Purchase order action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}
$flash = (string)($_SESSION['order_flash'] ?? ''); unset($_SESSION['order_flash']);
$orders = [];
try {
    $response = purchase_site_request('/api/admin-orders.php?status=' . urlencode($status));
    $orders = is_array($response['orders'] ?? null) ? $response['orders'] : [];
} catch (Throwable $exception) {
    error_log('Purchase order list failed: ' . $exception->getMessage());
    $error = $error !== '' ? $error : $exception->getMessage();
}
$selected = $orders[0] ?? null;
$requested = (string)($_GET['order'] ?? '');
foreach ($orders as $candidate) if (hash_equals((string)$candidate['public_id'],$requested)) $selected = $candidate;
$statusLabel = static fn(string $value): string => match($value){'PAID_AWAITING_APPROVAL'=>'Paid — awaiting approval','EMAILED'=>'Key emailed','EMAIL_FAILED'=>'Email issue',default=>str_replace('_',' ',$value)};
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Orders | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/orders.css?v=20260715-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator</span></a><form method="post" action="/logout.php" class="logout-form"><span>Owner Account</span><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a class="active" href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a></nav><p>Only verified PayPal payments can be approved.</p></aside>
<main class="orders-main"><div class="page-heading"><div><h1>Purchase Orders</h1><p>Approve captured payments and deliver customer activation keys.</p></div></div>
<?php if($flash!==''):?><div class="order-success" role="status"><?=e($flash)?></div><?php endif;?><?php if($error!==''):?><div class="order-error" role="alert"><?=e($error)?></div><?php endif;?>
<nav class="order-tabs" aria-label="Order status"><a class="<?=$status==='PAID_AWAITING_APPROVAL'?'active':''?>" href="?status=PAID_AWAITING_APPROVAL">Awaiting approval</a><a class="<?=$status==='EMAILED'?'active':''?>" href="?status=EMAILED">Key emailed</a><a class="<?=$status==='EMAIL_FAILED'?'active':''?>" href="?status=EMAIL_FAILED">Email issue</a></nav>
<div class="order-workspace"><section class="order-list" aria-label="Orders"><?php if(!$orders):?><div class="empty-orders"><span>✓</span><h2>No orders in this queue</h2><p>New matching orders will appear here automatically.</p></div><?php else:?><?php foreach($orders as $order):?><a class="order-row <?=($selected['public_id']??'')===$order['public_id']?'selected':''?>" href="?status=<?=urlencode($status)?>&amp;order=<?=urlencode($order['public_id'])?>"><div><strong><?=e($order['customer_name'])?></strong><span><?=e($order['email'])?></span></div><div><strong>$<?=e($order['amount'])?> <?=e($order['currency'])?></strong><span><?=e((string)($order['paid_at']??$order['created_at']))?> UTC</span></div><em><?=e($statusLabel($order['status']))?></em></a><?php endforeach;?><?php endif;?></section>
<aside class="order-detail"><?php if($selected):?><div class="detail-heading"><span class="verified">✓ Verified payment</span><h2><?=e($selected['customer_name'])?></h2><a href="mailto:<?=e($selected['email'])?>"><?=e($selected['email'])?></a></div><dl><div><dt>Amount</dt><dd>$<?=e($selected['amount'])?> <?=e($selected['currency'])?></dd></div><div><dt>PayPal order</dt><dd><?=e($selected['paypal_order_id'])?></dd></div><div><dt>Capture ID</dt><dd><?=e((string)($selected['paypal_capture_id']??'—'))?></dd></div><div><dt>Paid at</dt><dd><?=e((string)($selected['paid_at']??'—'))?> UTC</dd></div><?php if(!empty($selected['license_id'])):?><div><dt>License ID</dt><dd><?=e($selected['license_id'])?></dd></div><?php endif;?></dl>
<?php if($selected['status']==='PAID_AWAITING_APPROVAL'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="order" value="<?=e($selected['public_id'])?>"><input type="hidden" name="action" value="approve"><button class="approve-order">Approve &amp; email activation key</button></form><?php elseif($selected['status']==='EMAIL_FAILED'):?><div class="email-failure"><?=e((string)($selected['last_error']??'Email delivery failed.'))?></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="order" value="<?=e($selected['public_id'])?>"><input type="hidden" name="action" value="retry_email"><button class="approve-order">Retry activation email</button></form><?php else:?><div class="delivered">✓ Activation key emailed <?=e((string)($selected['emailed_at']??''))?> UTC</div><?php endif;?><p class="order-safety">A key is generated only after the server verifies a completed PayPal capture.</p><?php else:?><div class="no-selection">Select an order to view its details.</div><?php endif;?></aside></div></main></div></body></html>
