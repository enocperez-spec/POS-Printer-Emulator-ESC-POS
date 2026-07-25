<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

try {
    $product = clean_purchase_product((string)($_GET['product'] ?? 'license'));
} catch (InvalidArgumentException) {
    $product = 'license';
}
$renewal = $product === 'maintenance';
$offers = $renewal ? maintenance_offers() : license_offers();
$availableTiers = array_values(array_filter(
    array_keys($offers),
    static fn(string $tier): bool => (float)$offers[$tier]['price'] > 0
));
$selectedTier = select_purchase_tier($availableTiers, $_GET['tier'] ?? null);
$portalBase = 'https://userportal.posprinteremulator.com';
$catalogPath = __DIR__ . '/assets/license-catalog.json';
$licenseCatalog = is_file($catalogPath)
    ? json_decode((string)file_get_contents($catalogPath), true)
    : null;
$listenerCapacity = static function (string $tier) use ($licenseCatalog): string {
    $count = (int)($licenseCatalog['licenses'][$tier]['listenerLimit'] ?? 0);
    return $count === 1 ? '1 printer listener' : 'Up to ' . $count . ' printer listeners';
};
$features = [
    'Lite' => [
        'capacity' => $listenerCapacity('Lite'),
        'items' => ['Unlimited external POS print jobs', 'Full local receipt history', 'Watermark-free preview', 'Copy Receipt as Image'],
    ],
    'Pro' => [
        'capacity' => $listenerCapacity('Pro'),
        'items' => ['Everything in Lite', 'Capture, import, and replay tools', 'Expanded multi-listener testing', 'Copy Receipt as Image'],
    ],
    'Enterprise' => [
        'capacity' => $listenerCapacity('Enterprise'),
        'items' => ['Everything in Pro', 'Standard Development Diagnostics Report', 'Advanced Diagnostics Package', 'Maximum listener capacity'],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $renewal ? 'Renew POS Printer Emulator Maintenance and Support' : 'Buy POS Printer Emulator — Lite, Pro, or Enterprise License' ?></title>
  <meta name="description" content="<?= $renewal ? 'Sign in to renew Application Maintenance and Support through the secure POS Printer Emulator Customer Portal.' : 'Compare Lite, Pro, and Enterprise POS Printer Emulator Licenses, then sign in to purchase securely through the Customer Portal.' ?>">
  <meta name="theme-color" content="#07172d"><link rel="icon" type="image/png" href="assets/favicon.png"><link rel="stylesheet" href="assets/site.css?v=4">
</head>
<body>
<a class="skip" href="#license-comparison">Skip to license comparison</a>
<header><a class="brand" href="https://posprinteremulator.com/"><img src="assets/logo.png" alt="POS Printer Emulator"></a><a class="back" href="https://posprinteremulator.com/">← Product website</a></header>
<main>
  <section class="hero purchase-portal-hero">
    <div class="hero-copy">
      <nav class="purchase-switch" aria-label="Purchase type"><a class="<?= !$renewal ? 'active' : '' ?>" href="?product=license&amp;tier=<?= htmlspecialchars($selectedTier) ?>">Buy a license</a><a class="<?= $renewal ? 'active' : '' ?>" href="?product=maintenance&amp;tier=<?= htmlspecialchars($selectedTier) ?>">Renew maintenance</a></nav>
      <p class="eyebrow">POS Printer Emulator · Lite, Pro &amp; Enterprise</p>
      <h1><?= $renewal ? 'Renew coverage through your portal.' : 'Choose the license that fits your work.' ?></h1>
      <p class="lede"><?= $renewal ? 'Maintenance and Support renewals now begin in the secure Customer Portal, where your existing license and expiration date are already connected to your account.' : 'Compare every license before continuing. Purchases now happen in the secure Customer Portal so your license, payment, activation status, and Maintenance and Support date stay together.' ?></p>
      <ul class="trust"><li>One-time license purchase</li><li>No automatic billing</li><li>Secure Customer Portal checkout</li></ul>
    </div>
    <div class="app-shot"><img src="assets/product-app.png" alt="POS Printer Emulator receipt preview and command diagnostics"></div>
    <aside class="checkout portal-checkout" id="checkout">
      <span class="full-pill"><?= $renewal ? 'Existing customers' : 'Account-protected purchase' ?></span>
      <h2><?= $renewal ? 'Maintenance and Support renewal' : 'POS Printer Emulator License' ?></h2>
      <p><?= $renewal ? 'Sign in to view eligible licenses, the current coverage date, and the correct renewal option.' : 'Choose a license below. We will preserve your selection while you sign in or create an account.' ?></p>
      <?php if ($renewal): ?>
        <a class="portal-purchase-button" href="<?= $portalBase ?>/index.php?renew=maintenance">Log In to Renew</a>
      <?php else: ?>
        <div class="compact-license-links">
          <?php foreach (paid_license_tiers() as $tier): $offer = $offers[$tier]; ?>
            <a class="<?= $tier === $selectedTier ? 'selected' : '' ?>" href="<?= $portalBase ?>/index.php?purchase=<?= rawurlencode($tier) ?>">
              <span><strong><?= htmlspecialchars($tier) ?></strong><small><?= htmlspecialchars($features[$tier]['capacity']) ?></small></span>
              <b><?= (float)$offer['price'] > 0 ? htmlspecialchars('$' . number_format((float)$offer['price'], 2) . ' ' . $offer['currency']) : 'Pricing coming soon' ?></b>
              <em>Log In to Purchase</em>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="secure">Already own a license? The portal will offer an upgrade or an explicitly labeled additional-license purchase instead of creating an accidental duplicate.</p>
    </aside>
  </section>

  <?php if (!$renewal): ?>
  <section class="license-comparison" id="license-comparison">
    <div class="comparison-heading"><p class="eyebrow">Compare licenses</p><h2>Clear differences before you continue.</h2><p>Every paid license removes Trial limits, stores receipt history locally, removes the watermark, and includes one year of Maintenance and Support.</p></div>
    <div class="comparison-grid">
      <?php foreach ($features as $tier => $detail): $offer = $offers[$tier]; ?>
        <article class="comparison-card <?= $tier === 'Pro' ? 'recommended' : '' ?>">
          <?php if ($tier === 'Pro'): ?><span class="recommendation">Popular choice</span><?php endif; ?>
          <h3><?= htmlspecialchars($tier) ?></h3><p class="capacity"><?= htmlspecialchars($detail['capacity']) ?></p>
          <div class="comparison-price"><?= (float)$offer['price'] > 0 ? htmlspecialchars('$' . number_format((float)$offer['price'], 2)) : 'Contact us' ?><small><?= htmlspecialchars($offer['currency']) ?> · one-time</small></div>
          <ul><?php foreach ($detail['items'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?><li>One year of Maintenance and Support</li></ul>
          <a class="portal-purchase-button <?= $tier === 'Pro' ? '' : 'secondary' ?>" href="<?= $portalBase ?>/index.php?purchase=<?= rawurlencode($tier) ?>">Log In to Purchase</a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="features"><div><p class="eyebrow">Optional annual coverage</p><h2>Keep current without renting your software.</h2></div><div class="feature-list"><article><span>↺</span><div><h3>Updates and upgrades</h3><p>Install releases published during the renewed coverage period.</p></div></article><article><span>?</span><div><h3>Technical support</h3><p>Restore access to customer support for another year.</p></div></article><article><span>✓</span><div><h3>Your license keeps working</h3><p>The application and every purchased feature keep working after Maintenance and Support ends.</p></div></article></div></section>
  <?php endif; ?>

  <section class="activation"><p class="eyebrow">How portal purchasing works</p><h2>Choose. Sign in. Purchase securely.</h2><ol><li><b>1</b><div><strong>Choose a license</strong><span>Your Lite, Pro, or Enterprise selection is carried into the portal.</span></div></li><li><b>2</b><div><strong>Sign in or create an account</strong><span>Your verified account becomes the owner of the resulting license and purchase record.</span></div></li><li><b>3</b><div><strong>Complete secure checkout</strong><span>The portal shows upgrades for owned licenses or clearly labeled additional-license options.</span></div></li></ol></section>
  <section class="faq"><h2>Purchase questions</h2><details><summary>Why do I need a Customer Portal account?</summary><p>The portal securely associates the purchase with you and keeps your licenses, payment history, activation status, downloads, and Maintenance and Support dates in one place.</p></details><details><summary>What if I already own a license?</summary><p>The portal reviews your current license. Higher levels are offered as upgrades; same or lower levels are clearly identified as additional-license purchases.</p></details><details><summary>Is this a subscription?</summary><p>No. A POS Printer Emulator License is a one-time purchase. Optional Maintenance and Support renewals do not use automatic billing.</p></details></section>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="https://posprinteremulator.com/how-to-submit-a-support-request">Support</a></nav></footer>
</body></html>
