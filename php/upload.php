<?php
session_start();
$servername = "";
$username = "";
$password = "";
$dbname = "";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

// ตรวจสอบว่า login แล้วหรือยัง
if (!isset($_SESSION['user_id'])) {
    die("กรุณาเข้าสู่ระบบก่อนอัปโหลดสินค้า");
}
$user_id = $_SESSION['user_id']; // ✅ ดึง user_id จาก session

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $product_name = $_POST['product-name'];
    $price = $_POST['price'];
    $description = $_POST['description'];
    $category = $_POST['category'] ?? "อื่นๆ";

    $target_dir = "uploads/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $imageNames = [];
    foreach ($_FILES["images"]["name"] as $key => $name) {
        $imageFileType = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newFileName = uniqid() . "." . $imageFileType;
        $target_file = $target_dir . $newFileName;

        $check = getimagesize($_FILES["images"]["tmp_name"][$key]);
        if ($check !== false) {
            if (move_uploaded_file($_FILES["images"]["tmp_name"][$key], $target_file)) {
                $imageNames[] = $newFileName;
            } else {
                echo "เกิดข้อผิดพลาดในการอัปโหลดรูป: " . $name . "<br>";
            }
        } else {
            echo "ไฟล์ไม่ใช่รูปภาพ: " . $name . "<br>";
        }
    }

    $imageList = implode(",", $imageNames);

    // ✅ เพิ่ม user_id ลงไปใน SQL
    $sql = "INSERT INTO products (product_name, product_price, category, product_image, description, user_id) 
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdsssi", $product_name, $price, $category, $imageList, $description, $user_id);

    if ($stmt->execute()) {
        echo "<script>alert('สินค้าอัปโหลดสำเร็จ!'); window.location.href='index.php';</script>";

        echo "<br><a href='index.php'>กลับไปที่หน้าหลัก</a>";
    } else {
        echo "เกิดข้อผิดพลาด: " . $stmt->error;
    }
	$username = $_SESSION['username'];
$action = "ลงขายสินค้าใหม่";
$stmt = $conn->prepare("INSERT INTO activity_log (username, action) VALUES (?, ?)");
$stmt->bind_param("ss", $username, $action);
$stmt->execute();
}
$conn->close();
?>
