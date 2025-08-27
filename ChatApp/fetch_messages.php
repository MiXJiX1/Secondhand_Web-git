<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

$conn = new mysqli("sczfile.online", "mix", "mix1234", "secondhand_web");
if ($conn->connect_error) { echo json_encode([]); exit(); }

$requestId = isset($_GET['request_id']) ? trim($_GET['request_id']) : '';
$productId = isset($_GET['product_id']) ? (int)($_GET['product_id'] ?? 0) : 0;

if ($requestId === '') { echo json_encode([]); exit(); }

// ห้องแลกเปลี่ยน? EXC-<item_id>-<offer_id>
$isExchange = false;
if (preg_match('/^EXC-(\d+)-(\d+)$/', $requestId)) {
  $isExchange = true;
}

if ($isExchange) {
  // ดึงจาก exchange_messages (ใช้ created_at)
  $sql  = "SELECT id, sender_id, message, created_at
           FROM exchange_messages
           WHERE request_id = ?
           ORDER BY created_at ASC, id ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) { echo json_encode([]); exit(); }
  $stmt->bind_param("s", $requestId);
} else {
  // ดึงจาก messages ปกติ (เผื่อไม่มี sent_at ก็ใช้ created_at)
  if ($productId <= 0) { echo json_encode([]); exit(); }
  $sql  = "SELECT id, sender_id, message,
                  COALESCE(sent_at, created_at) AS created_at
           FROM messages
           WHERE request_id = ? AND product_id = ?
           ORDER BY created_at ASC, id ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) { echo json_encode([]); exit(); }
  $stmt->bind_param("si", $requestId, $productId);
}

if (!$stmt->execute()) { echo json_encode([]); exit(); }

$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    "id"        => (int)$row["id"],
    "sender_id" => (int)$row["sender_id"],
    "fullname"  => "",                         // ถ้าจะโชว์ชื่อ ค่อย JOIN users เพิ่มภายหลัง
    "message"   => (string)($row["message"] ?? ""),
    "sent_at"   => (string)($row["created_at"] ?? "") // ส่งชื่อคีย์เดิมให้ UI ใช้งานต่อได้
  ];
}
$stmt->close();
$conn->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
