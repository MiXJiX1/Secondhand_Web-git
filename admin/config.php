<?php
$host = 'sczfile.online';
$db   = 'secondhand_web';
$user = 'mix';
$pass = 'mix1234';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

define('PAYMENT_MODE', 'production'); // 'production' | 'sandbox' | 'disabled'
define('MSUPAY_ENDPOINT', 'https://<REAL_MSUPAY_GATEWAY>/checkout'); // ของจริงเท่านั้น

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}
