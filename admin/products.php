<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ../login.html"); exit();
}

$pdo = new PDO("mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4", "mix", "mix1234", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Filters & pagination ---------- */
$q     = trim($_GET['q'] ?? '');
$cat   = trim($_GET['category'] ?? '');
$stat  = trim($_GET['status'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset= ($page-1)*$limit;

/* ---------- Build query ---------- */
$where = ["1=1"];
$args  = [];
if ($q !== '')     { $where[] = "p.product_name LIKE ?"; $args[] = "%$q%"; }
if ($cat !== '')   { $where[] = "p.category = ?";        $args[] = $cat; }
if ($stat !== '')  { $where[] = "p.status = ?";          $args[] = $stat; }
$whereSql = implode(' AND ', $where);

/* count for pagination */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
$stmt->execute($args);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total/$limit));

/* main list */
$sql = "
  SELECT p.product_id, p.product_name, p.category, p.product_price, p.product_image,
         p.status, p.sold_at, p.user_id,
         u.username
  FROM products p
  LEFT JOIN users u ON u.user_id = p.user_id
  WHERE $whereSql
  ORDER BY p.product_id DESC
  LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$products = $stmt->fetchAll();

/* helper: get first image safely */
function firstImageFromField(?string $s): ?string {
  if (!$s) return null;
  $s = trim($s);
  if ($s !== '' && $s[0] === '[') {
    $a = json_decode($s, true);
    if (is_array($a) && !empty($a)) return basename((string)$a[0]);
  }
  $parts = preg_split('/[|,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
  if ($parts && isset($parts[0])) return basename(trim($parts[0]));
  return basename($s);
}

/* categories (ถ้าต้องการดึงแบบไดนามิกให้ SELECT DISTINCT) */
$categories = ['electronics','fashion','furniture','vehicle','gameandtoys','household','sport','music','others'];
$statuses   = ['active'=>'แสดง','sold'=>'ขายแล้ว','hidden'=>'ซ่อน'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>จัดการสินค้า</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f5f6f8}
    .table thead th{background:#1f2937;color:#fff}
    .thumb{width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;background:#fafafa}
    .badge.status-active{background:#d1fae5;color:#065f46}
    .badge.status-sold{background:#fee2e2;color:#991b1b}
    .badge.status-hidden{background:#e5e7eb;color:#374151}
    .pagination .page-link{color:#111827}
    .pagination .active .page-link{background:#111827;border-color:#111827}
  </style>
</head>
<body>

<div class="container mt-5">
  <div class="d-flex align-items-center mb-3">
    <h2 class="m-0">รายการสินค้าทั้งหมด</h2>
    <a class="btn btn-secondary ms-auto" href="dashboard.php">← กลับแดชบอร์ด</a>
  </div>

  <!-- Filters -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4">
      <input type="text" name="q" class="form-control" placeholder="ค้นหาชื่อสินค้า..." value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-md-3">
      <select name="category" class="form-select">
        <option value="">ทุกหมวดหมู่</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $cat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select">
        <option value="">ทุกสถานะ</option>
        <?php foreach ($statuses as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $stat===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-dark w-100">ค้นหา</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th style="width:32%">ชื่อสินค้า</th>
          <th style="width:13%">หมวดหมู่</th>
          <th style="width:10%" class="text-end">ราคา</th>
          <th style="width:13%">ผู้โพสต์</th>
          <th style="width:12%">สถานะ</th>
          <th style="width:10%">รูป</th>
          <th style="width:10%">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="7" class="text-center">— ไม่พบสินค้า —</td></tr>
        <?php else: foreach ($products as $p):
            $img = firstImageFromField($p['product_image']);
            $imgSrc = $img ? "../uploads/{$img}" : "../assets/no-image.png";
            $badgeClass = 'status-'.$p['status'];
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['product_name']) ?></div>
              <?php if ($p['status']==='sold' && !empty($p['sold_at'])): ?>
                <div class="text-muted small">ปิดการขาย: <?= htmlspecialchars($p['sold_at']) ?></div>
              <?php endif; ?>
              <div class="text-muted small">ID: <?= (int)$p['product_id'] ?></div>
            </td>
            <td><?= htmlspecialchars($p['category']) ?></td>
            <td class="text-end"><?= number_format((float)$p['product_price'], 2) ?> บาท</td>
            <td><?= htmlspecialchars($p['username'] ?? '—') ?></td>
            <td><span class="badge <?= $badgeClass ?>"><?= $statuses[$p['status']] ?? $p['status'] ?></span></td>
            <td>
              <img src="<?= htmlspecialchars($imgSrc) ?>" class="thumb"
                   onerror="this.src='../assets/no-image.png'">
            </td>
            <td>
              <form method="post" action="delete_product.php" onsubmit="return confirm('ยืนยันลบสินค้านี้?')">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="id" value="<?= (int)$p['product_id'] ?>">
                <button class="btn btn-sm btn-danger">ลบ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <nav aria-label="pagination" class="mt-3">
      <ul class="pagination">
        <?php
          $build = function($p) use ($q,$cat,$stat) {
            $params = http_build_query(['q'=>$q,'category'=>$cat,'status'=>$stat,'page'=>$p]);
            return "?$params";
          };
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $build($page-1) ?>">«</a>
        </li>
        <?php for($i=max(1,$page-2); $i<=min($pages,$page+2); $i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="<?= $build($i) ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $build($page+1) ?>">»</a>
        </li>
      </ul>
      <div class="text-muted small">ทั้งหมด <?= number_format($total) ?> รายการ • หน้า <?= $page ?>/<?= $pages ?></div>
    </nav>
  <?php endif; ?>
</div>
</body>
</html>
