<?php
// sales_income.php — รายรับจากการขายของผู้ใช้ปัจจุบัน
session_start();
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header("Location: login.php"); exit; }

$pdo = new PDO(
  "mysql:host=;dbname=;charset=utf8mb4",
  "","",
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);

/* ฟิลเตอร์ช่วงวันที่ (ไม่บังคับ) */
$start = $_GET['start'] ?? '';
$end   = $_GET['end']   ?? '';
$w     = " WHERE p.user_id=? AND o.status='paid' ";
$bind  = [$user_id];

if ($start !== '') { $w .= " AND DATE(o.paid_at) >= ? "; $bind[] = $start; }
if ($end   !== '') { $w .= " AND DATE(o.paid_at) <= ? "; $bind[] = $end; }

/* รวมยอด */
$sqlSum = "SELECT 
            COALESCE(SUM(o.amount),0) AS total_sum,
            COALESCE(SUM(CASE WHEN DATE(o.paid_at)=CURDATE() THEN o.amount END),0) AS today_sum,
            COALESCE(SUM(CASE WHEN YEAR(o.paid_at)=YEAR(CURDATE()) AND MONTH(o.paid_at)=MONTH(CURDATE()) THEN o.amount END),0) AS month_sum
          FROM orders o
          JOIN products p ON p.product_id = o.product_id
          $w";
$sumStmt = $pdo->prepare($sqlSum);
$sumStmt->execute($bind);
$sum = $sumStmt->fetch();

/* รายการคำสั่งซื้อ (ล่าสุด) */
$sqlList = "SELECT 
              o.order_no, o.amount, o.paid_at,
              b.username AS buyer_name,
              p.product_name, p.product_image
            FROM orders o
            JOIN products p ON p.product_id = o.product_id
            JOIN users b    ON b.user_id = o.user_id  -- ผู้ซื้อ
            $w
            ORDER BY o.paid_at DESC, o.order_no DESC
            LIMIT 100";
$listStmt = $pdo->prepare($sqlList);
$listStmt->execute($bind);
$rows = $listStmt->fetchAll();
?>
<!doctype html>
<html lang="th">
<meta charset="utf-8">
<title>รายรับจากการขาย</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f8f9fa}
  .topbar{display:flex;align-items:center;gap:12px;background:#ffcc00;padding:12px 16px;position:sticky;top:0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
  .back-btn{appearance:none;border:0;background:#000;color:#fff;padding:8px 14px;border-radius:999px;cursor:pointer;font-weight:700}
  .card-sum{border:1px solid #eee;border-radius:12px;background:#fff}
  .thumb{width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #eee;background:#fafafa}
</style>
<body>
<div class="topbar">
  <button class="back-btn" onclick="history.length>1?history.back():location.href='profile.php'">&larr; กลับ</button>
  <div class="fw-bold">รายรับจากการขาย</div>
</div>

<div class="container my-4">
  <!-- ฟิลเตอร์ช่วงวันที่ -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-auto">
      <label class="form-label mb-0">จากวันที่</label>
      <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="form-control">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0">ถึงวันที่</label>
      <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="form-control">
    </div>
    <div class="col-auto">
      <button class="btn btn-dark">แสดงผล</button>
      <a class="btn btn-outline-secondary" href="sales_income.php">ล้างตัวกรอง</a>
    </div>
  </form>

  <!-- สรุปรายรับ -->
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="p-3 card-sum">
        <div class="text-muted">รวมทั้งช่วงที่เลือก</div>
        <div class="fs-3 fw-bold"><?= number_format((float)$sum['total_sum'],2) ?> บาท</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="p-3 card-sum">
        <div class="text-muted">วันนี้</div>
        <div class="fs-4 fw-bold"><?= number_format((float)$sum['today_sum'],2) ?> บาท</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="p-3 card-sum">
        <div class="text-muted">เดือนนี้</div>
        <div class="fs-4 fw-bold"><?= number_format((float)$sum['month_sum'],2) ?> บาท</div>
      </div>
    </div>
  </div>

  <!-- ตารางคำสั่งซื้อ -->
  <div class="card-sum p-3">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th style="width:56px"></th>
            <th>สินค้า</th>
            <th>ผู้ซื้อ</th>
            <th class="text-end">ยอดชำระ</th>
            <th>เลขออเดอร์</th>
            <th>ชำระเมื่อ</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows): foreach($rows as $r):
          $img = !empty($r['product_image']) ? ('uploads/'.basename($r['product_image'])) : 'assets/no-image.png';
        ?>
          <tr>
            <td><img class="thumb" src="<?= htmlspecialchars($img) ?>" alt=""></td>
            <td><?= htmlspecialchars($r['product_name'] ?: '-') ?></td>
            <td><?= htmlspecialchars($r['buyer_name'] ?: '-') ?></td>
            <td class="text-end"><?= number_format((float)$r['amount'],2) ?></td>
            <td><code><?= htmlspecialchars($r['order_no']) ?></code></td>
            <td><?= htmlspecialchars($r['paid_at'] ?? '-') ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรายรับในช่วงที่เลือก</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
