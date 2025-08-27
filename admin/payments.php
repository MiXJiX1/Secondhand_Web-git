<?php
session_start();

/* ====== ตรวจสิทธิ์แอดมิน ====== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ../login.html");
  exit();
}

/* ====== DB ====== */
$pdo = new PDO(
  "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
  "mix",
  "mix1234",
  [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
);
$pdo->exec("SET NAMES utf8mb4");

/* ====== CSRF แบบง่าย ====== */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ====== กำหนดแท็บจาก dropdown ====== */
$tab = isset($_GET['type']) && in_array($_GET['type'], ['topup','withdraw'], true)
      ? $_GET['type'] : 'topup';

/* ====== Query ตามแท็บ ====== */
if ($tab === 'topup') {
  $stmt = $pdo->query("
    SELECT t.topup_id, t.user_id, t.amount, t.method, t.reference_no, t.slip_path,
           t.status, t.created_at, t.approved_at,
           u.username, u.credit_balance
    FROM credit_topups t
    LEFT JOIN users u ON u.user_id = t.user_id
    ORDER BY (t.status='pending') DESC, t.topup_id DESC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $stmt = $pdo->query("
    SELECT w.withdraw_id, w.user_id, w.amount, w.bank_name, w.bank_account, w.account_name,
           w.status, w.ref_txn, w.created_at, w.processed_at,
           u.username, u.credit_balance
    FROM credit_withdrawals w
    LEFT JOIN users u ON u.user_id = w.user_id
    ORDER BY (w.status='requested') DESC, w.withdraw_id DESC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <title>ภาพรวมการชำระเงิน (เติม/ถอน เครดิต)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    body{background:#f6f7f9}
    .table thead th{background:#1f2937;color:#fff}
    .badge.pending,.badge.requested{background:#ffe58f;color:#7a5d00}
    .badge.approved{background:#b7eb8f;color:#135200}
    .badge.rejected{background:#ffccc7;color:#820014}
    .badge.paid{background:#d1f7c4;color:#0f5132}
  </style>
</head>
<body>
<div class="container py-5">
  <div class="d-flex align-items-center mb-4 gap-3">
    <h3 class="m-0">📊 ภาพรวมการชำระเงิน / เครดิต</h3>
    <div class="ms-auto" style="min-width:220px">
      <select class="form-select" id="switchType">
        <option value="topup"   <?= $tab==='topup'?'selected':'' ?>>เติมเครดิต</option>
        <option value="withdraw"<?= $tab==='withdraw'?'selected':'' ?>>ถอนเครดิต</option>
      </select>
    </div>
  </div>

  <?php if ($tab === 'topup'): ?>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>ผู้ใช้</th>
            <th class="text-end">จำนวนเงิน</th>
            <th>ช่องทาง</th>
            <th>อ้างอิง</th>
            <th>สลิป</th>
            <th>สถานะ</th>
            <th>ยื่นเมื่อ</th>
            <th>อนุมัติเมื่อ</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10" class="text-center">— ไม่มีคำขอเติมเครดิต —</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['topup_id'] ?></td>
              <td><?= htmlspecialchars($r['username'] ?? ('UID '.$r['user_id'])) ?></td>
              <td class="text-end"><?= number_format((float)$r['amount'], 2) ?> บาท</td>
              <td><?= htmlspecialchars($r['method'] ?? '-') ?></td>
              <td><code><?= htmlspecialchars($r['reference_no'] ?? '-') ?></code></td>
              <td>
                <?php if (!empty($r['slip_path'])): ?>
                  <a href="../uploads_slip/<?= htmlspecialchars($r['slip_path']) ?>" target="_blank">ดูสลิป</a>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td><span class="badge <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td><?= htmlspecialchars($r['approved_at'] ?? '-') ?></td>
              <td>
                <?php if ($r['status'] === 'pending'): ?>
                  <form class="d-inline" method="post" action="topup_action.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="hidden" name="topup_id" value="<?= (int)$r['topup_id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-sm btn-success" onclick="return confirm('อนุมัติคำขอ #<?= (int)$r['topup_id'] ?> ?')">อนุมัติ</button>
                  </form>
                  <form class="d-inline" method="post" action="topup_action.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="hidden" name="topup_id" value="<?= (int)$r['topup_id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button class="btn btn-sm btn-danger" onclick="return confirm('ปฏิเสธคำขอ #<?= (int)$r['topup_id'] ?> ?')">ปฏิเสธ</button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php else: /* withdraw */ ?>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>ผู้ใช้</th>
            <th class="text-end">จำนวนเงิน</th>
            <th>ธนาคาร</th>
            <th>เลขบัญชี</th>
            <th>ชื่อบัญชี</th>
            <th>อ้างอิง</th>
            <th>สถานะ</th>
            <th>ยื่นเมื่อ</th>
            <th>ดำเนินการเมื่อ</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center">— ไม่มีคำขอถอนเครดิต —</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['withdraw_id'] ?></td>
              <td><?= htmlspecialchars($r['username'] ?? ('UID '.$r['user_id'])) ?></td>
              <td class="text-end"><?= number_format((float)$r['amount'], 2) ?> บาท</td>
              <td><?= htmlspecialchars($r['bank_name']) ?></td>
              <td><?= htmlspecialchars($r['bank_account']) ?></td>
              <td><?= htmlspecialchars($r['account_name']) ?></td>
              <td><code><?= htmlspecialchars($r['ref_txn'] ?? '-') ?></code></td>
              <td><span class="badge <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td><?= htmlspecialchars($r['processed_at'] ?? '-') ?></td>
              <td>
                <?php if (in_array($r['status'], ['requested','pending'], true)): ?>
                  <form class="d-inline" method="post" action="withdraw_action.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="hidden" name="withdraw_id" value="<?= (int)$r['withdraw_id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-sm btn-success" onclick="return confirm('อนุมัติคำขอถอน #<?= (int)$r['withdraw_id'] ?> ?')">อนุมัติ</button>
                  </form>
                  <form class="d-inline" method="post" action="withdraw_action.php" onsubmit="return confirm('ปฏิเสธคำขอนี้และคืนเครดิตให้ผู้ใช้?')">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="hidden" name="withdraw_id" value="<?= (int)$r['withdraw_id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button class="btn btn-sm btn-danger">ปฏิเสธ</button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <a href="dashboard.php" class="btn btn-secondary mt-3">← กลับแดชบอร์ด</a>
</div>

<script>
document.getElementById('switchType').addEventListener('change', function(){
  const url = new URL(location.href);
  url.searchParams.set('type', this.value);
  location.href = url.toString();
});
</script>
</body>
</html>
