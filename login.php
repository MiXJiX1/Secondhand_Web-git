<?php
/** -----------------------------------------------------------
 *  login.php (รวมหน้าแบบฟอร์ม + ประมวลผล)
 *  เปิด debug ชั่วคราว: ปิดเมื่อขึ้นโปรดักชัน
 *  ----------------------------------------------------------- */
ini_set('display_errors', 1);               // ❗ โปรดเปลี่ยนเป็น 0 บนโปรดักชัน
ini_set('display_startup_errors', 1);       // ❗ โปรดเปลี่ยนเป็น 0 บนโปรดักชัน
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();

/* ====== ตั้งค่าเชื่อมต่อฐานข้อมูล ====== */
$servername = "sczfile.online";
$username   = "mix";
$password   = "mix1234";
$dbname     = "secondhand_web";

/* ====== เชื่อมต่อฐานข้อมูล ====== */
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}

/* ====== ประมวลผลเมื่อส่งฟอร์ม (POST) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $inputUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
    $inputEmail    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
    $inputPassword = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if ($inputUsername === '' || $inputEmail === '' || $inputPassword === '') {
        header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']) . '?error=1');
        exit;
    }

    try {
        // โครงตาราง users: user_id, username, password, email, role, status, ...
        $sql  = "SELECT user_id, username, email, password, role, status
                 FROM users
                 WHERE username = ? AND email = ?
                 LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $inputUsername, $inputEmail);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $dbPass = (string)($row['password'] ?? '');

            // รองรับทั้งแบบ hash (password_hash) และเก็บตรง (plain text)
            $ok = false;
            if ($dbPass !== '') {
                if (password_verify($inputPassword, $dbPass)) {
                    $ok = true;
                } elseif (hash_equals($dbPass, $inputPassword)) { // fallback สำหรับระบบเดิม
                    $ok = true;
                }
            }

            if ($ok) {
                // (ถ้าจะบังคับสถานะ active ให้ปลดคอมเมนต์)
                // if (isset($row['status']) && strtolower($row['status']) !== 'active') {
                //     header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']) . '?error=2');
                //     exit;
                // }

                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$row['user_id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role']     = $row['role'] ?? 'user';

                // Redirect ตาม role
                if ($_SESSION['role'] === 'admin') {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: index.php');
                }
                exit;
            } else {
                header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']) . '?error=1');
                exit;
            }
        } else {
            header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']) . '?error=1');
            exit;
        }
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        die("Query ล้มเหลว: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เข้าสู่ระบบ</title>

  <!-- ✅ ใช้สไตล์ภายในไฟล์เดียว คงโทนพื้นมืด + เหลือง #ffcc00 -->
  <style>
:root{
  --bg:#f5f6f7; /* ✅ เปลี่ยนพื้นหลังเป็นโทนสว่าง */
  --card:#ffffff; /* กล่องฟอร์มพื้นขาว */
  --text:#333333; 
  --muted:#666666;
  --accent:#ffcc00;      
  --ring: rgba(255, 204, 0, .4);
}
    *{ box-sizing: border-box; }
    html,body{ height:100%; }
