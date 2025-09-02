<?php
session_start();

$servername = "";
$username   = "";
$password   = "";
$dbname     = "";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($product_id <= 0) { http_response_code(400); exit("รหัสสินค้าไม่ถูกต้อง"); }

/* ---- ดึงข้อมูลสินค้า + ผู้ขาย + สถานะขายแล้ว ---- */
$sql = "SELECT p.product_id, p.product_name, p.product_price, p.product_image,
               p.category, p.description, p.status, p.sold_at,
               p.user_id AS owner_id, u.fname, u.lname
        FROM products p
        LEFT JOIN users u ON p.user_id = u.user_id
        WHERE p.product_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { echo "ไม่พบสินค้านี้"; exit(); }
$product = $res->fetch_assoc();
$stmt->close();

$buyerId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isOwner  = ($buyerId > 0 && $buyerId === (int)$product['owner_id']);
$isSold   = (isset($product['status']) && $product['status'] === 'sold');
$soldText = ($isSold && !empty($product['sold_at'])) ? ('ขายเมื่อ '.date('d/m/Y H:i', strtotime($product['sold_at']))) : '';

$requestId = null;

/* ---- เตรียมห้องแชท (ถ้าล็อกอิน, ไม่ใช่เจ้าของ และยังไม่ขาย) ---- */
if ($buyerId > 0 && !$isOwner && !$isSold) {
    $q = $conn->prepare("SELECT request_id FROM chat_requests
                         WHERE seller_id = ? AND buyer_id = ? AND product_id = ?
                         LIMIT 1");
    $sellerId = (int)$product['owner_id'];
    $q->bind_param("iii", $sellerId, $buyerId, $product_id);
    $q->execute();
    $rq = $q->get_result();
    if ($row = $rq->fetch_assoc()) {
        $requestId = $row['request_id'];
    } else {
        $requestId = uniqid();
        $ins = $conn->prepare("INSERT INTO chat_requests (request_id, seller_id, buyer_id, product_id)
                               VALUES (?, ?, ?, ?)");
        $ins->bind_param("siii", $requestId, $sellerId, $buyerId, $product_id);
        $ins->execute();
        $ins->close();
    }
    $q->close();
}
$conn->close();

/* ---------- Helpers: แปลงฟิลด์รูปให้เป็น array ---------- */
function allImagesFromField(?string $s): array {
    if (!$s) return [];
    $s = trim($s);

    // JSON array
    if ($s !== '' && $s[0] === '[') {
        $arr = json_decode($s, true);
        if (is_array($arr)) {
            return array_values(array_filter(array_map(fn($x)=>basename((string)$x), $arr)));
        }
    }

    // คั่นด้วย , ; |
    $parts = preg_split('/[|,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts && count($parts) > 0) {
        return array_values(array_filter(array_map(fn($x)=>basename(trim($x)), $parts)));
    }

    // รูปเดียว
    return [basename($s)];
}

/* เตรียม URLs สำหรับสไลด์ */
$rawImgs = allImagesFromField($product['product_image']);
if (empty($rawImgs)) { $rawImgs = ['assets/no-image.png']; }
$imgUrls = array_map(function($fn){
    if (strpos($fn, 'assets/') === 0) return $fn;
    return 'uploads/' . $fn;
}, $rawImgs);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($product['product_name']) ?> | รายละเอียดสินค้า</title>
<style>
    :root{
        --brand:#ffcc00; --ink:#333; --muted:#666; --bg:#f8f9fa; --shadow:0 6px 18px rgba(0,0,0,.08);
        --primary:#1f7aec; --primaryHover:#0f5fc0;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--ink)}
    header{background:var(--brand);padding:16px;display:flex;justify-content:center;align-items:center}
    header h1{margin:0;font-size:22px;font-weight:800}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
    .grid{display:grid;grid-template-columns:520px 1fr;gap:24px;padding:24px}
    @media (max-width: 900px){ .grid{grid-template-columns:1fr} }

    /* --- Slider --- */
    .media{position:relative;display:flex;align-items:center;justify-content:center}
    .slider-frame{
        width:100%;max-height:520px;border-radius:12px;overflow:hidden;
        box-shadow:var(--shadow);background:#fafafa;display:flex;align-items:center;justify-content:center
    }
    .slider-frame img{width:100%;height:100%;object-fit:cover;display:block}
    .nav-btn{
        position:absolute;top:50%;transform:translateY(-50%);
        background:rgba(0,0,0,.55); color:#fff; border:none; width:40px; height:40px;
        border-radius:50%; cursor:pointer; font-size:18px; font-weight:800;
        display:flex; align-items:center; justify-content:center;
    }
    .nav-btn:hover{background:rgba(0,0,0,.75)}
    .prev{left:10px} .next{right:10px}
    .dots{position:absolute;bottom:10px;left:0;right:0; display:flex; gap:6px; justify-content:center}
    .dot{
        width:9px;height:9px;border-radius:50%;background:rgba(255,255,255,.6);border:1px solid rgba(0,0,0,.15);
        cursor:pointer;
    }
    .dot.active{background:#fff;border-color:#fff}

    /* --- Text/Badge --- */
    .title{font-size:24px;margin:0 0 8px 0;font-weight:800}
    .meta{color:var(--muted);margin:4px 0 10px 0}
    .price{font-size:22px;font-weight:800;margin:8px 0}
    .seller{font-weight:700}
    .desc{line-height:1.75;white-space:pre-wrap;margin-top:10px}
    .actions{margin-top:18px;display:flex;gap:12px;flex-wrap:wrap}
    .btn{
        display:inline-block;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:800;
        transition:.25s;border:none;cursor:pointer;font-size:14px
    }
    .btn-chat{background:var(--primary);color:#fff}
    .btn-chat:hover{background:var(--primaryHover)}
    .btn-login{background:var(--primary);color:#fff}
    .btn-login:hover{background:var(--primaryHover)}
    .btn-back{background:#1f1f1f;color:#fff}
    .btn-back:hover{background:#000}
    .badge{
        display:inline-block;background:#fff3bf;color:#8d6b00;border:1px solid #ffe08a;
        padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700
    }
    .panel{background:#fff;border-radius:12px;padding:16px;box-shadow:var(--shadow);margin-top:12px}

    /* --- Sold UI --- */
    .sold-ribbon{
        position:absolute;top:12px;left:12px;
        background:#ff4757;color:#fff;font-weight:900;font-size:12px;
        padding:6px 12px;border-radius:999px;box-shadow:0 2px 6px rgba(0,0,0,.12)
    }
    .sold-tag{
        background:#ff4757;color:#fff;font-weight:900;font-size:12px;
        padding:4px 10px;border-radius:999px;margin-left:8px
    }
    .btn-sold{
        background:#bdbdbd;color:#fff;cursor:not-allowed
    }
</style>
</head>
<body>
<header><h1>รายละเอียดสินค้า</h1></header>

<div class="wrap">
    <div class="card">
        <div class="grid">
            <!-- ====== Image Slider ====== -->
            <div class="media">
                <?php if ($isSold): ?>
                  <div class="sold-ribbon">ขายแล้ว</div>
                <?php endif; ?>
                <div class="slider-frame">
                    <img id="sliderImage" src="<?= htmlspecialchars($imgUrls[0]) ?>"
                         alt="<?= htmlspecialchars($product['product_name']) ?>"
                         onerror="this.src='assets/no-image.png';">
                </div>

                <?php if (count($imgUrls) > 1): ?>
                    <button class="nav-btn prev" id="btnPrev" aria-label="ภาพก่อนหน้า">‹</button>
                    <button class="nav-btn next" id="btnNext" aria-label="ภาพถัดไป">›</button>
                    <div class="dots" id="dots"></div>
                <?php endif; ?>
            </div>

            <!-- ====== Right content ====== -->
            <div>
                <div class="badge"><?= htmlspecialchars($product['category'] ?: 'ไม่ระบุหมวดหมู่') ?></div>
                <h2 class="title">
                  <?= htmlspecialchars($product['product_name']) ?>
                  <?php if ($isSold): ?><span class="sold-tag" title="<?= htmlspecialchars($soldText) ?>">ขายแล้ว</span><?php endif; ?>
                </h2>
                <div class="meta">ผู้ขาย: <span class="seller">
                    <?= htmlspecialchars(trim(($product['fname'] ?? '').' '.($product['lname'] ?? ''))) ?: 'ไม่ระบุชื่อ' ?>
                </span></div>
                <div class="price">ราคา: <?= number_format((float)$product['product_price'], 2) ?> บาท</div>

                <?php if ($isSold && $soldText): ?>
                  <div class="meta"><?= htmlspecialchars($soldText) ?></div>
                <?php endif; ?>

                <div class="panel desc"><?= nl2br(htmlspecialchars($product['description'] ?? '')) ?></div>

                <div class="actions">
                    <?php if ($buyerId <= 0): ?>
                        <a class="btn btn-login" href="login.php">เข้าสู่ระบบเพื่อแชท</a>
                    <?php elseif (!$isOwner && !$isSold): ?>
                        <a class="btn btn-chat"
                           href="../ChatApp/chat.php?request_id=<?= urlencode($requestId) ?>&product_id=<?= (int)$product['product_id'] ?>">
                           ติดต่อผู้ขาย
                        </a>
                    <?php elseif ($isSold && !$isOwner): ?>
                        <a href="#" class="btn btn-sold" onclick="alert('สินค้าชิ้นนี้ได้ถูกขายแล้ว'); return false;">สินค้าชิ้นนี้ถูกขายแล้ว</a>
                    <?php endif; ?>
                    <a class="btn btn-back" href="../index.php">กลับไปหน้าหลัก</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $jsImgs = json_encode(array_values($imgUrls), JSON_UNESCAPED_SLASHES); ?>
<script>
(function(){
  const images = <?= $jsImgs ?>;
  if (!images || images.length <= 1) return;
  const imgEl = document.getElementById('sliderImage');
  const prev  = document.getElementById('btnPrev');
  const next  = document.getElementById('btnNext');
  const dotsC = document.getElementById('dots');
  let idx = 0;

  const dots = images.map((_, i) => {
    const d = document.createElement('div');
    d.className = 'dot' + (i===0 ? ' active' : '');
    d.addEventListener('click', () => go(i));
    dotsC.appendChild(d);
    return d;
  });

  function render(){ imgEl.src = images[idx]; dots.forEach((d,i)=>d.classList.toggle('active', i===idx)); }
  function go(i){ idx = (i + images.length) % images.length; render(); }
  prev && prev.addEventListener('click', () => go(idx-1));
  next && next.addEventListener('click', () => go(idx+1));

  let touchX = null;
  imgEl.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, {passive:true});
  imgEl.addEventListener('touchend', e => {
    if (touchX === null) return;
    const dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 40) { dx < 0 ? go(idx + 1) : go(idx - 1); }
    touchX = null;
  }, {passive:true});

  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') go(idx - 1);
    if (e.key === 'ArrowRight') go(idx + 1);
  });
})();
</script>
</body>
</html>
