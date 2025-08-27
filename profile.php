<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header("Location: login.php"); exit; }

$pdo = new PDO(
  "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
  "mix","mix1234",
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]
);

/* -------------------------------------------
   0) ตารางคะแนน (ถ้ายังไม่มีให้สร้าง)
-------------------------------------------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_ratings(
  rating_id INT AUTO_INCREMENT PRIMARY KEY,
  rater_id INT NOT NULL,
  rated_user_id INT NOT NULL,
  order_id INT NOT NULL,
  product_id INT NULL,
  score TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
  comment TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_once (rater_id,rated_user_id,order_id),
  INDEX(rated_user_id), INDEX(rater_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* -------------------------------------------
   1) อัปโหลด/เปลี่ยนรูปโปรไฟล์
-------------------------------------------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
  try {
    $qOld = $pdo->prepare("SELECT img FROM users WHERE user_id=? LIMIT 1");
    $qOld->execute([$user_id]);
    $oldImg = (string)($qOld->fetchColumn() ?: '');

    if (!isset($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
      throw new RuntimeException("ไม่พบไฟล์อัปโหลด");
    }

    $allowedExt = ['jpg','jpeg','png','webp','gif'];
    $maxBytes   = 2*1024*1024; // 2MB
    $tmp        = $_FILES['avatar']['tmp_name'];
    $size       = (int)$_FILES['avatar']['size'];
    $ext        = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext,$allowedExt,true)) throw new RuntimeException("อัปโหลดได้เฉพาะ JPG/PNG/WebP/GIF เท่านั้น");
    if ($size > $maxBytes) throw new RuntimeException("ไฟล์ใหญ่เกินไป (จำกัด 2MB)");

    $dir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($dir)) { mkdir($dir, 0777, true); }

    $newName = 'u_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir.$newName)) throw new RuntimeException("อัปโหลดรูปไม่สำเร็จ");

    $u = $pdo->prepare("UPDATE users SET img=? WHERE user_id=?");
    $u->execute([$newName, $user_id]);

    if ($oldImg && is_file($dir.$oldImg)) @unlink($dir.$oldImg);
    $flash = ['type'=>'success', 'msg'=>'อัปเดตรูปโปรไฟล์เรียบร้อย'];
  } catch (Throwable $e) {
    $flash = ['type'=>'danger', 'msg'=>$e->getMessage()];
  }
}

/* -------------------------------------------
   1.1) ให้คะแนนผู้ขาย (เรทดาว)
-------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_seller') {
  try {
    $ratedUser = (int)($_POST['rated_user_id'] ?? 0);
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $score     = (int)($_POST['score'] ?? 0);
    $comment   = trim($_POST['comment'] ?? '');

    if ($ratedUser<=0 || $orderId<=0 || $score<1 || $score>5) {
      throw new RuntimeException('ข้อมูลให้คะแนนไม่ครบ');
    }

    // ยืนยันว่าออเดอร์เป็นของเรา และผู้ขายของสินค้าในออเดอร์นั้นตรงกับ $ratedUser
    $q = $pdo->prepare("
      SELECT o.id, o.user_id AS buyer_id, p.user_id AS seller_id
      FROM orders o
      JOIN products p ON p.product_id=o.product_id
      WHERE o.id=? AND o.user_id=? AND o.status IN ('paid','released','completed')
      LIMIT 1
    ");
    $q->execute([$orderId,$user_id]);
    $row = $q->fetch();
    if (!$row || (int)$row['seller_id'] !== $ratedUser) {
      throw new RuntimeException('ออเดอร์ไม่ตรงกับผู้ขาย');
    }

    // บันทึกคะแนน (กันซ้ำด้วย UNIQUE)
    $ins = $pdo->prepare("INSERT INTO user_ratings(rater_id,rated_user_id,order_id,product_id,score,comment) VALUES (?,?,?,?,?,?)");
    $ins->execute([$user_id,$ratedUser,$orderId,$productId,$score,$comment]);

    $flash = ['type'=>'success','msg'=>'ให้คะแนนสำเร็จ ✅'];
  } catch (Throwable $e) {
    $flash = ['type'=>'danger','msg'=>$e->getMessage()];
  }
}

/* -------------------------------------------
   2) ขอถอนเครดิต
-------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
  $minAmount = 20.00; $fee = 0.00;
  $amount   = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
  $bankName = trim($_POST['bank_name'] ?? '');
  $bankNo   = preg_replace('/\D+/', '', $_POST['bank_account'] ?? '');
  $accName  = trim($_POST['account_name'] ?? '');

  try {
    if ($amount <= 0) throw new RuntimeException('กรุณากรอกจำนวนเงินให้ถูกต้อง');
    if ($amount < $minAmount) throw new RuntimeException('ขั้นต่ำในการถอนคือ '.number_format($minAmount,2).' บาท');
    if ($bankName==='' || $bankNo==='' || $accName==='') throw new RuntimeException('กรุณากรอกข้อมูลบัญชีให้ครบ');

    $pdo->beginTransaction();

    $q = $pdo->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
    $q->execute([$user_id]);
    $currentBal = (float)$q->fetchColumn();

    $totalDeduct = $amount + $fee;
    if ($currentBal < $totalDeduct) {
      throw new RuntimeException('เครดิตไม่พอสำหรับการถอน (ต้องใช้ '.number_format($totalDeduct,2).' บาท)');
    }

    $ref = 'WD' . strtoupper(bin2hex(random_bytes(6)));

    $pdo->prepare("
      INSERT INTO credit_withdrawals(user_id, amount, bank_name, bank_account, account_name, status, ref_txn)
      VALUES(?,?,?,?,?,'requested',?)
    ")->execute([$user_id, $amount, $bankName, $bankNo, $accName, $ref]);

    $pdo->prepare("
      INSERT INTO credit_ledger(user_id, change_amt, reason, ref_id)
      VALUES(?, ?, 'withdraw', ?)
    ")->execute([$user_id, -$totalDeduct, $ref]);

    // $pdo->prepare("UPDATE users SET credit_balance = credit_balance - ? WHERE user_id=?")->execute([$totalDeduct, $user_id]);

    $pdo->commit();
    $flash = ['type'=>'success', 'msg'=>'ส่งคำขอถอนเครดิตเรียบร้อย เราจะโอนเข้าบัญชีที่ระบุ'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $flash = ['type'=>'danger', 'msg'=>$e->getMessage()];
  }
}

/* -------------------------------------------
   3) ดึงข้อมูลผู้ใช้ + เครดิต + เรทที่ได้รับ
-------------------------------------------- */
$u = $pdo->prepare("SELECT username, fname, lname, role, credit_balance, img FROM users WHERE user_id=? LIMIT 1");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) { header("Location: login.php"); exit; }

