<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { echo json_encode(['total'=>0]); exit; }

$pdo = new PDO("mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4","mix","mix1234",
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$userId = (int)$_SESSION['user_id'];

/*
  ตัวอย่างนับ “จำนวนห้องที่มีข้อความใหม่” หรือจะเปลี่ยนเป็น “จำนวนข้อความที่ยังไม่ได้อ่าน” ก็ได้
  ตรงนี้ขึ้นกับโครงสร้าง read-flag ของคุณ ถ้ายังไม่มี ให้เริ่มจากนับรวมทั้งหมดแบบง่าย ๆ ก่อน
*/
$st = $pdo->prepare("
  SELECT COUNT(*) FROM messages m
  JOIN chat_requests cr ON cr.request_id = m.request_id
  WHERE (cr.buyer_id=:uid OR cr.seller_id=:uid)
    AND m.sender_id <> :uid
    AND m.created_at > IFNULL(
          (SELECT MAX(viewed_at)
             FROM chat_reads
             WHERE user_id=:uid AND request_id=m.request_id),
          '1970-01-01'
        )
");
try {
  $st->execute([':uid'=>$userId]);
  $total = (int)$st->fetchColumn();
} catch (Throwable $e) {
  $total = 0;
}
echo json_encode(['total'=>$total], JSON_UNESCAPED_UNICODE);
