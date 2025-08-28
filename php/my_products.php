<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

/* --- DB --- */
$servername = "sczfile.online";
$username   = "mix";
$password   = "mix1234";
$dbname     = "secondhand_web";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$user_id = (int)$_SESSION['user_id'];

/* --- CSRF token สำหรับลบ --- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];

/* --- ดึงสินค้าของฉัน (เพิ่ม status, sold_at) --- */
$stmt = $conn->prepare("
  SELECT product_id, product_name, product_price, product_image, category, description,
         status, sold_at
  FROM products
  WHERE user_id = ?
  ORDER BY product_id DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

/* --- helper: รูปแรกจากฟิลด์ที่อาจเก็บหลายรูป --- */
function firstImageFromField(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if ($s !== '' && $s[0] === '[') {
        $arr = json_decode($s, true);
        if (is_array($arr) && !empty($arr)) return basename((string)$arr[0]);
    }
    $parts = preg_split('/[|,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts && isset($parts[0])) return basename(trim($parts[0]));
    return basename($s);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สินค้าของฉัน</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root{--brand:#ffcc00;--ink:#333;--muted:#666;--bg:#f8f9fa;--shadow:0 6px 18px rgba(0,0,0,.08)}
*{box-sizing:border-box}
body{margin:0;font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--ink)}
.topbar{display:flex;align-items:center;gap:12px;background:var(--brand);padding:12px 16px;position:sticky;top:0;box-shadow:0 2px 6px rgba(0,0,0,.06);z-index:10}
.title{font-size:20px;font-weight:800}
.back-btn{appearance:none;border:0;background:#000;color:#fff;padding:8px 14px;border-radius:999px;cursor:pointer;font-weight:700}
.back-btn:hover{opacity:.9}

.wrap{max-width:1200px;margin:18px auto;padding:0 16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}
.card{position:relative;background:#fff;border-radius:14px;box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}
.thumb{width:100%;height:220px;object-fit:cover;background:#fafafa;transition:.2s}
.body{padding:14px}
.name{font-weight:800;margin:6px 0 4px 0}
.meta{color:var(--muted);font-size:13px;margin:2px 0}
.price{font-weight:800;margin-top:6px}
.actions{display:flex;gap:8px;margin-top:12px}
.btn{display:inline-block;text-decoration:none;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;transition:.2s}
.btn-view{background:var(--brand);color:#000}
.btn-view:hover{filter:brightness(.95)}
.btn-edit{background:#1f7aec;color:#fff}
.btn-edit:hover{background:#0f5fc0}
.btn-del{background:#22aa55;color:#fff}
.btn-del:hover{filter:brightness(.9)}

.empty{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:26px;text-align:center}
.empty p{margin:6px 0;color:#666}

/* --- แท็กขายแล้ว --- */
.sold-badge{
  position:absolute; top:10px; left:10px;
  background:#ff4757; color:#fff; font-weight:900; font-size:12px;
  padding:4px 10px; border-radius:999px; box-shadow:0 2px 6px rgba(0,0,0,.12);
}
.card.sold .thumb{ filter:grayscale(100%); }
.card.sold .name{ color:#666; }
.card.sold .meta{ color:#8a8a8a; }
</style>
</head>
<body>
<div class="topbar">
  <button class="back-btn" onclick="goBack()">&larr; กลับ</button>
  <div class="title">สินค้าของฉัน</div>
</div>

<div class="wrap">
  <?php if ($result->num_rows === 0): ?>
    <div class="empty">
      <h3>ยังไม่มีสินค้า</h3>
      <p>เริ่มลงขายชิ้นแรกได้ที่ปุ่ม “ลงขายสินค้า” บนหน้าแรก</p>
      <a class="btn btn-view" href="sell.php">ลงขายสินค้า</a>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php while ($row = $result->fetch_assoc()): ?>
        <?php
          $first  = firstImageFromField($row['product_image']);
          $imgSrc = $first ? ('uploads/'.$first) : 'assets/no-image.png';
          $isSold = (isset($row['status']) && $row['status'] === 'sold');
          $soldAt = !empty($row['sold_at']) ? date('d/m/Y H:i', strtotime($row['sold_at'])) : '';
        ?>
        <div class="card <?= $isSold ? 'sold':'' ?>">
          <?php if ($isSold): ?>
            <div class="sold-badge" title="<?= $soldAt ? 'ขายเมื่อ '.$soldAt : 'ขายแล้ว' ?>">ขายแล้ว</div>
          <?php endif; ?>
          <img class="thumb" src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($row['product_name']) ?>"
               onerror="this.src='assets/no-image.png';">
          <div class="body">
            <div class="name"><?= htmlspecialchars($row['product_name']) ?></div>
            <div class="price">ราคา: <?= number_format((float)$row['product_price'], 2) ?> บาท</div>
            <div class="meta">หมวดหมู่: <?= htmlspecialchars($row['category'] ?: '-') ?></div>
            <div class="meta">รายละเอียด: <?= htmlspecialchars($row['description'] ?: '-') ?></div>
            <?php if ($isSold && $soldAt): ?>
              <div class="meta">ปิดการขาย: <?= htmlspecialchars($soldAt) ?></div>
            <?php endif; ?>

            <div class="actions">
              <a class="btn btn-view" href="product_detail.php?id=<?= (int)$row['product_id'] ?>">ดูรายละเอียด</a>
              <a class="btn btn-edit" href="edit_product.php?id=<?= (int)$row['product_id'] ?>">แก้ไขสินค้า</a>

              <form action="delete_product.php" method="POST" style="margin:0"
                    onsubmit="return confirm('ยืนยันการลบสินค้า “<?= htmlspecialchars($row['product_name']) ?>”?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="product_id" value="<?= (int)$row['product_id'] ?>">
                <button type="submit" class="btn btn-del">ลบสินค้า</button>
              </form>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function goBack(){
  if (history.length > 1) history.back();
  else window.location.href = "index.php";
}
</script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
