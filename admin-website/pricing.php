<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/purchase_site.php';
require_authentication();
$savedTier = ''; $error = ''; $offers = [
    'Pro' => ['tier'=>'Pro','price'=>'0.00','currency'=>'USD'],
    'Enterprise' => ['tier'=>'Enterprise','price'=>'0.00','currency'=>'USD'],
];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $tier = (string)($_POST['tier'] ?? '');
        $response = purchase_site_request('/api/admin-price.php', 'POST', ['tier'=>$tier,'price'=>(string)($_POST['price']??''),'currency'=>(string)($_POST['currency']??'USD')]);
        $savedTier = $tier;
    } else {
        $response = purchase_site_request('/api/admin-price.php');
    }
    $offers = is_array($response['offers'] ?? null) ? array_replace($offers, $response['offers']) : $offers;
} catch (Throwable $exception) {
    error_log('Purchase pricing management failure: ' . $exception->getMessage());
    $error = $exception->getMessage();
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Pricing | POS Printer Emulator Admin Portal</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/pricing.css?v=20260715-2"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a class="active" href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>Changes apply to new PayPal orders immediately.</p></aside>
<main class="pricing-main"><div class="page-heading"><div><h1>Purchase Pricing</h1><p>Manage the separate one-time Pro and Enterprise license prices displayed at buy.posprinteremulator.com.</p></div></div>
<?php if($savedTier!=='' && isset($offers[$savedTier])):?><div class="price-success" role="status"><?=e($savedTier)?> price updated. The Buy page now shows <strong>$<?=e((string)$offers[$savedTier]['price'])?> <?=e((string)$offers[$savedTier]['currency'])?></strong>.</div><?php endif;?>
<?php if($error!==''):?><div class="price-error" role="alert"><?=e($error)?></div><?php endif;?>
<div class="pricing-grid">
<?php foreach(['Pro','Enterprise'] as $tier): $offer=$offers[$tier];?>
<section class="price-panel <?=strtolower($tier)?>"><div><span class="eyebrow"><?=e($tier)?> License</span><h2><?=e($tier)?> one-time price</h2><p>The server uses this amount when a customer selects the <?=e($tier)?> License. Existing paid orders keep their original amount.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="tier" value="<?=e($tier)?>"><label>Price (USD)<div class="money-field"><span>$</span><input name="price" inputmode="decimal" value="<?=e((string)$offer['price'])?>" pattern="\d{1,6}(\.\d{1,2})?" required></div><small>Minimum $0.50. Maximum $999,999.99.</small></label><input type="hidden" name="currency" value="USD"><button class="save-price" type="submit">Save <?=e($tier)?> price</button></form></section>
<?php endforeach;?>
</div>
<a class="preview-price preview-wide" href="https://buy.posprinteremulator.com/" target="_blank" rel="noopener">Preview Purchase Website ↗</a>
<section class="security-card"><strong>Server-controlled pricing</strong><p>Customers cannot submit a different amount from their browser. Only this authenticated Admin Portal can update Pro and Enterprise checkout prices.</p></section></main></div></body></html>
