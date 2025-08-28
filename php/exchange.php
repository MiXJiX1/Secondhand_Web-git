<?php
/* exchange.php — หน้าแลกเปลี่ยนสินค้า (ฟอร์มอยู่ใน Modal + ปุ่มแชทหลังถูกยอมรับ + แจ้งเตือน) */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/* ===== DB ===== */
$DB_HOST = 'sczfile.online';
$DB_USER = 'mix';
$DB_PASS = 'mix1234';
$DB_NAME = 'secondhand_web';

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
  );
} catch (Throwable $e) { die('เชื่อมต่อฐานข้อมูลล้มเหลว: '.$e->getMessage()); }

/* ===== USER ===== */
$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username = $_SESSION['username'] ?? null;

/* ===== ตารางแจ้งเตือน (มีครั้งเดียวก็พอ) ===== */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS exchange_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    offer_id INT NOT NULL,
    type ENUM('offer_created') NOT NULL DEFAULT 'offer_created',
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(item_id), INDEX(offer_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- AJAX แจ้งเตือน (ต้องมาก่อนส่ง HTML) ---------- */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  $ajax = $_GET['ajax'];

  if (!$userId) {
    echo json_encode($ajax === 'notify_count' ? ['count'=>0] : ['items'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($ajax === 'notify_count') {
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM exchange_notifications WHERE user_id=? AND is_read=0");
    $st->execute([$userId]);
    $c  = (int)$st->fetchColumn();
    echo json_encode(['count'=>$c], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($ajax === 'notify_list') {
    $st = $pdo->prepare("SELECT notification_id,item_id,offer_id,message,is_read,created_at
                         FROM exchange_notifications
                         WHERE user_id=?
                         ORDER BY created_at DESC, notification_id DESC
                         LIMIT 30");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['items'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($ajax === 'notify_mark' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = $pdo->prepare("UPDATE exchange_notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $ok = $st->execute([$userId]);
    echo json_encode(['ok'=>$ok], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['ok'=>false, 'error'=>'unknown ajax'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_ex'])) $_SESSION['csrf_ex'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_ex'];

/* ===== CATEGORIES ===== */
$CATS = ['electronics','fashion','furniture','vehicle','gameandtoys','household','sport','music','others'];

/* ===== Helper: รูปแรก ===== */
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

/* ===== ACTIONS (ไม่แก้ลอจิกเดิม เพิ่มเติมเฉพาะสร้างแชท) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_ex'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF token ไม่ถูกต้อง'); }
  $action = $_POST['action'] ?? '';

  /* A) สร้างโพสต์แลกสินค้า */
  if ($action === 'create' && $userId > 0) {
    $title     = trim($_POST['title'] ?? '');
    $category  = trim($_POST['category'] ?? '');
    $want_text = trim($_POST['want_text'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $cond      = trim($_POST['condition_text'] ?? '');
    $location  = trim($_POST['location'] ?? '');

    if ($title==='' || $want_text==='' || $category==='') {
      $err = 'กรอกข้อมูลให้ครบ (ชื่อสินค้า / หมวดหมู่ / ต้องการแลก)';
    } elseif (!in_array($category, $CATS, true)) {
      $err = 'หมวดหมู่ไม่ถูกต้อง';
    } else {
      $uploadDir = __DIR__ . '/uploads';
      if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
      $saved = [];
      if (!empty($_FILES['images']['name'][0])) {
        $allow = ['image/jpeg','image/png','image/webp','image/gif'];
        foreach ($_FILES['images']['name'] as $i => $name) {
          if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
          $tmp  = $_FILES['images']['tmp_name'][$i];
          $type = @mime_content_type($tmp);
          if (!in_array($type, $allow, true)) continue;
          $ext  = pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg';
          $fn   = 'ex_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.strtolower($ext);
          if (move_uploaded_file($tmp, $uploadDir.'/'.$fn)) $saved[] = $fn;
        }
      }
      $imagesStr = $saved ? json_encode($saved, JSON_UNESCAPED_SLASHES) : null;

      $st = $pdo->prepare("
        INSERT INTO exchange_items (user_id, title, category, want_text, description, condition_text, images, location)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $st->execute([$userId, $title, $category, $want_text, $desc, $cond, $imagesStr, $location]);
      header("Location: exchange.php?ok=1"); exit;
    }
  }

  /* B) เสนอแลก */
  if ($action === 'offer' && $userId > 0) {
    $item_id    = (int)($_POST['item_id'] ?? 0);
    $offer_text = trim($_POST['offer_text'] ?? '');
    $q = $pdo->prepare("SELECT item_id,user_id,status FROM exchange_items WHERE item_id=? LIMIT 1");
    $q->execute([$item_id]);
    $item = $q->fetch(PDO::FETCH_ASSOC);

    if (!$item) $err='ไม่พบรายการแลกเปลี่ยนนี้';
    elseif ($offer_text==='') $err='กรุณากรอกสิ่งที่คุณอยากเสนอแลก';
    elseif ((int)$item['user_id']===$userId) $err='ไม่สามารถเสนอแลกกับสินค้าของตนเองได้';
    elseif ($item['status']!=='available') $err='รายการนี้ไม่พร้อมสำหรับการแลกแล้ว';
    else {
      $st = $pdo->prepare("INSERT INTO exchange_offers (item_id, seller_id, offer_user_id, offer_text) VALUES (?, ?, ?, ?)");
      $st->execute([$item_id, (int)$item['user_id'], $userId, $offer_text]);

      /* แจ้งเตือนเจ้าของโพสต์ */
      $offerId = (int)$pdo->lastInsertId();
      $display = $username ?: ("ผู้ใช้ #".$userId);
      if (function_exists('mb_strimwidth')) {
        $snippet = mb_strimwidth($offer_text, 0, 80, '…', 'UTF-8');
      } else {
        $snippet = (strlen($offer_text)>80)?(substr($offer_text,0,77).'...'):$offer_text;
      }
      $msg = $display.' ส่งคำขอแลก: '.$snippet;
      $nt = $pdo->prepare("INSERT INTO exchange_notifications (user_id,item_id,offer_id,type,message) VALUES (?,?,?,?,?)");
      $nt->execute([(int)$item['user_id'], $item_id, $offerId, 'offer_created', $msg]);

      header("Location: exchange.php?offer_ok=1#item-$item_id"); exit;
    }
  }

  /* C) อัปเดตสถานะโพสต์ */
  if ($action === 'update_status' && $userId > 0) {
    $item_id  = (int)($_POST['item_id'] ?? 0);
    $status   = trim($_POST['status'] ?? '');
    $offer_id = isset($_POST['offer_id']) ? (int)$_POST['offer_id'] : null;

    $q = $pdo->prepare("SELECT item_id,user_id FROM exchange_items WHERE item_id=? LIMIT 1");
    $q->execute([$item_id]);
    $it = $q->fetch(PDO::FETCH_ASSOC);
    if (!$it || (int)$it['user_id'] !== $userId) { $err='คุณไม่มีสิทธิ์จัดการรายการนี้'; }
    else {
      if ($status==='swapped') {
        $pdo->prepare("UPDATE exchange_items SET status='swapped' WHERE item_id=?")->execute([$item_id]);
        if ($offer_id) {
          $pdo->prepare("UPDATE exchange_offers SET status='accepted', responded_at=NOW() WHERE offer_id=?")->execute([$offer_id]);
          $pdo->prepare("UPDATE exchange_offers SET status='declined', responded_at=NOW() WHERE item_id=? AND offer_id<>? AND status='pending'")
              ->execute([$item_id,$offer_id]);

          /* ⬇⬇⬇ สร้างห้องแชทอัตโนมัติให้คู่ดีลนี้ */
          $of = $pdo->prepare("SELECT offer_user_id, seller_id FROM exchange_offers WHERE offer_id=? LIMIT 1");
          $of->execute([$offer_id]);
          if ($row = $of->fetch(PDO::FETCH_ASSOC)) {
            $buyerId  = (int)$row['offer_user_id']; // คนที่ขอแลก
            $sellerId = (int)$row['seller_id'];     // เจ้าของโพสต์
            $reqId    = "EXC-{$item_id}-{$offer_id}"; // request_id

            // chat_requests
            $pdo->exec("
              CREATE TABLE IF NOT EXISTS chat_requests (
                request_id VARCHAR(64) PRIMARY KEY,
                buyer_id INT NOT NULL,
                seller_id INT NOT NULL,
                product_id INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $ins = $pdo->prepare("INSERT IGNORE INTO chat_requests (request_id,buyer_id,seller_id,product_id) VALUES (?,?,?,0)");
            $ins->execute([$reqId, $buyerId, $sellerId]);

            // messages (ใช้ created_at)
            $pdo->exec("
              CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(64) NOT NULL,
                product_id INT NOT NULL DEFAULT 0,
                sender_id INT NOT NULL,
                message MEDIUMTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX(request_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $sys = $pdo->prepare("INSERT INTO messages (request_id,product_id,sender_id,message) VALUES (?,?,?,?)");
            $sys->execute([$reqId, 0, $sellerId, "[SYS] ผู้ขายยอมรับข้อเสนอแล้ว เริ่มแชทตกลงรายละเอียดได้เลย"]);
          }
          /* ⬆⬆⬆ จบสร้างห้องแชท */
        }
      } elseif (in_array($status,['cancelled','pending','available'],true)) {
        $pdo->prepare("UPDATE exchange_items SET status=? WHERE item_id=?")->execute([$status,$item_id]);
      }
      header("Location: exchange.php?upd=1#item-$item_id"); exit;
    }
  }
}

/* ===== FILTER/LIST ===== */
$kw       = trim($_GET['q'] ?? '');
$cat      = trim($_GET['cat'] ?? '');
$onlyMine = isset($_GET['mine']) ? 1 : 0;
$stat     = trim($_GET['stat'] ?? '');

$sql = "SELECT * FROM exchange_items WHERE 1=1";
$A = [];
if ($kw!==''){ $sql.=" AND (title LIKE ? OR want_text LIKE ?)"; $A[]="%$kw%"; $A[]="%$kw%"; }
if ($cat!=='' && in_array($cat,$CATS,true)){ $sql.=" AND category=?"; $A[]=$cat; }
if ($onlyMine && $userId>0){ $sql.=" AND user_id=?"; $A[]=$userId; }
if ($stat!=='' && in_array($stat,['available','pending','swapped','cancelled'],true)){ $sql.=" AND status=?"; $A[]=$stat; }
$sql.=" ORDER BY item_id DESC LIMIT 60";

$st = $pdo->prepare($sql);
$st->execute($A);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

/* โหลดข้อเสนอทั้งหมดของไอเท็มที่กำลังแสดง (เจ้าของไว้ดู) */
$allIds = array_column($items, 'item_id');
$offersByItem = [];
if ($allIds) {
  $in = implode(',', array_fill(0,count($allIds),'?'));
  $s2 = $pdo->prepare("SELECT * FROM exchange_offers WHERE item_id IN ($in) ORDER BY offer_id DESC");
  $s2->execute($allIds);
  while ($o = $s2->fetch(PDO::FETCH_ASSOC)) $offersByItem[$o['item_id']][]=$o;
}

/* ✅ ดึงข้อเสนอของ "เรา" เพื่อตรวจว่าถูกยอมรับหรือยัง (ไว้แสดงปุ่มแชทให้ผู้เสนอ) */
$myOffersByItem = [];
if ($userId && $allIds) {
  $in = implode(',', array_fill(0,count($allIds),'?'));
  $m  = $pdo->prepare("SELECT item_id, offer_id, status FROM exchange_offers WHERE offer_user_id=? AND item_id IN ($in)");
  $m->execute(array_merge([$userId], $allIds));
  while ($r = $m->fetch(PDO::FETCH_ASSOC)) $myOffersByItem[$r['item_id']] = $r;
}

/* Toast */
$toast = '';
if (isset($_GET['ok']))       $toast='ประกาศเรียบร้อย 🎉';
if (isset($_GET['offer_ok'])) $toast='ส่งข้อเสนอแล้ว ✅';
if (isset($_GET['upd']))      $toast='อัปเดตสถานะสำเร็จ ✅';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แลกเปลี่ยนสินค้า</title>
<style>
:root{--brand:#ffcc00;--ink:#333;--muted:#666;--bg:#f7f7f7;--shadow:0 6px 18px rgba(0,0,0,.08)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:'Segoe UI',Tahoma,sans-serif;color:var(--ink)}

/* --- HEADER: center title, left back button, right bell --- */
header{
  background:var(--brand);
  padding:14px 18px;
  display:grid;
  grid-template-columns:auto 1fr auto;
  align-items:center;
  column-gap:16px;
  box-shadow:0 2px 6px rgba(0,0,0,.06)
}
header .title{
  text-align:center;
  font-size:20px;
  font-weight:800;
  margin:0;
}
a.btn,button.btn{
  display:inline-block;padding:10px 14px;border-radius:10px;border:0;background:#1f1f1f;color:#fff;text-decoration:none;font-weight:800;cursor:pointer
}
a.btn:hover,button.btn:hover{background:#000}

/* layout */
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.panel{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:16px;margin-bottom:18px}

/* --- GRID: center cards --- */
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(260px, 300px));
  gap:16px;
  justify-content:center;   /* จัดคอลัมน์ให้อยู่กลาง */
}

/* card */
.card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:var(--shadow)}
.card .thumb{height:180px;background:#fafafa;display:flex;align-items:center;justify-content:center}
.card .thumb img{width:100%;height:100%;object-fit:cover}
.card .body{padding:12px}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#fff3bf;border:1px solid #ffe08a;color:#8d6b00;font-weight:800;font-size:12px;margin-right:6px}
.status{font-size:12px;color:#777;margin-top:4px}
.card .actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.btn2{padding:8px 12px;border:0;border-radius:8px;background:var(--brand);font-weight:800;cursor:pointer}
.btn2.alt{background:#0ea5e9;color:#fff}
.btn2.danger{background:#ef4444;color:#fff}
.btn2.gray{background:#e5e7eb;color:#111}

/* form */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-row>div{display:flex;flex-direction:column}
input[type=text],select,textarea{padding:10px;border:1px solid #ddd;border-radius:10px}
textarea{min-height:90px;resize:vertical}
hr{border:0;border-top:1px dashed #e5e5e5;margin:12px 0}
.small{font-size:12px;color:#777}

/* Modal */
.modal{display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:999; align-items:center; justify-content:center;}
.modal.show{display:flex;}
.modal-box{width:min(720px,92vw); background:#fff; color:#111; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;}
.modal-head{display:flex; align-items:center; justify-content:space-between; padding:14px 16px; background:#fff8d6; border-bottom:1px solid #fde68a}
.modal-body{padding:16px}
.modal-close{appearance:none; border:0; background:#111; color:#fff; border-radius:8px; padding:8px 10px; cursor:pointer}
.modal-close:hover{opacity:.9}

/* Bell */
.ex-bell{position:relative;cursor:pointer;margin-left:8px}
.ex-bell svg{width:22px;height:22px;vertical-align:middle}
.ex-badge{position:absolute;top:-6px;right:-8px;background:#ef4444;color:#fff;border-radius:999px;padding:0 6px;font-size:11px;font-weight:800}
.ex-pop{position:fixed;right:16px;top:58px;width:320px;max-height:70vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.18);padding:10px;display:none;z-index:1000}
.ex-item{padding:8px;border-radius:8px}
.ex-item:hover{background:#f6f7f8}
.ex-item small{color:#666;display:block}
.ex-pop .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.ex-pop button{border:0;background:#0ea5e9;color:#fff;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer}

/* CTA */
.cta-bar{display:flex; align-items:center; justify-content:space-between; gap:10px}
.cta-note{color:#666}

/* responsive tweak: ฟอร์ม 1 คอลัมน์บนจอเล็ก */
@media (max-width:640px){
  .form-row{grid-template-columns:1fr}
}
</style>
</head>
<body>

<header>
  <!-- ซ้าย: ปุ่มกลับหน้าแรก -->
  <a class="btn" href="../index.php">กลับหน้าแรก</a>

  <!-- กลาง: ไตเติลอยู่กลางจริงด้วย grid -->
  <h1 class="title">แลกเปลี่ยนสินค้า</h1>

  <!-- ขวา: กระดิ่งแจ้งเตือน (แสดงเมื่อ login) -->
  <div>
    <?php if($userId): ?>
      <div class="ex-bell" id="exBell" title="แจ้งเตือน">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0"/></svg>
        <span class="ex-badge" id="exCnt"></span>
      </div>
    <?php endif; ?>
  </div>
</header>

<?php if($userId): ?>
  <div class="ex-pop" id="exPop">
    <div class="top"><b>การแจ้งเตือน</b><button id="exMark">อ่านแล้ว</button></div>
    <div id="exList"></div>
  </div>
<?php endif; ?>

<div class="container">

  <?php if($toast): ?>
    <div class="panel" style="background:#e7fff1;border:1px solid #86efac;color:#065f46"><?= htmlspecialchars($toast) ?></div>
  <?php endif; ?>

  <!-- ฟิลเตอร์ -->
  <div class="panel">
    <form method="get" class="form-row" style="grid-template-columns:2fr 1fr 1fr auto;">
      <input type="text" name="q" placeholder="ค้นหาชื่อ/สิ่งที่ต้องการแลก..." value="<?= htmlspecialchars($kw) ?>">
      <select name="cat">
        <option value="">ทุกหมวดหมู่</option>
        <?php foreach($CATS as $c): ?><option value="<?= $c ?>" <?= $cat===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
      </select>
      <select name="stat">
        <option value="">ทุกสถานะ</option>
        <?php foreach(['available'=>'พร้อมแลก','pending'=>'ระหว่างตกลง','swapped'=>'ปิดการแลก','cancelled'=>'ยกเลิก'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $stat===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <label style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="mine" value="1" <?= $onlyMine?'checked':'' ?>>
        <span class="small">แสดงเฉพาะของฉัน</span>
      </label>
      <button class="btn2" type="submit">ค้นหา</button>
    </form>
  </div>

  <!-- CTA เปิดป็อปอัป -->
  <div class="panel">
    <div class="cta-bar">
      <div class="cta-note">ประกาศสิ่งที่อยากแลก พร้อมแนบรูปสวย ๆ</div>
      <?php if(!$userId): ?>
        <a class="btn" href="login.php">เข้าสู่ระบบเพื่อโพสต์</a>
      <?php else: ?>
        <button class="btn2" type="button" id="openPost">+ โพสต์แลกสินค้า</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- รายการโพสต์ -->
  <div class="grid">
    <?php if(!$items): ?>
      <div class="panel">ยังไม่มีรายการ</div>
    <?php else: foreach ($items as $it):
      $imgs   = firstImageFromField($it['images'] ?? '');
      $src    = $imgs ? 'uploads/'.htmlspecialchars($imgs) : 'assets/no-image.png';
      $isOwner = $userId>0 && $userId==(int)$it['user_id'];
      $offers  = $offersByItem[$it['item_id']] ?? [];
      $mine    = $myOffersByItem[$it['item_id']] ?? null; // ✅ ข้อเสนอของเรา ถ้ามี
    ?>
      <div class="card" id="item-<?= (int)$it['item_id'] ?>">
        <div class="thumb"><img src="<?= $src ?>" onerror="this.src='assets/no-image.png'"></div>
        <div class="body">
          <div>
            <span class="badge"><?= htmlspecialchars($it['category']) ?></span>
            <span class="badge" style="background:#d1fae5;border-color:#a7f3d0;color:#065f46">
              <?= htmlspecialchars($it['status']) ?>
            </span>
          </div>
          <h4 style="margin:8px 0 6px 0"><?= htmlspecialchars($it['title']) ?></h4>
          <div class="small">อยากแลกกับ: <strong><?= htmlspecialchars($it['want_text']) ?></strong></div>
          <?php if(!empty($it['condition_text'])): ?><div class="small">สภาพ: <?= htmlspecialchars($it['condition_text']) ?></div><?php endif; ?>
          <?php if(!empty($it['location'])): ?><div class="small">นัดรับ: <?= htmlspecialchars($it['location']) ?></div><?php endif; ?>
          <?php if(!empty($it['description'])): ?><hr><div class="small"><?= nl2br(htmlspecialchars($it['description'])) ?></div><?php endif; ?>

          <div class="actions">
            <?php
            // ✅ ถ้าเราเป็น "ผู้เสนอ" และข้อเสนอเราโดนยอมรับ ให้ขึ้นปุ่มแชท
            if ($userId && !$isOwner && $mine && $mine['status']==='accepted') {
              $reqId = 'EXC-'.$it['item_id'].'-'.$mine['offer_id'];
              echo '<a class="btn2 alt" href="ChatApp/chat.php?request_id='.urlencode($reqId).'&product_id=0">แชทกับผู้ขาย</a>';
            }
            ?>

            <?php if($userId && !$isOwner && $it['status']==='available' && (!$mine || $mine['status']!=='accepted')): ?>
              <details>
                <summary class="btn2 alt" style="list-style:none;cursor:pointer">เสนอแลก</summary>
                <form method="post" style="margin-top:8px">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="offer">
                  <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                  <input type="text" name="offer_text" placeholder="คุณอยากเสนอแลกอะไร?" required>
                  <div style="margin-top:6px"><button class="btn2 alt" type="submit">ส่งข้อเสนอ</button></div>
                </form>
              </details>
            <?php endif; ?>

            <?php if($isOwner): ?>
              <details>
                <summary class="btn2 gray" style="list-style:none;cursor:pointer">จัดการสถานะ</summary>
                <form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                  <button class="btn2 gray"  name="status" value="available" type="submit">เปิดให้แลก</button>
                  <button class="btn2"       name="status" value="pending"   type="submit">ระหว่างตกลง</button>
                  <button class="btn2 danger" name="status" value="cancelled" type="submit">ยกเลิก</button>
                </form>
              </details>
            <?php endif; ?>
          </div>

          <?php if($isOwner && $offers): ?>
            <hr>
            <div class="small" style="font-weight:700;margin-bottom:6px">ข้อเสนอที่ได้รับ</div>
            <?php foreach($offers as $o): ?>
              <div class="panel" style="padding:10px;margin:6px 0">
                <div class="small">จากผู้ใช้ #<?= (int)$o['offer_user_id'] ?> | สถานะ: <b><?= htmlspecialchars($o['status']) ?></b></div>
                <div style="margin:6px 0"><?= htmlspecialchars($o['offer_text']) ?></div>
                <?php if($it['status']!=='swapped' && $o['status']==='pending'): ?>
                  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                    <input type="hidden" name="offer_id" value="<?= (int)$o['offer_id'] ?>">
                    <button class="btn2 alt" name="status" value="swapped"  type="submit">ยอมรับ & ปิดการแลก</button>
                    <button class="btn2 danger" name="status" value="cancelled" type="submit">ยกเลิกโพสต์</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Modal ลงประกาศ -->
<?php if($userId): ?>
<div class="modal" id="postModal" aria-hidden="true">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="postTitle">
    <div class="modal-head">
      <h3 id="postTitle" style="margin:0;font-size:18px;font-weight:800;color:#111">ลงประกาศแลกสินค้า</h3>
      <button class="modal-close" type="button" id="closePost">ปิด</button>
    </div>
    <div class="modal-body">
      <?php if(!empty($err)): ?>
        <div style="color:#b91c1c;background:#ffe4e6;border:1px solid #fecdd3;padding:10px;border-radius:10px;margin-bottom:10px">
          <?= htmlspecialchars($err) ?>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" id="postForm" style="display:grid;gap:10px">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-row">
          <div><label>ชื่อสินค้า</label><input type="text" name="title" required></div>
          <div>
            <label>หมวดหมู่</label>
            <select name="category" required>
              <option value="">เลือก</option>
              <?php foreach($CATS as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div><label>สภาพสินค้า</label><input type="text" name="condition_text" placeholder="เช่น ใหม่มาก/มีรอยเล็กน้อย"></div>
          <div><label>สถานที่สะดวกนัดรับ</label><input type="text" name="location" placeholder="เช่น หน้าอาคารเรียนรวม"></div>
        </div>

        <div class="form-row">
          <div><label>อยากแลกกับ</label><input type="text" name="want_text" required placeholder="สิ่งที่อยากได้ในการแลก"></div>
          <div><label>อัปโหลดรูป (เลือกได้หลายไฟล์)</label><input type="file" name="images[]" multiple accept="image/*"></div>
        </div>

        <label>รายละเอียด</label>
        <textarea name="description" placeholder="รายละเอียดเพิ่มเติม"></textarea>

        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn2 gray" id="cancelPost">ยกเลิก</button>
          <button type="submit" class="btn2">ลงประกาศแลก</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const openBtn  = document.getElementById('openPost');
  const modal    = document.getElementById('postModal');
  const btnClose = document.getElementById('closePost');
  const btnCancel= document.getElementById('cancelPost');
  const firstInp = () => modal?.querySelector('input[name="title"]');

  function openModal(){ if(!modal) return; modal.classList.add('show'); document.body.style.overflow='hidden'; setTimeout(()=>{ try{ firstInp()?.focus(); }catch(e){} }, 50); }
  function closeModal(){ if(!modal) return; modal.classList.remove('show'); document.body.style.overflow=''; }

  openBtn && openBtn.addEventListener('click', openModal);
  btnClose && btnClose.addEventListener('click', closeModal);
  btnCancel && btnCancel.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeModal(); });

  <?php if(!empty($err) && $userId): ?> openModal(); <?php endif; ?>
})();
</script>

<?php if($userId): ?>
<script>
// ===== แจ้งเตือน (เรียก AJAX ในไฟล์เดียวกัน) =====
const exBell = document.getElementById('exBell');
const exCnt  = document.getElementById('exCnt');
const exPop  = document.getElementById('exPop');
const exList = document.getElementById('exList');
const exMark = document.getElementById('exMark');

async function exRefreshCnt(){
  try{
    const r = await fetch('exchange.php?ajax=notify_count',{cache:'no-store'});
    const j = await r.json();
    exCnt.textContent = (j.count>0) ? j.count : '';
  }catch(e){}
}
async function exOpen(){
  const r = await fetch('exchange.php?ajax=notify_list',{cache:'no-store'});
  const j = await r.json();
  exList.innerHTML = (j.items||[]).map(x =>
    `<div class="ex-item">
       <a href="exchange.php#item-${x.item_id}" onclick="document.getElementById('exPop').style.display='none'">
         ${x.message}
       </a>
       <small>${x.created_at}${x.is_read==0?' · ใหม่':''}</small>
     </div>`
  ).join('') || '<div class="ex-item">ไม่มีแจ้งเตือน</div>';
  exPop.style.display = 'block';
}
exBell && exBell.addEventListener('click', ()=> exPop.style.display==='block' ? exPop.style.display='none' : exOpen());
exMark && exMark.addEventListener('click', async ()=>{
  await fetch('exchange.php?ajax=notify_mark',{method:'POST'});
  exPop.style.display='none';
  exRefreshCnt();
});
setInterval(exRefreshCnt, 15000);
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) exRefreshCnt(); });
exRefreshCnt();
</script>
<?php endif; ?>

</body>
</html>
