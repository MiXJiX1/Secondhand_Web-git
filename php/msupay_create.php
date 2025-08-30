<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$raw = json_decode(file_get_contents('php://input'), true);
$requestId = trim($raw['request_id'] ?? '');
$productId = (int)($raw['product_id'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

if ($requestId==='' || $productId<=0) { echo json_encode(['ok'=>false,'error'=>'bad params']); exit; }

$pdo = new PDO("mysql:host=;dbname=;charset=utf8mb4","","",
               [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

// ดึงราคาสินค้า
$st = $pdo->prepare("SELECT product_price, product_name FROM products WHERE product_id=?");
$st->execute([$productId]);
$p = $st->fetch(PDO::FETCH_ASSOC);
if(!$p){ echo json_encode(['ok'=>false,'error'=>'product not found']); exit; }

$amount = (float)$p['product_price'];
$orderNo = 'MSU'.date('YmdHis').bin2hex(random_bytes(3));

// บันทึกคำสั่งซื้อ
$ins = $pdo->prepare("INSERT INTO orders(order_no,user_id,product_id,request_id,amount,status) VALUES (?,?,?,?,?,'pending')");
$ins->execute([$orderNo,$userId,$productId,$requestId,$amount]);

/* ======= ตั้งค่าตาม MSUPAY ของ มมส. ======= */
$MERCHANT_ID = 'YOUR_MSU_MERCHANT_ID';
$SECRET      = 'YOUR_SECRET_KEY';
$RETURN_URL  = "https://sczfile.online/mix/project101/msupay_return.php";
$NOTIFY_URL  = "https://sczfile.online/mix/project101/msupay_webhook.php";
$ENDPOINT    = "https://msupay.msu.ac.th/payment"; // ต้องเอาของจริงจาก มมส.

/* ===== สร้าง params ===== */
$params = [
  'merchant_id' => $MERCHANT_ID,
  'order_no'    => $orderNo,
  'amount'      => number_format($amount,2,'.',''),
  'currency'    => 'THB',
  'desc'        => 'สินค้า: '.$p['product_name'],
  'return_url'  => $RETURN_URL,
  'notify_url'  => $NOTIFY_URL,
];

/* ===== สร้าง signature (ขึ้นอยู่กับคู่มือ MSUPAY) ===== */
$base = implode('|', [$params['merchant_id'],$params['order_no'],$params['amount'],$params['currency']]);
$params['signature'] = hash_hmac('sha256',$base,$SECRET);

echo json_encode([
  'ok'=>true,
  'pay_url'=>$ENDPOINT.'?'.http_build_query($params)
]);
