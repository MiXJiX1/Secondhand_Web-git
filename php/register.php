<?php
/** -----------------------------------------------------------
 *  register.php (ฟอร์ม + ประมวลผล)
 *  เปิด debug ชั่วคราว: ปิดเมื่อขึ้นโปรดักชัน
 *  ----------------------------------------------------------- */
ini_set('display_errors', 1);               // ✔ เปลี่ยนเป็น 0 บนโปรดักชัน
ini_set('display_startup_errors', 1);       // ✔ เปลี่ยนเป็น 0 บนโปรดักชัน
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ====== ตั้งค่าเชื่อมต่อฐานข้อมูล ====== */
$servername = "sczfile.online";
$username   = "mix";
$password   = "mix1234";
$dbname     = "secondhand_web";

/* ====== ฟังก์ชันตอบ JSON ====== */
function json_response($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ====== POST: ประมวลผลสมัครสมาชิก ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        $conn->set_charset("utf8mb4");

        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $pwd      = isset($_POST['password']) ? (string)$_POST['password'] : '';
        $confirm  = isset($_POST['confirm'])  ? (string)$_POST['confirm']  : '';
        $fname    = isset($_POST['fname'])    ? trim($_POST['fname'])      : '';
        $lname    = isset($_POST['lname'])    ? trim($_POST['lname'])      : '';
        $email    = isset($_POST['email'])    ? trim($_POST['email'])      : '';

        if ($username === '' || $pwd === '' || $confirm === '' || $fname === '' || $lname === '' || $email === '') {
            json_response(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'], 400);
        }
        if (!preg_match('/@msu\.ac\.th$/i', $email)) {
            json_response(['status' => 'error', 'message' => 'กรุณาใช้อีเมลที่ลงท้ายด้วย @msu.ac.th'], 400);
        }
        if ($pwd !== $confirm) {
            json_response(['status' => 'error', 'message' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน'], 400);
        }
        if (strlen($pwd) < 6) {
            json_response(['status' => 'error', 'message' => 'รหัสผ่านควรมีอย่างน้อย 6 ตัวอักษร'], 400);
        }

        // ตรวจซ้ำ
        $sqlCheck = "SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1";
        $stmtC = $conn->prepare($sqlCheck);
        $stmtC->bind_param('ss', $username, $email);
        $stmtC->execute();
        if ($stmtC->get_result()->fetch_assoc()) {
            json_response(['status' => 'error', 'message' => 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้งานแล้ว'], 409);
        }

        // ---- อัปโหลดรูปโปรไฟล์ (ไม่บังคับ) ----
        $avatarFileName = ''; // ค่าเริ่มต้น
        if (!empty($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
            $allowedExt = ['jpg','jpeg','png','webp','gif'];
            $maxBytes   = 2 * 1024 * 1024; // 2MB

            $origName = $_FILES['avatar']['name'];
            $tmpPath  = $_FILES['avatar']['tmp_name'];
            $size     = (int)$_FILES['avatar']['size'];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                json_response(['status' => 'error', 'message' => 'รูปโปรไฟล์ต้องเป็นไฟล์ JPG/PNG/WebP/GIF เท่านั้น'], 400);
            }
            if ($size > $maxBytes) {
                json_response(['status' => 'error', 'message' => 'ไฟล์รูปต้องไม่เกิน 2MB'], 400);
            }

            $uploadDir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

            $avatarFileName = 'u_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $uploadDir . $avatarFileName)) {
                json_response(['status' => 'error', 'message' => 'อัปโหลดรูปโปรไฟล์ไม่สำเร็จ'], 500);
            }
        }

        // ---- บันทึกข้อมูลผู้ใช้ (เพิ่มฟิลด์ img) ----
        $hash   = password_hash($pwd, PASSWORD_DEFAULT);
        $role   = 'user';
        $status = 'active';

        $sqlInsert = "INSERT INTO users (username, password, email, role, status, fname, lname, img)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtI = $conn->prepare($sqlInsert);
        $stmtI->bind_param('ssssssss', $username, $hash, $email, $role, $status, $fname, $lname, $avatarFileName);
        $stmtI->execute();

        json_response(['status' => 'success', 'message' => 'สมัครสมาชิกสำเร็จ']);
    } catch (mysqli_sql_exception $e) {
        json_response(['status' => 'error', 'message' => 'ข้อผิดพลาดของระบบฐานข้อมูล: ' . $e->getMessage()], 500);
    }
}

/* ====== GET: แสดงฟอร์ม ====== */
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>สมัครสมาชิก</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{ --bg:#f5f6f7; --card:#ffffff; --text:#333; --muted:#666; --accent:#ffcc00; --ring: rgba(255,204,0,.4); }
    *{ box-sizing:border-box }
    html,body{ height:100% }
    body{
      margin:0; font-family:ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial;
      color:var(--text); background:var(--bg); display:grid; place-items:center; padding:24px;
    }
    .wrap{ width:min(92vw, 480px); background:var(--card); border:1px solid rgba(0,0,0,.05);
      border-radius:12px; padding:28px; box-shadow:0 8px 24px rgba(0,0,0,.08) }
    .title{ display:flex; align-items:center; gap:12px; margin-bottom:10px }
    .dot{ width:12px; height:12px; border-radius:999px; background:var(--accent); box-shadow:0 0 0 6px var(--ring), 0 0 20px var(--accent) }
    h2{ margin:0; font-size:22px }
    p.sub{ margin:6px 0 18px; color:var(--muted); font-size:13px }

    form{ display:grid; gap:12px }
    label{ font-size:12px; color:var(--muted); margin-bottom:6px; display:block }
    input[type=text], input[type=password], input[type=email]{
      width:100%; padding:12px 14px; background:#fff; border:1px solid #d9dee3; border-radius:10px; font-size:15px;
      outline:none; transition:border-color .15s, box-shadow .15s
    }
    input:focus{ border-color:var(--accent); box-shadow:0 0 0 3px var(--ring) }

    /* Avatar */
    .avatar-row{ display:flex; align-items:center; gap:14px }
    .avatar{
      width:64px; height:64px; border-radius:999px; object-fit:cover; background:#fafafa;
      border:1px solid #e6e6e6; box-shadow: inset 0 0 0 1px rgba(0,0,0,.02)
    }
    .btn-file{ display:inline-block; padding:10px 12px; border-radius:10px; border:1px solid #e3e3e3; background:#fff; cursor:pointer; font-weight:700; font-size:13px }
    .help{ font-size:12px; color:#888 }

    .actions{ margin-top:6px }
    .btn{ width:100%; border:none; cursor:pointer; border-radius:999px; padding:12px 16px; font-weight:700; font-size:15px }
    .btn-primary{ background:var(--accent); color:#000 }
    .btn-primary:hover{ background:#e6b800 }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="title">
      <span class="dot"></span>
      <h2>สมัครสมาชิก</h2>
    </div>
    <p class="sub">กรอกข้อมูลด้านล่างเพื่อสร้างบัญชีใหม่</p>

    <!-- ต้องใส่ enctype เพื่อให้อัปโหลดไฟล์ได้ -->
    <form id="registerForm" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
      <div>
        <label>ชื่อผู้ใช้</label>
        <input type="text" name="username" placeholder="ชื่อผู้ใช้" required minlength="3" maxlength="20" />
      </div>

      <div class="avatar-row">
        <img id="avatarPreview" class="avatar" src="assets/no-avatar.png" alt="avatar">
        <div>
          <label>รูปโปรไฟล์ (ไม่บังคับ)</label>
          <input type="file" id="avatar" name="avatar" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none">
          <button type="button" class="btn-file" onclick="document.getElementById('avatar').click()">เลือกไฟล์</button>
          <div class="help">รองรับ JPG/PNG/WebP/GIF ขนาดไม่เกิน 2MB</div>
        </div>
      </div>

      <div>
        <label>รหัสผ่าน</label>
        <input type="password" name="password" placeholder="รหัสผ่าน" id="password" required minlength="6" />
      </div>
      <div>
        <label>ยืนยันรหัสผ่าน</label>
        <input type="password" name="confirm" placeholder="ยืนยันรหัสผ่าน" id="confirm" required minlength="6" />
      </div>
      <div>
        <label>ชื่อจริง</label>
        <input type="text" name="fname" placeholder="ชื่อจริง" required />
      </div>
      <div>
        <label>นามสกุล</label>
        <input type="text" name="lname" placeholder="นามสกุล" required />
      </div>
      <div>
        <label>อีเมล (@msu.ac.th)</label>
        <input type="email" name="email" placeholder="you@msu.ac.th" id="email" required />
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-primary">สมัครสมาชิก</button>
      </div>
    </form>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // พรีวิวรูปโปรไฟล์
    document.getElementById('avatar').addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f) return;
      const url = URL.createObjectURL(f);
      const img = document.getElementById('avatarPreview');
      img.src = url;
      img.onload = () => URL.revokeObjectURL(url);
    });

    const form = document.getElementById("registerForm");
    form.addEventListener("submit", function(e) {
      e.preventDefault();
      const pwd = document.getElementById("password").value;
      const confirm = document.getElementById("confirm").value;
      const email = document.getElementById("email").value.trim();

      if (!email.toLowerCase().endsWith("@msu.ac.th")) {
        Swal.fire("ไม่สามารถสมัครได้", "กรุณาใช้อีเมลที่ลงท้ายด้วย @msu.ac.th", "warning");
        return;
      }
      if (pwd !== confirm) {
        Swal.fire("รหัสผ่านไม่ตรงกัน", "กรุณากรอกให้ตรงกัน", "error");
        return;
      }

      const formData = new FormData(form);
      fetch("<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>", {
        method: "POST",
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === "success") {
          Swal.fire({ icon: 'success', title: 'สมัครสมาชิกสำเร็จ', confirmButtonText: 'ไปหน้าเข้าสู่ระบบ' })
          .then(() => { window.location.href = "login.php"; });
        } else {
          Swal.fire("ผิดพลาด", data.message, "error");
        }
      })
      .catch(() => Swal.fire("ข้อผิดพลาด", "เกิดปัญหาในการเชื่อมต่อ", "error"));
    });
  </script>
</body>
</html>
