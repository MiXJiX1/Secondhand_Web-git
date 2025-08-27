<?php
session_start();

/* ตรวจสิทธิ์แอดมิน + CSRF */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ../login.html"); exit();
}
if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
  http_response_code(403); die('Invalid CSRF');
}

/* DB */
$pdo = new PDO(
  "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
  "mix",
  "mix1234",
  [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
);

$topupId = isset($_POST['topup_id']) ? (int)$_POST['topup_id'] : 0;
$action  = $_POST['action'] ?? '';
if ($topupId <= 0 || !in_array($action, ['approve','reject'], true)) {
  http_response_code(400); die('Invalid request');
}

try {
  $pdo->beginTransaction();

  // lock แถวที่กำลังพิจารณา
  $st = $pdo->prepare("SELECT user_id, amount, status FROM credit_topups WHERE topup_id = ? FOR UPDATE");
  $st->execute([$topupId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('Topup not found'); }
  if ($row['status'] !== 'pending') { throw new Exception('Already processed'); }

  if ($action === 'approve') {
    // เพิ่มเครดิตให้ผู้ใช้
    $u = $pdo->prepare("UPDATE users SET credit_balance = credit_balance + ? WHERE user_id = ?");
    $u->execute([(float)$row['amount'], (int)$row['user_id']]);
    // ลงสมุดบัญชี (ถ้ามีตารางนี้)
    $pdo->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id) VALUES (?, ?, 'topup_approved', ?)")
        ->execute([(int)$row['user_id'], (float)$row['amount'], (string)$topupId]);

    // อัปเดตสถานะ topup
    $t = $pdo->prepare("UPDATE credit_topups SET status='approved', approved_at=NOW(), admin_id=? WHERE topup_id=?");
    $t->execute([ (int)($_SESSION['user_id'] ?? 0), $topupId ]);

  } else { // reject
    $t = $pdo->prepare("UPDATE credit_topups SET status='rejected', approved_at=NOW(), admin_id=? WHERE topup_id=?");
    $t->execute([ (int)($_SESSION['user_id'] ?? 0), $topupId ]);
  }

  $pdo->commit();
  header("Location: payments.php");
  exit();

} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "Action error: " . htmlspecialchars($e->getMessage());
}
