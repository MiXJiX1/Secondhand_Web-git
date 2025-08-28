<?php
/* feedback.php — ให้คะแนน/รายงานผู้ใช้ (ดึงผู้ขายจากคำสั่งซื้อของเรา) */
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$DB_HOST='sczfile.online';
$DB_USER='mix';
$DB_PASS='mix1234';
$DB_NAME='secondhand_web';

$pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

$userId = (int)$_SESSION['user_id'];
$tab    = ($_GET['tab'] ?? 'rate') === 'report' ? 'report' : 'rate';

/* ---------- สร้างตารางถ้ายังไม่มี ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_ratings(
  rating_id INT AUTO_INCREMENT PRIMARY KEY,
  rater_id  INT NOT NULL,
  rated_user_id INT NOT NULL,
  order_id  INT NULL,
  product_id INT NULL,
  score     TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
  comment   TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_once (rater_id, rated_user_id, order_id),
  INDEX(rated_user_id),
  INDEX(rater_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS user_reports(
  report_id INT AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT NOT NULL,
  reported_user_id INT NOT NULL,
  reason ENUM('fraud','fake','offensive','spam','other') NOT NULL DEFAULT 'other',
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('open','reviewing','done') NOT NULL DEFAULT 'open',
  INDEX(reporter_id), INDEX(reported_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_fb'])) $_SESSION['csrf_fb']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_fb'];

/* ---------- ส่งฟอร์ม: ให้คะแนน ---------- */
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && hash_equals($csrf, $_POST['csrf'] ?? '')) {
  if ($_POST['action']==='rate') {
    $rated = (int)($_POST['rated_user_id'] ?? 0);
    $order = (int)($_POST['order_id'] ?? 0);
    $prod  = (int)($_POST['product_id'] ?? 0);
    $score = (int)($_POST['score'] ?? 0);
    $cmt   = trim($_POST['comment'] ?? '');

    if ($rated<=0 || $order<=0 || $score<1 || $score>5) {
      $msg = 'ข้อมูลไม่ครบ';
    } else {
      // ป้องกันกดคะแนนมั่ว: ตรวจว่า order นี้เป็นของเราจริง และผู้ขายคือ $rated
      $q = $pdo->prepare("
        SELECT o.id, p.user_id AS seller_id
        FROM orders o
        JOIN products p ON p.product_id=o.product_id
        WHERE o.id=? AND o.user_id=? AND o.status IN ('paid','released','completed')
        LIMIT 1
      ");
      $q->execute([$order,$userId]);
      $ok = $q->fetch(PDO::FETCH_ASSOC);

      if (!$ok || (int)$ok['seller_id'] !== $rated) {
        $msg = 'ไม่พบคำสั่งซื้อที่ตรงกับผู้ขาย';
      } else {
        // ให้คะแนน (ถ้าเคยให้แล้วตาม unique จะ error ให้จับแล้วแสดงข้อความ)
        try {
          $ins = $pdo->prepare("INSERT INTO user_ratings(rater_id,rated_user_id,order_id,product_id,score,comment) VALUES (?,?,?,?,?,?)");
          $ins->execute([$userId,$rated,$order,$prod,$score,$cmt]);
          $msg = 'ให้คะแนนสำเร็จ ✅';
        } catch (Throwable $e) {
          $msg = 'คุณให้คะแนนรายการนี้ไปแล้ว';
        }
      }
    }
  }

  /* ---------- ส่งฟอร์ม: รายงาน ---------- */
  if ($_POST['action']==='report') {
    $reported = (int)($_POST['reported_user_id'] ?? 0);
    $reason   = $_POST['reason'] ?? 'other';
    $details  = trim($_POST['details'] ?? '');
    if (!$reported || !in_array($reason,['fraud','fake','offensive','spam','other'],true)) {
      $msg='ข้อมูลรายงานไม่ครบ';
    } else {
      $ins = $pdo->prepare("INSERT INTO user_reports(reporter_id,reported_user_id,reason,details) VALUES (?,?,?,?)");
      $ins->execute([$userId,$reported,$reason,$details]);
      $msg='ส่งรายงานแล้ว ✅';
    }
  }
}

/* ---------- ดึง “ผู้ขายที่เราเคยซื้อ” ---------- */
$buyersSellers = [];  // รายการผู้ขาย + ออร์เดอร์ของเรา
$st = $pdo->prepare("
  SELECT 
    o.id AS order_id,
    o.product_id,
    u.user_id AS seller_id,
    CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS seller_name,
    p.product_name
  FROM orders o
  JOIN products p ON p.product_id=o.product_id
  JOIN users u ON u.user_id=p.user_id
  WHERE o.user_id=? AND o.status IN ('paid','released','completed')
  ORDER BY o.id DESC
");
$st->execute([$userId]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  $key = $r['seller_id'];
  if (!isset($buyersSellers[$key])) {
    $buyersSellers[$key] = [
      'seller_id'   => (int)$r['seller_id'],
      'seller_name' => $r['seller_name'] ?: ('ผู้ใช้ #'.$r['seller_id']),
      'orders'      => []
    ];
  }
  $buyersSellers[$key]['orders'][] = [
    'order_id'    => (int)$r['order_id'],
    'product_id'  => (int)$r['product_id'],
    'product_name'=> $r['product_name']
  ];
}

/* ---------- ค่าเฉลี่ยที่ผู้ใช้รายนั้นได้รับ ---------- */
$avgRatingByUser = [];
if ($buyersSellers) {
  $ids = implode(',', array_fill(0,count($buyersSellers),'?'));
  $q = $pdo->prepare("SELECT rated_user_id, AVG(score) as avg_score, COUNT(*) as cnt FROM user_ratings WHERE rated_user_id IN ($ids) GROUP BY rated_user_id");
  $q->execute(array_keys($buyersSellers));
  while ($a = $q->fetch(PDO::FETCH_ASSOC)) {
    $avgRatingByUser[(int)$a['rated_user_id']] = [
      'avg' => round((float)$a['avg_score'],2),
      'cnt' => (int)$a['cnt']
    ];
  }
}

/* ---------- รายงานที่เราเคยส่ง และคะแนนที่เราเคยให้ ---------- */
$myReports = $pdo->prepare("
  SELECT r.*, CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS reported_name
  FROM user_reports r
  JOIN users u ON u.user_id=r.reported_user_id
  WHERE r.reporter_id=? ORDER BY r.report_id DESC LIMIT 50
");
$myReports->execute([$userId]);
$myReports = $myReports->fetchAll(PDO::FETCH_ASSOC);

$myRatings = $pdo->prepare("
  SELECT a.*, CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS rated_name
  FROM user_ratings a
  JOIN users u ON u.user_id=a.rated_user_id
  WHERE a.rater_id=? ORDER BY a.rating_id DESC LIMIT 50
");
$myRatings->execute([$userId]);
$myRatings = $myRatings->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ให้คะแนน / รายงานผู้ใช้</title>
<style>
:root{--brand:#ffcc00;--ink:#222;--muted:#777;--bg:#f7f7f7;--shadow:0 6px 18px rgba(0,0,0,.08)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:var(--ink)}
header{background:var(--brand);padding:12px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between}
h1{margin:0;font-size:18px;font-weight:800}
a.btn,button.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:0;background:#111;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
a.btn:hover,button.btn:hover{background:#000}
.tabs{display:flex;gap:8px}
.tab{padding:8px 12px;border-radius:999px;background:#fff8cc;cursor:pointer;text-decoration:none;color:#111;font-weight:800}
.tab.active{background:#111;color:#fff}
.container{max-width:1000px;margin:18px auto;padding:0 16px}
.panel{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:16px;margin-bottom:16px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:760px){.row{grid-template-columns:1fr}}
input[type=text],textarea,select{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px}
textarea{min-height:90px;resize:vertical}
.small{font-size:12px;color:var(--muted)}
.star input{display:none}
.star label{font-size:22px;cursor:pointer;color:#ddd}
.star input:checked ~ label, .star label:hover, .star label:hover ~ label{color:#ffb703}
.list{display:grid;gap:10px}
.item{padding:10px;border:1px solid #eee;border-radius:10px}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#ffe9a8;margin-left:6px}
footer{padding:20px 0;color:#aaa;text-align:center}
</style>
</head>
<body>

<header>
  <h1>ให้คะแนน / รายงานผู้ใช้</h1>
  <div class="tabs">
    <a class="tab <?= $tab==='rate'?'active':'' ?>" href="feedback.php?tab=rate">ให้คะแนน</a>
    <a class="tab <?= $tab==='report'?'active':'' ?>" href="feedback.php?tab=report">รายงานผู้ใช้</a>
  </div>
  <a class="btn" href="../index.php">กลับหน้าแรก</a>
</header>

<div class="container">
  <?php if($msg): ?>
    <div class="panel" style="background:#e7fff1;border:1px solid #86efac;color:#065f46"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if ($tab==='rate'): ?>
    <div class="panel">
      <h3 style="margin-top:0">ผู้ขายที่คุณเคยซื้อ</h3>
      <?php if(!$buyersSellers): ?>
        <div class="small">ยังไม่มีคำสั่งซื้อสำเร็จ จึงยังไม่สามารถให้คะแนนได้</div>
      <?php else: ?>
        <form method="post" style="display:grid;gap:12px">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="rate">

          <div class="row">
            <div>
              <label>เลือกผู้ขาย</label>
              <select name="rated_user_id" id="rated_user_id" required>
                <option value="">— เลือกผู้ขาย —</option>
                <?php foreach($buyersSellers as $sid=>$info):
                  $avg = $avgRatingByUser[$sid]['avg'] ?? null;
                  $cnt = $avgRatingByUser[$sid]['cnt'] ?? 0;
                  ?>
                  <option value="<?= (int)$sid ?>">
                    <?= htmlspecialchars($info['seller_name']) ?>
                    <?= $cnt? ' (★'.number_format($avg,2).' / '.$cnt.' รีวิว)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>เลือกรายการสั่งซื้อ</label>
              <select name="order_id" id="order_id" required>
                <option value="">— เลือกจากสินค้า —</option>
                <?php foreach($buyersSellers as $sid=>$info): ?>
                  <?php foreach($info['orders'] as $o): ?>
                    <option value="<?= (int)$o['order_id'] ?>" data-seller="<?= (int)$sid ?>" data-product="<?= (int)$o['product_id'] ?>">
                      #<?= (int)$o['order_id'] ?> — <?= htmlspecialchars($o['product_name']) ?>
                    </option>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="product_id" id="product_id">
              <div class="small">** ระบบจะกรองออร์เดอร์ตามผู้ขายที่เลือกอัตโนมัติ</div>
            </div>
          </div>

          <div>
            <label>ให้คะแนน</label>
            <div class="star" style="display:flex;gap:4px;flex-direction:row-reverse;justify-content:flex-end">
              <input type="radio" id="s5" name="score" value="5" required><label for="s5">★</label>
              <input type="radio" id="s4" name="score" value="4"><label for="s4">★</label>
              <input type="radio" id="s3" name="score" value="3"><label for="s3">★</label>
              <input type="radio" id="s2" name="score" value="2"><label for="s2">★</label>
              <input type="radio" id="s1" name="score" value="1"><label for="s1">★</label>
            </div>
          </div>

          <div>
            <label>ความคิดเห็น (ถ้ามี)</label>
            <textarea name="comment" placeholder="เช่น สินค้าตรงปก ส่งไว บริการดี"></textarea>
          </div>

          <div style="text-align:right">
            <button class="btn">บันทึกคะแนน</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3 style="margin:0 0 10px 0">คะแนนที่คุณเคยให้ล่าสุด</h3>
      <?php if(!$myRatings): ?>
        <div class="small">ยังไม่มีข้อมูล</div>
      <?php else: ?>
        <div class="list">
          <?php foreach($myRatings as $r): ?>
            <div class="item">
              <div><b><?= htmlspecialchars($r['rated_name'] ?: ('ผู้ใช้ #'.$r['rated_user_id'])) ?></b>
                <span class="badge">★<?= (int)$r['score'] ?></span>
              </div>
              <?php if($r['comment']): ?>
                <div class="small" style="margin-top:6px"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
              <?php endif; ?>
              <div class="small" style="margin-top:6px">เมื่อ <?= htmlspecialchars($r['created_at']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php else: /* report */ ?>

    <div class="panel">
      <h3 style="margin-top:0">รายงานผู้ใช้</h3>
      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="report">

        <div class="row">
          <div>
            <label>ระบุผู้ใช้ที่ต้องการรายงาน</label>
            <select name="reported_user_id" required>
              <option value="">— เลือกผู้ขายจากที่คุณเคยซื้อ (แนะนำ) —</option>
              <?php foreach($buyersSellers as $sid=>$info): ?>
                <option value="<?= (int)$sid ?>"><?= htmlspecialchars($info['seller_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small">หากต้องการรายงานผู้ใช้อื่น สามารถใส่รหัสผู้ใช้แทนได้</div>
            <input type="text" name="reported_user_id_manual" placeholder="หรือกรอกรหัสผู้ใช้... (ตัวเลข)">
          </div>
          <div>
            <label>สาเหตุ</label>
            <select name="reason" required>
              <option value="fraud">ฉ้อโกง/โกงเงิน</option>
              <option value="fake">สินค้าปลอม/ไม่ตรงปก</option>
              <option value="offensive">ข้อความไม่เหมาะสม</option>
              <option value="spam">สแปม/รบกวน</option>
              <option value="other">อื่น ๆ</option>
            </select>
          </div>
        </div>

        <div>
          <label>รายละเอียดเพิ่มเติม</label>
          <textarea name="details" placeholder="เล่าเหตุการณ์โดยย่อ (ถ้ามี)"></textarea>
        </div>

        <div style="text-align:right">
          <button class="btn">ส่งรายงาน</button>
        </div>
      </form>
    </div>

    <div class="panel">
      <h3 style="margin:0 0 10px 0">รายงานที่คุณเคยส่ง</h3>
      <?php if(!$myReports): ?>
        <div class="small">ยังไม่มีข้อมูล</div>
      <?php else: ?>
        <div class="list">
          <?php foreach($myReports as $rp): ?>
            <div class="item">
              <div><b><?= htmlspecialchars($rp['reported_name'] ?: ('ผู้ใช้ #'.$rp['reported_user_id'])) ?></b>
                <span class="badge"><?= htmlspecialchars($rp['reason']) ?></span>
                <span class="badge">สถานะ: <?= htmlspecialchars($rp['status']) ?></span>
              </div>
              <?php if($rp['details']): ?>
                <div class="small" style="margin-top:6px"><?= nl2br(htmlspecialchars($rp['details'])) ?></div>
              <?php endif; ?>
              <div class="small" style="margin-top:6px">เมื่อ <?= htmlspecialchars($rp['created_at']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<footer class="small">MSU Marketplace — feedback module</footer>

<script>
/* กรอง dropdown ออร์เดอร์ตามผู้ขายที่เลือก + เติม product_id ซ่อน */
const ratedSel  = document.getElementById('rated_user_id');
const orderSel  = document.getElementById('order_id');
const productId = document.getElementById('product_id');

function filterOrders(){
  if (!ratedSel || !orderSel) return;
  const seller = ratedSel.value;
  for (const opt of orderSel.options){
    if (!opt.value) { opt.hidden=false; continue; }
    opt.hidden = (String(opt.dataset.seller)!==String(seller));
  }
  orderSel.value='';
  productId.value='';
}
ratedSel && ratedSel.addEventListener('change', filterOrders);
orderSel && orderSel.addEventListener('change', ()=>{
  const opt = orderSel.selectedOptions[0];
  productId.value = opt ? (opt.dataset.product || '') : '';
});
filterOrders();

/* ถ้าใส่รหัสผู้ใช้เองในหน้า report ให้แทนค่าจากช่องเลือก */
const manual = document.querySelector('input[name="reported_user_id_manual"]');
if (manual){
  manual.addEventListener('input', ()=>{
    const v = manual.value.trim();
    const sel = document.querySelector('select[name="reported_user_id"]');
    if (/^\d+$/.test(v)){ sel.value=''; sel.disabled=true; }
    else { sel.disabled=false; }
  });
}
</script>
</body>
</html>
