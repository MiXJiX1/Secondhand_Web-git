<?php
session_start();
$servername = "sczfile.online";
$username = "mix";
$password = "mix1234";
$dbname = "secondhand_web";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

if (isset($_GET['id'])) {
    $product_id = intval($_GET['id']);
    $sql = "SELECT * FROM products WHERE product_id = $product_id";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
    } else {
        echo "ไม่พบสินค้านี้";
        exit();
    }
} else {
    echo "ไม่มีสินค้าระบุ";
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ชำระเงิน - <?php echo htmlspecialchars($product['product_name']); ?></title>
    <link rel="stylesheet" href="styles.css">
	<link rel="stylesheet" href="payment.css">
</head>
<body>

<div class="payment-container">
    <h2>ยืนยันการสั่งซื้อ</h2>

    <div class="product-info">
        <img src="uploads/<?php echo htmlspecialchars($product['product_image']); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
        <h3><?php echo htmlspecialchars($product['product_name']); ?></h3>
        <p>หมวดหมู่: <?php echo htmlspecialchars($product['category']); ?></p>
        <p>ราคา: <strong><?php echo number_format($product['product_price'], 2); ?> บาท</strong></p>
    </div>

    <form action="order_success.php" method="POST">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
        
        <label for="customer_name">ชื่อผู้รับสินค้า:</label>
        <input type="text" name="customer_name" id="customer_name" required>

        <label for="phone">เบอร์โทรศัพท์:</label>
        <input type="text" name="phone" id="phone" required>

        <label for="address">ที่อยู่สำหรับจัดส่ง:</label>
        <textarea name="address" id="address" required></textarea>

<label for="payment_method">วิธีชำระเงิน:</label>
<select name="payment_method" id="payment_method" required>
    <option value="">-- กรุณาเลือก --</option>
    <option value="เก็บเงินปลายทาง">เก็บเงินปลายทาง</option>
    <option value="MSU.PAY">MSU.PAY</option>
</select>


        <button type="submit">ยืนยันการสั่งซื้อ</button>
    </form>
</div>

</body>
</html>
