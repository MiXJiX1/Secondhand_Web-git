<?php
// /mix/project101/ChatApp/payment_process.php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('not login'); }

require_once __DIR__.'/../config.php';   // ปรับ path ให้ตรงของคุณ
$userId = (int)$_SESSION['user_id'];

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit('db error'); }
$mysqli->set_charset('utf8mb4');

$action = $_GET['action'] ?? '';

if ($action === 'pay') {
  // รับเลขออเดอร์
  $orderNo = trim($_POST['order_no'] ?? '');
  if ($orderNo === '') { http_response_code(400); exit('missing order_no'); }

  /* สมมติคุณมีตาราง orders โครงสร้างประมาณนี้
     orders(order_id PK, order_no UNIQUE, user_id, amount, status, paid_at, payment_ref)
     - status: pending|paid|cancelled
  */

  // 1) ดึงคำสั่งซื้อ + ล็อกแถว (กันแข่ง/กดซ้ำ)
  $stmt = $mysqli->prepare("SELECT order_id, user_id, amount, status
                            FROM orders WHERE order_no=? FOR UPDATE");
  $stmt->bind_param('s', $orderNo);
  $mysqli->begin_transaction();

  try {
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) { throw new Exception('order not found'); }
    if ((int)$order['user_id'] !== $userId) { throw new Exception('permission denied'); }
    if ($order['status'] === 'paid') {
      // กดซ้ำ: ถือว่าสำเร็จแล้ว redirect กลับ
      $mysqli->commit();
      header("Location: payment_result.php?order_no=".$orderNo."&status=paid");
      exit;
    }
    if ($order['status'] !== 'pending') { throw new Exception('invalid status'); }

    $amount = (float)$order['amount'];    // ราคาที่ต้องตัดเครดิต

    // 2) เช็คยอดคงเหลือผู้ใช้ด้วย FOR UPDATE
    $u = $mysqli->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
    $u->bind_param('i', $userId);
    $u->execute();
    $urow = $u->get_result()->fetch_assoc();
    if (!$urow) { throw new Exception('user not found'); }

    $balance = (float)$urow['credit_balance'];
    if ($balance + 1e-9 < $amount) {  // กัน floating error เล็กน้อย
      throw new Exception('insufficient balance');
    }

    // 3) ตัดเครดิตจาก users และลง ledger (ค่าติดลบ)
    $dec = $mysqli->prepare("UPDATE users SET credit_balance = credit_balance - ? WHERE user_id=?");
    $dec->bind_param('di', $amount, $userId);
    if (!$dec->execute()) { throw new Exception('debit failed'); }

    $led = $mysqli->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                             VALUES (?, ?, 'purchase', ?, NOW())");
    $minus = -$amount;                     // ใส่ค่าติดลบ
    $refId = $orderNo;                     // เก็บหมายเลขออเดอร์เป็น ref
    $led->bind_param('ids', $userId, $minus, $refId);
    if (!$led->execute()) { throw new Exception('ledger failed'); }

    // 4) อัปเดตคำสั่งซื้อเป็น paid พร้อมบันทึก payment_ref
    $payRef = 'PAY'.date('ymdHis').random_int(100,999);
    $upd = $mysqli->prepare("UPDATE orders
                             SET status='paid', paid_at=NOW(), payment_ref=?
                             WHERE order_id=? AND status='pending'");
    $upd->bind_param('si', $payRef, $order['order_id']);
    if (!$upd->execute() || $upd->affected_rows !== 1) {
      throw new Exception('update order failed');
    }

    // ทุกอย่างผ่าน → commit
    $mysqli->commit();

    // กลับไปหน้าแจ้งผล
    header("Location: payment_result.php?order_no=".$orderNo."&status=paid");
    exit;

  } catch (Throwable $e) {
    $mysqli->rollback();
    // สถานะผิด/เงินไม่พอ → ส่งกลับพร้อมข้อความ
    header("Location: payment_result.php?order_no=".$orderNo."&status=failed&msg=".urlencode($e->getMessage()));
    exit;
  }
}

http_response_code(400);
echo 'unknown action';
