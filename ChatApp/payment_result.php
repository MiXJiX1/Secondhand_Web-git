<?php
$status = $_GET['status'] ?? 'failed';
$orderNo= $_GET['order_no'] ?? '';
$msg    = $_GET['msg'] ?? '';
?>
<!doctype html><meta charset="utf-8">
<style>body{font-family:Segoe UI;display:grid;place-items:center;height:100vh}
.card{padding:24px;border:1px solid #eee;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.06);text-align:center}
.ok{color:#0a7a2a}.no{color:#b3001b}</style>
<div class="card">
  <h2><?= $status==='paid' ? '<span class="ok">ชำระเงินสำเร็จ</span>' : '<span class="no">ชำระเงินไม่สำเร็จ</span>' ?></h2>
  <p>Order Number: <b><?= htmlspecialchars($orderNo) ?></b></p>
  <?php if($msg && $status!=='paid'): ?><p><?= htmlspecialchars($msg) ?></p><?php endif; ?>
  <p><a href="../topup.php">กลับหน้าเติมเครดิต/ยอดคงเหลือ</a></p>
</div>
