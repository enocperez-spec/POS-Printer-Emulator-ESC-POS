<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/purchase_site.php';
require_authentication();
$saved = false; $error = ''; $offer = ['price'=>'0.00','currency'=>'USD'];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $response = purchase_site_request('/api/admin-price.php', 'POST', ['price'=>(string)($_POST['price']??''),'currency'=>(string)($_POST['currency']??'USD')]);
        $saved = true;
    } else {
        $response = purchase_site_request('/api/admin-price.php');
    }
    $offer = $response['offer'] ?? $offer;
} catch (Throwable $exception) {
    error_log('Purchase pricing management failure: ' . $exception->getMessage());
    $error = $exception->getMessage();
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Pricing | POS Printer Emulator</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/pricing.css?v=20260715-1"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator</span></a><form method="post" action="/logout.php" class="logout-form"><span>Owner Account</span><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a class="active" href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>Changes apply to new PayPal orders immediately.</p></aside>
<main class="pricing-main"><div class="page-heading"><div><h1>Purchase Pricing</h1><p>Manage the one-time Pro Version price displayed at buy.posprinteremulator.com.</p></div></div>
<?php if($saved):?><div class="price-success" role="status">Price updated. The Buy page now shows <strong>$<?=e((string)$offer['price'])?> <?=e((string)$offer['currency'])?></strong>.</div><?php endif;?>
<?php if($error!==''):?><div class="price-error" role="alert"><?=e($error)?></div><?php endif;?>
<section class="price-panel"><div><span class="eyebrow">Pro Version</span><h2>One-time license price</h2><p>The server uses this value when it creates a PayPal order. Existing paid orders keep their original amount.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Price (USD)<div class="money-field"><span>$</span><input name="price" inputmode="decimal" value="<?=e((string)$offer['price'])?>" pattern="\d{1,6}(\.\d{1,2})?" required></div><small>Minimum $0.50. Maximum $999,999.99.</small></label><input type="hidden" name="currency" value="USD"><button class="save-price" type="submit">Save price</button><a class="preview-price" href="https://buy.posprinteremulator.com/" target="_blank" rel="noopener">Preview Buy page ↗</a></form></section>
<section class="security-card"><strong>Server-controlled pricing</strong><p>Customers cannot submit a different amount from their browser. Only this authenticated owner portal can update the checkout price.</p></section></main></div></body></html>
