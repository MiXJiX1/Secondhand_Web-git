<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);


session_start();
function jerr(string $msg){ echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function jok(array $d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['user_id'])) jerr('unauthorized');

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) jerr('invalid json body');

$requestId = isset($in['request_id']) ? trim($in['request_id']) : '';
$productId = isset($in['product_id']) ? (int)$in['product_id'] : 0;
$password  = (string)($in['password'] ?? '');
$userId    = (int)$_SESSION['user_id'];

if ($requestId === '' || $productId <= 0) jerr('bad params');
if ($password === '') jerr('กรุณากรอกรหัสผ่าน');

try {
  $pdo = new PDO(
    "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
    "mix","mix1234",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );

  // 1) ตรวจรหัสผ่านผู้ใช้
  $st = $pdo->prepare("SELECT password FROM users WHERE user_id=?");
  $st->execute([$userId]);
  $u = $st->fetch();
  if (!$u) jerr('user not found');

  $hash = $u['password']; 
  // ถ้าเก็บ plain-text (ไม่แนะนำ) ให้เปลี่ยนเป็น: if ($password !== $hash)
  if (!password_verify($password, $hash)) jerr('รหัสผ่านไม่ถูกต้อง');

  // 2) ดึงข้อมูลสินค้า
  $st = $pdo->prepare("SELECT product_name, product_price FROM products WHERE product_id=?");
  $st->execute([$productId]);
  $p = $st->fetch();
  if (!$p) jerr('product not found');

  $amount = (float)$p['product_price'];
  if ($amount <= 0) jerr('invalid product price');

  // 3) สร้างคำสั่งซื้อ
  $orderNo = 'MSU'.date('YmdHis').bin2hex(random_bytes(3));
  $ins = $pdo->prepare("INSERT INTO orders(order_no,user_id,product_id,request_id,amount,status,created_at)
                        VALUES (?,?,?,?,?,'pending',NOW())");
  $ins->execute([$orderNo,$userId,$productId,$requestId,$amount]);

  // 4) ส่งลิงก์ไปหน้าชำระเงินจำลอง (ทดสอบ)
  $payUrl = "mock_payment.php?order_no=".urlencode($orderNo);
  jok(['ok'=>true,'pay_url'=>$payUrl]);

} catch (Throwable $e) {
  jerr('server error: '.$e->getMessage());
}
