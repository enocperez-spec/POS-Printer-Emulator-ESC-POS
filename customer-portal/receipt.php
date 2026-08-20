<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/portal-data.php';

$account = portal_require_account();
$customerId = (string)$account['customer_id'];
$reference = trim((string)($_GET['reference'] ?? ''));
$purchase = portal_purchase_record($customerId, $reference);
if (!is_array($purchase)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The requested receipt is unavailable.';
    exit;
}

$displayReference = portal_purchase_display_reference($purchase);
$invoiceNumber = portal_purchase_invoice_number($purchase);
$paymentApprovalReference = portal_purchase_payment_approval_reference($purchase);
$download = (string)($_GET['download'] ?? '') === '1';
$safeFileReference = preg_replace('/[^A-Za-z0-9_-]+/', '-', $displayReference) ?: 'receipt';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
if ($download) {
    header('Content-Disposition: attachment; filename="POS-Printer-Emulator-Receipt-' . $safeFileReference . '.html"');
}

$status = portal_purchase_status_label((string)$purchase['purchase_status']);
$purchaseType = portal_purchase_type_label($purchase);
$paidAt = portal_datetime($purchase['paid_at']);
$license = portal_purchase_license_label($purchase);
$maintenancePrevious = portal_long_date($purchase['maintenance_previous_expires_at'] ?? null);
$maintenanceNew = portal_long_date($purchase['maintenance_new_expires_at'] ?? null);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Receipt <?= portal_e($displayReference) ?> | POS Printer Emulator</title>
  <style>
    :root{font-family:Inter,"Segoe UI",system-ui,sans-serif;color:#0b1e3a;background:#eef3f8}
    *{box-sizing:border-box}body{margin:0;padding:32px}.receipt{background:#fff;border:1px solid #d8e0ea;border-radius:14px;box-shadow:0 18px 48px rgba(7,23,45,.1);margin:auto;max-width:760px;overflow:hidden}
    header{align-items:center;background:#0b1b33;color:#fff;display:flex;justify-content:space-between;padding:24px 30px}.brand{align-items:center;display:flex;gap:14px}.brand img{display:block;height:48px;width:48px}.brand h1{font-size:22px;margin:0}.brand p{color:#bcd0e8;margin:4px 0 0}
    main{padding:30px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:28px}.meta section{border:1px solid #d8e0ea;border-radius:9px;padding:16px}.meta span,.detail dt{color:#5d6e86;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.meta strong{display:block;margin-top:5px}
    .detail{border-block:1px solid #d8e0ea;margin:0;padding:8px 0}.detail div{display:flex;justify-content:space-between;gap:20px;padding:10px 0}.detail dd{font-weight:650;margin:0;text-align:right}.total{align-items:center;display:flex;font-size:20px;justify-content:space-between;padding:24px 0}
    footer{background:#f5f8fc;border-top:1px solid #d8e0ea;color:#5d6e86;padding:20px 30px}.actions{display:flex;gap:12px;margin:0 auto 18px;max-width:760px}.actions a,.actions button{background:#0d6ee8;border:0;border-radius:7px;color:#fff;cursor:pointer;font:700 14px inherit;padding:12px 18px;text-decoration:none}.actions a.secondary{background:#fff;border:1px solid #8eb5ed;color:#0d6ee8}
    @media(max-width:600px){body{padding:12px}.meta{grid-template-columns:1fr}.detail div{display:block}.detail dd{text-align:left;margin-top:4px}.actions{flex-wrap:wrap}.brand img{height:40px;width:40px}}
    @media print{body{background:#fff;padding:0}.actions{display:none}.receipt{border:0;box-shadow:none;max-width:none}}
  </style>
</head>
<body>
<?php if (!$download): ?><nav class="actions" aria-label="Receipt actions"><a class="secondary" href="/portal.php?page=billing">Back to billing</a><a href="/receipt.php?reference=<?= rawurlencode($reference) ?>&amp;download=1">Download receipt</a></nav><?php endif; ?>
<article class="receipt">
  <header><div class="brand"><img src="/assets/product-icon.png" alt="POS Printer Emulator logo"><div><h1>POS Printer Emulator</h1><p>EPCOM Ltd. · Georgia, United States</p></div></div><strong>RECEIPT</strong></header>
  <main>
    <div class="meta">
      <section><span>Billed to</span><strong><?= portal_e((string)$purchase['display_name']) ?></strong><div><?= portal_e((string)$purchase['canonical_email']) ?></div></section>
      <section><span>Transaction</span><strong><?= portal_e($displayReference) ?></strong><div><?= portal_e($paidAt) ?></div></section>
    </div>
    <dl class="detail">
      <div><dt>Purchase</dt><dd><?= portal_e($purchaseType) ?></dd></div>
      <div><dt>License</dt><dd><?= portal_e($license) ?></dd></div>
      <div><dt>Payment status</dt><dd><?= portal_e($status) ?></dd></div>
      <div><dt>Invoice number</dt><dd><?= portal_e($invoiceNumber) ?></dd></div>
      <div><dt>PayPal approval reference</dt><dd><?= portal_e($paymentApprovalReference) ?></dd></div>
      <?php if ((string)($purchase['checkout_order_type'] ?? '') === 'MAINTENANCE'): ?>
        <div><dt>Previous coverage date</dt><dd><?= portal_e($maintenancePrevious) ?></dd></div>
        <div><dt>New coverage date</dt><dd><?= portal_e($maintenanceNew) ?></dd></div>
      <?php endif; ?>
    </dl>
    <div class="total"><strong>Total paid</strong><strong><?= portal_e((string)$purchase['currency']) ?> <?= number_format((float)$purchase['amount'], 2) ?></strong></div>
  </main>
  <footer>The PayPal approval reference is the verified capture ID returned for this payment. Payment and account credentials are intentionally excluded.</footer>
</article>
</body>
</html>
