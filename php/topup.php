<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$conn = new mysqli("","","","");
if ($conn->connect_error) die("DB error: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

$userId = (int)$_SESSION['user_id'];

// ยอดคงเหลือ
$bal = 0.00;
$st = $conn->prepare("SELECT credit_balance FROM users WHERE user_id=?");
$st->bind_param("i",$userId); $st->execute(); $st->bind_result($bal); $st->fetch(); $st->close();

// ประวัติล่าสุด
$hist = $conn->prepare("
  SELECT topup_id, amount, method, status, created_at, approved_at, reference_no
  FROM credit_topups WHERE user_id=? ORDER BY topup_id DESC LIMIT 20
");
$hist->bind_param("i",$userId); $hist->execute(); $res = $hist->get_result();
?>
<!doctype html>
<html lang="th"><meta charset="utf-8">
<title>เติมเครดิต</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--brand:#ffcc00;--bg:#f6f7f9;--bd:#eee;--ok:#d9f7be;--wait:#fff1c2;--no:#ffd6e7}
*{box-sizing:border-box}body{font-family:Segoe UI,Arial;margin:0;background:var(--bg);color:#111}
.topbar{display:flex;align-items:center;gap:12px;background:var(--brand);padding:12px 16px;position:sticky;top:0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.back-btn{appearance:none;border:0;background:#000;color:#fff;padding:8px 14px;border-radius:999px;cursor:pointer;font-weight:700}
.title{font-size:20px;font-weight:700}
.wrap{max-width:1000px;margin:24px auto;padding:0 16px}
.balance{background:#fff8db;border:1px solid #ffe08a;padding:12px 16px;border-radius:10px;margin:0 0 18px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid var(--bd);border-radius:12px;padding:16px;box-shadow:0 8px 20px rgba(0,0,0,.05)}
.card h3{margin:0 0 10px}
label{display:block;margin:10px 0 6px;font-weight:600}
input[type=number],input[type=text],select,input[type=file]{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
button{background:var(--brand);border:0;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer}
button:disabled{opacity:.6;cursor:not-allowed}
.small{font-size:12px;color:#666}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}
.badge{padding:4px 8px;border-radius:999px;font-size:12px}
.badge.pending{background:var(--wait)} .badge.approved{background:var(--ok)} .badge.rejected{background:var(--no)}
.qrbox{display:none;margin-top:12px;text-align:center}
.qrref{margin-top:8px;font-size:13px;color:#444}
</style>
<body>
<div class="topbar">
  <button class="back-btn" onclick="goBack()">&larr; กลับ</button>
  <div class="title">เติมเครดิต</div>
</div>

<div class="wrap">
  <div class="balance">ยอดคงเหลือปัจจุบัน: <b><?= number_format((float)$bal,2) ?></b> บาท</div>

  <div class="grid">
    <div class="card">
      <h3>ยื่นคำขอเติมเครดิต</h3>

      <!-- ใส่จำนวนเงิน + ปุ่มสร้าง QR -->
      <label>จำนวนเงิน (บาท)</label>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="number" id="amountInput" min="1" step="0.01" placeholder="ใส่จำนวนเงินที่ต้องการเติม">
        <button type="button" id="btnCreateQR">สร้าง QR PromptPay</button>
      </div>
      <div class="small">ระบบจะสร้างคำขอแบบ pending และอ้างอิง (Ref) เพื่อใช้จับคู่กับสลิป</div>

      <!-- แสดง QR จาก pp-qr.com -->
      <div class="qrbox" id="qrBox">
        <img id="qrImg" alt="QR PromptPay" width="220" height="220" style="border-radius:8px;border:1px solid #eee">
        <div class="qrref">อ้างอิง: <b id="refText">-</b> • หมดอายุภายใน 15 นาที</div>
        <hr>
      </div>

      <!-- ฟอร์มอัปโหลดสลิป → Thunder ตรวจ -->
      <form id="verifyForm" action="topup_process.php?action=verify_slip" method="post" enctype="multipart/form-data">
        <input type="hidden" name="ref" id="refField">
        <label>อัปโหลดสลิป (JPG/PNG/WebP ≤ 5MB)</label>
        <input type="file" name="slip" id="slipInput" accept=".jpg,.jpeg,.png,.webp" required>
        <div style="margin-top:12px">
          <button type="submit" id="btnVerify" disabled>อัปโหลดสลิปเพื่อตรวจ</button>
          <span class="small" id="verifyHint">* ต้องสร้าง QR และได้ Ref ก่อน</span>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>ประวัติคำขอ</h3>
      <table>
        <tr><th>#</th><th>จำนวน</th><th>ช่องทาง</th><th>สถานะ</th><th>อ้างอิง</th><th>ยื่นเมื่อ</th><th>อนุมัติ</th></tr>
        <?php while($row=$res->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$row['topup_id'] ?></td>
            <td><?= number_format((float)$row['amount'],2) ?></td>
            <td><?= htmlspecialchars($row['method'] ?: '-') ?></td>
            <td><span class="badge <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
            <td><?= htmlspecialchars($row['reference_no'] ?: '-') ?></td>
            <td><?= htmlspecialchars($row['created_at']) ?></td>
            <td><?= htmlspecialchars($row['approved_at'] ?? '-') ?></td>
          </tr>
        <?php endwhile; $hist->close(); $conn->close(); ?>
      </table>
    </div>
  </div>
</div>

<script>
const amountInput = document.getElementById('amountInput');
const btnCreateQR = document.getElementById('btnCreateQR');
const qrBox = document.getElementById('qrBox');
const qrImg = document.getElementById('qrImg');
const refField = document.getElementById('refField');
const refText = document.getElementById('refText');
const btnVerify = document.getElementById('btnVerify');
const slipInput = document.getElementById('slipInput');
const verifyHint = document.getElementById('verifyHint');

btnCreateQR.addEventListener('click', async () => {
  const amt = parseFloat((amountInput.value || '0').replace(',', ''));
  if (!(amt > 0)) { alert('กรุณากรอกจำนวนเงินให้ถูกต้อง'); amountInput.focus(); return; }

  btnCreateQR.disabled = true; btnCreateQR.textContent = 'กำลังสร้าง...';
  try {
    const resp = await fetch('topup_process.php?action=create_qr', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ amount: amt })
    });
    const data = await resp.json();
    if (!data || !data.ok) throw new Error(data?.message || 'สร้าง QR ไม่สำเร็จ');

    // แสดง QR จาก URL ที่ server สร้าง (pp-qr.com)
    qrImg.src = data.qr_img;            // เช่น https://www.pp-qr.com/api/image/08xxx/100
    refField.value = data.ref;
    refText.textContent = data.ref;
    qrBox.style.display = 'block';

    btnVerify.disabled = false;
    verifyHint.textContent = 'พร้อมอัปโหลดสลิปเพื่อตรวจ';
  } catch (e) {
    alert(e.message);
  } finally {
    btnCreateQR.disabled = false; btnCreateQR.textContent = 'สร้าง QR PromptPay';
  }
});

// client-side file size check
slipInput.addEventListener('change', () => {
  const f = slipInput.files?.[0]; if (!f) return;
  if (f.size > 5*1024*1024) { alert('ไฟล์ใหญ่เกิน 5MB'); slipInput.value=''; }
});

const verifyForm = document.getElementById('verifyForm');
verifyForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('btnVerify');
  btn.disabled = true; btn.textContent = 'กำลังตรวจ...';

  try {
    const resp = await fetch(verifyForm.action, { method: 'POST', body: new FormData(verifyForm) });
    const data = await resp.json();

    if (data && data.ok) {
      alert('เติมเครดิตสำเร็จ +' + Number(data.amount).toFixed(2) + ' บาท');
      // รีโหลดหน้าเพื่อดึงยอดคงเหลือล่าสุดจากฐานข้อมูล
      location.reload();
    } else {
      alert(data?.message || 'ตรวจสลิปไม่สำเร็จ');
      btn.disabled = false; btn.textContent = 'อัปโหลดสลิปเพื่อตรวจ';
    }
  } catch (err) {
    alert('มีข้อผิดพลาดในการเชื่อมต่อ');
    btn.disabled = false; btn.textContent = 'อัปโหลดสลิปเพื่อตรวจ';
  }
});

function goBack(){  window.location.href = "../index.php"; }
</script>
</body>
</html>