$credit     = (float)$user['credit_balance'];
$avatarPath = $user['img'] ? ('uploads/avatars/'.basename($user['img'])) : 'assets/no-avatar.png';

$avgQ = $pdo->prepare("SELECT ROUND(AVG(score),2) AS avg_score, COUNT(*) AS cnt FROM user_ratings WHERE rated_user_id=?");
$avgQ->execute([$user_id]);
$avgRow = $avgQ->fetch() ?: ['avg_score'=>0,'cnt'=>0];
$avgScore = (float)$avgRow['avg_score']; $avgCnt=(int)$avgRow['cnt'];

$recvQ = $pdo->prepare("
  SELECT r.score, r.comment, r.created_at,
         CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS rater_name
  FROM user_ratings r
  LEFT JOIN users u ON u.user_id=r.rater_id
  WHERE r.rated_user_id=?
  ORDER BY r.rating_id DESC
  LIMIT 5
");
$recvQ->execute([$user_id]);
$recentReceived = $recvQ->fetchAll();

/* -------------------------------------------
   4) ประวัติการซื้อ (ชำระแล้ว 5 รายการ) + เตรียมข้อมูลให้คะแนน
-------------------------------------------- */
$po = $pdo->prepare("
  SELECT o.id AS order_id, o.order_no, o.amount, o.paid_at,
         p.product_id, p.product_name, p.product_image,
         p.user_id AS seller_id,
         CONCAT(COALESCE(s.fname,''),' ',COALESCE(s.lname,'')) AS seller_name
  FROM orders o
  LEFT JOIN products p ON p.product_id = o.product_id
  LEFT JOIN users s ON s.user_id = p.user_id
  WHERE o.user_id = ? AND o.status IN ('paid','released','completed')
  ORDER BY COALESCE(o.paid_at, o.created_at) DESC
  LIMIT 5
");
$po->execute([$user_id]);
$purchases = $po->fetchAll();

// ออเดอร์ที่เรา “เคยให้คะแนนแล้ว”
$ratedMap = [];
$rr = $pdo->prepare("SELECT order_id FROM user_ratings WHERE rater_id=?");
$rr->execute([$user_id]);
while ($x = $rr->fetch()) $ratedMap[(int)$x['order_id']] = true;

/* -------------------------------------------
   5) Topup ล่าสุด 5 รายการ
-------------------------------------------- */
$t = $pdo->prepare("
  SELECT topup_id, amount, status, created_at, approved_at
  FROM credit_topups
  WHERE user_id = ?
  ORDER BY topup_id DESC
  LIMIT 5
");
$t->execute([$user_id]);
$topups = $t->fetchAll();

/* -------------------------------------------
   6) Withdrawal ล่าสุด 5 รายการ
-------------------------------------------- */
$wd = $pdo->prepare("
  SELECT withdraw_id, amount, bank_name, bank_account, account_name, status, created_at, processed_at, ref_txn
  FROM credit_withdrawals
  WHERE user_id=?
  ORDER BY withdraw_id DESC
  LIMIT 5
");
$wd->execute([$user_id]);
$withdraws = $wd->fetchAll();

/* -------------------------------------------
   Helper
-------------------------------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>โปรไฟล์ของฉัน</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f8f9fa}
    .topbar{display:flex;align-items:center;gap:12px;background:#ffcc00;padding:12px 16px;position:sticky;top:0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
    .back-btn{appearance:none;border:0;background:#000;color:#fff;padding:8px 14px;border-radius:999px;cursor:pointer;font-weight:600}
    .title{font-size:20px;font-weight:700;color:#000}
    .profile-box{background:#fff;padding:30px;border-radius:12px;box-shadow:0 0 15px rgba(0,0,0,.05)}
    .credit-box{background:#eafaf1;padding:20px;border-radius:10px}
    .back-button{display:inline-block;background:#333;color:#fff;padding:10px 15px;border-radius:5px;text-decoration:none;font-weight:700}
    .avatar-wrap{display:flex;align-items:center;gap:16px;margin-bottom:10px}
    .avatar{width:84px;height:84px;border-radius:999px;object-fit:cover;border:1px solid #e6e6e6;background:#fafafa}
    .btn-file{border:1px solid #e3e3e3;background:#fff;border-radius:10px;padding:8px 12px;font-weight:700}
    .help{font-size:12px;color:#888}
    .badge.pending{background:#ffe58f;color:#7a5d00}
    .badge.approved{background:#b7eb8f;color:#135200}
    .badge.rejected{background:#ffccc7;color:#820014}
    .badge.requested{background:#e6f4ff;color:#0b60b0}
    .badge.paid{background:#d1f7c4;color:#0f5132}
    .thumb{width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #eee;background:#fafafa}
    /* stars */
    .stars{display:inline-flex;gap:2px;vertical-align:middle}
    .stars .s{font-size:18px;color:#ffd166}
    .stars .g{color:#e5e7eb}
    .rate-stars input{display:none}
    .rate-stars label{font-size:26px;cursor:pointer;color:#ddd}
    .rate-stars input:checked ~ label,
    .rate-stars label:hover,
    .rate-stars label:hover ~ label{color:#ffb703}
  </style>
</head>
<body>
<div class="topbar">
  <button class="back-btn" onclick="(history.length>1)?history.back():location.href='index.php'">&larr; กลับ</button>
  <div class="title">ดูโปรไฟล์</div>
</div>

<div class="container mt-5">
  <div class="profile-box">

    <?php if($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- รูปโปรไฟล์ -->
    <div class="avatar-wrap">
      <img class="avatar" id="avatarPreview" src="<?= h($avatarPath) ?>" alt="avatar">
      <form method="post" enctype="multipart/form-data" id="avatarForm">
        <input type="hidden" name="action" value="upload_avatar">
        <input type="file" id="avatar" name="avatar" accept=".jpg,.jpeg,.png,.webp,.gif" class="d-none">
        <button type="button" class="btn-file" onclick="document.getElementById('avatar').click()">เปลี่ยนรูปโปรไฟล์</button>
        <div class="help">รองรับ JPG/PNG/WebP/GIF ขนาดไม่เกิน 2MB</div>
      </form>
    </div>

    <h2 class="mb-1">👋 <strong>สวัสดีคุณ <?= h($user['fname'].' '.$user['lname']) ?></strong></h2>
    <p class="mb-2"><strong>ชื่อผู้ใช้:</strong> <?= h($user['username']) ?></p>

    <!-- เรทที่ได้รับ -->
    <div class="mb-3">
      <strong>เรทที่ได้รับ:</strong>
      <?php
        $full = floor($avgScore);
        $half = ($avgScore - $full) >= 0.5 ? 1 : 0; // (แสดงเป็นเต็มดวงไว้ก่อน)
        $empty = 5 - $full - $half;
      ?>
      <span class="stars" title="เฉลี่ย <?= number_format($avgScore,2) ?> จาก <?= (int)$avgCnt ?> รีวิว">
        <?= str_repeat('<span class="s">★</span>',$full) ?>
        <?= str_repeat('<span class="s">★</span>',$half) ?>
        <?= str_repeat('<span class="s g">★</span>',$empty) ?>
      </span>
      <span class="text-muted">(<?= number_format($avgScore,2) ?> / <?= (int)$avgCnt ?> รีวิว)</span>
    </div>

    <?php if($recentReceived): ?>
      <div class="mb-4">
        <div class="fw-bold mb-1">รีวิวล่าสุดที่คุณได้รับ</div>
        <ul class="list-group">
          <?php foreach($recentReceived as $rv): ?>
            <li class="list-group-item">
              <span class="stars">
                <?= str_repeat('<span class="s">★</span>', (int)$rv['score']) ?>
                <?= str_repeat('<span class="s g">★</span>', 5-(int)$rv['score']) ?>
              </span>
              <span class="ms-2"><?= h($rv['rater_name'] ?: 'ผู้ซื้อ') ?></span>
              <div class="text-muted small"><?= h($rv['created_at']) ?></div>
              <?php if($rv['comment']): ?><div class="mt-1"><?= nl2br(h($rv['comment'])) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ประวัติการซื้อ + ปุ่มให้คะแนน -->
    <div class="mt-2">
      <h5 class="mb-2">🛒 ประวัติการซื้อสินค้าล่าสุด</h5>
      <?php if ($purchases): ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr><th style="width:56px"></th><th>สินค้า</th><th>ผู้ขาย</th><th class="text-end">ยอดชำระ</th><th>เลขออเดอร์</th><th>ชำระเมื่อ</th><th style="width:140px"></th></tr>
            </thead>
            <tbody>
              <?php foreach ($purchases as $p):
                $img = !empty($p['product_image']) ? ('uploads/'.basename($p['product_image'])) : 'assets/no-image.png';
                $already = !empty($ratedMap[(int)$p['order_id']]);
              ?>
              <tr>
                <td><img class="thumb" src="<?= h($img) ?>" alt=""></td>
                <td><?= h($p['product_name'] ?: '-') ?></td>
                <td><?= h($p['seller_name'] ?: ('ผู้ขาย #'.$p['seller_id'])) ?></td>
                <td class="text-end"><?= number_format((float)$p['amount'], 2) ?></td>
                <td><code><?= h($p['order_no']) ?></code></td>
                <td><?= h($p['paid_at'] ?? '-') ?></td>
                <td class="text-end">
                  <?php if ($already): ?>
                    <span class="text-success">ให้คะแนนแล้ว</span>
                  <?php else: ?>
                    <button class="btn btn-warning btn-sm"
                      data-rate='<?= h(json_encode([
                        "order_id"   => (int)$p['order_id'],
                        "product_id" => (int)$p['product_id'],
                        "seller_id"  => (int)$p['seller_id'],
                        "seller_name"=> (string)$p['seller_name'],
                        "product_name" => (string)$p['product_name']
                      ], JSON_UNESCAPED_UNICODE)) ?>'
                    >ให้คะแนน</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">ยังไม่มีรายการสั่งซื้อสำเร็จ</div>
      <?php endif; ?>
    </div>

    <!-- เครดิตคงเหลือ + ปุ่ม -->
    <div class="credit-box mt-4">
      <h5 class="mb-2">เครดิตคงเหลือของคุณ</h5>
      <p class="fs-3 text-success mb-0"><?= number_format($credit, 2) ?> บาท</p>
      <a href="topup.php" class="btn btn-outline-success btn-sm mt-3">➕ เติมเครดิต</a>
      <a href="sales_income.php" class="btn btn-outline-dark btn-sm mt-3">💰 ดูรายรับจากการขาย</a>
      <button type="button" class="btn btn-outline-danger btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#withdrawModal">↘️ ถอนเครดิต</button>
    </div>

    <!-- คำขอเติมเครดิต -->
    <?php if ($topups): ?>
      <div class="mt-4">
        <h5 class="mb-2">คำขอเติมเครดิตล่าสุด</h5>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>#</th><th>จำนวน</th><th>สถานะ</th><th>ยื่นเมื่อ</th><th>อนุมัติเมื่อ</th></tr></thead>
            <tbody>
              <?php foreach ($topups as $row): ?>
              <tr>
                <td><?= (int)$row['topup_id'] ?></td>
                <td><?= number_format((float)$row['amount'],2) ?></td>
                <td><span class="badge <?= h($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td><?= h($row['created_at']) ?></td>
                <td><?= h($row['approved_at'] ?? '-') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- คำขอถอนเครดิต -->
    <?php if ($withdraws): ?>
      <div class="mt-4">
        <h5 class="mb-2">คำขอถอนเครดิตล่าสุด</h5>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr><th>#</th><th>จำนวน</th><th>ธนาคาร</th><th>เลขบัญชี</th><th>สถานะ</th><th>ยื่นเมื่อ</th><th>อ้างอิง</th></tr>
            </thead>
            <tbody>
              <?php foreach ($withdraws as $w): ?>
              <tr>
                <td><?= (int)$w['withdraw_id'] ?></td>
                <td><?= number_format((float)$w['amount'],2) ?></td>
                <td><?= h($w['bank_name']) ?></td>
                <td><?= h($w['bank_account']) ?></td>
                <td><span class="badge <?= h($w['status']) ?>"><?= h($w['status']) ?></span></td>
                <td><?= h($w['created_at']) ?></td>
                <td><code><?= h($w['ref_txn'] ?? '-') ?></code></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="mt-3 d-flex gap-2">
      <a href="edit_profile.php" class="btn btn-outline-primary">✏️ แก้ไขโปรไฟล์</a>
      <a href="index.php" class="back-button">กลับหน้าหลัก</a>
    </div>
  </div>
</div>

<!-- Modal: Withdraw -->
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ถอนเครดิต</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">จำนวนเงินที่จะถอน (บาท)</label>
          <input type="number" step="0.01" min="0" name="amount" class="form-control" required>
          <div class="form-text">ขั้นต่ำ 20 บาท และต้องมียอดคงเหลือเพียงพอ</div>
        </div>
        <div class="mb-3">
          <label class="form-label">ธนาคาร</label>
          <select name="bank_name" class="form-select" required>
            <option value="">-- เลือกธนาคาร --</option>
            <option>SCB</option><option>KBANK</option><option>BAY</option>
            <option>KTB</option><option>BBL</option><option>TTB</option><option>GSB</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">เลขบัญชี</label>
          <input type="text" name="bank_account" pattern="\d{6,}" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">ชื่อบัญชี (ชื่อบัญชีต้องตรงกับชื่อของ Account)</label>
          <input type="text" name="account_name" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="withdraw">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="submit" class="btn btn-danger">ยืนยันถอน</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Rate Seller -->
<div class="modal fade" id="rateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ให้คะแนนผู้ขาย</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><b id="rateProductName"></b></div>
        <div class="mb-3 text-muted" id="rateSellerName"></div>

        <div class="rate-stars text-center mb-3">
          <input type="radio" id="r5" name="score" value="5" required><label for="r5">★</label>
          <input type="radio" id="r4" name="score" value="4"><label for="r4">★</label>
          <input type="radio" id="r3" name="score" value="3"><label for="r3">★</label>
          <input type="radio" id="r2" name="score" value="2"><label for="r2">★</label>
          <input type="radio" id="r1" name="score" value="1"><label for="r1">★</label>
        </div>

        <div class="mb-3">
          <label class="form-label">ความคิดเห็น (ถ้ามี)</label>
          <textarea name="comment" class="form-control" placeholder="เช่น สินค้าตรงปก ส่งไว บริการดี"></textarea>
        </div>

        <input type="hidden" name="action" value="rate_seller">
        <input type="hidden" name="rated_user_id" id="rated_user_id">
        <input type="hidden" name="order_id" id="rated_order_id">
        <input type="hidden" name="product_id" id="rated_product_id">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="submit" class="btn btn-warning">บันทึกคะแนน</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* preview avatar */
const input = document.getElementById('avatar');
const form  = document.getElementById('avatarForm');
const preview = document.getElementById('avatarPreview');
if (input){
  input.addEventListener('change', function(){
    const f = this.files && this.files[0];
    if (!f) return;
    const ok = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!ok.includes(f.type)) { alert('กรุณาเลือก JPG/PNG/WebP/GIF'); this.value=''; return; }
    if (f.size > 2*1024*1024) { alert('ไฟล์ใหญ่เกินไป (จำกัด 2MB)'); this.value=''; return; }
    const url = URL.createObjectURL(f);
    preview.src = url; preview.onload = () => URL.revokeObjectURL(url);
    form.submit();
  });
}

/* open rate modal */
document.querySelectorAll('button[data-rate]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const d = JSON.parse(btn.getAttribute('data-rate'));
    document.getElementById('rated_user_id').value   = d.seller_id;
    document.getElementById('rated_order_id').value  = d.order_id;
    document.getElementById('rated_product_id').value= d.product_id;
    document.getElementById('rateProductName').textContent = d.product_name || '';
    document.getElementById('rateSellerName').textContent  = 'ผู้ขาย: ' + (d.seller_name || ('ผู้ขาย #' + d.seller_id));
    // reset stars
    document.querySelectorAll('#rateModal input[name=score]').forEach(r=>r.checked=false);
    const modal = new bootstrap.Modal(document.getElementById('rateModal'));
    modal.show();
  });
});
</script>
</body>
</html>
