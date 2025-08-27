<?php
session_start();
if ($_SESSION['role'] !== 'admin') exit();

$host = 'sczfile.online';
$dbname = 'secondhand_web';
$username = 'mix';
$password = 'mix1234';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection error");
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    // ดึงชื่อไฟล์เพื่อเอาไปลบ
    $stmt = $pdo->prepare("SELECT product_image FROM products WHERE product_id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product && file_exists("../uploads/" . $product['product_image'])) {
        unlink("../uploads/" . $product['product_image']);
    }

    // ลบจากฐานข้อมูล
    $del = $pdo->prepare("DELETE FROM products WHERE product_id = ?");
    $del->execute([$id]);
	//insert log
	$username = $_SESSION['username'];
$action = "ลบสินค้า #" . $product_id;
$stmt = $conn->prepare("INSERT INTO activity_log (username, action) VALUES (?, ?)");
$stmt->bind_param("ss", $username, $action);
$stmt->execute();

}

header("Location: products.php");
exit();
