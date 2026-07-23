<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$token = trim((string)($_GET['session'] ?? ''));
$session = null;
$offer = null;
$error = '';
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
    $error = 'This secure checkout link is invalid. Return to the Customer Portal and start again.';
} else {
    try {
        $session = portal_commerce_service_request(['action' => 'resolve', 'checkoutToken' => $token]);
        $offer = self_service_offer(
            (string)$session['orderType'],
            (string)$session['currentTier'],
            (string)$session['targetTier']
        );
    } catch (Throwable $exception) {
        $error = $exception instanceof DomainException
            ? $exception->getMessage()
            : 'Secure checkout is temporarily unavailable. Return to the Customer Portal and try again.';
    }
}
$ready = is_array($session) && is_array($offer) && (float)$offer['price'] > 0 &&
    !str_starts_with((string)config('paypal.client_id'), 'REPLACE_');
$maintenance = is_array($session) && strtoupper((string)$session['orderType']) === 'MAINTENANCE';
$effective = 'Immediately after PayPal confirms payment';
if ($maintenance) {
    $base = !empty($session['maintenanceExpiresAt']) && strtotime((string)$session['maintenanceExpiresAt']) > time()
        ? new DateTimeImmutable((string)$session['maintenanceExpiresAt'], new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $effective = 'Coverage through ' . $base->modify('+1 year')->format('M j, Y');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Review your POS Printer Emulator order</title>
  <meta name="description" content="Review and pay for a verified POS Printer Emulator license upgrade or maintenance renewal.">
  <meta name="theme-color" content="#07172d">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="stylesheet" href="/assets/site.css?v=4">
  <link rel="stylesheet" href="/assets/self-service.css?v=1">
  <?php if ($ready): ?><script src="https://www.paypal.com/web-sdk/v6/core" async></script><?php endif; ?>
</head>
<body class="self-service" data-client-id="<?= htmlspecialchars((string)config('paypal.client_id')) ?>"
      data-currency="<?= htmlspecialchars((string)($offer['currency'] ?? 'USD')) ?>"
      data-checkout-ready="<?= $ready ? 'true' : 'false' ?>"
      data-checkout-token="<?= htmlspecialchars($token) ?>">
<a class="skip" href="#order-review">Skip to order review</a>
<header>
  <a class="brand" href="https://posprinteremulator.com/"><img src="/assets/logo.png" alt="POS Printer Emulator"></a>
  <a class="back" href="https://userportal.posprinteremulator.com/portal.php?page=plans">← Customer Portal</a>
</header>
<main class="service-shell">
  <section class="service-heading">
    <p class="eyebrow">Verified customer checkout</p>
    <h1>Review the change<br>before you pay.</h1>
    <p class="lede">The customer, license, and price below come from protected server records. PayPal handles the payment; card details never reach POS Printer Emulator.</p>
    <ul class="trust"><li>Permanent license</li><li>No automatic billing</li><li>Server-verified fulfillment</li></ul>
  </section>

  <aside class="service-review" id="order-review">
    <?php if ($error !== ''): ?>
      <div class="service-error" role="alert"><strong>Checkout unavailable</strong><p><?= htmlspecialchars($error) ?></p></div>
      <a class="service-button secondary" href="https://userportal.posprinteremulator.com/portal.php?page=plans">Return to Customer Portal</a>
    <?php else: ?>
      <div class="review-label"><?= $maintenance ? 'Annual maintenance' : 'Permanent license upgrade' ?></div>
      <h2><?= htmlspecialchars((string)$session['currentTier']) ?> <span aria-hidden="true">→</span> <?= htmlspecialchars((string)$session['targetTier']) ?></h2>
      <div class="review-price"><strong><?= ($offer['currency'] ?? '') === 'USD' ? '$' : '' ?><?= htmlspecialchars(number_format((float)$offer['price'], 2)) ?></strong><span><?= htmlspecialchars((string)$offer['currency']) ?> · one-time</span></div>
      <dl class="review-facts">
        <div><dt>Customer</dt><dd><?= htmlspecialchars((string)$session['customerName']) ?></dd></div>
        <div><dt>Email</dt><dd><?= htmlspecialchars((string)$session['email']) ?></dd></div>
        <div><dt>Effective</dt><dd><?= htmlspecialchars($effective) ?></dd></div>
        <div><dt>Maintenance effect</dt><dd><?= $maintenance ? 'Adds one year; your permanent license is unchanged.' : 'Existing coverage transfers to the replacement key.' ?></dd></div>
      </dl>
      <div class="review-terms">
        <strong>Before payment</strong>
        <p>License upgrades are fulfilled immediately after verified capture. Refunds and chargebacks require review and may revoke the replacement entitlement. Optional maintenance is not a subscription.</p>
      </div>
      <div id="form-error" class="error" role="alert" hidden></div>
      <?php if ($ready): ?>
        <paypal-button id="paypal-button" type="pay"></paypal-button>
      <?php else: ?>
        <button class="disabled" type="button" disabled>Pay securely with PayPal</button>
      <?php endif; ?>
      <p class="secure">Your order is locked to this verified account and expires automatically.</p>
    <?php endif; ?>
  </aside>
</main>
<footer><span>© 2026 POS Printer Emulator</span><nav><a href="https://posprinteremulator.com/privacy.html">Privacy</a><a href="mailto:support@posprinteremulator.com">Support</a></nav></footer>
<?php if ($ready): ?><script src="/assets/self-service.js?v=1" type="module"></script><?php endif; ?>
</body>
</html>
