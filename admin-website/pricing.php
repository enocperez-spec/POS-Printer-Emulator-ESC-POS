<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/purchase_site.php';
require_authentication();
$savedTier = ''; $savedProduct=''; $error = ''; $offers = [
    'Lite' => ['tier'=>'Lite','price'=>'24.99','currency'=>'USD'],
    'Pro' => ['tier'=>'Pro','price'=>'0.00','currency'=>'USD'],
    'Enterprise' => ['tier'=>'Enterprise','price'=>'0.00','currency'=>'USD'],
];
$maintenanceOffers=[
    'Lite'=>['tier'=>'Lite','price'=>'9.99','currency'=>'USD'],
    'Pro'=>['tier'=>'Pro','price'=>'19.99','currency'=>'USD'],
    'Enterprise'=>['tier'=>'Enterprise','price'=>'59.99','currency'=>'USD'],
];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $tier = (string)($_POST['tier'] ?? '');
        $savedProduct=(string)($_POST['product']??'license');
        $response = purchase_site_request('/api/admin-price.php', 'POST', ['product'=>$savedProduct,'tier'=>$tier,'price'=>(string)($_POST['price']??''),'currency'=>(string)($_POST['currency']??'USD')]);
        $savedTier = $tier;
    } else {
        $response = purchase_site_request('/api/admin-price.php');
    }
    $offers = is_array($response['offers'] ?? null) ? array_replace($offers, $response['offers']) : $offers;
    $maintenanceOffers = is_array($response['maintenanceOffers'] ?? null) ? array_replace($maintenanceOffers,$response['maintenanceOffers']) : $maintenanceOffers;
} catch (Throwable $exception) {
    error_log('Purchase pricing management failure: ' . $exception->getMessage());
    $error = $exception->getMessage();
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Pricing | POS Printer Emulator Admin Portal</title><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/admin.css?v=20260714-2"><link rel="stylesheet" href="assets/pricing.css?v=20260719-lite"><link rel="stylesheet" href="assets/mobile-nav.css?v=20260715-1"></head>
<body><div class="app-shell"><header class="topbar"><a class="brand" href="/"><img src="assets/icon-web.png" alt=""><span>POS Printer Emulator <small>Admin Portal</small></span></a><form method="post" action="/logout.php" class="logout-form"><span>Admin Account</span><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button>Log out</button></form></header>
<aside class="sidebar"><nav><a href="/"><span aria-hidden="true">▥</span>Dashboard</a><a href="/customers.php"><span aria-hidden="true">◎</span>Customers</a><a href="/#installations"><span aria-hidden="true">□</span>Installations</a><a href="/licenses.php"><span aria-hidden="true">◇</span>License Manager</a><a href="/orders.php"><span aria-hidden="true">▤</span>Purchase Orders</a><a class="active" href="/pricing.php"><span aria-hidden="true">$</span>Purchase Pricing</a><a href="/communications.php"><span aria-hidden="true">✉</span>Communications</a><a href="/dev-support.php"><span aria-hidden="true">⌁</span>Dev Support</a></nav><p>Changes apply to new PayPal orders immediately.</p></aside>
<main class="pricing-main"><div class="page-heading"><div><h1>Purchase Pricing</h1><p>Manage permanent-license prices and optional one-time annual Application Maintenance and Support renewal prices.</p></div></div>
<?php $savedOffers=$savedProduct==='maintenance'?$maintenanceOffers:$offers; if($savedTier!=='' && isset($savedOffers[$savedTier])):?><div class="price-success" role="status"><?=e($savedTier)?> <?= $savedProduct==='maintenance'?'maintenance renewal':'license' ?> price updated. The Buy page now shows <strong>$<?=e((string)$savedOffers[$savedTier]['price'])?> <?=e((string)$savedOffers[$savedTier]['currency'])?></strong>.</div><?php endif;?>
<?php if($error!==''):?><div class="price-error" role="alert"><?=e($error)?></div><?php endif;?>
<h2 class="pricing-section-title">Permanent license prices</h2><p class="pricing-section-copy">One-time purchases. Each new paid license includes its first year of maintenance and support.</p>
<div class="pricing-grid">
<?php foreach(['Lite','Pro','Enterprise'] as $tier): $offer=$offers[$tier];?>
<section class="price-panel <?=strtolower($tier)?>"><div><span class="eyebrow"><?=e($tier)?> License</span><h2><?=e($tier)?> one-time price</h2><p>The server uses this amount when a customer selects the <?=e($tier)?> License. Existing paid orders keep their original amount.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="product" value="license"><input type="hidden" name="tier" value="<?=e($tier)?>"><label>Price (USD)<div class="money-field"><span>$</span><input name="price" inputmode="decimal" value="<?=e((string)$offer['price'])?>" pattern="\d{1,6}(\.\d{1,2})?" required></div><small>Minimum $0.50. Maximum $999,999.99.</small></label><input type="hidden" name="currency" value="USD"><button class="save-price" type="submit">Save <?=e($tier)?> price</button></form></section>
<?php endforeach;?>
</div>
<h2 class="pricing-section-title maintenance-title">Annual maintenance renewal prices</h2><p class="pricing-section-copy">Optional one-time renewals with no recurring agreement or automatic billing.</p>
<div class="pricing-grid maintenance-grid"><?php foreach(['Lite','Pro','Enterprise'] as $tier):$offer=$maintenanceOffers[$tier];?><section class="price-panel <?=strtolower($tier)?>"><div><span class="eyebrow"><?=e($tier)?> Maintenance</span><h2><?=e($tier)?> annual renewal</h2><p>Restores updates and technical support for one year. The permanent license does not expire.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="product" value="maintenance"><input type="hidden" name="tier" value="<?=e($tier)?>"><label>Renewal price (USD)<div class="money-field"><span>$</span><input name="price" inputmode="decimal" value="<?=e((string)$offer['price'])?>" pattern="\d{1,6}(\.\d{1,2})?" required></div><small>One-time annual payment; never billed automatically.</small></label><input type="hidden" name="currency" value="USD"><button class="save-price" type="submit">Save <?=e($tier)?> renewal</button></form></section><?php endforeach;?></div>
<a class="preview-price preview-wide" href="https://buy.posprinteremulator.com/" target="_blank" rel="noopener">Preview Purchase Website ↗</a>
<section class="security-card"><strong>Server-controlled pricing</strong><p>Customers cannot submit a different amount from their browser. Only this authenticated Admin Portal can update permanent-license and maintenance-renewal checkout prices.</p></section></main></div></body></html>
