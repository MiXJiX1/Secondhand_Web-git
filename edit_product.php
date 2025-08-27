<?php
session_start();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$mysqli = new mysqli("sczfile.online","mix","mix1234","secondhand_web");
if ($mysqli->connect_error) die("DB error: ".$mysqli->connect_error);
$mysqli->set_charset("utf8mb4");

$currentUserId = (int)$_SESSION['user_id'];
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) { http_response_code(400); exit("รหัสสินค้าผิดพลาด"); }

/* ---------- helper ---------- */
function allImagesFromField(?string $s): array {
    if (!$s) return [];
    $s = trim($s);
    if ($s !== '' && $s[0] === '[') {
        $arr = json_decode($s, true);
        if (is_array($arr)) return array_values(array_filter(array_map(fn($x)=>basename((string)$x), $arr)));
    }
    $parts = preg_split('/[|,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts) return array_values(array_filter(array_map(fn($x)=>basename(trim($x)), $parts)));
    return [basename($s)];
}
function packImagesForField(array $names): string {
    $names = array_values(array_filter(array_map(fn($s)=>basename((string)$s), $names)));
    if (count($names) <= 1) return $names[0] ?? '';
    $json = json_encode($names, JSON_UNESCAPED_SLASHES);
    if (strlen($json) <= 250) return $json;
    $reduced=[];
    foreach($names as $fn){ $reduced[]=$fn; $try=json_encode($reduced,JSON_UNESCAPED_SLASHES); if(strlen($try)>250){array_pop($reduced);break;}}
    return json_encode($reduced, JSON_UNESCAPED_SLASHES);
}

$categories = [
    'electronics'=>'อุปกรณ์อิเล็กทรอนิกส์','fashion'=>'แฟชั่น','furniture'=>'เฟอร์นิเจอร์',
    'vehicle'=>'ยานพาหนะ','gameandtoys'=>'เกมและของเล่น','household'=>'ของใช้ในครัวเรือน',
    'sport'=>'อุปกรณ์กีฬา','music'=>'เครื่องดนตรี','others'=>'อื่นๆ',
];

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrfToken = $_SESSION['csrf_token'];

/* ---------- ดึงสินค้า + ตรวจสิทธิ์ ---------- */
$st = $mysqli->prepare("SELECT product_id, product_name, product_price, product_image, category, description, status, sold_at, user_id AS owner_id
                        FROM products WHERE product_id=? LIMIT 1");
$st->bind_param('i',$productId);
$st->execute();
$prod = $st->get_result()->fetch_assoc();
$st->close();
if (!$prod) { http_response_code(404); exit("ไม่พบสินค้า"); }
if ((int)$prod['owner_id'] !== $currentUserId) { http_response_code(403); exit("คุณไม่มีสิทธิ์แก้ไขสินค้านี้"); }

$successMsg = $errorMsg = "";

/* ---------- Action: ปิดการขาย / เปิดขาย ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { $errorMsg="CSRF token ไม่ถูกต้อง"; }
    else {
        if ($_POST['action']==='close_sale') {
            $q = $mysqli->prepare("UPDATE products SET status='sold', sold_at=NOW() WHERE product_id=? AND user_id=?");
            $q->bind_param('ii', $productId, $currentUserId);
            $q->execute();
            if ($q->affected_rows>=0){ $successMsg="ปิดการขายเรียบร้อย"; $prod['status']='sold'; $prod['sold_at']=date('Y-m-d H:i:s'); }
            $q->close();
        } elseif ($_POST['action']==='reopen_sale') {
            $q = $mysqli->prepare("UPDATE products SET status='active', sold_at=NULL WHERE product_id=? AND user_id=?");
            $q->bind_param('ii', $productId, $currentUserId);
            $q->execute();
            if ($q->affected_rows>=0){ $successMsg="เปิดขายอีกครั้งแล้ว"; $prod['status']='active'; $prod['sold_at']=null; }
            $q->close();
        }
    }
}

/* ---------- บันทึกการแก้ไขทั่วไป ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['product_name']) && !isset($_POST['action'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $errorMsg = "CSRF token ไม่ถูกต้อง";
    } else {
        $name  = trim($_POST['product_name'] ?? '');
        $price = (float)($_POST['product_price'] ?? 0);
        $cat   = trim($_POST['category'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $status= in_array($_POST['status'] ?? 'active',['active','sold','hidden'],true) ? $_POST['status'] : 'active';

        if ($name==='' || $price<0) {
            $errorMsg = "กรุณากรอกชื่อสินค้าและราคาที่ถูกต้อง";
        } else {
            $uploadDir = __DIR__.'/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir,0777,true);
            $allowed = ['jpg','jpeg','png','webp','gif'];

            $oldImages = allImagesFromField($prod['product_image']);
            $newImages = [];
            if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
                foreach ($_FILES['images']['name'] as $i=>$fname) {
                    if (!is_uploaded_file($_FILES['images']['tmp_name'][$i])) continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext,$allowed,true)) continue;
                    $new = 'p_'.$productId.'_'.bin2hex(random_bytes(4)).'.'.$ext;
                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir.$new)) $newImages[]=$new;
                }
            }
            if (!empty($_POST['replace_images']) && $_POST['replace_images']=='1') {
                foreach ($oldImages as $old) { if ($old && file_exists($uploadDir.$old)) @unlink($uploadDir.$old); }
                $finalImages = $newImages;
            } else {
                $finalImages = array_values(array_unique(array_merge($oldImages,$newImages)));
            }
            $imgField = packImagesForField($finalImages);

            // sold_at: ถ้าสถานะเปลี่ยนเป็น sold และเดิมไม่ใช่ sold → set เวลา; ถ้าไม่ใช่ → คงค่าเดิม
            $setSoldAt = ($status==='sold' && $prod['status']!=='sold') ? ", sold_at=NOW()" : (($status!=='sold') ? ", sold_at=NULL" : "");

            $sql = "UPDATE products SET product_name=?, product_price=?, category=?, description=?, product_image=?, status=? $setSoldAt
                    WHERE product_id=? AND user_id=?";
            $upd = $mysqli->prepare($sql);
            $upd->bind_param('sdssssii', $name, $price, $cat, $desc, $imgField, $status, $productId, $currentUserId);
            if ($upd->execute()) {
                $successMsg = "บันทึกการแก้ไขสำเร็จ";
                $prod['product_name']=$name; $prod['product_price']=$price; $prod['category']=$cat;
                $prod['description']=$desc; $prod['product_image']=$imgField; $prod['status']=$status;
                if ($setSoldAt!=='' ) $prod['sold_at'] = ($status==='sold') ? date('Y-m-d H:i:s') : null;
            } else {
                $errorMsg = "เกิดข้อผิดพลาดในการบันทึก: ".$mysqli->error;
            }
            $upd->close();
        }
    }
}
$mysqli->close();
?>
<!doctype html>
<html lang="th">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>แก้ไขสินค้า</title>
<style>
:root{--brand:#ffcc00;--ink:#333;--bg:#f8f9fa;--shadow:0 6px 18px rgba(0,0,0,.08)}
body{margin:0;font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--ink)}
header{background:var(--brand);padding:16px;display:flex;justify-content:center;align-items:center}
header h1{margin:0;font-size:22px;font-weight:800}
.wrap{max-width:1000px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:20px}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:12px}
.success{background:#e7f7ec;border:1px solid #b4e1c2;color:#226b3a}
.error{background:#fdecea;border:1px solid #f5c2c0;color:#a12622}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
label{font-weight:700;margin:6px 0 4px;display:block}
input[type=text],input[type=number],select,textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px;font-size:14px}
textarea{min-height:120px;resize:vertical}
.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.gallery img{width:100%;height:120px;object-fit:cover;border-radius:10px}
.fileBox{border:2px dashed #ccc;border-radius:12px;padding:16px;text-align:center}
.actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
.btn{padding:12px 18px;border-radius:10px;border:none;font-weight:800;cursor:pointer}
.btn-primary{background:#1f7aec;color:#fff}.btn-primary:hover{background:#0f5fc0}
.btn-secondary{background:#333;color:#fff}.btn-secondary:hover{background:#000}
.btn-danger{background:#b91c1c;color:#fff}.btn-danger:hover{background:#991b1b}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px}
.badge.active{background:#e6fffb;color:#006d75}
.badge.sold{background:#ffe2e5;color:#b71c1c}
.badge.hidden{background:#f0f0f0;color:#555}
</style>
<body>
<header><h1>แก้ไขสินค้า</h1></header>

<div class="wrap">
  <div class="card">
    <?php if($successMsg):?><div class="alert success"><?=htmlspecialchars($successMsg)?></div><?php endif;?>
    <?php if($errorMsg):?><div class="alert error"><?=htmlspecialchars($errorMsg)?></div><?php endif;?>

    <!-- แถวแสดงสถานะ + ปุ่มปิด/เปิดขาย -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
      <div>สถานะปัจจุบัน: <span class="badge <?= htmlspecialchars($prod['status']) ?>"><?= htmlspecialchars($prod['status']) ?></span>
        <?php if($prod['sold_at']):?> <small style="color:#666"> (ขายเมื่อ <?= htmlspecialchars($prod['sold_at']) ?>)</small><?php endif;?>
      </div>
      <form method="post" style="margin-left:auto">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <?php if($prod['status']!=='sold'): ?>
          <button class="btn btn-danger" name="action" value="close_sale" onclick="return confirm('ยืนยันปิดการขาย?')">ปิดการขาย</button>
        <?php else: ?>
          <button class="btn btn-success" name="action" value="reopen_sale">เปิดขายอีกครั้ง</button>
        <?php endif; ?>
      </form>
    </div>

    <form method="post" enctype="multipart/form-data" action="edit_product.php?id=<?= (int)$prod['product_id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

      <div class="grid">
        <div>
          <label for="product_name">ชื่อสินค้า</label>
          <input type="text" id="product_name" name="product_name" maxlength="255" value="<?= htmlspecialchars($prod['product_name']) ?>" required>

          <label for="product_price">ราคา (บาท)</label>
          <input type="number" id="product_price" name="product_price" min="0" step="0.01" value="<?= htmlspecialchars((string)$prod['product_price']) ?>" required>

          <label for="category">หมวดหมู่</label>
          <select id="category" name="category">
            <option value="">-- เลือกหมวดหมู่ --</option>
            <?php foreach($categories as $val=>$label): ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $prod['category']===$val?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>

          <label for="status">สถานะสินค้า</label>
          <select id="status" name="status">
            <option value="active" <?= $prod['status']==='active'?'selected':'' ?>>เปิดขาย (active)</option>
            <option value="sold"   <?= $prod['status']==='sold'?'selected':'' ?>>ปิดการขาย/ขายแล้ว (sold)</option>
            <option value="hidden" <?= $prod['status']==='hidden'?'selected':'' ?>>ซ่อนประกาศ (hidden)</option>
          </select>

          <label for="description">รายละเอียด</label>
          <textarea id="description" name="description"><?= htmlspecialchars($prod['description'] ?? '') ?></textarea>
        </div>

        <div>
          <label>รูปปัจจุบัน</label>
          <?php $imgs = allImagesFromField($prod['product_image']); if (empty($imgs)) $imgs=['assets/no-image.png']; ?>
          <div class="gallery">
            <?php foreach($imgs as $fn): $src = (strpos($fn,'assets/')===0)? $fn : 'uploads/'.$fn; ?>
              <img src="<?= htmlspecialchars($src) ?>" alt="image" onerror="this.src='assets/no-image.png';">
            <?php endforeach; ?>
          </div>

          <label style="margin-top:12px">อัปโหลดรูปใหม่ (ใส่ได้หลายรูป)</label>
          <div class="fileBox">
            <input type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
            <div style="font-size:12px;color:#666;margin-top:6px">รองรับ JPG/PNG/WebP/GIF</div>
            <label style="display:inline-flex;gap:6px;align-items:center;margin-top:10px">
              <input type="checkbox" name="replace_images" value="1"> แทนที่รูปเดิมทั้งหมดด้วยรูปใหม่
            </label>
          </div>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
        <a href="product_detail.php?id=<?= (int)$prod['product_id'] ?>" class="btn btn-secondary">ยกเลิก</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
