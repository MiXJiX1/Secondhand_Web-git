<?php
// รับ JSON (ถ้าระบบจริงส่งเป็น form-urlencoded ให้ปรับตามเอกสาร)
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$MERCHANT_ID = 'YOUR_MERCHANT_ID';
$SECRET      = 'YOUR_SECRET';

$orderNo  = $data['order_no'] ?? '';
$status   = $data['status']   ?? '';
$amount   = $data['amount']   ?? '0.00';
$currency = $data['currency'] ?? 'THB';
$sig      = $data['signature']?? '';
$txn      = $data['txn_id']   ?? null;

// ตรวจลายเซ็น
$base  = implode('|', [$MERCHANT_ID,$orderNo,$amount,$currency,$status]);
if (hash_hmac('sha256',$base,$SECRET) !== $sig) { http_response_code(403); exit('bad sig'); }

$pdo = new PDO("mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4","mix","mix1234",
               [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$st = $pdo->prepare("SELECT id,request_id,product_id,user_id,status FROM orders WHERE order_no=?");
$st->execute([$orderNo]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); exit('no order'); }

if ($status==='paid' && $o['status']==='pending') {
  $pdo->beginTransaction();
  $pdo->prepare("UPDATE orders SET status='paid', paid_at=NOW(), msupay_txn=? WHERE order_no=?")
      ->execute([$txn, $orderNo]);
  $pdo->prepare("INSERT INTO messages(request_id,product_id,sender_id,message) VALUES (?,?,?,?)")
      ->execute([$o['request_id'],$o['product_id'],$o['user_id'],"✅ ชำระเงินสำเร็จผ่าน MSUPAY เลขที่สั่งซื้อ {$orderNo} ยอด {$amount} {$currency}"]);
  $pdo->commit();
}
echo 'OK';
