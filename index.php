<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------- DB ----------
$servername   = "sczfile.online";
$username     = "mix";
$password     = "mix1234";
$dbname       = "secondhand_web";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// ---------- Inputs ----------
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$priceRange = isset($_GET['price_range']) ? $_GET['price_range'] : '';
$category   = isset($_GET['category']) ? trim($_GET['category']) : '';

$page     = max(1, (int)($_GET['page'] ?? 1));         // หน้าปัจจุบัน
$perPage  = max(1, (int)($_GET['per_page'] ?? 20));    // จำนวนต่อหน้า (เปลี่ยนได้)
$offset   = ($page - 1) * $perPage;

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// ---------- หา owner column ----------
$ownerCol = null;
$possibleCols  = ['user_id','seller_id','owner_id'];
$ph            = implode(',', array_fill(0, count($possibleCols), '?'));
$colStmt = $conn->prepare("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME IN ($ph)
  LIMIT 1
");
$types  = 's' . str_repeat('s', count($possibleCols));
$params = array_merge([$dbname], $possibleCols);
$colStmt->bind_param($types, ...$params);
$colStmt->execute();
$colRes = $colStmt->get_result();
if ($r = $colRes->fetch_assoc()) { $ownerCol = $r['COLUMN_NAME']; }
$colStmt->close();

// ---------- สร้าง WHERE + พารามิเตอร์ (ใช้ร่วมกันทั้ง count และ select) ----------
$where  = " WHERE 1=1 AND status='active' ";
$argt   = '';
$args   = [];

if ($category !== '') { $where .= " AND category = ?"; $argt .= 's'; $args[] = $category; }
if ($searchTerm !== '') { $where .= " AND product_name LIKE ?"; $argt .= 's'; $args[] = '%'.$searchTerm.'%'; }

if ($priceRange !== '') {
    if     ($priceRange === '0-100')    { $where .= " AND product_price BETWEEN 0 AND 100"; }
    elseif ($priceRange === '100-500')  { $where .= " AND product_price > 100 AND product_price <= 500"; }
    elseif ($priceRange === '500-1000') { $where .= " AND product_price > 500 AND product_price <= 1000"; }
    elseif ($priceRange === '1000+')    { $where .= " AND product_price > 1000"; }
}

// ---------- นับจำนวนทั้งหมดเพื่อคำนวณหน้าสุดท้าย ----------
$countSql = "SELECT COUNT(*) AS cnt FROM products" . $where;
$stCount  = $conn->prepare($countSql);
if ($argt !== '') { $stCount->bind_param($argt, ...$args); }
$stCount->execute();
$totalRows = (int)$stCount->get_result()->fetch_assoc()['cnt'];
$stCount->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// ---------- ดึงสินค้าหน้าปัจจุบัน ----------
$ownerSelect = $ownerCol ? ", $ownerCol AS owner_id" : "";
$sqlActive = "SELECT product_id, product_name, product_price, product_image, status, sold_at $ownerSelect
              FROM products
              $where
              ORDER BY product_id DESC
              LIMIT ? OFFSET ?";

$stActive = $conn->prepare($sqlActive);
$typesActive = $argt . 'ii';
$bindArgs = $args;
$bindArgs[] = $perPage;
$bindArgs[] = $offset;
$stActive->bind_param($typesActive, ...$bindArgs);
$stActive->execute();
$rsActive = $stActive->get_result();

// ---------- คิวรีสินค้าที่ปิดการขาย (แสดงคงที่ 12 รายการล่าสุด) ----------
$sqlSold = "SELECT product_id, product_name, product_price, product_image, status, sold_at $ownerSelect
            FROM products
            WHERE status='sold'
            ORDER BY COALESCE(sold_at, product_id) DESC
            LIMIT 12";
$stSold = $conn->prepare($sqlSold);
$stSold->execute();
$rsSold = $stSold->get_result();

// ---------- Helper: รูปแรกจากฟิลด์ ----------
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ระบบขายของมือสองภายในมหาวิทยาลัย</title>
<style>
body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; background: #f8f9fa; color: #333; }
header { background: #ffcc00; padding: 16px; display: flex; justify-content: center; align-items: center; position: relative; }
header h1 { font-size: 22px; font-weight: 700; margin: 0; }
.login-button { position: absolute; right: 16px; }
.login-button a { padding: 8px 18px; background: #333; color: #fff; border-radius: 25px; text-decoration: none; font-size: 14px; transition: 0.3s; }
.login-button a:hover { background: #000; }
nav { background: #333; }
.nav-container { display: flex; justify-content: space-between; align-items: center; max-width: 1100px; margin: auto; padding: 10px 20px; }
.nav-links { display: flex; gap: 18px; }
.nav-links a { color: #ffcc00; font-weight: 600; text-decoration: none; font-size: 14px; }
.nav-links a:hover { text-decoration: underline; }
.menu-toggle { display: none; font-size: 22px; color: #fff; cursor: pointer; }
@media (max-width: 768px) {
  .menu-toggle { display: block; }
  .nav-links { display: none; flex-direction: column; width: 100%; background: #333; padding: 15px 0; }
  .nav-links.active { display: flex; }
}
.container { max-width: 1100px; margin: auto; padding: 20px; }
.search-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 3px 8px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
.search-box input, .search-box select, .search-box button { padding: 10px; border-radius: 8px; border: 1px solid #ccc; font-size: 14px; }
.search-box button { background: #ffcc00; border: none; font-weight: 600; cursor: pointer; transition: 0.3s; }
.search-box button:hover { background: #ffaa00; }

.product-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(250px,1fr)); gap: 20px; }
.product-card { position: relative; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s; }
.product-card:hover { transform: translateY(-5px); }
.product-card img { width: 100%; height: 200px; object-fit: cover; display:block; }
.product-card .info { padding: 12px; text-align: center; }
.product-card h3 { font-size: 16px; margin: 5px 0; }
.product-card p { font-size: 14px; color: #777; }

.view-button { display: inline-block; margin-top: 10px; padding: 8px 14px; border-radius: 6px; font-weight: bold; text-decoration: none; transition: 0.3s; background: #ffcc00; color: #333; }
.view-button:hover { background: #ffaa00; }

/* ป้ายเจ้าของ */
.owner-badge{
  position:absolute; top:10px; left:10px;
  background:rgba(0,0,0,.75); color:#fff;
  padding:6px 10px; border-radius:999px; font-size:12px; font-weight:800;
  box-shadow:0 2px 6px rgba(0,0,0,.2);
}

/* การ์ดสถานะปิดการขาย */
.product-card.sold img{ filter:grayscale(100%); opacity:.6; }
.product-card .sold-overlay{
  position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.35); color:#fff; font-weight:800; font-size:20px; letter-spacing:.5px;
}
.product-card .ribbon-sold{
  position:absolute; top:12px; right:-40px; transform:rotate(45deg);
  background:#ff4757; color:#fff; padding:6px 60px; font-weight:800; box-shadow:0 2px 8px rgba(0,0,0,.25);
}
.view-button.disabled{ pointer-events:none; opacity:.5; filter:grayscale(100%); }
.sold-meta{ font-size:12px; color:#888; margin-top:6px; }

/* ---------- Pagination ---------- */
.pagination{
  display:flex; gap:8px; justify-content:center; align-items:center;
  margin:24px 0; flex-wrap:wrap;
}
.pagination a, .pagination span{
  padding:8px 12px; border-radius:8px; border:1px solid #ddd;
  text-decoration:none; color:#333; background:#fff; font-weight:600; font-size:14px;
}
.pagination a:hover{ background:#fffbdd; border-color:#ffcc00; }
.pagination .active{ background:#ffcc00; border-color:#ffcc00; color:#000; }
.pagination .muted{ opacity:.5; pointer-events:none; }
.summary{ text-align:center; color:#666; margin-top:-10px; margin-bottom:20px; }
</style>
</head>
<body>

<header>
  <h1>ระบบขายของมือสองภายในมหาวิทยาลัย</h1>
  <div class="login-button">
    <?php if (isset($_SESSION['username'])): ?>
      <a href="php/logout.php">ออกจากระบบ</a>
    <?php else: ?>
      <a href="php/login.php">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>
</header>

<nav>
  <div class="nav-container">
    <div class="menu-toggle" id="menuToggle">☰</div>
    <div class="nav-links" id="navLinks">
      <a href="index.php">หน้าแรก</a>
      <a href="php/sell.php">ลงขายสินค้า</a>
	  <a href="php/exchange.php">แลกเปลี่ยนสินค้า</a>
      <a href="ChatApp/chat_list.php">แชท <span id="unreadBadge" style="background:#ef4444;color:#fff;border-radius:999px;padding:2px 8px;font-weight:700;display:none"></span></a>
      <a href="php/my_products.php">สินค้าของฉัน</a>
      <a href="php/topup.php">เติมเครดิต</a>
	  <a href="php/feedback.php">รายงานผู้ใช้/ให้คะแนน</a>
      <a href="php/profile.php">โปรไฟล์</a>
      <?php if (!isset($_SESSION['username'])): ?>
        <a href="php/login.php" class="login-mobile">เข้าสู่ระบบ</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container">
  <form class="search-box" method="GET" action="index.php">
    <input type="text" name="search" placeholder="ค้นหาชื่อสินค้า..." value="<?= htmlspecialchars($searchTerm) ?>">
    <select name="price_range">
      <option value="">-- เลือกราคา --</option>
      <option value="0-100"   <?= $priceRange=='0-100'   ? 'selected' : '' ?>>0 - 100 บาท</option>
      <option value="100-500" <?= $priceRange=='100-500' ? 'selected' : '' ?>>100 - 500 บาท</option>
      <option value="500-1000"<?= $priceRange=='500-1000'? 'selected' : '' ?>>500 - 1,000 บาท</option>
      <option value="1000+"   <?= $priceRange=='1000+'   ? 'selected' : '' ?>>มากกว่า 1,000 บาท</option>
    </select>
    <select name="category">
      <option value="">-- เลือกหมวดหมู่ --</option>
      <option value="electronics" <?= $category=='electronics' ? 'selected' : '' ?>>อุปกรณ์อิเล็กทรอนิกส์</option>
      <option value="fashion"     <?= $category=='fashion'     ? 'selected' : '' ?>>แฟชั่น</option>
      <option value="furniture"   <?= $category=='furniture'   ? 'selected' : '' ?>>เฟอร์นิเจอร์</option>
      <option value="vehicle"     <?= $category=='vehicle'     ? 'selected' : '' ?>>ยานพาหนะ</option>
      <option value="gameandtoys" <?= $category=='gameandtoys' ? 'selected' : '' ?>>เกมและของเล่น</option>
      <option value="household"   <?= $category=='household'   ? 'selected' : '' ?>>ของใช้ในครัวเรือน</option>
      <option value="sport"       <?= $category=='sport'       ? 'selected' : '' ?>>อุปกรณ์กีฬา</option>
      <option value="music"       <?= $category=='music'       ? 'selected' : '' ?>>เครื่องดนตรี</option>
      <option value="others"      <?= $category=='others'      ? 'selected' : '' ?>>อื่นๆ</option>
    </select>
    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
    <button type="submit">ค้นหา</button>
  </form>

  <!-- สรุปผลและปุ่มแบ่งหน้า (บน) -->
  <div class="summary">
    พบทั้งหมด <?= number_format($totalRows) ?> รายการ | หน้า <?= $page ?> / <?= $totalPages ?>
  </div>

  <!-- สินค้าปกติ -->
  <h2>สินค้าแนะนำ</h2>
  <div class="product-grid">
    <?php
    if ($rsActive && $rsActive->num_rows > 0) {
      while ($row = $rsActive->fetch_assoc()) {
        $isOwner = ($ownerCol && $currentUserId > 0 && isset($row['owner_id']) && (int)$row['owner_id'] === $currentUserId);
        $firstImg = firstImageFromField($row['product_image']);
        $imgSrc   = $firstImg ? 'uploads/'.$firstImg : 'assets/no-image.png';
        ?>
        <div class="product-card">
          <?php if ($isOwner): ?><div class="owner-badge">เจ้าของ</div><?php endif; ?>

          <img src="<?= htmlspecialchars($imgSrc) ?>"
               alt="<?= htmlspecialchars($row['product_name']) ?>"
               onerror="this.src='assets/no-image.png';">

          <div class="info">
            <h3><?= htmlspecialchars($row['product_name']) ?></h3>
            <p>ราคา: <?= number_format((float)$row['product_price'], 2) ?> บาท</p>
            <a href="php/product_detail.php?id=<?= (int)$row['product_id'] ?>" class="view-button">ดูรายละเอียด</a>
          </div>
        </div>
        <?php
      }
    } else {
      echo '<p>ไม่มีสินค้า</p>';
    }
    ?>
  </div>

  <!-- ปุ่มแบ่งหน้า (ล่าง) -->
  <div class="pagination">
    <?php
    // สร้าง query string คงค่ากรองเดิม
    function pageUrl($p){
        $qs = $_GET;
        $qs['page'] = $p;
        return 'index.php?' . http_build_query($qs);
    }

    // ปุ่ม Previous
    if ($page > 1) {
        echo '<a href="'.htmlspecialchars(pageUrl($page-1)).'">« ก่อนหน้า</a>';
    } else {
        echo '<span class="muted">« ก่อนหน้า</span>';
    }

    // แสดงหมายเลขหน้าแบบย่อ (รอบ ๆ หน้าปัจจุบัน)
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    if ($start > 1) {
        echo '<a href="'.htmlspecialchars(pageUrl(1)).'">1</a>';
        if ($start > 2) echo '<span class="muted">…</span>';
    }
    for ($i=$start; $i<=$end; $i++){
        if ($i == $page) echo '<span class="active">'.$i.'</span>';
        else echo '<a href="'.htmlspecialchars(pageUrl($i)).'">'.$i.'</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages-1) echo '<span class="muted">…</span>';
        echo '<a href="'.htmlspecialchars(pageUrl($totalPages)).'">'.$totalPages.'</a>';
    }

    // ปุ่ม Next
    if ($page < $totalPages) {
        echo '<a href="'.htmlspecialchars(pageUrl($page+1)).'">ถัดไป »</a>';
    } else {
        echo '<span class="muted">ถัดไป »</span>';
    }
    ?>
  </div>

  <!-- สินค้าที่ปิดการขาย -->
  <h2 style="margin-top:32px">สินค้าที่ปิดการขายแล้ว</h2>
  <div class="product-grid">
    <?php
    if ($rsSold && $rsSold->num_rows > 0) {
      while ($row = $rsSold->fetch_assoc()) {
        $isOwner = ($ownerCol && $currentUserId > 0 && isset($row['owner_id']) && (int)$row['owner_id'] === $currentUserId);
        $firstImg = firstImageFromField($row['product_image']);
        $imgSrc   = $firstImg ? 'uploads/'.$firstImg : 'assets/no-image.png';
        ?>
        <div class="product-card sold">
          <?php if ($isOwner): ?><div class="owner-badge">เจ้าของ</div><?php endif; ?>
          <div class="ribbon-sold">ปิดการขาย</div>

          <img src="<?= htmlspecialchars($imgSrc) ?>"
               alt="<?= htmlspecialchars($row['product_name']) ?>"
               onerror="this.src='assets/no-image.png';">

          <div class="sold-overlay">ขายแล้ว</div>

          <div class="info">
            <h3><?= htmlspecialchars($row['product_name']) ?></h3>
            <p>ราคา: <?= number_format((float)$row['product_price'], 2) ?> บาท</p>
            <?php if (!empty($row['sold_at'])): ?>
              <div class="sold-meta">ปิดการขายเมื่อ: <?= htmlspecialchars($row['sold_at']) ?></div>
            <?php endif; ?>
            <a class="view-button disabled">ดูรายละเอียด</a>
          </div>
        </div>
        <?php
      }
    } else {
      echo '<p>ยังไม่มีรายการที่ปิดการขาย</p>';
    }
    ?>
  </div>
</div>

<script>
document.getElementById('menuToggle').addEventListener('click', function(){
  document.getElementById('navLinks').classList.toggle('active');
});
function pollUnread(){
  fetch('ChatApp/get_unread_total.php',{cache:'no-store'})
    .then(r=>r.json())
    .then(d=>{
      const n = Number(d.total||0);
      const b = document.getElementById('unreadBadge');
      if (!b) return;
      if (n>0){ b.textContent = n; b.style.display='inline-block'; }
      else { b.style.display='none'; }
    })
    .catch(()=>{});
}
pollUnread();
setInterval(pollUnread, 10000); // ทุก 10 วินาที
</script>

</body>
</html>
<?php
$stActive->close();
$stSold->close();
$conn->close();
