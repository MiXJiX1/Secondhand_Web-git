<?php
// ห้ามมี output ก่อน header/json เด็ดขาด
ini_set('display_errors', 0); // กัน warning หลุดหน้า
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'message'=>'not login']); exit; }
$userId = (int)$_SESSION['user_id'];

require_once __DIR__.'/config.php';
// ไม่จำเป็นต้องใช้ topup_lib ถ้าไม่ได้สร้าง payload เอง แต่คงไว้ได้ไม่ผิด
// require_once __DIR__.'/topup_lib.php';

// DB
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { echo json_encode(['ok'=>false,'message'=>'db connect error']); exit; }
$mysqli->set_charset('utf8mb4');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =========================
 * 1) สร้าง QR ด้วย pp-qr.com
 * ========================= */
if ($action === 'create_qr') {
  // อ่าน JSON อย่างเดียว
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) { echo json_encode(['ok'=>false,'message'=>'invalid json']); exit; }

  $amount = (float)($payload['amount'] ?? 0);
  if (!($amount > 0)) { echo json_encode(['ok'=>false,'message'=>'invalid amount']); exit; }

  // gen อ้างอิงคำขอ
  $ref = 'TP'.date('ymdHis').random_int(100,999);

  // บันทึกคำขอ pending (หมดอายุ 15 นาที)
  $stmt = $mysqli->prepare("INSERT INTO credit_topups (user_id, amount, method, reference_no, status, created_at, expire_at)
                            VALUES (?, ?, 'promptpay', ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
  $stmt->bind_param('ids', $userId, $amount, $ref);
  if (!$stmt->execute()) { echo json_encode(['ok'=>false,'message'=>'db error']); exit; }

  // สร้าง URL รูป QR จาก pp-qr.com (โหลดผ่าน <img> ได้เลย)
  $ppId = preg_replace('/\D+/', '', PROMPTPAY_ID); // ให้เหลือตัวเลขล้วน
  $amountFmt = number_format($amount, 2, '.', '');  // 100.00
  // ตัวอย่างรูปแบบ: https://www.pp-qr.com/api/image/0812345678/100.00
  $qrImg = "https://www.pp-qr.com/api/image/{$ppId}/{$amountFmt}";

  echo json_encode(['ok'=>true,'ref'=>$ref,'qr_img'=>$qrImg]); exit;
}

/* =========================
 * 2) อัปโหลดสลิป → ตรวจด้วย Thunder
 * ========================= */
if ($action === 'verify_slip') {
  // รับ ref + ไฟล์
  $ref = $_POST['ref'] ?? '';
  if ($ref==='') { echo json_encode(['ok'=>false,'message'=>'missing ref']); exit; }

  if (!isset($_FILES['slip']) || $_FILES['slip']['error']!==UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'message'=>'no slip']); exit;
  }
  $mime = mime_content_type($_FILES['slip']['tmp_name']);
  if (!in_array($mime, ['image/jpeg','image/png','image/webp'])) {
    echo json_encode(['ok'=>false,'message'=>'file must be image']); exit;
  }
  if ($_FILES['slip']['size'] > 5*1024*1024) {
    echo json_encode(['ok'=>false,'message'=>'file too large']); exit;
  }

  // ล็อกแถวคำขอ
  $stmt = $mysqli->prepare("SELECT topup_id, amount, status, expire_at FROM credit_topups
                            WHERE reference_no=? AND user_id=? FOR UPDATE");
  $stmt->bind_param('si', $ref, $userId);
  $stmt->execute();
  $req = $stmt->get_result()->fetch_assoc();
  if (!$req) { echo json_encode(['ok'=>false,'message'=>'request not found']); exit; }
  if ($req['status']!=='pending') { echo json_encode(['ok'=>false,'message'=>'status not pending']); exit; }
  if ($req['expire_at'] && strtotime($req['expire_at']) < time()) {
    echo json_encode(['ok'=>false,'message'=>'request expired']); exit;
  }

  // เรียก Thunder
  $ch = curl_init('https://api.thunder.in.th/v1/verify');
  $fields = ['file'=> new CURLFile($_FILES['slip']['tmp_name'], $mime, $_FILES['slip']['name'])];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$fields, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.THUNDER_TOKEN],
    CURLOPT_TIMEOUT=>30
  ]);
  $res  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($err) { echo json_encode(['ok'=>false,'message'=>'api error']); exit; }

  $j = json_decode($res, true);
  if ($code!==200 || !isset($j['data'])) {
    echo json_encode(['ok'=>false,'message'=>$j['message'] ?? 'verify failed']); exit;
  }
  $slip = $j['data'];

  $paid     = (float)($slip['amount']['amount'] ?? 0);
  $bank_id  = $slip['receiver']['bank']['id'] ?? '';
  $transRef = $slip['transRef'] ?? '';
  $payload  = $slip['payload']  ?? '';

  if ($paid <= 0) { echo json_encode(['ok'=>false,'message'=>'invalid paid']); exit; }
  // ตรวจยอดตรงกับคำขอ
  if (abs($paid - (float)$req['amount']) > 0.01) {
    echo json_encode(['ok'=>false,'message'=>'amount mismatch']); exit;
  }
  // ตรวจธนาคารผู้รับ (ถ้าตั้งค่าไว้)
  if (defined('RECEIVER_BANK_ID') && RECEIVER_BANK_ID !== '' && $bank_id !== '' && $bank_id !== RECEIVER_BANK_ID) {
    echo json_encode(['ok'=>false,'message'=>'receiver mismatch']); exit;
  }

  // บันทึกรูป
  $dir = __DIR__.'/uploads/slips/';
  if (!is_dir($dir)) mkdir($dir,0777,true);
  $saveName = 'slip_'.$req['topup_id'].'_'.time().'.'.pathinfo($_FILES['slip']['name'], PATHINFO_EXTENSION);
  if (!move_uploaded_file($_FILES['slip']['tmp_name'], $dir.$saveName)) {
    echo json_encode(['ok'=>false,'message'=>'save slip failed']); exit;
  }

  // ทำเป็นธุรกรรมเดียว
  $mysqli->begin_transaction();
  try {
    // อนุมัติคำขอ + กันสลิปซ้ำ (ควรทำ UNIQUE index ที่ credit_topups.trans_ref / thunder_payload)
    $up = $mysqli->prepare("UPDATE credit_topups SET
          method='bank_transfer',
          status='approved',
          approved_at=NOW(),
          verified_at=NOW(),
          verified_amount=?,
          trans_ref=?,
          thunder_payload=?,
          slip_path=?,
          receiver_bank_id=?
        WHERE topup_id=? AND status='pending'");
    $up->bind_param('dssssi', $paid, $transRef, $payload, $saveName, $bank_id, $req['topup_id']);
    if(!$up->execute() || $up->affected_rows!==1){ throw new Exception('update topup failed'); }

    // เติมเครดิตให้ผู้ใช้
    $inc = $mysqli->prepare("UPDATE users SET credit_balance = credit_balance + ? WHERE user_id=?");
    $inc->bind_param('di', $paid, $userId);
    if(!$inc->execute()){ throw new Exception('update balance failed'); }

    // ลงสมุดเครดิต
    $led = $mysqli->prepare("INSERT INTO credit_ledger (user_id, change_amt, reason, ref_id, created_at)
                             VALUES (?,?, 'topup', ?, NOW())");
    $refId = (string)$req['topup_id'];
    $led->bind_param('ids', $userId, $paid, $refId);
    if(!$led->execute()){ throw new Exception('insert ledger failed'); }

    $mysqli->commit();
    echo json_encode(['ok'=>true,'amount'=>$paid,'transRef'=>$transRef]); exit;
  } catch (Throwable $e) {
    $mysqli->rollback();
    echo json_encode(['ok'=>false,'message'=>'duplicate or db error']); exit;
  }
}

echo json_encode(['ok'=>false,'message'=>'unknown action']); exit;
