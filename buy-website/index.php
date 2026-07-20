<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$offers = license_offers();
$availableTiers = array_values(array_filter(array_keys($offers), static fn(string $tier): bool => (float)$offers[$tier]['price'] > 0));
$selectedTier = select_purchase_tier($availableTiers, $_GET['tier'] ?? null);
$selectedOffer = $offers[$selectedTier];
$currency = $selectedOffer['currency'];
$checkoutReady = count($availableTiers) > 0 && !str_starts_with((string) config('paypal.client_id'), 'REPLACE_');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Buy POS Printer Emulator — Lite, Pro, or Enterprise License</title>
  <meta name="description" content="Choose a one-time Lite, Pro, or Enterprise License with paid receipt tools and the listener capacity your testing workflow needs.">
  <meta name="theme-color" content="#07172d"><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/site.css?v=2">
  <?php if ($checkoutReady): ?><script src="https://www.paypal.com/web-sdk/v6/core" async></script><?php endif; ?>
</head>
<body data-client-id="<?= htmlspecialchars((string) config('paypal.client_id')) ?>" data-currency="<?= htmlspecialchars($currency) ?>" data-checkout-ready="<?= $checkoutReady ? 'true' : 'false' ?>">
<a class="skip" href="#checkout">Skip to checkout</a>
<header><a class="brand" href="https://posprinteremulator.com/"><img src="assets/logo.png" alt="POS Printer Emulator"></a><a class="back" href="https://posprinteremulator.com/">← Product website</a></header>
<main>
  <section class="hero">
    <div class="hero-copy"><p class="eyebrow">POS Printer Emulator · Lite, Pro &amp; Enterprise</p><h1>Choose the license<br>that fits your work.</h1><p class="lede">Upgrade the app you already installed with the Lite, Pro, or Enterprise license level that fits your receipt-testing workflow.</p><ul class="trust"><li>One-time license</li><li>No reinstall required</li><li>Secure PayPal checkout</li></ul></div>
    <div class="app-shot"><img src="assets/product-app.png" alt="POS Printer Emulator receipt preview and command diagnostics"></div>
    <aside class="checkout" id="checkout">
      <span class="full-pill" id="selected-tier-pill"><?= htmlspecialchars($selectedTier) ?> License</span><h2>Permanent desktop license</h2>
      <fieldset class="license-options"><legend>Choose your license</legend><?php foreach(paid_license_tiers() as $tier): $offer=$offers[$tier]; $configured=(float)$offer['price']>0; ?><label class="license-option <?= $configured ? '' : 'unavailable' ?>"><input type="radio" form="purchase-form" name="licenseTier" value="<?= htmlspecialchars($tier) ?>" data-price="<?= htmlspecialchars($offer['price']) ?>" data-currency="<?= htmlspecialchars($offer['currency']) ?>" <?= $tier===$selectedTier?'checked':'' ?> <?= $configured?'':'disabled' ?>><span><strong><?= htmlspecialchars($tier) ?></strong><small><?= $configured ? htmlspecialchars('$'.number_format((float)$offer['price'],2).' '.$offer['currency']) : 'Pricing coming soon' ?></small></span></label><?php endforeach; ?></fieldset>
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
  <section class="features"><div><p class="eyebrow">Three paid levels</p><h2>All paid tools. The listener capacity you need.</h2></div><div class="feature-list"><article><span>∞</span><div><h3>Unlimited emulated print jobs</h3><p>Lite, Pro, and Enterprise remove the Trial daily limit.</p></div></article><article><span>↺</span><div><h3>Persistent local history</h3><p>Keep up to 500 jobs locally with import, export, replay, and profiles.</p></div></article><article><span>✓</span><div><h3>Clean previews and paid tools</h3><p>Remove the watermark and unlock Print/PDF, stored logos, printer state, updates, and support.</p></div></article><article><span>+</span><div><h3>Scale printer listeners</h3><p>Lite supports 1 listener, Pro supports up to 2, and Enterprise supports up to 15.</p></div></article></div></section>
  <section class="activation"><p class="eyebrow">How activation works</p><h2>Pay once. Activate the app you already have.</h2><ol><li><b>1</b><div><strong>Complete checkout</strong><span>Enter the same registration name and email you use in the app.</span></div></li><li><b>2</b><div><strong>Payment is verified</strong><span>We confirm the captured PayPal payment before issuing a key.</span></div></li><li><b>3</b><div><strong>Receive your key by email</strong><span>Open Settings → License and activate immediately—no new download.</span></div></li></ol></section>
  <section class="faq"><h2>Purchase questions</h2><details><summary>Is this a subscription?</summary><p>No. Lite, Pro, and Enterprise are one-time licenses.</p></details><details><summary>Will I need to reinstall?</summary><p>No. Enter the emailed key in Settings → License in your existing installation.</p></details><details><summary>When is my key sent?</summary><p>After PayPal confirms payment and the order is approved. It is sent to the purchase email address.</p></details><details><summary>What information must match?</summary><p>The customer/company name and email used for purchase must match the registration information entered in the app.</p></details></section>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="mailto:support@posprinteremulator.com">Support</a></nav></footer>
<script src="assets/checkout.js?v=3" type="module"></script>
</body></html>
