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
    echo 'The requested invoice is unavailable.';
    exit;
}

$invoiceNumber = portal_purchase_invoice_number($purchase);
$displayReference = portal_purchase_display_reference($purchase);
$paymentApprovalReference = portal_purchase_payment_approval_reference($purchase);
$download = (string)($_GET['download'] ?? '') === '1';
$safeInvoiceNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoiceNumber) ?: 'invoice';
$status = portal_purchase_status_label((string)$purchase['purchase_status']);
$purchaseType = portal_purchase_type_label($purchase);
$paidAt = portal_datetime($purchase['paid_at']);
$license = portal_purchase_license_label($purchase);
$maintenanceNew = portal_long_date($purchase['maintenance_new_expires_at'] ?? null);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
if ($download) {
    header('Content-Disposition: attachment; filename="POS-Printer-Emulator-Invoice-' . $safeInvoiceNumber . '.html"');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Invoice <?= portal_e($invoiceNumber) ?> | POS Printer Emulator</title>
  <style>
    :root{font-family:Inter,"Segoe UI",system-ui,sans-serif;color:#0b1e3a;background:#eef3f8}
    *{box-sizing:border-box}body{margin:0;padding:32px}.invoice{background:#fff;border:1px solid #d8e0ea;border-radius:14px;box-shadow:0 18px 48px rgba(7,23,45,.1);margin:auto;max-width:800px;overflow:hidden}
    header{align-items:center;background:#0b1b33;color:#fff;display:flex;justify-content:space-between;padding:26px 32px}header h1{font-size:23px;margin:0}header p{color:#bcd0e8;margin:4px 0 0}.invoice-number{text-align:right}.invoice-number span{color:#bcd0e8;display:block;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
    main{padding:32px}.parties{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:30px}.parties section{border:1px solid #d8e0ea;border-radius:9px;padding:18px}.parties span,.detail dt{color:#5d6e86;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.parties strong{display:block;margin:6px 0 2px}
    .detail{border-block:1px solid #d8e0ea;margin:0;padding:8px 0}.detail div{display:flex;justify-content:space-between;gap:20px;padding:11px 0}.detail dd{font-weight:650;margin:0;text-align:right}.total{align-items:center;display:flex;font-size:21px;justify-content:flex-end;gap:28px;padding:26px 0}
    footer{background:#f5f8fc;border-top:1px solid #d8e0ea;color:#5d6e86;padding:20px 32px}.actions{display:flex;gap:12px;margin:0 auto 18px;max-width:800px}.actions a,.actions button{background:#0d6ee8;border:0;border-radius:7px;color:#fff;cursor:pointer;font:700 14px inherit;padding:12px 18px;text-decoration:none}.actions a.secondary{background:#fff;border:1px solid #8eb5ed;color:#0d6ee8}
    @media(max-width:600px){body{padding:12px}.parties{grid-template-columns:1fr}.detail div{display:block}.detail dd{text-align:left;margin-top:4px}.actions{flex-wrap:wrap}.invoice-number{text-align:left}}
    @media print{body{background:#fff;padding:0}.actions{display:none}.invoice{border:0;box-shadow:none;max-width:none}}
  </style>
</head>
<body>
<?php if (!$download): ?><nav class="actions" aria-label="Invoice actions"><a class="secondary" href="/portal.php?page=billing">Back to billing</a><a href="/invoice.php?reference=<?= rawurlencode($reference) ?>&amp;download=1">Download invoice</a></nav><?php endif; ?>
<article class="invoice">
  <header>
    <div><h1>POS Printer Emulator</h1><p>Invoice from EPCOM Ltd.</p></div>
    <div class="invoice-number"><span>Invoice</span><strong><?= portal_e($invoiceNumber) ?></strong></div>
  </header>
  <main>
    <div class="parties">
      <section><span>From</span><strong>EPCOM Ltd.</strong><div>Georgia, United States</div><div>posprinteremulator.com</div></section>
      <section><span>Billed to</span><strong><?= portal_e((string)$purchase['display_name']) ?></strong><div><?= portal_e((string)$purchase['canonical_email']) ?></div></section>
    </div>
    <dl class="detail">
      <div><dt>Invoice date</dt><dd><?= portal_e($paidAt) ?></dd></div>
      <div><dt>Description</dt><dd><?= portal_e($purchaseType) ?> — <?= portal_e((string)$purchase['license_tier']) ?> edition</dd></div>
      <div><dt>Associated license</dt><dd><?= portal_e($license) ?></dd></div>
      <?php if ($maintenanceNew !== 'Not available'): ?><div><dt>Maintenance and Support Until</dt><dd><?= portal_e($maintenanceNew) ?></dd></div><?php endif; ?>
      <div><dt>Payment status</dt><dd><?= portal_e($status) ?></dd></div>
      <div><dt>PayPal approval reference</dt><dd><?= portal_e($paymentApprovalReference) ?></dd></div>
      <div><dt>PayPal order reference</dt><dd><?= portal_e($displayReference) ?></dd></div>
    </dl>
    <div class="total"><strong>Total paid</strong><strong><?= portal_e((string)$purchase['currency']) ?> <?= number_format((float)$purchase['amount'], 2) ?></strong></div>
  </main>
  <footer>The PayPal approval reference is the verified capture ID returned for this payment. Payment and account credentials are intentionally excluded.</footer>
</article>
</body>
</html>
