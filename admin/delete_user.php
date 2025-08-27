<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit("ไม่อนุญาต");
}

require 'config.php'; // ✅ แก้ path ให้ตรงกับตำแหน่งไฟล์จริง

if (!isset($_GET['id'])) {
    exit("ไม่พบรหัสผู้ใช้");
}

$id = (int) $_GET['id'];

try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$id]);
    header("Location: users.php"); // ✅ อยู่ใน directory เดียวกัน
    exit();
} catch (PDOException $e) {
    echo "เกิดข้อผิดพลาด: " . $e->getMessage();
}
