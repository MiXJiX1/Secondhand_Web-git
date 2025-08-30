<?php
session_start();

// ต้องล็อกอินก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$servername = "";
$username   = "";
$password   = "";
$dbname     = "";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$currentUserId = (int)$_SESSION['user_id'];

// ---------- ฟังก์ชันช่วย ----------
function packImagesForField(array $names): string {
    $names = array_values(array_filter(array_map(fn($s)=>basename((string)$s), $names)));
    if (count($names) <= 1) return $names[0] ?? '';
    $json = json_encode($names, JSON_UNESCAPED_SLASHES);
    if (strlen($json) <= 250) return $json;
    $reduced = [];
    foreach ($names as $fn) {
        $reduced[] = $fn;
        $try = json_encode($reduced, JSON_UNESCAPED_SLASHES);
        if (strlen($try) > 250) { array_pop($reduced); break; }
    }
    return json_encode($reduced, JSON_UNESCAPED_SLASHES);
}

$categories = [
    'electronics' => 'อุปกรณ์อิเล็กทรอนิกส์',
    'fashion'     => 'แฟชั่น',
    'furniture'   => 'เฟอร์นิเจอร์',
    'vehicle'     => 'ยานพาหนะ',
    'gameandtoys' => 'เกมและของเล่น',
    'household'   => 'ของใช้ในครัวเรือน',
    'sport'       => 'อุปกรณ์กีฬา',
    'music'       => 'เครื่องดนตรี',
    'others'      => 'อื่นๆ',
];

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrfToken = $_SESSION['csrf_token'];

