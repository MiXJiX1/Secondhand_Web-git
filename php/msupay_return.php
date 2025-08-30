<?php
session_start();
$pdo = new PDO("mysql:host=;dbname=;charset=utf8mb4","","",
               [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$MERCHANT_ID = 'YOUR_MERCHANT_ID';
$SECRET      = 'YOUR_SECRET';

$orderNo   = $_GET['order_no']  ?? '';
$status    = $_GET['status']    ?? '';    // 'paid' | 'failed' | 'cancel'
$amount    = $_GET['amount']    ?? '0.00';
$currency  = $_GET['currency']  ?? 'THB';
$signature = $_GET['signature'] ?? '';
$txn       = $_GET['txn_id']    ?? null;

// ตรวจลายเซ็น (ตัวอย่าง)
$base  = implode('|', [$MERCHANT_ID,$orderNo,$amount,$currency,$status]);
$valid = hash_hmac('sha256',$base,$SECRET) === $signature;

// ค้นคำสั่งซื้อ
$st = $pdo->prepare("SELECT id,request_id,product_id,user_id,status FROM orders WHERE order_no=?");
$st->execute([$orderNo]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ die('order not found'); }

$msg = 'การชำระเงินไม่สำเร็จ';
if ($valid && $status==='paid') {
  if ($o['status']==='pending') {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE orders SET status='paid', paid_at=NOW(), msupay_txn=? WHERE order_no=?")
        ->execute([$txn, $orderNo]);
    // ส่งข้อความเข้าแชท
    $pdo->prepare("INSERT INTO messages(request_id,product_id,sender_id,message) VALUES (?,?,?,?)")
        ->execute([$o['request_id'],$o['product_id'],$o['user_id'],"✅ ชำระเงินสำเร็จผ่าน MSUPAY เลขที่สั่งซื้อ {$orderNo} ยอด {$amount} {$currency}"]);
    $pdo->commit();
  }
  $msg = 'ชำระเงินสำเร็จ';
}
?>
<!doctype html><meta charset="utf-8">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<div class="container py-5">
  <h3><?= htmlspecialchars($msg) ?></h3>
  <p>คำสั่งซื้อ: <?= htmlspecialchars($orderNo) ?></p>
  <a class="btn btn-primary" href="ChatApp/chat.php?request_id=<?= urlencode($o['request_id']) ?>&product_id=<?= (int)$o['product_id'] ?>">กลับไปห้องแชท</a>
</div>
