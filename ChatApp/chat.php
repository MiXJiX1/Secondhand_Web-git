<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

$conn = new mysqli("sczfile.online", "mix", "mix1234", "secondhand_web");
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$userId    = (int)$_SESSION['user_id'];
$requestId = isset($_GET['request_id']) ? trim($_GET['request_id']) : '';
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

$pdo = new PDO(
  "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
  "mix","mix1234",
  [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
);

if ($requestId === '' || !preg_match('/^[a-zA-Z0-9:_-]{6,64}$/', $requestId)) {
    die("รหัสห้องสนทนาไม่ถูกต้อง");
}

/* ==== ห้องนี้เป็นการแลกเปลี่ยนไหม? (รูปแบบ EXC-<item_id>-<offer_id>) ==== */
$isExchange = false;
$exItemId = 0; $exOfferId = 0;
if (preg_match('/^EXC-(\d+)-(\d+)$/', $requestId, $mm)) {
    $isExchange = true;
    $exItemId  = (int)$mm[1];
    $exOfferId = (int)$mm[2];
}

/* ==== ดึงสถานะออเดอร์ล่าสุดของผู้ใช้ในห้องนี้ (เฉพาะซื้อขายปกติ) ==== */
$isPaid = false; $isReleased = false;
if (!$isExchange && $productId > 0) {
  $st = $pdo->prepare("SELECT status FROM orders WHERE request_id=? AND product_id=? AND user_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$requestId,$productId,$userId]);
  $lastOrder = $st->fetch(PDO::FETCH_ASSOC);
  $isPaid     = $lastOrder && $lastOrder['status']==='paid';
  $isReleased = $lastOrder && $lastOrder['status']==='released';
}

/* ==== รู้ว่าเราเป็นผู้ซื้อ/ผู้ขาย จาก chat_requests (เฉพาะซื้อขายปกติ) ==== */
$isCurrentBuyer = false;
$sellerIdFromReq = 0;
$buyerIdFromReq  = 0;
if (!$isExchange && $requestId !== '') {
    $qr = $conn->prepare("SELECT seller_id,buyer_id FROM chat_requests WHERE request_id=? LIMIT 1");
    $qr->bind_param("s", $requestId);
    $qr->execute();
    if ($r = $qr->get_result()->fetch_assoc()) {
        $sellerIdFromReq = (int)$r['seller_id'];
        $buyerIdFromReq  = (int)$r['buyer_id'];
        $isCurrentBuyer  = ($userId === $buyerIdFromReq);
    }
    $qr->close();
}

/* ==== ชื่อสินค้า + ชื่อผู้ขาย (ไว้แสดงหัวแชท) ==== */
$productName = "ไม่พบสินค้า";
$sellerName  = "";
$productLink = ($productId > 0) ? ("../product_detail.php?id=" . $productId) : ""; // ปกติลิงก์ไปหน้าสินค้า

if (!$isExchange && $productId > 0) {
    $stmt = $conn->prepare("
        SELECT p.product_name, u.fname, u.lname
        FROM products p
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE p.product_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $productName = $row['product_name'];
        $sellerName  = trim(($row['fname'] ?? '').' '.($row['lname'] ?? ''));
    }
    $stmt->close();
}

/* ==== ดึง “ชื่อผู้ซื้อ” จาก request_id (เฉพาะซื้อขายปกติ) ==== */
$buyerName = "";
if (!$isExchange && $requestId !== '') {
    $qb = $conn->prepare("
        SELECT u.fname, u.lname
        FROM chat_requests cr
        LEFT JOIN users u ON u.user_id = cr.buyer_id
        WHERE cr.request_id = ?
        LIMIT 1
    ");
    $qb->bind_param("s", $requestId);
    $qb->execute();
    $br = $qb->get_result();
    if ($b = $br->fetch_assoc()) {
        $buyerName = trim(($b['fname'] ?? '').' '.($b['lname'] ?? ''));
    }
    $qb->close();
}

/* ==== Fallback: ห้องแชทจากการแลกเปลี่ยน -> ใช้ชื่อจาก exchange_items และลิงก์ไป exchange.php ==== */
if ($isExchange) {
    $stmtEx = $conn->prepare("
        SELECT ei.title, u.fname, u.lname
        FROM exchange_items ei
        LEFT JOIN users u ON u.user_id = ei.user_id
        WHERE ei.item_id = ?
        LIMIT 1
    ");
    $stmtEx->bind_param("i", $exItemId);
    $stmtEx->execute();
    $resEx = $stmtEx->get_result();
    if ($ex = $resEx->fetch_assoc()) {
        $productName = 'แลกเปลี่ยน: ' . ($ex['title'] ?? 'ไม่ระบุ');
        $sellerName  = trim(($ex['fname'] ?? '') . ' ' . ($ex['lname'] ?? ''));
        $productLink = "../exchange.php#item-" . $exItemId;
    }
    $stmtEx->close();
}

/* ==== ส่งข้อความ (AJAX) : รองรับห้องแลกเปลี่ยนและห้องปกติ ==== */
$isExchange = false; $excItemId = 0; $excOfferId = 0;
if ($productId === 0 && preg_match('/^EXC-(\d+)-(\d+)$/', $requestId, $mm)) {
    $isExchange = true;
    $excItemId  = (int)$mm[1];
    $excOfferId = (int)$mm[2];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['message']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $msg = trim($_POST['message'] ?? '');
    if ($msg !== "") {
        if ($isExchange) {
            // เขียนลง exchange_messages
            $stmtSend = $conn->prepare("
                INSERT INTO exchange_messages (request_id, item_id, offer_id, sender_id, message)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtSend->bind_param("siiis", $requestId, $excItemId, $excOfferId, $userId, $msg);
        } else {
            // ห้องปกติ เขียนลง messages
            $stmtSend = $conn->prepare("
                INSERT INTO messages (request_id, product_id, sender_id, message)
                VALUES (?, ?, ?, ?)
            ");
            $stmtSend->bind_param("siis", $requestId, $productId, $userId, $msg);
        }
        $stmtSend->execute();
        $stmtSend->close();
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แชท - <?= htmlspecialchars($productName) ?></title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f0f2f5; margin: 0; padding: 0; }
    .chat-container { max-width: 800px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); display: flex; flex-direction: column; height: 80vh; overflow: hidden; }
    .chat-header { display:flex; align-items:center; gap:12px; background: #ffcc00; color: #000; padding: 12px 16px; font-size: 18px; font-weight: bold; }
    .back-btn { appearance:none; border:0; background:#000; color:#fff; padding:8px 12px; border-radius:999px; cursor:pointer; font-weight:600; }
    .back-btn:hover { opacity:.9; }
    .chat-header a { color: #000; text-decoration: underline; margin-left:auto; }

    #chat-messages { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: #fff8e1; }
    .message { max-width: 70%; padding: 10px 14px; border-radius: 16px; word-wrap: break-word; line-height: 1.4; }
    .own { background: #ffcc00; color: #000; align-self: flex-end; border-bottom-right-radius: 4px; }
    .other { background: #f1f0f0; color: #333; align-self: flex-start; border-bottom-left-radius: 4px; }
    .fullname { font-size: 17px; font-weight: bold; margin-bottom: 4px; opacity: 0.9; }

    .message.system{ background: transparent; color: #777; align-self: center; font-size: 12px; padding: 2px 8px; border-radius: 6px; max-width: 90%; }

    .chat-form { display: flex; padding: 15px; border-top: 1px solid #ddd; background: #fff; gap: 10px; flex-wrap: wrap; }
    .chat-form input { flex: 1; min-width: 220px; padding: 10px 15px; border-radius: 20px; border: 1px solid #ccc; font-size: 14px; outline: none; }
    .chat-form button { padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 20px; cursor: pointer; font-weight: bold; }
    .chat-form button:hover { background: #218838; }

    /* MSUPAY Popup */
    #msuOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; }
    #msuPopup { background:#fff; border-radius:12px; padding:22px; width: min(420px, 92vw); box-shadow: 0 10px 30px rgba(0,0,0,.2); text-align:center; }
    #msuPopup img { max-width:220px; max-height:220px; width:auto; height:auto; border-radius:10px; display:block; margin:10px auto 8px; object-fit:cover; }
    #msuPopup .title { font-size:20px; font-weight:700; margin:2px 0 6px; }
    #msuPopup .price { margin:6px 0 12px; }
    #msuPopup input[type=password]{ width:100%; border:1px solid #ccc; border-radius:8px; padding:10px 12px; }
    #msuPopup .row { display:flex; gap:10px; justify-content:center; margin-top:14px; }
    #msuPopup button{ border:0; border-radius:8px; padding:10px 14px; color:#fff; cursor:pointer; font-weight:700; }
    #confirmPay{ background:#16a34a; }
    #cancelPay{ background:#dc2626; }

    /* Modal Map */
    #mapModal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2000; align-items:center; justify-content:center; }
    #mapModal.show{ display:flex; }
    #mapContainer{ width:min(820px,92vw); height:min(560px,80vh); background:#fff; border-radius:12px; box-shadow:0 16px 40px rgba(0,0,0,.25); overflow:hidden; display:flex; flex-direction:column; }
    #map{ flex:1; }
    #mapButtons{ padding:10px; background:#f8f8f8; display:flex; gap:10px; justify-content:flex-end; }
    #mapButtons button{ border:0; border-radius:8px; padding:8px 14px; cursor:pointer; color:#fff; font-weight:700; }
    #mapButtons .confirm{ background:#16a34a; }
    #mapButtons .cancel{  background:#dc2626; }
</style>
</head>
<body>

<div class="chat-container">
  <div class="chat-header">
      <button class="back-btn" onclick="goBack()">&larr; กลับ</button>
      <div style="font-weight:700;">แชท</div>
      <?php if (!empty($productLink)): ?>
        <a href="<?= htmlspecialchars($productLink) ?>" title="ดูหน้าสินค้า/โพสต์">
          <?= htmlspecialchars($productName) ?>
        </a>
      <?php else: ?>
        <span><?= htmlspecialchars($productName) ?></span>
      <?php endif; ?>
  </div>

  <div id="chat-messages">กำลังโหลด...</div>

  <form id="chat-form" class="chat-form">
      <input type="text" id="message" name="message" placeholder="พิมพ์ข้อความ..." required>
      <button type="submit">ส่ง</button>
      <button type="button" id="shareLocationBtn" style="background:#007bff;">แชร์ตำแหน่งของฉัน</button>

      <?php if (!$isExchange): ?>
        <button type="button" id="btnMSUPAY" style="background:#0ea5e9; color:white; padding:10px 20px; border:none; border-radius:8px;">จ่ายด้วย MSUPAY</button>
      <?php endif; ?>

      <?php if (!$isExchange && $isCurrentBuyer): ?>
        <button type="button" id="btnRelease" style="background:#10b981; color:#fff; padding:10px 20px; border:none; border-radius:8px;">
          ฉันได้รับสินค้าแล้ว
        </button>
      <?php endif; ?>
  </form>
</div>

<!-- MSUPAY Popup -->
<div id="msuOverlay">
  <div id="msuPopup">
    <div class="title">ยืนยันการชำระเงิน</div>
    <img id="msuImage" src="" alt="สินค้า">
    <div id="msuName" class="title" style="font-size:18px;"></div>
    <div class="price">ราคาสินค้า: <strong id="msuAmount"></strong> บาท</div>
    <input type="password" id="accountPassword" placeholder="กรอกรหัสผ่านบัญชี">
    <div class="row">
      <button id="confirmPay" type="button">ยืนยัน</button>
      <button id="cancelPay" type="button">ยกเลิก</button>
    </div>
  </div>
</div>

<!-- Modal Map -->
<div id="mapModal">
  <div id="mapContainer">
      <div id="map"></div>
      <div id="mapButtons">
          <button class="cancel" onclick="closeMap()">ยกเลิก</button>
          <button class="confirm" onclick="sendLocation()">ยืนยันตำแหน่ง</button>
      </div>
  </div>
</div>

<script>
const userId      = <?= json_encode($userId) ?>;
const requestId   = <?= json_encode($requestId) ?>;
const productId   = <?= json_encode($productId) ?>;
const sellerName  = <?= json_encode($sellerName) ?>;
const buyerName   = <?= json_encode($buyerName) ?>;
const productName = <?= json_encode($productName) ?>;

const isPaid      = <?= $isPaid ? 'true' : 'false' ?>;
const isReleased  = <?= $isReleased ? 'true' : 'false' ?>;

const chatBox       = document.getElementById("chat-messages");
const form          = document.getElementById("chat-form");
const messageInput  = document.getElementById("message");
const btnMSUPAY     = document.getElementById("btnMSUPAY");
const msuOverlay    = document.getElementById("msuOverlay");
const imgEl         = document.getElementById("msuImage");
const nameEl        = document.getElementById("msuName");
const amountEl      = document.getElementById("msuAmount");
const pwdEl         = document.getElementById("accountPassword");
const btnRelease    = document.getElementById("btnRelease");

function goBack() {
    if (history.length > 1) history.back();
    else window.location.href = 'chat_list.php';
}

// โหลดข้อความ
function loadMessages() {
  const url = `fetch_messages.php?request_id=${encodeURIComponent(requestId)}&product_id=${encodeURIComponent(productId)}&t=${Date.now()}`;
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" }, cache: "no-store" })
    .then(r => r.json())
    .then(data => {
      const rows = Array.isArray(data) ? data : [];
      chatBox.innerHTML = rows.map(m => {
        let msg = m.message || '';
        const isSystem = msg.startsWith('[SYS]');
        if (isSystem) msg = msg.replace(/^\[SYS\]\s*/, '');
        const whoClass = (m.sender_id == userId) ? 'own' : 'other';
        return isSystem
          ? `<div class="message system">${msg}</div>`
          : `<div class="message ${whoClass}">
               ${m.fullname ? `<div class="fullname">${m.fullname}</div>` : ''}
               ${msg}
             </div>`;
      }).join('');
      chatBox.scrollTop = chatBox.scrollHeight;
    })
    .catch(err => chatBox.innerHTML = 'โหลดข้อความล้มเหลว: ' + err.message);
}

form.addEventListener("submit", e => {
    e.preventDefault();
    const text = messageInput.value.trim();
    if (!text) return;
    const fd = new FormData();
    fd.append("message", text);
    fetch("chat.php?request_id=" + encodeURIComponent(requestId) + "&product_id=" + productId, {
        method: "POST",
        body: fd,
        headers: { "X-Requested-With": "XMLHttpRequest" }
    }).then(() => {
        messageInput.value = "";
        loadMessages();
    });
});

loadMessages();
setInterval(loadMessages, 3000);

/* ====== Share Location with Leaflet (Modal) ====== */
let map, marker, lat, lng;
document.getElementById("shareLocationBtn").addEventListener("click", () => {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            lat = pos.coords.latitude;
            lng = pos.coords.longitude;
            openMap(lat, lng);
        }, err => alert("ไม่สามารถดึงตำแหน่งได้: " + err.message));
    } else {
        alert("เบราว์เซอร์ของคุณไม่รองรับการแชร์ตำแหน่ง");
    }
});
function openMap(lat, lng) {
  const modal = document.getElementById("mapModal");
  modal.classList.add("show");

  if (!map) {
      map = L.map('map').setView([lat, lng], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', e => {
          const pos = e.target.getLatLng();
          lat = pos.lat; lng = pos.lng;
      });
  } else {
      map.setView([lat, lng], 15);
      marker.setLatLng([lat, lng]);
  }
}
function closeMap(){ document.getElementById("mapModal").classList.remove("show"); }
function sendLocation() {
    const locationUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
    const fd = new FormData();
    fd.append("message", "📍 ตำแหน่งของฉัน: <a href='" + locationUrl + "' target='_blank'>ดูแผนที่</a>");
    fetch("chat.php?request_id=" + encodeURIComponent(requestId) + "&product_id=" + productId, {
        method: "POST",
        body: fd,
        headers: { "X-Requested-With": "XMLHttpRequest" }
    }).then(() => { closeMap(); loadMessages(); });
}

/* ====== MSUPAY (ใช้เฉพาะซื้อขายปกติ) ====== */
if (btnMSUPAY) {
  btnMSUPAY.addEventListener('click', async () => {
    try{
      const res  = await fetch(`get_product_info.php?product_id=${productId}`);
      const data = await res.json();
      if (!data.ok) { alert(data.error || 'โหลดข้อมูลสินค้าไม่สำเร็จ'); return; }

      imgEl.src  = data.image || '';
      nameEl.textContent   = data.name || '';
      amountEl.textContent = (+data.price || 0).toFixed(2);
      pwdEl.value = '';
      msuOverlay.style.display = 'flex';
    }catch(e){ alert('เกิดข้อผิดพลาดในการโหลดข้อมูลสินค้า'); }
  });

  document.getElementById('confirmPay').addEventListener('click', async () => {
    const pwd = pwdEl.value.trim();
    if (!pwd) { alert('กรุณากรอกรหัสผ่าน'); return; }

    try {
      const nameInParen = productName
        ? (productName.trim().startsWith('(') ? productName : `(${productName})`)
        : '(สินค้า)';

      const sysText = `[SYS] ${(<?= json_encode($buyerName) ?> || 'ผู้ซื้อ')} ได้จ่ายด้วย MSUPAY ${nameInParen}`;
      const fdSys = new FormData();
      fdSys.append("message", sysText);
      await fetch("chat.php?request_id=" + encodeURIComponent(requestId) + "&product_id=" + productId, {
        method: "POST",
        body: fdSys,
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });
      loadMessages();

      const res = await fetch('msupay_create.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ request_id: requestId, product_id: productId, password: pwd })
      });
      const payData = await res.json();
      if (!payData.ok) { alert(payData.error || 'ชำระเงินล้มเหลว'); return; }
      window.location = payData.pay_url;
    } catch(e) {
      alert('เกิดข้อผิดพลาดในการสร้างคำสั่งชำระเงิน');
    }
  });

  document.getElementById('cancelPay').addEventListener('click', () => {
    msuOverlay.style.display = 'none';
  });

  if (isPaid) {
    btnMSUPAY.disabled = true;
    btnMSUPAY.textContent = 'จ่ายแล้ว';
    btnMSUPAY.style.opacity = .6;
  }
}

/* ====== ปุ่ม “ฉันได้รับสินค้าแล้ว” -> ปล่อยเงิน (เฉพาะซื้อขายปกติ) ====== */
if (btnRelease) {
  if (!isPaid || isReleased) {
    btnRelease.disabled = true;
    btnRelease.style.opacity = .6;
  }
  btnRelease.addEventListener('click', async () => {
    if (!confirm('ยืนยันว่าคุณได้รับสินค้าเรียบร้อย และต้องการโอนเงินให้ผู้ขาย?')) return;
    try{
      const res = await fetch('release_escrow.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ request_id: requestId, product_id: productId })
      });
      const data = await res.json();
      if (!data.ok) { alert(data.error || 'ปล่อยเงินไม่สำเร็จ'); return; }
      btnRelease.disabled = true;
      btnRelease.style.opacity = .6;
      alert('โอนเงินให้ผู้ขายเรียบร้อย');
      loadMessages();
    }catch(e){
      alert('เกิดข้อผิดพลาดขณะปล่อยเงิน');
    }
  });
}
</script>
</body>
</html>
