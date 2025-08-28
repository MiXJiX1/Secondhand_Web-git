<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../php/login.php"); exit(); }

$conn = new mysqli("localhost", "mix", "mix1234", "secondhand_web");
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }

$userId = (int)$_SESSION['user_id'];

/** คืนชื่อไฟล์รูปแรกจากฟิลด์ที่อาจเก็บได้หลายแบบ */
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

/* ดึงห้องแชท พร้อมสถานะสินค้า */
$sql = "SELECT m1.request_id, m1.message, m1.created_at,
               cr.product_id,
               p.product_name, p.product_image, p.status AS product_status, p.sold_at,
               u1.fname AS seller_fname, u1.lname AS seller_lname,
               u2.fname AS buyer_fname,  u2.lname AS buyer_lname
        FROM messages m1
        INNER JOIN (
          SELECT request_id, MAX(id) AS last_id
          FROM messages
          WHERE request_id IN (
            SELECT request_id FROM chat_requests
            WHERE seller_id = ? OR buyer_id = ?
          )
          GROUP BY request_id
        ) m2 ON m1.id = m2.last_id
        INNER JOIN chat_requests cr ON m1.request_id = cr.request_id
        LEFT JOIN products p ON cr.product_id = p.product_id
        LEFT JOIN users u1 ON cr.seller_id = u1.user_id
        LEFT JOIN users u2 ON cr.buyer_id  = u2.user_id
        ORDER BY m1.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$res = $stmt->get_result();

$chatList = [];
while ($row = $res->fetch_assoc()) {
    $sellerName = trim(($row['seller_fname'] ?? '') . ' ' . ($row['seller_lname'] ?? ''));
    $buyerName  = trim(($row['buyer_fname'] ?? '')  . ' ' . ($row['buyer_lname']  ?? ''));

    $firstImg = firstImageFromField($row['product_image'] ?? '');
    $imgSrc   = $firstImg ? "../uploads/".$firstImg : null;

    $isSold   = (isset($row['product_status']) && $row['product_status'] === 'sold');
    $soldText = $isSold && !empty($row['sold_at']) ? ('ขายเมื่อ ' . date("d/m/Y H:i", strtotime($row['sold_at']))) : '';

    $chatList[] = [
        'request_id'   => $row['request_id'],
        'product_id'   => $row['product_id'],
        'product_name' => $row['product_name'] ?: "ไม่ทราบชื่อสินค้า",
        'seller_name'  => $sellerName ?: "ไม่ทราบผู้ขาย",
        'buyer_name'   => $buyerName  ?: "ไม่ทราบผู้ซื้อ",
        'last_message' => (string)$row['message'],
        'last_time'    => date("d/m/Y H:i", strtotime($row['created_at'])),
        'image'        => $imgSrc,
        'is_sold'      => $isSold,
        'sold_text'    => $soldText
    ];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายการแชท</title>
<style>
  body { font-family: Arial, sans-serif; background:#f4f4f4; margin:0 }
  .topbar{display:flex;align-items:center;gap:12px;background:#ffcc00;padding:12px 16px;position:sticky;top:0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
  .back-btn{appearance:none;border:0;background:#000;color:#fff;padding:8px 14px;border-radius:999px;cursor:pointer;font-weight:600}
  .back-btn:hover{opacity:.9}
  .title{font-size:20px;font-weight:700;color:#000}

  .chat-list { max-width: 700px; margin: 20px auto; padding: 0 12px; }
  .chat-item { display:flex; align-items:center; background:#fff; border-radius:10px; padding:15px; margin-bottom:12px; box-shadow:0 2px 6px rgba(0,0,0,.08); transition:background .2s }
  .chat-item:hover{ background:#f9f9f9 }
  .chat-item a { text-decoration:none; color:inherit; display:flex; width:100%; align-items:center }

  .chat-avatar{ width:50px; height:50px; border-radius:50%; overflow:hidden; background:#ffcc00; color:#fff; font-weight:700; display:flex; align-items:center; justify-content:center; margin-right:15px }
  .chat-avatar img{ width:100%; height:100%; object-fit:cover; display:block }

  .chat-info{ flex:1; min-width:0 }
  .chat-title{ font-size:16px; font-weight:700; margin:0; color:#333; display:flex; align-items:center; gap:8px; flex-wrap:wrap }
  .chat-subtitle{ font-size:14px; color:#666; margin-top:4px; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical }
  .chat-time{ font-size:12px; color:#999; white-space:nowrap; margin-left:10px }

  /* ป้ายขายแล้ว + สไตล์เมื่อขายแล้ว */
  .tag-sold{ background:#ff4757; color:#fff; font-size:12px; font-weight:800; padding:2px 8px; border-radius:999px }
  .chat-item.sold .chat-avatar{ filter:grayscale(100%) }
  .chat-item.sold .chat-title{ color:#666 }
  .chat-item.sold .chat-subtitle{ color:#8a8a8a }
</style>
</head>
<body>

<div class="topbar">
  <button class="back-btn" onclick="goBack()">&larr; กลับ</button>
  <div class="title">รายการห้องแชทของคุณ</div>
</div>

<div class="chat-list">
<?php if (empty($chatList)): ?>
  <div style="text-align:center; padding:40px; color:#666; font-size:16px;">
    🛍️ ยังไม่มีห้องแชท <br>เริ่มพูดคุยและซื้อขายสินค้าผ่านแอพของเราได้เลย!
  </div>
<?php else: ?>
  <?php foreach ($chatList as $c): ?>
<div class="chat-item <?= $c['is_sold'] ? 'sold':'' ?>">
  <a
    class="chat-link"
    href="chat.php?request_id=<?= urlencode($c['request_id']) ?>&product_id=<?= (int)$c['product_id'] ?>"
    <?= $c['is_sold'] ? 'data-sold="1" aria-disabled="true"':'' ?>
  >
    <div class="chat-avatar">
      <?php if (!empty($c['image'])): ?>
        <img src="<?= htmlspecialchars($c['image']) ?>" alt="product"
             onerror="this.onerror=null;this.src='../assets/no-image.png'">
      <?php else: ?>
        <?= htmlspecialchars(mb_substr($c['product_name'], 0, 1)) ?>
      <?php endif; ?>
    </div>

    <div class="chat-info">
      <p class="chat-title">
        <?= htmlspecialchars($c['product_name']) ?>
        <?php if ($c['is_sold']): ?>
          <span class="tag-sold">ขายแล้ว</span>
        <?php endif; ?>
      </p>
      <p class="chat-subtitle">
        ผู้ขาย: <?= htmlspecialchars($c['seller_name']) ?><br>
        <?= htmlspecialchars(strip_tags($c['last_message'])) ?>
        <?php if ($c['is_sold'] && $c['sold_text']): ?><br><?= htmlspecialchars($c['sold_text']) ?><?php endif; ?>
      </p>
    </div>

    <div class="chat-time"><?= htmlspecialchars($c['last_time']) ?></div>
  </a>
</div>

  <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
function goBack(){
  if (history.length > 1) history.back();
  else location.href = "../index.php";
}
document.querySelector('.chat-list').addEventListener('click', function(e){
  const a = e.target.closest('a.chat-link');
  if (!a) return;
  if (a.dataset.sold === '1') {
    e.preventDefault();
    alert('สินค้าชิ้นนี้ได้ถูกขายแล้ว');
  }
});
</script>
</body>
</html>
