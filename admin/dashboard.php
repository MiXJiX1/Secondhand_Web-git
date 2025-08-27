<?php
session_start();

/* --- DB --- */
$conn = new mysqli("localhost", "mix", "mix1234", "secondhand_web");
if ($conn->connect_error) {
  die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

/* --- helper: scalar --- */
function scalar($conn, $sql, $default=0){
  if (!$res = $conn->query($sql)) return $default;
  $row = $res->fetch_row();
  return $row ? (float)$row[0] : $default;
}

/* --- summary numbers (ป้องกันตารางหายด้วย try แบบง่าย ๆ) --- */
$totalUsers     = scalar($conn, "SELECT COUNT(*) FROM users", 0);
$totalProducts  = scalar($conn, "SELECT COUNT(*) FROM products", 0);
$totalOrders    = scalar($conn, "SELECT COUNT(*) FROM orders", 0);
$paidOrders     = scalar($conn, "SELECT COUNT(*) FROM orders WHERE status='paid'", 0);
$sumPaid        = scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid'", 0);
$openReports    = scalar($conn, "SELECT COUNT(*) FROM abuse_reports WHERE status IN ('open','reviewing')", 0);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>แดชบอร์ดผู้ดูแลระบบ</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{
      --brand:#ffcc00;
      --ink:#111;
      --muted:#6b7280;
      --cardshadow:0 4px 16px rgba(0,0,0,.08);
    }
    body{background:#f7f7f9;font-family:'Sarabun',system-ui,Segoe UI,Tahoma}
    .admin-header{background:var(--brand);color:#000;padding:16px 18px;box-shadow:0 2px 6px rgba(0,0,0,.06);position:sticky;top:0;z-index:10}
    .admin-header h1{font-size:22px;font-weight:800;margin:0;display:flex;align-items:center;gap:10px}
    .logout{background:#111;border:0;border-radius:999px;color:#fff;font-weight:700;padding:8px 14px}
    .logout:hover{opacity:.9}

    .stat{background:#fff;border-radius:14px;padding:16px;box-shadow:var(--cardshadow);position:relative;overflow:hidden}
    .stat .icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff}
    .i-users{background:linear-gradient(135deg,#6366f1,#60a5fa)}
    .i-products{background:linear-gradient(135deg,#f59e0b,#f97316)}
    .i-orders{background:linear-gradient(135deg,#10b981,#34d399)}
    .i-report{background:linear-gradient(135deg,#ef4444,#f43f5e)}
    .stat h6{margin:10px 0 4px;font-weight:700}
    .stat .num{font-size:26px;font-weight:800}

    .card-box{background:#fff;border:0;border-radius:14px;box-shadow:var(--cardshadow);transition:.2s}
    .card-box:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.12)}
    .card-box .bi{font-size:22px;margin-right:6px}
    .stretched-link{text-decoration:none}

    .section-title{font-size:16px;font-weight:800;color:#111;margin-bottom:10px}
    .empty{color:var(--muted)}
  </style>
</head>
<body>

<header class="admin-header d-flex justify-content-between align-items-center">
  <h1><i class="bi bi-speedometer2"></i> แดชบอร์ดผู้ดูแลระบบ</h1>
  <a href="../logout.php" class="logout">ออกจากระบบ</a>
</header>

<div class="container my-4">

  <!-- สรุปตัวเลข -->
  <div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="stat d-flex align-items-center gap-3">
        <div class="icon i-users"><i class="bi bi-people"></i></div>
        <div>
          <h6>ผู้ใช้งานทั้งหมด</h6>
          <div class="num"><?= number_format($totalUsers) ?></div>
          <div class="text-muted small">กำลังใช้งาน: <b><?= number_format($totalUsers) ?></b></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="stat d-flex align-items-center gap-3">
        <div class="icon i-products"><i class="bi bi-box-seam"></i></div>
        <div>
          <h6>สินค้าทั้งหมด</h6>
          <div class="num"><?= number_format($totalProducts) ?></div>
          <div class="text-muted small">โพสต์ในระบบ</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="stat d-flex align-items-center gap-3">
        <div class="icon i-orders"><i class="bi bi-cash-coin"></i></div>
        <div>
          <h6>ออเดอร์ที่ชำระแล้ว</h6>
          <div class="num"><?= number_format($paidOrders) ?></div>
          <div class="text-muted small">ยอดรวม <b><?= number_format($sumPaid,2) ?></b> บาท</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="stat d-flex align-items-center gap-3">
        <div class="icon i-report"><i class="bi bi-bell-exclamation"></i></div>
        <div>
          <h6>รายงาน/ร้องเรียนค้าง</h6>
          <div class="num"><?= number_format($openReports) ?></div>
          <div class="text-muted small">สถานะ: เปิด/กำลังตรวจสอบ</div>
        </div>
      </div>
    </div>
  </div>

  <!-- เมนูหลัก -->
  <div class="mt-4 section-title">การจัดการหลัก</div>
  <div class="row g-4">
    <div class="col-md-6 col-lg-4">
      <div class="card card-box h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-people"></i> ผู้ใช้งานทั้งหมด</h5>
          <p class="card-text text-muted">ดู/ลบ/อัปเกรดผู้ใช้ในระบบ</p>
          <a href="users.php" class="btn btn-dark stretched-link">จัดการผู้ใช้</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card card-box h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-boxes"></i> สินค้าทั้งหมด</h5>
          <p class="card-text text-muted">ดูรายการสินค้า หรือลบสินค้าที่ผิดกฎ</p>
          <a href="products.php" class="btn btn-warning stretched-link">จัดการสินค้า</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card card-box h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-graph-up"></i> สถิติระบบ</h5>
          <p class="card-text text-muted">ดูจำนวนผู้ใช้ สินค้า และยอดการใช้งาน</p>
          <a href="#" class="btn btn-success stretched-link">ดูรายงาน</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card card-box h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-credit-card"></i> ภาพรวมการชำระเงิน</h5>
          <p class="card-text text-muted">รายงานยอดเงินเข้า-ออกระบบ</p>
          <a href="payments.php" class="btn btn-success stretched-link">ดูรายการทั้งหมด</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card card-box h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-shield-exclamation"></i> การจัดการรายงานและข้อร้องเรียน</h5>
          <p class="card-text text-muted">ติดตาม/เปลี่ยนสถานะ/จดบันทึกการตรวจสอบ</p>
          <a href="admin_reports.php" class="btn btn-danger stretched-link">ดูรายงานทั้งหมด</a>
        </div>
      </div>
    </div>
  </div>

  <!-- กิจกรรมล่าสุด -->
  <div class="mt-5 section-title">กิจกรรมล่าสุด</div>
  <div class="card card-box">
    <div class="card-body">
      <ul class="list-group list-group-flush">
        <?php
        $sql = "SELECT username, action, created_at FROM activity_log ORDER BY created_at DESC LIMIT 10";
        if ($result = $conn->query($sql)):
          if ($result->num_rows):
            while ($row = $result->fetch_assoc()):
        ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>🔹 ผู้ใช้ <strong><?= htmlspecialchars($row['username']) ?></strong> <?= htmlspecialchars($row['action']) ?></span>
            <small class="text-muted"><?= date("d/m/Y H:i", strtotime($row['created_at'])) ?></small>
          </li>
        <?php
            endwhile;
          else:
        ?>
          <li class="list-group-item text-center empty">ยังไม่มีกิจกรรม</li>
        <?php
          endif;
        endif;
        ?>
      </ul>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