body{
  margin:0;
  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
  color:var(--text);
  background-color: var(--bg); /* ใช้สีพื้นหลังสว่าง */
  display:grid; 
  place-items:center;
  padding:24px;
}

    .wrap{
      width:min(92vw, 460px);
      background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
      backdrop-filter: blur(8px);
      border:1px solid rgba(255,255,255,.08);
      border-radius: 20px;
      padding: 28px;
      box-shadow: 0 10px 30px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
      animation: pop .25s ease;
    }
    @keyframes pop{ from{ transform: scale(.98); opacity:.7 } }

    .title{
      display:flex; align-items:center; gap:12px; margin-bottom:10px;
    }
    .dot{
      width:12px; height:12px; border-radius:999px; background:var(--accent);
      box-shadow:0 0 0 6px var(--ring), 0 0 20px var(--accent);
    }
    h2{ margin:0; font-size:22px; letter-spacing:.3px; }
    p.sub{ margin:6px 0 18px; color:var(--muted); font-size:13px; }

    form{ display:grid; gap:14px; }
    label{ font-size:12px; color:var(--muted); display:block; margin-bottom:6px; }
    .control{
      position:relative;
    }
    input{
      width:100%;
      padding: 12px 14px;
      background:#FFFFFF;
      border:1px solid rgba(255,255,255,.12);
      border-radius:12px;
      color:var(--text);
      outline:none;
      transition:border-color .15s ease, box-shadow .15s ease;
    }
    input:focus{
      border-color:var(--accent);
      box-shadow:0 0 0 3px var(--ring);
    }
    .toggle{
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      background:transparent; border:none; color:var(--muted); cursor:pointer; font-size:12px;
    }
    .actions{ display:grid; gap:10px; margin-top:6px; }
    .btn{
      width:100%; border:none; cursor:pointer;
      border-radius:12px; padding:12px 16px; font-weight:700; font-size:15px;
      transition: transform .04s ease;
    }
    .btn-primary{
      background: linear-gradient(90deg, var(--accent), #ffdb4d);
      color:#121212;
    }
    .btn-primary:hover{ transform: translateY(-1px); }
    .btn-ghost{
      background: transparent; color:var(--text);
      border:1px solid rgba(255,255,255,.18);
    }
    .footer{
      margin-top:12px; text-align:center; font-size:12px; color:var(--muted);
    }
    a.link{ color:#51e1a7; text-decoration:none; }
    .hint{ font-size:12px; color:var(--muted); margin-top:-6px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="title">
      <span class="dot"></span>
      <h2>เข้าสู่ระบบ</h2>
    </div>
    <p class="sub">กรอกชื่อผู้ใช้ อีเมล และรหัสผ่านเพื่อเข้าสู่ระบบ</p>

    <form id="loginForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" novalidate>
      <div>
        <label for="username">ชื่อผู้ใช้</label>
        <div class="control">
          <input type="text" id="username" name="username" placeholder="กรอกชื่อผู้ใช้" required minlength="3" autocomplete="username">
        </div>
      </div>

      <div>
        <label for="email">อีเมล</label>
        <div class="control">
          <input type="email" id="email" name="email" placeholder="กรอกอีเมล" required autocomplete="email">
        </div>
      </div>

      <div>
        <label for="password">รหัสผ่าน</label>
        <div class="control">
          <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required minlength="6" autocomplete="current-password">
          <button class="toggle" type="button" id="togglePass">แสดง</button>
        </div>
        <div class="hint">อย่างน้อย 6 ตัวอักษร</div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-primary" id="loginBtn">เข้าสู่ระบบ</button>
        <button type="button" class="btn btn-ghost" onclick="location.href='register.php'">ยังไม่มีบัญชี? สมัครสมาชิก</button>
        <button type="button" class="btn btn-ghost" onclick="location.href='register.php'">ลืมรหัสผ่าน?</button>   
      </div>
    </form>

    <div class="footer">
      ปลอดภัยขึ้นด้วยการเข้ารหัสรหัสผ่านฝั่งเซิร์ฟเวอร์
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // Toggle password
    const toggle = document.getElementById('togglePass');
    const pwd = document.getElementById('password');
    toggle.addEventListener('click', () => {
      const isPwd = pwd.getAttribute('type') === 'password';
      pwd.setAttribute('type', isPwd ? 'text' : 'password');
      toggle.textContent = isPwd ? 'ซ่อน' : 'แสดง';
    });

    // Disable ปุ่มตอน submit กันคลิกซ้ำ
    const form = document.getElementById('loginForm');
    const btn  = document.getElementById('loginBtn');
    form.addEventListener('submit', () => {
      btn.disabled = true;
      btn.textContent = 'กำลังเข้าสู่ระบบ...';
      setTimeout(() => { btn.disabled = false; btn.textContent = 'เข้าสู่ระบบ'; }, 6000);
    });

    // แสดง error จาก query string
    const url = new URL(window.location.href);
    if (url.searchParams.get("error") === "1") {
      Swal.fire({ icon: 'error', title: 'เข้าสู่ระบบล้มเหลว', text: 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง' });
    }
    if (url.searchParams.get("error") === "2") {
      Swal.fire({ icon: 'warning', title: 'ไม่สามารถเข้าสู่ระบบได้', text: 'บัญชีของคุณยังไม่พร้อมใช้งาน' });
    }
  </script>
</body>
</html>
