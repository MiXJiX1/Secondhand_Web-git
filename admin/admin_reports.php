<?php
/* admin_reports.php — จัดการรายงาน/ข้อร้องเรียน (Admin) */
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();

/* ====== ตรวจสิทธิ์แอดมิน ====== */
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';
if ($role !== 'admin') { http_response_code(403); die('Forbidden'); }

/* ====== DB ====== */
$pdo = new PDO(
  "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
  "mix","mix1234",
  [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
);

/* ====== ตารางที่ใช้ (ถ้ายังไม่มีให้สร้าง) ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS abuse_reports(
  report_id INT AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT NOT NULL,
  target_kind VARCHAR(32) NOT NULL,     -- user, product, exchange_item, chat, order, other
  target_id VARCHAR(64) NOT NULL,       -- id ของเป้าหมาย (string เพื่อรองรับ request_id)
  reason VARCHAR(120) NOT NULL,
  details TEXT NULL,
  evidence JSON NULL,                   -- เก็บไฟล์หลักฐาน (เช่น ['up/evi1.jpg','up/evi2.png'])
  status ENUM('open','reviewing','resolved','rejected','spam') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  assigned_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(reporter_id), INDEX(target_kind), INDEX(status), INDEX(assigned_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS abuse_report_actions(
  action_id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  admin_id INT NOT NULL,
  action_type ENUM('status_change','note','assign') NOT NULL,
  action_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(report_id), INDEX(admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ====== CSRF ง่าย ๆ ====== */
if (empty($_SESSION['csrf_admin'])) $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_admin'];

/* ====== ส่วน AJAX (list/detail/update/bulk) ====== */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');

  /* ----- 1) รายการ + ค้นหา/กรอง + เพจ ----- */
  if ($_GET['ajax']==='list') {
    $q      = trim($_GET['q'] ?? '');
    $st     = trim($_GET['status'] ?? '');
    $kind   = trim($_GET['kind'] ?? '');
    $prio   = trim($_GET['priority'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(100, max(10, (int)($_GET['per'] ?? 20)));
    $off    = ($page-1)*$per;

    $sql = "FROM abuse_reports r
            LEFT JOIN users u ON u.user_id=r.reporter_id
            WHERE 1=1";
    $P = [];
    if ($q!=='') {
      $sql.=" AND (r.reason LIKE ? OR r.details LIKE ? OR r.target_id LIKE ? OR u.username LIKE ?)";
      array_push($P, "%$q%","%$q%","%$q%","%$q%");
    }
    if ($st!=='')   { $sql.=" AND r.status=?";   $P[]=$st; }
    if ($kind!=='') { $sql.=" AND r.target_kind=?"; $P[]=$kind; }
    if ($prio!=='') { $sql.=" AND r.priority=?"; $P[]=$prio; }

    $total = (int)$pdo->prepare("SELECT COUNT(*) $sql")->execute($P) ? (int)$pdo->query("SELECT FOUND_ROWS()") : 0;
    // note: MySQL 8 ไม่มี FOUND_ROWS() แบบเดิม — ใช้ query แยกแทน
    $stCount = $pdo->prepare("SELECT COUNT(*) $sql");
    $stCount->execute($P);
    $total = (int)$stCount->fetchColumn();

    $rows = $pdo->prepare("SELECT r.*, CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS reporter_name, u.username $sql
                           ORDER BY r.created_at DESC, r.report_id DESC LIMIT $per OFFSET $off");
    $rows->execute($P);
    echo json_encode([
      'ok'=>true,
      'items'=>$rows->fetchAll(),
      'page'=>$page,'per'=>$per,'total'=>$total
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ----- 2) ดูรายละเอียดรายงาน ----- */
  if ($_GET['ajax']==='detail') {
    $id = (int)($_GET['id'] ?? 0);
    $row = null;
    if ($id>0) {
      $s = $pdo->prepare("SELECT r.*, CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS reporter_name, u.username
                          FROM abuse_reports r
                          LEFT JOIN users u ON u.user_id=r.reporter_id
                          WHERE r.report_id=? LIMIT 1");
      $s->execute([$id]);
      $row = $s->fetch();
    }
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

    // โหลด log การจัดการ
    $lg = $pdo->prepare("SELECT a.*, CONCAT(COALESCE(u.fname,''),' ',COALESCE(u.lname,'')) AS admin_name
                         FROM abuse_report_actions a
                         LEFT JOIN users u ON u.user_id=a.admin_id
                         WHERE a.report_id=? ORDER BY a.action_id DESC");
    $lg->execute([$id]);
    $row['actions'] = $lg->fetchAll();

    // สรุปลิงก์เป้าหมายให้แอดมินกด
    $row['target_link'] = '';
    switch ($row['target_kind']) {
      case 'product':       $row['target_link'] = 'product_detail.php?id='.urlencode($row['target_id']); break;
      case 'user':          $row['target_link'] = 'view_profile.php?id='.urlencode($row['target_id']); break;
      case 'exchange_item': $row['target_link'] = 'exchange.php#item-'.urlencode($row['target_id']); break;
      case 'chat':          $row['target_link'] = 'ChatApp/chat.php?request_id='.urlencode($row['target_id']).'&product_id=0'; break;
      case 'order':         $row['target_link'] = 'admin_order_view.php?id='.urlencode($row['target_id']); break;
      default:              $row['target_link'] = '';
    }

    echo json_encode(['ok'=>true,'item'=>$row], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ----- 3) อัปเดตรายงาน (สถานะ/มอบหมาย/เพิ่มโน้ต) ----- */
  if ($_GET['ajax']==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
      http_response_code(400); echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
    }
    $id    = (int)($_POST['report_id'] ?? 0);
    $type  = trim($_POST['type'] ?? ''); // status|assign|note
    $note  = trim($_POST['note'] ?? '');
    if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'invalid id']); exit; }

    if ($type==='status') {
      $newStatus = trim($_POST['status'] ?? '');
      if (!in_array($newStatus,['open','reviewing','resolved','rejected','spam'],true)) {
        echo json_encode(['ok'=>false,'error'=>'invalid status']); exit;
      }
      $pdo->prepare("UPDATE abuse_reports SET status=?, updated_at=NOW() WHERE report_id=?")->execute([$newStatus,$id]);
      $pdo->prepare("INSERT INTO abuse_report_actions(report_id,admin_id,action_type,action_note) VALUES(?,?, 'status_change', ?)")
          ->execute([$id,$userId,'เปลี่ยนสถานะ: '.$newStatus.($note?(' · '.$note):'')]);
      echo json_encode(['ok'=>true]); exit;
    }

    if ($type==='assign') {
      $ass = (int)($_POST['assigned_admin_id'] ?? 0);
      $pdo->prepare("UPDATE abuse_reports SET assigned_admin_id=?, updated_at=NOW() WHERE report_id=?")
          ->execute([$ass?:$userId, $id]);
      $pdo->prepare("INSERT INTO abuse_report_actions(report_id,admin_id,action_type,action_note) VALUES(?,?,'assign',?)")
          ->execute([$id,$userId,'มอบหมายให้ admin_id='.$ass ?: $userId]);
      echo json_encode(['ok'=>true]); exit;
    }

    if ($type==='note') {
      if ($note===''){ echo json_encode(['ok'=>false,'error'=>'note empty']); exit; }
      $pdo->prepare("INSERT INTO abuse_report_actions(report_id,admin_id,action_type,action_note) VALUES(?,?, 'note', ?)")
          ->execute([$id,$userId,$note]);
      echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'invalid type']); exit;
  }

  /* ----- 4) ทำเครื่องหมายหลายรายการ (bulk) ----- */
  if ($_GET['ajax']==='bulk' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
      http_response_code(400); echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
    }
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $to  = trim($_POST['status'] ?? '');
    if (!$ids || !in_array($to,['open','reviewing','resolved','rejected','spam'],true)) {
      echo json_encode(['ok'=>false]); exit;
    }
    $in = implode(',', array_fill(0,count($ids),'?'));
    $st = $pdo->prepare("UPDATE abuse_reports SET status=?, updated_at=NOW() WHERE report_id IN ($in)");
    $st->execute(array_merge([$to],$ids));
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown']); exit;
}

/* ====== HTML ====== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>จัดการรายงานและข้อร้องเรียน</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{--brand:#ffcc00;--ink:#111;--muted:#666;--bg:#f7f7f7;--shadow:0 6px 18px rgba(0,0,0,.08)}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);font-family:ui-sans-serif,system-ui,'Segoe UI',Tahoma}
  header{background:var(--brand);padding:12px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 6px rgba(0,0,0,.06)}
  h1{margin:0;font-size:18px;font-weight:800}
  .container{max-width:1200px;margin:20px auto;padding:0 16px}
  .panel{background:#fff;padding:14px;border-radius:12px;box-shadow:var(--shadow);margin-bottom:16px}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap}
  input,select,textarea{border:1px solid #ddd;border-radius:8px;padding:8px 10px}
  button{border:0;border-radius:8px;padding:8px 12px;cursor:pointer;font-weight:700}
  .btn{background:#111;color:#fff} .btn.sec{background:#0ea5e9} .btn.warn{background:#f59e0b}
  .btn.gray{background:#e5e7eb;color:#111}
  table{width:100%;border-collapse:collapse} th,td{padding:10px;border-bottom:1px solid #eee;font-size:14px}
  tr:hover{background:#fafafa}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
  .st-open{background:#fee2e2;color:#7f1d1d} .st-reviewing{background:#dbeafe;color:#1e3a8a}
  .st-resolved{background:#dcfce7;color:#065f46} .st-rejected{background:#ffe4e6;color:#9f1239}
  .st-spam{background:#f5f5f5;color:#374151}
  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:999;align-items:center;justify-content:center}
  .modal.show{display:flex}
  .box{width:min(820px,92vw);background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}
  .box .head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#fff8d6;border-bottom:1px solid #fde68a}
  .box .body{padding:14px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .muted{color:#666;font-size:12px}
  .ev{display:flex;gap:8px;flex-wrap:wrap}
  .ev img{max-width:140px;max-height:140px;border-radius:8px;border:1px solid #eee}
</style>
</head>
<body>

<header>
  <h1>จัดการรายงานและข้อร้องเรียน</h1>
  <a href="dashboard.php" class="btn gray" style="text-decoration:none">← กลับหน้าแอดมิน</a>
</header>

<div class="container">

  <div class="panel">
    <div class="toolbar">
      <input id="q" placeholder="ค้นหา (เหตุผล/รายละเอียด/เป้าหมาย/ผู้แจ้ง)">
      <select id="kind">
        <option value="">ทุกประเภทเป้าหมาย</option>
        <option value="product">สินค้า</option>
        <option value="user">ผู้ใช้</option>
        <option value="exchange_item">โพสต์แลกเปลี่ยน</option>
        <option value="chat">ห้องแชท</option>
        <option value="order">ออเดอร์</option>
        <option value="other">อื่น ๆ</option>
      </select>
      <select id="status">
        <option value="">ทุกสถานะ</option>
        <option value="open">เปิดใหม่</option>
        <option value="reviewing">กำลังตรวจสอบ</option>
        <option value="resolved">ปิดแล้ว</option>
        <option value="rejected">ปฏิเสธ</option>
        <option value="spam">สแปม</option>
      </select>
      <select id="priority">
        <option value="">ทุกความสำคัญ</option>
        <option value="high">เร่งด่วน</option>
        <option value="normal">ปกติ</option>
        <option value="low">ต่ำ</option>
      </select>
      <button class="btn" onclick="loadList(1)">ค้นหา</button>
      <div style="flex:1"></div>
      <select id="per" onchange="loadList(1)">
        <option>20</option><option>50</option><option>100</option>
      </select>
      <button class="btn warn" onclick="bulkResolve()">ปิดเรื่องที่เลือก</button>
    </div>
  </div>

  <div class="panel">
    <table id="tbl">
      <thead>
        <tr>
          <th style="width:30px"><input type="checkbox" id="chkAll" onclick="toggleAll(this)"></th>
          <th>#</th><th>ประเภท</th><th>เป้าหมาย</th><th>เหตุผล</th><th>ผู้แจ้ง</th><th>สถานะ</th><th>เมื่อ</th><th style="width:80px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <div id="pager" class="muted" style="padding:8px 2px"></div>
  </div>
</div>

<!-- Modal รายละเอียด -->
<div class="modal" id="md">
  <div class="box">
    <div class="head">
      <div><b id="mdTitle">รายละเอียดรายงาน</b></div>
      <div>
        <button class="btn gray" onclick="closeMd()">ปิด</button>
      </div>
    </div>
    <div class="body" id="mdBody">
      กำลังโหลด...
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode($csrf) ?>;

let curPage=1, total=0, per=20;
function badge(st){
  const cls = {
    open:'st-open', reviewing:'st-reviewing', resolved:'st-resolved', rejected:'st-rejected', spam:'st-spam'
  }[st] || 'st-open';
  return `<span class="badge ${cls}">${st}</span>`;
}
function esc(s){ return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

async function loadList(page){
  curPage = page||1;
  per = +document.getElementById('per').value;
  const params = new URLSearchParams({
    ajax:'list',
    q: document.getElementById('q').value.trim(),
    status: document.getElementById('status').value,
    kind: document.getElementById('kind').value,
    priority: document.getElementById('priority').value,
    page: curPage, per: per
  });
  const r = await fetch('admin_reports.php?'+params, {cache:'no-store'});
  const j = await r.json();
  const tb = document.querySelector('#tbl tbody');
  tb.innerHTML = (j.items||[]).map(x => `
    <tr>
      <td><input type="checkbox" class="chk" value="${x.report_id}"></td>
      <td>${x.report_id}</td>
      <td>${esc(x.target_kind)}</td>
      <td><code>${esc(x.target_id)}</code></td>
      <td>${esc(x.reason)}</td>
      <td>${esc(x.reporter_name || x.username || ('#'+x.reporter_id))}</td>
      <td>${badge(x.status)}</td>
      <td>${esc(x.created_at)}</td>
      <td><button class="btn sec" onclick="openDetail(${x.report_id})">ดู</button></td>
    </tr>
  `).join('') || `<tr><td colspan="9" class="muted" style="text-align:center;padding:18px">ไม่มีรายการ</td></tr>`;
  total = j.total||0;
  const start = (curPage-1)*per + 1, end = Math.min(total, curPage*per);
  document.getElementById('pager').textContent = total? `แสดง ${start}-${end} จาก ${total}` : '';
  document.getElementById('chkAll').checked=false;
}

function toggleAll(el){
  document.querySelectorAll('.chk').forEach(c=>c.checked=el.checked);
}

async function bulkResolve(){
  const ids = Array.from(document.querySelectorAll('.chk:checked')).map(x=>+x.value);
  if (!ids.length) { alert('กรุณาเลือกรายการ'); return; }
  if (!confirm('ยืนยันปิดเรื่องที่เลือก?')) return;
  const fd = new FormData();
  fd.append('csrf', csrf); fd.append('status','resolved');
  ids.forEach(id=>fd.append('ids[]', id));
  const r = await fetch('admin_reports.php?ajax=bulk', {method:'POST', body:fd});
  const j = await r.json(); if(!j.ok){ alert('ทำรายการไม่สำเร็จ'); return; }
  loadList(curPage);
}

function closeMd(){ document.getElementById('md').classList.remove('show'); }

async function openDetail(id){
  const r = await fetch('admin_reports.php?ajax=detail&id='+id, {cache:'no-store'});
  const j = await r.json(); if(!j.ok){ alert('ไม่พบรายงาน'); return; }
  const it = j.item;
  const ev = (() => {
    try{
      const arr = Array.isArray(it.evidence)? it.evidence : JSON.parse(it.evidence||'[]');
      return (arr||[]).map(p=>`<a href="${esc(p)}" target="_blank"><img src="${esc(p)}"></a>`).join('');
    }catch(e){ return ''; }
  })();

  document.getElementById('mdBody').innerHTML = `
    <div class="grid2">
      <div><b>รหัส:</b> ${it.report_id}</div>
      <div><b>สถานะ:</b> ${badge(it.status)}</div>
      <div><b>ผู้แจ้ง:</b> ${esc(it.reporter_name||it.username||('#'+it.reporter_id))}</div>
      <div><b>ความสำคัญ:</b> ${esc(it.priority)}</div>
      <div><b>ประเภทเป้าหมาย:</b> ${esc(it.target_kind)}</div>
      <div><b>รหัสเป้าหมาย:</b> <code>${esc(it.target_id)}</code> ${it.target_link?`· <a href="${esc(it.target_link)}" target="_blank">เปิด</a>`:''}</div>
      <div style="grid-column:1/-1"><b>เหตุผล:</b> ${esc(it.reason)}</div>
      <div style="grid-column:1/-1"><b>รายละเอียด:</b><br>${esc(it.details||'-')}</div>
      <div style="grid-column:1/-1"><b>หลักฐาน:</b><div class="ev">${ev||'<span class="muted">ไม่มีไฟล์แนบ</span>'}</div></div>
    </div>
    <hr>
    <div class="grid2">
      <div>
        <label>เปลี่ยนสถานะ</label><br>
        <select id="mdStatus">
          ${['open','reviewing','resolved','rejected','spam'].map(s=>`<option value="${s}" ${s===it.status?'selected':''}>${s}</option>`).join('')}
        </select>
        <button class="btn" onclick="saveStatus(${it.report_id})">บันทึก</button>
      </div>
      <div>
        <label>มอบหมายให้ (admin id)</label><br>
        <input id="mdAssign" type="number" placeholder="admin id" value="${it.assigned_admin_id||''}" style="width:140px">
        <button class="btn sec" onclick="saveAssign(${it.report_id})">มอบหมาย</button>
      </div>
    </div>
    <div style="margin-top:10px">
      <label>เพิ่มโน้ต (ภายในทีม)</label><br>
      <textarea id="mdNote" rows="3" style="width:100%"></textarea>
      <div style="margin-top:6px"><button class="btn warn" onclick="addNote(${it.report_id})">เพิ่มโน้ต</button></div>
    </div>
    <hr>
    <div>
      <b>ประวัติการจัดการ</b>
      <div class="muted">ล่าสุดก่อน</div>
      <ul>
        ${(it.actions||[]).map(a=>`<li>[${esc(a.created_at)}] ${esc(a.admin_name||('#'+a.admin_id))} · ${esc(a.action_type)} · ${esc(a.action_note||'')}</li>`).join('') || '<li class="muted">ยังไม่มี</li>'}
      </ul>
    </div>
  `;
  document.getElementById('md').classList.add('show');
}

async function saveStatus(id){
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('report_id', id);
  fd.append('type','status');
  fd.append('status', document.getElementById('mdStatus').value);
  const r = await fetch('admin_reports.php?ajax=update', {method:'POST', body:fd});
  const j = await r.json(); if(!j.ok){ alert('บันทึกไม่สำเร็จ'); return; }
  loadList(curPage); openDetail(id);
}
async function saveAssign(id){
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('report_id', id);
  fd.append('type','assign');
  fd.append('assigned_admin_id', document.getElementById('mdAssign').value);
  const r = await fetch('admin_reports.php?ajax=update', {method:'POST', body:fd});
  const j = await r.json(); if(!j.ok){ alert('บันทึกไม่สำเร็จ'); return; }
  loadList(curPage); openDetail(id);
}
async function addNote(id){
  const note = document.getElementById('mdNote').value.trim();
  if (!note) { alert('กรอกโน้ตก่อน'); return; }
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('report_id', id);
  fd.append('type','note');
  fd.append('note', note);
  const r = await fetch('admin_reports.php?ajax=update', {method:'POST', body:fd});
  const j = await r.json(); if(!j.ok){ alert('บันทึกไม่สำเร็จ'); return; }
  document.getElementById('mdNote').value='';
  openDetail(id);
}

loadList(1);
</script>
</body>
</html>
