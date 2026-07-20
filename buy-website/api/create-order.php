<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
require_same_origin(); enforce_rate_limit('create-order', 10, 3600);
try {
    $input=request_json(); $name=clean_customer((string)($input['customerName']??'')); $email=clean_email((string)($input['email']??''));
    $product=clean_purchase_product((string)($input['product']??'license'));
    $tier=clean_license_tier((string)($input['licenseTier']??'Pro'));
    $renewalLicenseId=null;$renewalDigest=null;$previousExpiration=null;
    if($product==='maintenance'){
        $renewalLicenseId=clean_license_id((string)($input['licenseId']??''));
        $renewalDigest=maintenance_registration_digest($name,$email);
        $entitlement=maintenance_service_request([
            'action'=>'prepare-renewal','licenseId'=>$renewalLicenseId,'registrationDigest'=>$renewalDigest,
        ]);
        if(empty($entitlement['renewalEligible']))throw new DomainException('This license is not eligible for maintenance renewal.');
        $verifiedTier=clean_license_tier((string)($entitlement['tier']??''));
        if(!hash_equals($tier,$verifiedTier))throw new InvalidArgumentException('Select the maintenance level that matches this license.');
        $previousExpiration=(string)($entitlement['maintenanceExpiresAt']??'');
        $offer=maintenance_offer($tier);
    }else{
        $offer=license_offer($tier);
    }
    $amount=$offer['price']; $currency=$offer['currency'];
    if ((float)$amount<=0) throw new RuntimeException('Checkout is not configured.');
    $description=$product==='maintenance'
        ? 'POS Printer Emulator '.$tier.' Annual Application Maintenance and Support Renewal'
        : 'POS Printer Emulator '.$tier.' Permanent License';
    $publicId=random_public_id(); $created=paypal_request('POST','/v2/checkout/orders',[
      'intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$publicId,'description'=>$description,'amount'=>['currency_code'=>$currency,'value'=>$amount]]],
      'payment_source'=>['paypal'=>['experience_context'=>['brand_name'=>'POS Emulator','shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','return_url'=>config('app_url').'/?status=return','cancel_url'=>config('app_url').'/?status=cancel']]],
    ],$publicId);
    $paypalOrderId=$created['id']??null;
    if (!is_string($paypalOrderId)||!preg_match('/^[A-Z0-9]{8,30}$/i',$paypalOrderId)) throw new RuntimeException('PayPal did not return a valid order ID.');
    $q=db()->prepare('INSERT INTO orders(public_id,customer_name,email,order_type,license_tier,renewal_license_id,renewal_registration_digest,paypal_order_id,amount,currency,status,created_at,maintenance_previous_expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$publicId,$name,$email,strtoupper($product),$tier,$renewalLicenseId,$renewalDigest,$paypalOrderId,$amount,$currency,'CREATED',now_utc(),$previousExpiration]);
    $id=(int)db()->lastInsertId(); audit($id,'ORDER_CREATED',json_encode(['paypalOrderId'=>$paypalOrderId,'licenseTier'=>$tier,'product'=>$product]));
    json_response(['orderId'=>$paypalOrderId]);
} catch (InvalidArgumentException $e) { json_response(['error'=>$e->getMessage()],422); }
catch (DomainException $e) { json_response(['error'=>$e->getMessage()],409); }
