<?php
// ChatApp/release_escrow.php
// ปล่อยเงินจากบัญชีกลางให้ผู้ขาย เมื่อผู้ซื้อกดยืนยันว่าได้รับสินค้าแล้ว

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false,'error'=>'กรุณาเข้าสู่ระบบ']); exit;
}
$userId = (int)$_SESSION['user_id'];

// รับ JSON
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
$requestId = isset($in['request_id']) ? trim($in['request_id']) : '';
$productId = isset($in['product_id']) ? (int)$in['product_id'] : 0;

if ($requestId==='' || $productId<=0) {
  echo json_encode(['ok'=>false,'error'=>'ข้อมูลไม่ครบ']); exit;
}

try {
  $pdo = new PDO(
    "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
    "mix","mix1234",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );

  // ===== helper: หา (หรือสร้าง) ผู้ใช้ escrow =====
  $escrowId = getEscrowUserId($pdo);

  // ===== ตรวจว่าเราเป็นผู้ซื้อจริงในห้องนี้ไหม =====
  $q = $pdo->prepare("SELECT seller_id, buyer_id FROM chat_requests WHERE request_id=? LIMIT 1");
  $q->execute([$requestId]);
  $cr = $q->fetch();
  if (!$cr) { echo json_encode(['ok'=>false,'error'=>'ไม่พบห้องแชท']); exit; }
  if ((int)$cr['buyer_id'] !== $userId) {
    echo json_encode(['ok'=>false,'error'=>'คุณไม่ใช่ผู้ซื้อของห้องนี้']); exit;
  }
  $sellerId = (int)$cr['seller_id'];

  // ===== ดึงคำสั่งซื้อ (ล่าสุดของผู้ซื้อในห้องนี้) =====
  $o = $pdo->prepare("SELECT * FROM orders WHERE request_id=? AND product_id=? AND user_id=? ORDER BY id DESC LIMIT 1");
  $o->execute([$requestId,$productId,$userId]);
  $order = $o->fetch();
  if (!$order) { echo json_encode(['ok'=>false,'error'=>'ไม่พบคำสั่งซื้อ']); exit; }

  $orderNo = $order['order_no'];
  $amount  = (float)$order['amount'];

  // ดึงข้อมูลสินค้า/ชื่อผู้ขาย (ไว้ทำข้อความระบบ)
  $p = $pdo->prepare("SELECT p.product_name, u.fname, u.lname
                      FROM products p LEFT JOIN users u ON u.user_id=p.user_id
                      WHERE p.product_id=? LIMIT 1");
  $p->execute([$productId]);
  $pi = $p->fetch();
  $productName = $pi['product_name'] ?? 'สินค้า';
  $sellerName  = trim(($pi['fname'] ?? '').' '.($pi['lname'] ?? ''));

  $pdo->beginTransaction();

  // ล็อกแถวออเดอร์
  $lk = $pdo->prepare("SELECT status FROM orders WHERE order_no=? FOR UPDATE");
  $lk->execute([$orderNo]);
  $cur = $lk->fetch();
  if (!$cur) { throw new Exception('order not found'); }

  // จบซ้ำ (idempotent)
  if ($cur['status']==='released') {
    $pdo->commit();
    echo json_encode(['ok'=>true,'status'=>'released']); exit;
  }
  if ($cur['status']!=='paid') {
    throw new Exception('สถานะออเดอร์ไม่พร้อมปล่อยเงิน (ต้องเป็น paid)');
  }

  // ล็อกยอด escrow + seller
  $u1 = $pdo->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
  $u1->execute([$escrowId]);
  $esc = $u1->fetch();
  if (!$esc) throw new Exception('ไม่พบบัญชีกลาง');

  if ((float)$esc['credit_balance'] + 1e-9 < $amount) {
    throw new Exception('ยอดในบัญชีกลางไม่เพียงพอ (ออเดอร์ยังไม่ได้โอนเข้ากลาง)');
  }

  $u2 = $pdo->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
  $u2->execute([$sellerId]);
  if (!$u2->fetch()) throw new Exception('ไม่พบผู้ขาย');

  // โอนจาก escrow -> seller
  $dec = $pdo->prepare("UPDATE users SET credit_balance = credit_balance - ? WHERE user_id=?");
  $dec->execute([$amount, $escrowId]);

  $inc = $pdo->prepare("UPDATE users SET credit_balance = credit_balance + ? WHERE user_id=?");
  $inc->execute([$amount, $sellerId]);

  // บันทึก ledger
  $led1 = $pdo->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                         VALUES (?, ?, 'escrow_release', ?, NOW())");
  $led1->execute([$escrowId, -$amount, $orderNo]);

  $led2 = $pdo->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                         VALUES (?, ?, 'sale_income', ?, NOW())");
  $led2->execute([$sellerId,  +$amount, $orderNo]);

  // อัปเดตออเดอร์ -> released
  $upd = $pdo->prepare("UPDATE orders SET status='released' WHERE order_no=? AND status='paid'");
  $upd->execute([$orderNo]);
  if ($upd->rowCount()!==1) throw new Exception('อัปเดตสถานะออเดอร์ไม่สำเร็จ');

  // ปิดการขายสินค้า (ถ้ามีคอลัมน์)
  try {
    $pdo->prepare("UPDATE products SET status='sold', sold_at=NOW() WHERE product_id=?")->execute([$productId]);
  } catch(Throwable $e) { /* ข้ามได้ถ้าไม่มีคอลัมน์ */ }

  // ข้อความระบบเข้าแชท
  $sysMsg = "[SYS] ผู้ซื้อยืนยันรับของแล้ว – โอนเงินให้ {$sellerName} ({$productName}) จำนวน ".number_format($amount,2)." บาท";
  $m = $pdo->prepare("INSERT INTO messages (request_id, product_id, sender_id, message)
                      VALUES (?, ?, ?, ?)");
  $m->execute([$requestId, $productId, $userId, $sysMsg]);

  $pdo->commit();
  echo json_encode(['ok'=>true,'status'=>'released']);
} catch(Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
}

// ===== helper =====
function getEscrowUserId(PDO $pdo): int {
  $q = $pdo->query("SELECT user_id FROM users WHERE username='escrow' LIMIT 1");
  $id = $q->fetchColumn();
  if ($id) return (int)$id;

  // ใส่คอลัมน์ NOT NULL ให้ครบ (เช่น img/status ในสคีมาเดิม)
  $pdo->prepare("
    INSERT INTO users (username,password,role,credit_balance,fname,lname,email,img,status)
    VALUES ('escrow','', 'admin', 0, 'Escrow','Wallet','escrow@example.com','', 'active')
  ")->execute();

  return (int)$pdo->lastInsertId();
}
