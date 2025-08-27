<?php
session_start();
if ($_SESSION['role'] !== 'admin') exit();

require __DIR__ . '/config.php';
$id = $_GET['id'];

$stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?");
$stmt->execute([$id]);

header("Location: users.php");
exit();
