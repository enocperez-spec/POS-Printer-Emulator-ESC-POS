<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$offer = license_offer();
$price = $offer['price'];
$currency = $offer['currency'];
$priceConfigured = (float) $price > 0;
$checkoutReady = $priceConfigured && !str_starts_with((string) config('paypal.client_id'), 'REPLACE_');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Buy POS Printer Emulator — Full Version</title>
  <meta name="description" content="Upgrade POS Printer Emulator to unlimited print jobs, full history, clean receipt previews, and every premium feature.">
  <meta name="theme-color" content="#07172d"><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/site.css?v=1">
  <?php if ($checkoutReady): ?><script src="https://www.paypal.com/web-sdk/v6/core" async></script><?php endif; ?>
</head>
<body data-client-id="<?= htmlspecialchars((string) config('paypal.client_id')) ?>" data-currency="<?= htmlspecialchars($currency) ?>" data-checkout-ready="<?= $checkoutReady ? 'true' : 'false' ?>">
<a class="skip" href="#checkout">Skip to checkout</a>
<header><a class="brand" href="https://posprinteremulator.com/"><img src="assets/logo.png" alt="POS Printer Emulator"></a><a class="back" href="https://posprinteremulator.com/">← Product website</a></header>
<main>
  <section class="hero">
    <div class="hero-copy"><p class="eyebrow">POS Printer Emulator · Full Version</p><h1>Unlock every receipt.<br>Keep every detail.</h1><p class="lede">Upgrade the app you already installed. Get unlimited receipt emulation, complete local history, watermark-free previews, and every premium feature.</p><ul class="trust"><li>One-time license</li><li>No reinstall required</li><li>Secure PayPal checkout</li></ul></div>
    <div class="app-shot"><img src="assets/product-app.png" alt="POS Printer Emulator receipt preview and command diagnostics"></div>
    <aside class="checkout" id="checkout">
      <span class="full-pill">Full Version</span><h2>Permanent desktop license</h2>
      <?php if ($priceConfigured): ?><div class="price"><strong><?= htmlspecialchars($currency === 'USD' ? '$' . number_format((float)$price, 2) : $price) ?></strong><span><?= htmlspecialchars($currency) ?> · one-time</span></div><?php else: ?><div class="notice">Checkout is being configured. Please check back shortly.</div><?php endif; ?>
      <form id="purchase-form" novalidate>
        <label>Customer or company name<input id="customer-name" name="customerName" maxlength="160" autocomplete="organization" required></label>
        <label>Email address<input id="email" name="email" type="email" maxlength="254" autocomplete="email" required><small>Your activation key will be sent here after payment is confirmed.</small></label>
        <div id="form-error" class="error" role="alert" hidden></div>
        <?php if ($checkoutReady): ?><paypal-button id="paypal-button" type="pay"></paypal-button><?php else: ?><button class="disabled" type="button" disabled>Pay securely with PayPal</button><?php endif; ?>
      </form>
      <p class="secure">Payment is processed by PayPal. Card details never reach this website.</p>
    </aside>
  </section>
  <section class="features"><div><p class="eyebrow">Full Version</p><h2>Everything you need to test without limits.</h2></div><div class="feature-list"><article><span>∞</span><div><h3>Unlimited emulated print jobs</h3><p>Test as often as the job requires—no daily cap.</p></div></article><article><span>↺</span><div><h3>Full print-job history</h3><p>Return to prior receipts and diagnostics stored locally.</p></div></article><article><span>✓</span><div><h3>Clean receipt previews</h3><p>Remove the TRIAL watermark from the receipt viewer.</p></div></article><article><span>+</span><div><h3>All premium features</h3><p>Unlock exports and every current Full Version capability.</p></div></article></div></section>
  <section class="activation"><p class="eyebrow">How activation works</p><h2>Pay once. Activate the app you already have.</h2><ol><li><b>1</b><div><strong>Complete checkout</strong><span>Enter the same registration name and email you use in the app.</span></div></li><li><b>2</b><div><strong>Payment is verified</strong><span>We confirm the captured PayPal payment before issuing a key.</span></div></li><li><b>3</b><div><strong>Receive your key by email</strong><span>Open Settings → License and activate immediately—no new download.</span></div></li></ol></section>
  <section class="faq"><h2>Purchase questions</h2><details><summary>Is this a subscription?</summary><p>No. This is a one-time license for the Full Version.</p></details><details><summary>Will I need to reinstall?</summary><p>No. Enter the emailed key in Settings → License in your existing installation.</p></details><details><summary>When is my key sent?</summary><p>After PayPal confirms payment and the order is approved. It is sent to the purchase email address.</p></details><details><summary>What information must match?</summary><p>The customer/company name and email used for purchase must match the registration information entered in the app.</p></details></section>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="mailto:support@posprinteremulator.com">Support</a></nav></footer>
<script src="assets/checkout.js?v=3" type="module"></script>
</body></html>
