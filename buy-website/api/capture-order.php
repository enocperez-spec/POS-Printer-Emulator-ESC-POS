<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
require_same_origin(); enforce_rate_limit('capture-order', 20, 3600);
$input=request_json(); $paypalId=(string)($input['orderId']??'');
if (!preg_match('/^[A-Z0-9]{8,30}$/i',$paypalId)) json_response(['error'=>'Invalid order.'],422);
$q=db()->prepare('SELECT * FROM orders WHERE paypal_order_id=?'); $q->execute([$paypalId]); $order=$q->fetch();
if (!$order) json_response(['error'=>'Order not found.'],404);
if (in_array($order['status'],['PAID_AWAITING_APPROVAL','APPROVED','EMAILED'],true)) json_response(['publicId'=>$order['public_id'],'status'=>$order['status']]);
$paypalPath='/v2/checkout/orders/'.rawurlencode($paypalId);
try {
    $captured=paypal_request('POST',$paypalPath.'/capture',null,'capture-'.$order['public_id']);
} catch (RuntimeException) {
    // A provider capture can succeed even if the response is lost before our local
    // transaction commits. Reconcile the PayPal order before asking a customer to pay again.
    $captured=paypal_request('GET',$paypalPath);
}
$capture=$captured['purchase_units'][0]['payments']['captures'][0]??null;
if (($captured['status']??'')!=='COMPLETED'||!is_array($capture)||($capture['status']??'')!=='COMPLETED'||($capture['amount']['value']??'')!==$order['amount']||($capture['amount']['currency_code']??'')!==$order['currency']) {
    audit((int)$order['id'],'CAPTURE_REJECTED',json_encode(['status'=>$captured['status']??null])); json_response(['error'=>'Payment could not be verified.'],409);
}
$providerCapturedAt=trim((string)($capture['create_time']??''));
try {
    if($providerCapturedAt==='') throw new RuntimeException('Missing capture time.');
    $paidAt=(new DateTimeImmutable($providerCapturedAt))->setTimezone(new DateTimeZone('UTC'));
} catch (Throwable) {
    audit((int)$order['id'],'CAPTURE_REJECTED','PayPal did not provide a valid capture time.');
    json_response(['error'=>'Payment could not be verified.'],409);
}
if($paidAt>new DateTimeImmutable('+5 minutes',new DateTimeZone('UTC'))){
    audit((int)$order['id'],'CAPTURE_REJECTED','PayPal capture time was in the future.');
    json_response(['error'=>'Payment could not be verified.'],409);
}
$q=db()->prepare("UPDATE orders SET paypal_capture_id=?,status='PAID_AWAITING_APPROVAL',paid_at=? WHERE id=? AND status='CREATED'");
$q->execute([$capture['id'],$paidAt->format('Y-m-d H:i:s'),$order['id']]);
audit((int)$order['id'],'PAYMENT_CAPTURED',json_encode(['captureId'=>$capture['id'],'capturedAt'=>$paidAt->format(DATE_ATOM)]));
json_response(['publicId'=>$order['public_id'],'status'=>'PAID_AWAITING_APPROVAL']);
