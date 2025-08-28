<?php
// mock_payment.php — หักเครดิตจริงเมื่อกดยืนยันชำระ แล้ว "พักเงินไว้บัญชีกลาง (escrow)"
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) { echo "ต้องเข้าสู่ระบบก่อน"; exit; }
$userId = (int)$_SESSION['user_id'];

if (!isset($_GET['order_no']) || trim($_GET['order_no'])==='') { echo "missing order_no"; exit; }
$orderNo = trim($_GET['order_no']);

// ----- DB -----
$pdo = new PDO(
    "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
    "mix", "mix1234",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

/** หา/สร้าง user escrow */
function getEscrowUserId(PDO $pdo): int {
    // ใช้ username = 'escrow' เป็นบัญชีกลาง
    $q = $pdo->prepare("SELECT user_id FROM users WHERE username='escrow' LIMIT 1");
    $q->execute();
    $id = $q->fetchColumn();
    if ($id) return (int)$id;

    // ไม่มี -> สร้างใหม่ (ต้องใส่คอลัมน์ NOT NULL ให้ครบ เช่น img, status)
    // ใส่รูปว่าง ๆ ก็ได้ เพราะเป็นแค่ placeholder
    $ins = $pdo->prepare("
        INSERT INTO users
            (username, password, role, credit_balance, fname, lname, email, img, status)
        VALUES
            ('escrow', '', 'admin', 0, 'Escrow', 'Wallet', 'escrow@example.com', '', 'active')
    ");
    $ins->execute();

    return (int)$pdo->lastInsertId();
}


// ----- ดึงข้อมูล order + สินค้า + จำนวนเงิน + seller_id -----
$sql = "
SELECT 
  o.order_no,
  o.user_id       AS buyer_id,
  o.status,
  o.amount,
  o.product_id,
  p.product_name,
  p.product_image,
  p.user_id       AS seller_id
FROM orders o
LEFT JOIN products p ON o.product_id = p.product_id
WHERE o.order_no = ?
LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([$orderNo]);
$order = $st->fetch();

if (!$order) { echo "ไม่พบข้อมูลคำสั่งซื้อ"; exit; }
if ((int)$order['buyer_id'] !== $userId) { echo "คุณไม่มีสิทธิ์ชำระคำสั่งซื้อนี้"; exit; }

$productName  = $order['product_name'] ?: 'ไม่พบชื่อสินค้า';
$productImage = !empty($order['product_image']) ? '../uploads/'.$order['product_image'] : '../default_product.png';
$amount       = (float)$order['amount'];
$productId    = (int)$order['product_id'];
$sellerId     = (int)$order['seller_id'];
$escrowId     = getEscrowUserId($pdo);

// ============ เมื่อกดยืนยันชำระ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1) ล็อกแถวออเดอร์กันกดซ้ำ/แข่งกัน
        $lock = $pdo->prepare("SELECT status FROM orders WHERE order_no=? FOR UPDATE");
        $lock->execute([$orderNo]);
        $cur = $lock->fetch();
        if (!$cur) { throw new Exception('order not found'); }

        if ($cur['status'] === 'paid') {
            // จ่ายไปแล้ว -> ถือว่าสำเร็จ
            $pdo->commit();
            $paid = true;
        } else {
            if ($cur['status'] !== 'pending') { throw new Exception('สถานะออเดอร์ไม่พร้อมชำระ'); }

            // 2) ล็อกยอดผู้ซื้อ
            $u = $pdo->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
            $u->execute([$userId]);
            $urow = $u->fetch();
            if (!$urow) { throw new Exception('ไม่พบผู้ใช้'); }
            $balance = (float)$urow['credit_balance'];
            if ($balance + 1e-9 < $amount) { throw new Exception('ยอดเครดิตไม่พอ กรุณาเติมเครดิต'); }

            // 3) หักเครดิตผู้ซื้อ
            $dec = $pdo->prepare("UPDATE users SET credit_balance = credit_balance - ? WHERE user_id=?");
            $dec->execute([$amount, $userId]);

            // 4) ลงสมุดเครดิต (ผู้ซื้อ: purchase เป็นค่าติดลบ)
            $ledBuy = $pdo->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                                     VALUES (?, ?, 'purchase', ?, NOW())");
            $ledBuy->execute([$userId, -$amount, $orderNo]);

            // 5) โอนเข้าบัญชีกลาง (escrow) + สมุดเครดิตฝั่ง escrow
            //    ล็อกบัญชีกลางก่อนกันแข่ง
            $ue = $pdo->prepare("SELECT credit_balance FROM users WHERE user_id=? FOR UPDATE");
            $ue->execute([$escrowId]);

            $inc = $pdo->prepare("UPDATE users SET credit_balance = credit_balance + ? WHERE user_id=?");
            $inc->execute([$amount, $escrowId]);

            $ledEsc = $pdo->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                                     VALUES (?, ?, 'escrow_hold', ?, NOW())");
            $ledEsc->execute([$escrowId, $amount, $orderNo]);

            // 6) เก็บบันทึกการพักเงิน (ถ้ามีตาราง escrow_holds)
            try {
                $escIns = $pdo->prepare("
                    INSERT INTO escrow_holds (order_no, buyer_id, seller_id, product_id, amount, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'held', NOW())
                ");
                $escIns->execute([$orderNo, $userId, $sellerId, $productId, $amount]);
            } catch (\Throwable $e) {
                // ไม่มีตารางก็ข้ามได้
            }

            // 7) อัปเดตออเดอร์เป็น paid (+ paid_at ถ้ามี)
            try {
                $upd = $pdo->prepare("UPDATE orders SET status='paid', paid_at=NOW() WHERE order_no=? AND status='pending'");
                $upd->execute([$orderNo]);
                if ($upd->rowCount() !== 1) { throw new Exception('update order failed'); }
            } catch (\Throwable $e) {
                $upd = $pdo->prepare("UPDATE orders SET status='paid' WHERE order_no=? AND status='pending'");
                $upd->execute([$orderNo]);
                if ($upd->rowCount() !== 1) { throw new Exception('update order failed'); }
            }

            $pdo->commit();
            $paid = true;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $paid = false;
        $errMsg = $e->getMessage();
    }

    // ----- แสดงผลหลังยืนยัน -----
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <title><?= $paid ? 'ชำระเงินสำเร็จ' : 'ชำระเงินไม่สำเร็จ' ?></title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, sans-serif; background:#f5f5f5; text-align:center; padding:40px; }
            .box { background:white; max-width:460px; margin:auto; padding:30px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
            img { max-width:200px; max-height:200px; margin-bottom:15px; border-radius:8px; }
            h3.ok { color: #16a34a; } h3.no { color:#b91c1c; }
            .order { font-weight: bold; margin: 10px 0; }
            a.btn { display:inline-block; margin-top:20px; background:#0ea5e9; color:white; padding:10px 18px; border-radius:6px; text-decoration:none; font-weight:600; }
            a.btn:hover { background:#0284c7; }
            .price{margin:6px 0;color:#111;font-weight:700}
            .muted{color:#555;margin-top:8px}
        </style>
    </head>
    <body>
        <div class="box">
            <h3 class="<?= $paid ? 'ok' : 'no' ?>"><?= $paid ? '✅ ชำระเงินสำเร็จ' : '❌ ชำระเงินไม่สำเร็จ' ?></h3>
            <img src="<?= htmlspecialchars($productImage) ?>" alt="สินค้า">
            <div><?= htmlspecialchars($productName) ?></div>
            <div class="order">Order Number : <?= htmlspecialchars($orderNo) ?></div>
            <?php if(!$paid): ?>
                <div class="muted"><?= htmlspecialchars($errMsg ?? 'เกิดข้อผิดพลาด') ?></div>
                <div style="margin-top:12px">
                    <a href="../php/topup.php" class="btn">เติมเครดิต</a>
                </div>
            <?php else: ?>
                <div class="muted">ยอดเงินถูกพักไว้กับตัวกลางแล้วรอผู้ซื้อกดได้รับของ</div>
                <a href="../index.php" class="btn">กลับไปหน้าแรก</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>MSUPAY (Mock)</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background:#f5f5f5; text-align:center; padding:40px; }
        .box { background:white; max-width:460px; margin:auto; padding:30px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
        img { max-width:200px; max-height:200px; margin-bottom:15px; border-radius:8px; }
        h2 { margin-bottom:10px; }
        .order { font-weight: bold; margin: 10px 0; }
        button { background:#16a34a; color:white; padding:10px 20px; border:none; border-radius:6px; font-weight:600; cursor:pointer; }
        button:hover { background:#15803d; }
        .price{margin:6px 0;color:#111;font-weight:700}
        a.btn { display:inline-block; margin-top:18px; background:#0ea5e9; color:#fff; padding:10px 18px; border-radius:6px; text-decoration:none; font-weight:600; }
        a.btn:hover { background:#0284c7; }
    </style>
</head>
<body>
    <div class="box">
        <h2>MSUPAY (Mock)</h2>
        <img src="<?= htmlspecialchars($productImage) ?>" alt="สินค้า">
        <div><?= htmlspecialchars($productName) ?></div>
        <div class="price">ยอดชำระ: <?= number_format($amount,2) ?> บาท</div>
        <div class="order">Order Number : <?= htmlspecialchars($orderNo) ?></div>
        <?php if ($order['status']==='paid'): ?>
            <div style="color:#16a34a;margin:10px 0">ออเดอร์นี้ชำระแล้ว</div>
            <a href="../index.php" class="btn">กลับหน้าแรก</a>
        <?php else: ?>
            <form method="post">
                <button type="submit">ยืนยันชำระ</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
