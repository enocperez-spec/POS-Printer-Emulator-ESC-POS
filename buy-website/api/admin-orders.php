<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/license_keys.php';
require __DIR__ . '/../includes/mailer.php';
header('Cache-Control: no-store');
require_admin_api_token();
enforce_rate_limit('admin-orders', 240, 3600);

$allowedStatuses = ['PAID_AWAITING_APPROVAL','APPROVED','EMAILED','EMAIL_FAILED'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = (string)($_GET['status'] ?? 'PAID_AWAITING_APPROVAL');
    if (!in_array($status, $allowedStatuses, true)) json_response(['error'=>'Invalid order status.'],422);
    $query = db()->prepare('SELECT public_id,customer_name,email,license_tier,paypal_order_id,paypal_capture_id,amount,currency,status,license_id,created_at,paid_at,approved_at,emailed_at,last_error FROM orders WHERE status=? ORDER BY COALESCE(paid_at,created_at) DESC LIMIT 200');
    $query->execute([$status]);
    json_response(['orders'=>$query->fetchAll(),'status'=>$status]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
$input = request_json();
$publicId = strtoupper(trim((string)($input['order'] ?? '')));
$action = (string)($input['action'] ?? 'approve');
if (!preg_match('/^[A-F0-9]{16}$/',$publicId) || !in_array($action,['approve','retry_email'],true)) json_response(['error'=>'Invalid order action.'],422);
$query = db()->prepare('SELECT * FROM orders WHERE public_id=?'); $query->execute([$publicId]); $order = $query->fetch();
if (!$order) json_response(['error'=>'Order not found.'],404);
if ($action === 'approve' && $order['status'] !== 'PAID_AWAITING_APPROVAL') json_response(['error'=>'Only a verified paid order awaiting approval can be approved.'],409);
if ($action === 'retry_email' && !in_array($order['status'], ['APPROVED', 'EMAIL_FAILED'], true)) json_response(['error'=>'Only an approved or failed activation email can be retried.'],409);

if ($action === 'approve') {
    $license = issue_activation_key($order['customer_name'],$order['email'],(string)($order['license_tier'] ?? 'Pro'));
    $update = db()->prepare("UPDATE orders SET activation_key=?,license_id=?,status='APPROVED',approved_at=? WHERE id=? AND status='PAID_AWAITING_APPROVAL'");
    $update->execute([$license['activation_key'],$license['license_id'],now_utc(),$order['id']]);
    if ($update->rowCount() !== 1) json_response(['error'=>'The order was already processed. Refresh the order list.'],409);
    audit((int)$order['id'],'LICENSE_APPROVED',$license['license_id']);
}
$query = db()->prepare('SELECT * FROM orders WHERE id=?'); $query->execute([$order['id']]); $order = $query->fetch();
try {
    email_activation_key($order);
    $update = db()->prepare("UPDATE orders SET status='EMAILED',emailed_at=?,last_error=NULL WHERE id=?"); $update->execute([now_utc(),$order['id']]);
    audit((int)$order['id'],'ACTIVATION_EMAIL_SENT');
    json_response(['status'=>'EMAILED','message'=>'The order was approved and the activation key was emailed.']);
} catch (Throwable $exception) {
    $update = db()->prepare("UPDATE orders SET status='EMAIL_FAILED',last_error=? WHERE id=?"); $update->execute([$exception->getMessage(),$order['id']]);
    audit((int)$order['id'],'ACTIVATION_EMAIL_FAILED',$exception->getMessage());
    $message = 'The key was generated, but email delivery failed. Use Retry email after checking mail settings.';
    json_response(['status'=>'EMAIL_FAILED','message'=>$message,'error'=>$message],502);
}
