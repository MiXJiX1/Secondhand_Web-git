<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>true,'count'=>0]); exit; }

$pdo = new PDO("mysql:host=.;dbname=;charset=utf8mb4","","",[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);
$st=$pdo->prepare("SELECT COUNT(*) FROM exchange_notifications WHERE user_id=? AND is_read=0");
$st->execute([(int)$_SESSION['user_id']]);
echo json_encode(['ok'=>true,'count'=>(int)$st->fetchColumn()]);
