<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
try { $product = clean_purchase_product((string)($_GET['product'] ?? 'license')); } catch (InvalidArgumentException) { $product = 'license'; }
$renewal = $product === 'maintenance';
$offers = $renewal ? maintenance_offers() : license_offers();
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
  <title><?= $renewal ? 'Renew POS Printer Emulator Maintenance' : 'Buy POS Printer Emulator — Lite, Pro, or Enterprise License' ?></title>
  <meta name="description" content="<?= $renewal ? 'Renew optional annual Application Maintenance and Support with a one-time payment. Your permanent license is never a subscription.' : 'Choose a one-time Lite, Pro, or Enterprise License with paid receipt tools and the listener capacity your testing workflow needs.' ?>">
  <meta name="theme-color" content="#07172d"><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/site.css?v=2">
  <?php if ($checkoutReady): ?><script src="https://www.paypal.com/web-sdk/v6/core" async></script><?php endif; ?>
</head>
<body data-client-id="<?= htmlspecialchars((string) config('paypal.client_id')) ?>" data-currency="<?= htmlspecialchars($currency) ?>" data-product="<?= htmlspecialchars($product) ?>" data-checkout-ready="<?= $checkoutReady ? 'true' : 'false' ?>">
<a class="skip" href="#checkout">Skip to checkout</a>
<header><a class="brand" href="https://posprinteremulator.com/"><img src="assets/logo.png" alt="POS Printer Emulator"></a><a class="back" href="https://posprinteremulator.com/">← Product website</a></header>
<main>
  <section class="hero">
    <div class="hero-copy"><nav class="purchase-switch" aria-label="Purchase type"><a class="<?= !$renewal?'active':'' ?>" href="?product=license&amp;tier=<?= htmlspecialchars($selectedTier) ?>">Buy a license</a><a class="<?= $renewal?'active':'' ?>" href="?product=maintenance&amp;tier=<?= htmlspecialchars($selectedTier) ?>">Renew maintenance</a></nav><p class="eyebrow">POS Printer Emulator · Lite, Pro &amp; Enterprise</p><h1><?= $renewal ? 'Keep updates and<br>support available.' : 'Choose the license<br>that fits your work.' ?></h1><p class="lede"><?= $renewal ? 'Renew optional annual Application Maintenance and Support with a one-time payment. Your permanent application license and purchased features never expire.' : 'Upgrade the app you already installed with the Lite, Pro, or Enterprise license level that fits your receipt-testing workflow.' ?></p><ul class="trust"><li><?= $renewal?'One-time annual renewal':'One-time license' ?></li><li>No automatic billing</li><li>Secure PayPal checkout</li></ul></div>
    <div class="app-shot"><img src="assets/product-app.png" alt="POS Printer Emulator receipt preview and command diagnostics"></div>
    <aside class="checkout" id="checkout">
      <span class="full-pill" id="selected-tier-pill"><?= htmlspecialchars($selectedTier) ?> <?= $renewal?'Maintenance':'License' ?></span><h2><?= $renewal?'Annual maintenance renewal':'Permanent desktop license' ?></h2>
      <fieldset class="license-options"><legend><?= $renewal?'License level':'Choose your license' ?></legend><?php foreach(paid_license_tiers() as $tier): $offer=$offers[$tier]; $configured=(float)$offer['price']>0; ?><label class="license-option <?= $configured ? '' : 'unavailable' ?>"><input type="radio" form="purchase-form" name="licenseTier" value="<?= htmlspecialchars($tier) ?>" data-price="<?= htmlspecialchars($offer['price']) ?>" data-currency="<?= htmlspecialchars($offer['currency']) ?>" <?= $tier===$selectedTier?'checked':'' ?> <?= $configured?'':'disabled' ?>><span><strong><?= htmlspecialchars($tier) ?></strong><small><?= $configured ? htmlspecialchars('$'.number_format((float)$offer['price'],2).' '.$offer['currency']) : 'Pricing coming soon' ?></small></span></label><?php endforeach; ?></fieldset>
      <?php if ($availableTiers): ?><div class="price"><strong id="selected-price"><?= htmlspecialchars($currency === 'USD' ? '$' . number_format((float)$selectedOffer['price'], 2) : $selectedOffer['price']) ?></strong><span id="selected-currency"><?= htmlspecialchars($currency) ?> · <?= $renewal?'one-time renewal':'one-time' ?></span></div><?php else: ?><div class="notice">Checkout is being configured. Please check back shortly.</div><?php endif; ?>
      <form id="purchase-form" novalidate>
        <?php if($renewal): ?><label>License ID<input id="license-id" name="licenseId" maxlength="36" autocomplete="off" pattern="[0-9a-fA-F-]{36}" required><small>Find this ID in POS Printer Emulator under Settings → License.</small></label><?php endif; ?>
        <label>Customer or company name<input id="customer-name" name="customerName" maxlength="160" autocomplete="organization" required></label>
        <label>Email address<input id="email" name="email" type="email" maxlength="254" autocomplete="email" required><small><?= $renewal?'Must match the registered permanent license. Renewal confirmation will be sent here.':'Your activation key will be sent here after payment is confirmed.' ?></small></label>
        <div id="form-error" class="error" role="alert" hidden></div>
        <?php if ($checkoutReady): ?><paypal-button id="paypal-button" type="pay"></paypal-button><?php else: ?><button class="disabled" type="button" disabled>Pay securely with PayPal</button><?php endif; ?>
      </form>
      <p class="secure">Payment is processed by PayPal. Card details never reach this website.</p>
    </aside>
  </section>
  <?php if($renewal): ?><section class="features"><div><p class="eyebrow">Optional annual coverage</p><h2>Keep current without renting your software.</h2></div><div class="feature-list"><article><span>↺</span><div><h3>Updates and upgrades</h3><p>Install releases published during the renewed coverage period.</p></div></article><article><span>?</span><div><h3>Technical support</h3><p>Restore access to customer support for another year.</p></div></article><article><span>✓</span><div><h3>Your license stays permanent</h3><p>The application and every purchased feature keep working after maintenance ends.</p></div></article><article><span>1</span><div><h3>One-time payment</h3><p>No recurring agreement, automatic renewal, or subscription billing.</p></div></article></div></section><?php else: ?><section class="features"><div><p class="eyebrow">Three paid levels</p><h2>All paid tools. The listener capacity you need.</h2></div><div class="feature-list"><article><span>∞</span><div><h3>Unlimited emulated print jobs</h3><p>Lite, Pro, and Enterprise remove the Trial daily limit.</p></div></article><article><span>↺</span><div><h3>Persistent local history</h3><p>Keep up to 500 jobs locally with import, export, replay, and profiles.</p></div></article><article><span>✓</span><div><h3>Clean previews and paid tools</h3><p>Remove the watermark and unlock Print/PDF, stored logos, printer state, plus one year of updates and support.</p></div></article><article><span>+</span><div><h3>Scale printer listeners</h3><p>Lite supports 1 listener, Pro supports up to 2, and Enterprise supports up to 15.</p></div></article></div></section><?php endif; ?>
  <section class="activation"><p class="eyebrow"><?= $renewal?'How renewal works':'How activation works' ?></p><h2><?= $renewal?'Pay once. Refresh the license you own.':'Pay once. Activate the app you already have.' ?></h2><ol><li><b>1</b><div><strong>Complete checkout</strong><span>Enter the License ID, name, and email that match the application.</span></div></li><li><b>2</b><div><strong>Payment is verified</strong><span>We verify the captured PayPal payment and license level.</span></div></li><li><b>3</b><div><strong><?= $renewal?'Refresh maintenance':'Receive your key by email' ?></strong><span><?= $renewal?'Open Settings → License to refresh coverage; confirmation is also emailed.':'Open Settings → License and activate immediately—no new download.' ?></span></div></li></ol></section>
  <section class="faq"><h2>Purchase questions</h2><details><summary>Is this a subscription?</summary><p>No. Licenses are permanent and maintenance renewals are optional one-time annual purchases with no automatic billing.</p></details><details><summary>What happens when maintenance expires?</summary><p>Your purchased application and existing features keep working. Technical support and access to new updates pause until you renew.</p></details><details><summary>How is an early renewal calculated?</summary><p>An early renewal adds one year to the current expiration date. If coverage has lapsed, the new year starts on the verified payment date.</p></details><details><summary>What information must match?</summary><p>The License ID, customer/company name, email, and license level must match the registered permanent license.</p></details></section>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="mailto:support@posprinteremulator.com">Support</a></nav></footer>
<script src="assets/checkout.js?v=3" type="module"></script>
</body></html>
