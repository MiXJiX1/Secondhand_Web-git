<?php
/**
 * admin_withdraw_action.php
 * จัดการคำขอถอนเครดิต (approve / reject / mark_paid) ฝั่งแอดมิน
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ../login.html");
  exit();
}
if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
  http_response_code(400);
  die('CSRF invalid');
}

$action = $_POST['action'] ?? '';
$withdrawId = (int)($_POST['withdraw_id'] ?? 0);
if ($withdrawId <= 0) {
  header("Location: payments.php?type=withdraw");
  exit();
}

try {
  $pdo = new PDO(
    "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
    "mix","mix1234",
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
  $pdo->exec("SET NAMES utf8mb4");

  $pdo->beginTransaction();

  // 🔒 ล็อกแถวไว้กันกดซ้ำ/แข่งกัน
  $q = $pdo->prepare("SELECT * FROM credit_withdrawals WHERE withdraw_id=? FOR UPDATE");
  $q->execute([$withdrawId]);
  $w = $q->fetch();
  if (!$w) {
    $pdo->rollBack();
    header("Location: payments.php?type=withdraw");
    exit();
  }

  $status = $w['status'];
  $now    = date('Y-m-d H:i:s');

  // helper redirect (ส่ง ok หรือ err ไปแสดง)
  $done = function(string $flag) use ($pdo) {
    if ($pdo->inTransaction()) $pdo->commit();
    header("Location: payments.php?type=withdraw&$flag=1");
    exit();
  };

  // ===== 1) อนุมัติ =====
  if ($action === 'approve') {
    // อนุญาตเฉพาะ requested -> approved
    if (in_array($status, ['approved','paid','rejected'], true)) {
      // ไม่เปลี่ยนซ้ำ
      $done('ok');
    }
    if ($status !== 'requested') {
      // สถานะผิด flow
      $done('err');
    }

    $u = $pdo->prepare("UPDATE credit_withdrawals SET status='approved', processed_at=? WHERE withdraw_id=?");
    $u->execute([$now, $withdrawId]);
    $done('ok');
  }

  // ===== 2) ปฏิเสธ & คืนเครดิต =====
  if ($action === 'reject') {
    // ต้องยังไม่ถูก reject/paid มาก่อน
    if (in_array($status, ['rejected','paid'], true)) {
      $done('ok');
    }

    // คืนเครดิต: ตอนผู้ใช้ยื่น withdraw เราหักเครดิตไปแล้ว -> คืนกลับด้วยเลดเจอร์เป็นบวก
    $ref = $w['ref_txn'] ?: ('WD'.strtoupper(bin2hex(random_bytes(6))));

    // กันคืนซ้ำ: แนะนำให้ตั้ง Unique Index ที่ credit_ledger(user_id, reason, ref_id)
    // ALTER TABLE credit_ledger ADD UNIQUE KEY uq_ledger_ref(user_id, reason, ref_id);
    $ins = $pdo->prepare("
      INSERT INTO credit_ledger(user_id, change_amt, reason, ref_id)
      VALUES(?, ?, 'withdraw_refund', ?)
    ");
    try {
      $ins->execute([(int)$w['user_id'], (float)$w['amount'], $ref]);
    } catch (PDOException $e) {
      // ถ้า unique ชน แปลว่าคืนไปแล้ว ก็ปล่อยผ่าน
      // 1062 = duplicate entry
      if ($e->getCode() !== '23000') throw $e;
    }

    $u = $pdo->prepare("UPDATE credit_withdrawals SET status='rejected', processed_at=? WHERE withdraw_id=?");
    $u->execute([$now, $withdrawId]);

    $done('ok');
  }

  // ===== 3) ทำเครื่องหมายว่า "โอนเงินแล้ว" =====
  // ใช้หลังจากอนุมัติและโอนเงินจริงสำเร็จ (นอกระบบ)
  if ($action === 'mark_paid') {
    if ($status === 'paid') {
      $done('ok'); // ทำแล้วไม่ต้องซ้ำ
    }
    // ปกติควรจะ approve ก่อนถึง paid
    if (!in_array($status, ['approved','requested'], true)) {
      $done('err');
    }
    // ถ้าอยู่ requested แต่โอนแล้วจริง ๆ ก็อนุโลมอัพเกรดเป็น paid เลย
    $u = $pdo->prepare("UPDATE credit_withdrawals SET status='paid', processed_at=? WHERE withdraw_id=?");
    $u->execute([$now, $withdrawId]);

    $done('ok');
  }

  // action ไม่ถูกต้อง
  if ($pdo->inTransaction()) $pdo->rollBack();
  header("Location: payments.php?type=withdraw");
  exit();

} catch (Throwable $e) {
  if (!headers_sent()) {
    header("Location: payments.php?type=withdraw&err=1");
  } else {
    echo "Error: ".$e->getMessage();
  }
  exit();
}
