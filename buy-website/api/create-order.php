<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
require_same_origin(); enforce_rate_limit('create-order', 10, 3600);
try {
    $input=request_json(); $name=clean_customer((string)($input['customerName']??'')); $email=clean_email((string)($input['email']??''));
    $tier=clean_license_tier((string)($input['licenseTier']??'Pro'));
    $offer=license_offer($tier); $amount=$offer['price']; $currency=$offer['currency'];
    if ((float)$amount<=0) throw new RuntimeException('Checkout is not configured.');
    $publicId=random_public_id(); $created=paypal_request('POST','/v2/checkout/orders',[
      'intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$publicId,'description'=>'POS Printer Emulator '.$tier.' License','amount'=>['currency_code'=>$currency,'value'=>$amount]]],
      'payment_source'=>['paypal'=>['experience_context'=>['brand_name'=>'POS Emulator','shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','return_url'=>config('app_url').'/?status=return','cancel_url'=>config('app_url').'/?status=cancel']]],
    ],$publicId);
    $paypalOrderId=$created['id']??null;
    if (!is_string($paypalOrderId)||!preg_match('/^[A-Z0-9]{8,30}$/i',$paypalOrderId)) throw new RuntimeException('PayPal did not return a valid order ID.');
    $q=db()->prepare('INSERT INTO orders(public_id,customer_name,email,license_tier,paypal_order_id,amount,currency,status,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $q->execute([$publicId,$name,$email,$tier,$paypalOrderId,$amount,$currency,'CREATED',now_utc()]); $id=(int)db()->lastInsertId(); audit($id,'ORDER_CREATED',json_encode(['paypalOrderId'=>$paypalOrderId,'licenseTier'=>$tier]));
    json_response(['orderId'=>$paypalOrderId]);
} catch (InvalidArgumentException $e) { json_response(['error'=>$e->getMessage()],422); }
