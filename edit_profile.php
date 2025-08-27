<?php
session_start();
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header("Location: login.html");
    exit;
}

$pdo = new PDO("mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8", "mix", "mix1234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// โหลดข้อมูลผู้ใช้
$stmt = $pdo->prepare("SELECT username, fname, lname FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['username']);
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $new_password = $_POST['password'];

    // เช็ค username ซ้ำ
    $check = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $check->execute([$new_username, $user_id]);
    if ($check->rowCount() > 0) {
        $error = "ชื่อผู้ใช้นี้ถูกใช้แล้ว";
    } else {
        if (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET username=?, fname=?, lname=?, password=? WHERE user_id=?");
            $update->execute([$new_username, $fname, $lname, $hashed, $user_id]);
        } else {
            $update = $pdo->prepare("UPDATE users SET username=?, fname=?, lname=? WHERE user_id=?");
            $update->execute([$new_username, $fname, $lname, $user_id]);
        }

        $_SESSION['username'] = $new_username;
        header("Location: profile.php?updated=true");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>แก้ไขโปรไฟล์</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
  <div class="container mt-5">
    <h2>แก้ไขข้อมูลโปรไฟล์</h2>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
      <div class="mb-3">
        <label class="form-label">ชื่อผู้ใช้</label>
        <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($user['username']) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">ชื่อจริง</label>
        <input type="text" name="fname" class="form-control" required value="<?= htmlspecialchars($user['fname']) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">นามสกุล</label>
        <input type="text" name="lname" class="form-control" required value="<?= htmlspecialchars($user['lname']) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">รหัสผ่านใหม่ <small>(ถ้าไม่เปลี่ยน ให้เว้นว่าง)</small></label>
        <input type="password" name="password" class="form-control">
      </div>

      <button type="submit" class="btn btn-warning">บันทึกการเปลี่ยนแปลง</button>
      <a href="profile.php" class="btn btn-secondary">กลับ</a>
    </form>
  </div>
</body>
</html>
