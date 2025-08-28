<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$conn = new mysqli("localhost", "mix", "mix1234", "secondhand_web");
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
    $user_id = $_SESSION['user_id'];

    // ตรวจสอบว่าเป็นเจ้าของสินค้าจริง
    $check = $conn->prepare("SELECT * FROM products WHERE product_id = ? AND user_id = ?");
    $check->bind_param("ii", $product_id, $user_id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $delete = $conn->prepare("DELETE FROM products WHERE product_id = ?");
        $delete->bind_param("i", $product_id);
        $delete->execute();

        // log กิจกรรม
        $username = $_SESSION['username'];
        $action = "ลบสินค้า #$product_id";
        $log = $conn->prepare("INSERT INTO activity_log (username, action) VALUES (?, ?)");
        $log->bind_param("ss", $username, $action);
        $log->execute();
    }
}

header("Location: my_products.php");
exit();
