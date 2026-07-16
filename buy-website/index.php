<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$offers = license_offers();
$availableTiers = array_values(array_filter(array_keys($offers), static fn(string $tier): bool => (float)$offers[$tier]['price'] > 0));
$selectedTier = in_array('Pro', $availableTiers, true) ? 'Pro' : ($availableTiers[0] ?? 'Pro');
$selectedOffer = $offers[$selectedTier];
$currency = $selectedOffer['currency'];
$checkoutReady = count($availableTiers) > 0 && !str_starts_with((string) config('paypal.client_id'), 'REPLACE_');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Buy POS Printer Emulator — Pro or Enterprise License</title>
  <meta name="description" content="Choose a Pro or Enterprise License for unlimited print jobs, full history, clean receipt previews, and premium features.">
  <meta name="theme-color" content="#07172d"><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/site.css?v=1">
  <?php if ($checkoutReady): ?><script src="https://www.paypal.com/web-sdk/v6/core" async></script><?php endif; ?>
</head>
<body data-client-id="<?= htmlspecialchars((string) config('paypal.client_id')) ?>" data-currency="<?= htmlspecialchars($currency) ?>" data-checkout-ready="<?= $checkoutReady ? 'true' : 'false' ?>">
<a class="skip" href="#checkout">Skip to checkout</a>
<header><a class="brand" href="https://posprinteremulator.com/"><img src="assets/logo.png" alt="POS Printer Emulator"></a><a class="back" href="https://posprinteremulator.com/">← Product website</a></header>
<main>
  <section class="hero">
    <div class="hero-copy"><p class="eyebrow">POS Printer Emulator · Pro &amp; Enterprise</p><h1>Unlock every receipt.<br>Keep every detail.</h1><p class="lede">Choose the license level that fits your organization. Both tiers upgrade the app you already installed with unlimited receipt emulation, complete local history, and watermark-free previews.</p><ul class="trust"><li>One-time license</li><li>No reinstall required</li><li>Secure PayPal checkout</li></ul></div>
    <div class="app-shot"><img src="assets/product-app.png" alt="POS Printer Emulator receipt preview and command diagnostics"></div>
    <aside class="checkout" id="checkout">
      <span class="full-pill" id="selected-tier-pill"><?= htmlspecialchars($selectedTier) ?> License</span><h2>Permanent desktop license</h2>
      <fieldset class="license-options"><legend>Choose your license</legend><?php foreach(['Pro','Enterprise'] as $tier): $offer=$offers[$tier]; $configured=(float)$offer['price']>0; ?><label class="license-option <?= $configured ? '' : 'unavailable' ?>"><input type="radio" form="purchase-form" name="licenseTier" value="<?= htmlspecialchars($tier) ?>" data-price="<?= htmlspecialchars($offer['price']) ?>" data-currency="<?= htmlspecialchars($offer['currency']) ?>" <?= $tier===$selectedTier?'checked':'' ?> <?= $configured?'':'disabled' ?>><span><strong><?= htmlspecialchars($tier) ?></strong><small><?= $configured ? htmlspecialchars('$'.number_format((float)$offer['price'],2).' '.$offer['currency']) : 'Pricing coming soon' ?></small></span></label><?php endforeach; ?></fieldset>
      <?php if ($availableTiers): ?><div class="price"><strong id="selected-price"><?= htmlspecialchars($currency === 'USD' ? '$' . number_format((float)$selectedOffer['price'], 2) : $selectedOffer['price']) ?></strong><span id="selected-currency"><?= htmlspecialchars($currency) ?> · one-time</span></div><?php else: ?><div class="notice">Checkout is being configured. Please check back shortly.</div><?php endif; ?>
      <form id="purchase-form" novalidate>
        <label>Customer or company name<input id="customer-name" name="customerName" maxlength="160" autocomplete="organization" required></label>
        <label>Email address<input id="email" name="email" type="email" maxlength="254" autocomplete="email" required><small>Your activation key will be sent here after payment is confirmed.</small></label>
        <div id="form-error" class="error" role="alert" hidden></div>
        <?php if ($checkoutReady): ?><paypal-button id="paypal-button" type="pay"></paypal-button><?php else: ?><button class="disabled" type="button" disabled>Pay securely with PayPal</button><?php endif; ?>
      </form>
      <p class="secure">Payment is processed by PayPal. Card details never reach this website.</p>
    </aside>
  </section>
  <section class="features"><div><p class="eyebrow">Pro &amp; Enterprise</p><h2>Everything you need to test without limits.</h2></div><div class="feature-list"><article><span>∞</span><div><h3>Unlimited emulated print jobs</h3><p>Test as often as the job requires—no daily cap.</p></div></article><article><span>↺</span><div><h3>Full print-job history</h3><p>Return to prior receipts and diagnostics stored locally.</p></div></article><article><span>✓</span><div><h3>Clean receipt previews</h3><p>Remove the TRIAL watermark from the receipt viewer.</p></div></article><article><span>+</span><div><h3>Tier-ready activation</h3><p>Receive the Pro or Enterprise activation key selected during checkout.</p></div></article></div></section>
  <section class="activation"><p class="eyebrow">How activation works</p><h2>Pay once. Activate the app you already have.</h2><ol><li><b>1</b><div><strong>Complete checkout</strong><span>Enter the same registration name and email you use in the app.</span></div></li><li><b>2</b><div><strong>Payment is verified</strong><span>We confirm the captured PayPal payment before issuing a key.</span></div></li><li><b>3</b><div><strong>Receive your key by email</strong><span>Open Settings → License and activate immediately—no new download.</span></div></li></ol></section>
  <section class="faq"><h2>Purchase questions</h2><details><summary>Is this a subscription?</summary><p>No. Pro and Enterprise are one-time licenses.</p></details><details><summary>Will I need to reinstall?</summary><p>No. Enter the emailed key in Settings → License in your existing installation.</p></details><details><summary>When is my key sent?</summary><p>After PayPal confirms payment and the order is approved. It is sent to the purchase email address.</p></details><details><summary>What information must match?</summary><p>The customer/company name and email used for purchase must match the registration information entered in the app.</p></details></section>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="mailto:support@posprinteremulator.com">Support</a></nav></footer>
<script src="assets/checkout.js?v=3" type="module"></script>
</body></html>
