<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.html");
    exit();
}

$host = 'sczfile.online';
$dbname = 'secondhand_web';
$username = 'mix';
$password = 'mix1234';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ดึงข้อมูลผู้ใช้พร้อมเครดิต
    $stmt = $pdo->query("
        SELECT 
            u.*, 
            COALESCE(SUM(p.amount), 0) AS credit_balance
        FROM users u
        LEFT JOIN payments p ON u.username = p.username AND p.status = 'completed'
        GROUP BY u.user_id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ ดึงจำนวนผู้ใช้ทั้งหมด
    $countStmt = $pdo->query("SELECT COUNT(*) AS total_users FROM users");
    $totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total_users'];

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>จัดการผู้ใช้</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
  <h2 class="mb-2">รายการผู้ใช้ในระบบ</h2>
  <h5 class="text-muted mb-4">จำนวนผู้ใช้ทั้งหมดในระบบ: <?= $totalUsers ?> คน</h5>

  <table class="table table-bordered table-hover">
    <thead class="table-dark">
      <tr>
        <th>ชื่อผู้ใช้</th>
        <th>ชื่อจริง</th>
        <th>อีเมล</th>
        <th>เครดิตคงเหลือ</th>
        <th>สิทธิ์</th>
        <th>สถานะ</th>
        <th>การจัดการ</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user): ?>
      <tr>
        <td><?= htmlspecialchars($user['username']) ?></td>
        <td><?= htmlspecialchars($user['fname'] . ' ' . $user['lname']) ?></td>
        <td><?= htmlspecialchars($user['email']) ?></td>
        <td><?= number_format($user['credit_balance'], 2) ?> บาท</td>
        <td><?= htmlspecialchars($user['role']) ?></td>
        <td><?= htmlspecialchars($user['status']) ?></td>
        <td>
          <?php if ($user['role'] !== 'admin'): ?>
            <a href="upgrade_user.php?id=<?= $user['user_id'] ?>" class="btn btn-success btn-sm">อัปเกรดเป็น Admin</a>
            <a href="delete_user.php?id=<?= $user['user_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('ยืนยันการลบ?')">ลบ</a>
          <?php else: ?>
            <span class="text-muted">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
