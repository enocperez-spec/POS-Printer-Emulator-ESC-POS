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
$captured=paypal_request('POST','/v2/checkout/orders/'.rawurlencode($paypalId).'/capture',null,'capture-'.$order['public_id']);
$capture=$captured['purchase_units'][0]['payments']['captures'][0]??null;
if (($captured['status']??'')!=='COMPLETED'||!is_array($capture)||($capture['status']??'')!=='COMPLETED'||($capture['amount']['value']??'')!==$order['amount']||($capture['amount']['currency_code']??'')!==$order['currency']) {
    audit((int)$order['id'],'CAPTURE_REJECTED',json_encode(['status'=>$captured['status']??null])); json_response(['error'=>'Payment could not be verified.'],409);
}
$q=db()->prepare("UPDATE orders SET paypal_capture_id=?,status='PAID_AWAITING_APPROVAL',paid_at=? WHERE id=? AND status='CREATED'");
$q->execute([$capture['id'],now_utc(),$order['id']]); audit((int)$order['id'],'PAYMENT_CAPTURED',$capture['id']);
json_response(['publicId'=>$order['public_id'],'status'=>'PAID_AWAITING_APPROVAL']);
