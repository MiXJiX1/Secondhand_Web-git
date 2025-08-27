<?php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauth']); exit; }

$userId = (int)$_SESSION['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = isset($raw['request_id']) ? trim($raw['request_id']) : '';
$productId = isset($raw['product_id']) ? (int)$raw['product_id'] : 0;

if ($requestId === '') { echo json_encode(['ok'=>false]); exit; }

$conn = new mysqli("sczfile.online","mix","mix1234","secondhand_web");
if ($conn->connect_error) { echo json_encode(['ok'=>false]); exit; }

$isExchange = preg_match('/^EXC-\d+-\d+$/',$requestId);

if ($isExchange) {
  $sql = "SELECT IFNULL(MAX(id),0) AS max_id FROM exchange_messages WHERE request_id=?";
  $st = $conn->prepare($sql); $st->bind_param("s",$requestId);
} else {
  $sql = "SELECT IFNULL(MAX(id),0) AS max_id FROM messages WHERE request_id=? AND product_id=?";
  $st = $conn->prepare($sql); $st->bind_param("si",$requestId,$productId);
}
$st->execute();
$maxId = (int)$st->get_result()->fetch_assoc()['max_id'];
$st->close();

$up = $conn->prepare("INSERT INTO chat_reads (request_id,user_id,last_read_id)
                      VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE last_read_id=VALUES(last_read_id)");
$up->bind_param("sii",$requestId,$userId,$maxId);
$ok = $up->execute(); $up->close(); $conn->close();

echo json_encode(['ok'=>$ok]);
