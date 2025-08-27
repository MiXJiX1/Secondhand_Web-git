<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'items'=>[]]); exit; }

$pdo = new PDO("mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4","mix","mix1234",[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);
$st=$pdo->prepare("
  SELECT notification_id,item_id,offer_id,message,is_read,created_at
  FROM exchange_notifications
  WHERE user_id=?
  ORDER BY is_read ASC, notification_id DESC
  LIMIT 20
");
$st->execute([(int)$_SESSION['user_id']]);
echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