$successMsg = $errorMsg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMsg = "CSRF token ไม่ถูกต้อง";
    } else {
        $name  = trim($_POST['product_name'] ?? '');
        $price = trim($_POST['product_price'] ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $desc  = trim($_POST['description'] ?? '');

        if ($name === '' || !is_numeric($price) || (float)$price < 0) {
            $errorMsg = "กรุณากรอกชื่อสินค้าและราคาที่ถูกต้อง";
        } else {
            // อัปโหลดหลายรูป
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            $allowed = ['jpg','jpeg','png','webp','gif'];
            $imageNames = [];

            if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
                foreach ($_FILES['images']['name'] as $i => $fname) {
                    if (!is_uploaded_file($_FILES['images']['tmp_name'][$i])) continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed)) continue;

                    $new = 'p_'.bin2hex(random_bytes(6)).'.'.$ext;
                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir.$new)) {
                        $imageNames[] = $new;
                    }
                }
            }

            $productImageField = packImagesForField($imageNames);
            $priceFloat = (float)$price;

            $ins = $conn->prepare("INSERT INTO products
                (product_name, product_price, product_image, category, description, user_id)
                VALUES (?, ?, ?, ?, ?, ?)");
            $ins->bind_param('sdsssi', $name, $priceFloat, $productImageField, $cat, $desc, $currentUserId);
            if ($ins->execute()) {
                $newId = $ins->insert_id;
                $ins->close();
                header("Location: product_detail.php?id=" . (int)$newId);
                exit();
            } else {
                $errorMsg = "บันทึกสินค้าไม่สำเร็จ: " . $conn->error;
                $ins->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ลงขายสินค้า</title>
<style>
    :root{
        --brand:#ffcc00; --ink:#333; --muted:#666; --bg:#f8f9fa;
        --primary:#1f7aec; --primaryHover:#0f5fc0; --shadow:0 8px 24px rgba(0,0,0,.08)
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--ink)}
    header{background:var(--brand);padding:16px;text-align:center}
    header h1{margin:0;font-weight:800;font-size:22px}

    .wrap{max-width:980px;margin:26px auto;padding:0 16px}
    .card{background:#fff;border-radius:16px;box-shadow:var(--shadow);padding:22px}

    .alert{padding:12px 16px;border-radius:12px;margin-bottom:14px}
    .success{background:#e7f7ec;border:1px solid #b4e1c2;color:#226b3a}
    .error{background:#fdecea;border:1px solid #f5c2c0;color:#a12622}

    .grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
    @media (max-width:900px){ .grid{grid-template-columns:1fr} }

    label{display:block;font-weight:800;margin:8px 0 6px}
    input[type=text], input[type=number], select, textarea{
        width:100%; padding:12px 14px; border:1px solid #dcdcdc; border-radius:12px; font-size:14px;
        transition:border-color .15s, box-shadow .15s; background:#fff
    }
    input:focus, select:focus, textarea:focus{
        outline:none; border-color:#ffcc00; box-shadow:0 0 0 3px rgba(255,204,0,.25)
    }
    textarea{min-height:180px; resize:vertical}

    /* Dropzone */
    .fileBox{
        border:2px dashed #d7d7d7; border-radius:14px; padding:20px; text-align:center; background:#fafafa;
        display:flex; flex-direction:column; align-items:center; gap:10px
    }
    .fileBox.drag{ border-color:#ffcc00; background:#fff8d6 }
    .hint{font-size:12px;color:var(--muted)}
    .previews{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:10px;width:100%}
    .thumb{width:100%;height:90px;object-fit:cover;border-radius:10px;border:1px solid #eee;background:#fff}

    .actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
    .btn{padding:12px 18px;border-radius:12px;border:none;font-weight:800;cursor:pointer}
    .btn-primary{background:var(--primary);color:#fff}
    .btn-primary:hover{background:var(--primaryHover)}
    .btn-secondary{background:#1f1f1f;color:#fff}
    .btn-secondary:hover{background:#000}

    /* ทำให้คอลัมน์สูงใกล้เคียงกัน */
    .column{display:flex;flex-direction:column;gap:12px}
</style>
</head>
<body>
<header><h1>ลงขายสินค้า</h1></header>

<div class="wrap">
    <div class="card">
        <?php if($errorMsg): ?><div class="alert error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
        <?php if($successMsg): ?><div class="alert success"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="grid">
                <div class="column">
                    <div>
                        <label for="product_name">ชื่อสินค้า</label>
                        <input type="text" id="product_name" name="product_name" maxlength="255" required>
                    </div>

                    <div>
                        <label for="product_price">ราคา (บาท)</label>
                        <input type="number" id="product_price" name="product_price" min="0" step="0.01" required>
                    </div>

                    <div>
                        <label for="category">หมวดหมู่</label>
                        <select id="category" name="category">
                            <option value="">-- เลือกหมวดหมู่ --</option>
                            <?php foreach($categories as $val=>$label): ?>
                                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="column">
                    <div>
                        <label for="description">รายละเอียด</label>
                        <textarea id="description" name="description" placeholder="ใส่รายละเอียดสินค้า..."></textarea>
                    </div>

                    <div>
                        <label>อัปโหลดรูปสินค้า (ได้หลายรูป)</label>
                        <div class="fileBox" id="dropzone">
                            <input id="fileInput" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,.gif" multiple style="display:none">
                            <div>
                                <button type="button" class="btn btn-secondary" id="chooseBtn">เลือกไฟล์</button>
                            </div>
                            <div class="hint">ลากรูปมาวางได้ | รองรับ JPG/PNG/WebP/GIF</div>
                            <div class="previews" id="previews"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">บันทึกสินค้า</button>
                <a href="index.php" class="btn btn-secondary">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const dz = document.getElementById('dropzone');
    const input = document.getElementById('fileInput');
    const choose = document.getElementById('chooseBtn');
    const previews = document.getElementById('previews');

    choose.addEventListener('click', ()=> input.click());

    function addFiles(files){
        // รวมไฟล์เดิมกับไฟล์ใหม่
        const dt = new DataTransfer();
        // เก็บไฟล์เดิม (ถ้ามี)
        for (const f of input.files) dt.items.add(f);
        // เพิ่มไฟล์ใหม่
        for (const f of files) dt.items.add(f);
        input.files = dt.files;
        renderPreviews();
    }

    function renderPreviews(){
        previews.innerHTML = '';
        if (!input.files || input.files.length === 0) return;
        Array.from(input.files).forEach(file=>{
            const url = URL.createObjectURL(file);
            const img = document.createElement('img');
            img.src = url; img.className = 'thumb';
            img.onload = ()=> URL.revokeObjectURL(url);
            previews.appendChild(img);
        });
    }

    // drag and drop
    ['dragenter','dragover'].forEach(evt=>{
        dz.addEventListener(evt, e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.add('drag'); });
    });
    ['dragleave','drop'].forEach(evt=>{
        dz.addEventListener(evt, e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.remove('drag'); });
    });
    dz.addEventListener('drop', e=>{
        const files = e.dataTransfer.files;
        if (files && files.length) addFiles(files);
    });

    input.addEventListener('change', renderPreviews);
})();
</script>
</body>
</html>
