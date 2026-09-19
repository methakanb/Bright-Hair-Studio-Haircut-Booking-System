<?php
// ===================================================
// Bright Hair Studio — My Customers Page
// stylist-customers.php — เชื่อมต่อ DB จริง
// ===================================================
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: employee_login.php');
    exit;
}

date_default_timezone_set('Asia/Bangkok');

// ── DB CONFIG ───────────────────────────────────────
require_once __DIR__ . '/env.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    die('<div style="font-family:sans-serif;padding:40px;color:#c0392b;">❌ DB Error: '.htmlspecialchars($conn->connect_error).'</div>');
}

$emp_id = (int)$_SESSION['employee_id'];

// ── AJAX: ยกเลิกวันลา ────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'cancel_leave') {
    header('Content-Type: application/json; charset=utf-8');
    $upd = $conn->prepare("UPDATE employees SET status='online' WHERE id=?");
    $upd->bind_param('i', $emp_id);
    $upd->execute();
    $ok = $upd->affected_rows >= 0;
    $upd->close();
    $upd2 = $conn->prepare("UPDATE leave_requests SET status='cancelled' WHERE employee_id=? AND status IN ('pending', 'approved') AND leave_date_end >= CURDATE()");
    $upd2->bind_param('i', $emp_id);
    $upd2->execute();
    $upd2->close();
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── AJAX: ขอลา ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'request_leave') {
    header('Content-Type: application/json; charset=utf-8');
    $leave_start  = $_POST['leave_date_start'] ?? '';
    $leave_end    = $_POST['leave_date_end']   ?? '';
    $leave_reason = trim($_POST['reason'] ?? '');
    $leave_type   = trim($_POST['type']   ?? '');
    $min_date = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));
    if (!$leave_start || !$leave_end) { echo json_encode(['ok'=>false,'msg'=>'กรุณาเลือกวันที่']); exit; }
    if ($leave_start < $min_date || $leave_end > $max_date || $leave_end < $leave_start) { echo json_encode(['ok'=>false,'msg'=>'ช่วงวันที่ไม่ถูกต้อง']); exit; }
    $diff = (new DateTime($leave_end))->diff(new DateTime($leave_start))->days + 1;
    if ($diff > 3) { echo json_encode(['ok'=>false,'msg'=>'ลาได้สูงสุด 3 วันต่อครั้ง']); exit; }
    // ตรวจสอบโควตา 14 วัน
    $current_year_req = date('Y', strtotime($leave_start));
    $qStmt = $conn->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
    $qStmt->bind_param('is', $emp_id, $current_year_req);
    $qStmt->execute();
    $used_this_year = (int)($qStmt->get_result()->fetch_assoc()['used_days'] ?? 0);
    $qStmt->close();
    $remaining_req = max(0, 14 - $used_this_year);
    if ($diff > $remaining_req) { echo json_encode(['ok'=>false,'msg'=>"เกินโควตา! คุณเหลือวันลาอีกเพียง {$remaining_req} วันในปีนี้"]); exit; }
    $ins = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_date_start, leave_date_end, leave_type, leave_note, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $ins->bind_param('issss', $emp_id, $leave_start, $leave_end, $leave_type, $leave_reason);
    $ins->execute();
    $ok = $ins->affected_rows > 0;
    $ins->close();
    echo json_encode(['ok'=>$ok, 'msg'=>$ok?'ส่งคำขอลาหยุดเรียบร้อยแล้ว รอผู้ดูแลระบบอนุมัติ':'เกิดข้อผิดพลาด']);
    exit;
}

// ── ดึงข้อมูลช่าง ────────────────────────────────────
$empStmt = $conn->prepare("SELECT * FROM employees WHERE id=?");
$empStmt->bind_param('i', $emp_id);
$empStmt->execute();
$empRow = $empStmt->get_result()->fetch_assoc();
$empStmt->close();

// ดึงข้อมูลการลาที่ approved และยังไม่หมดเขต
$activeLeaveStmt = $conn->prepare("SELECT leave_date_start, leave_date_end FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND leave_date_end >= CURDATE() ORDER BY leave_date_start ASC LIMIT 1");
$activeLeaveStmt->bind_param('i', $emp_id);
$activeLeaveStmt->execute();
$activeLeave = $activeLeaveStmt->get_result()->fetch_assoc();
$activeLeaveStmt->close();

$leave_date_start = $activeLeave ? $activeLeave['leave_date_start'] : null;
$leave_date_end   = $activeLeave ? $activeLeave['leave_date_end']   : null;

$raw_status   = $empRow ? ($empRow['status'] ?? 'online') : 'online';
$today_check  = date('Y-m-d');

if ($leave_date_start) {
    if ($today_check < $leave_date_start) {
        $effective_status = 'online';
        if ($raw_status !== 'online') $conn->query("UPDATE employees SET status='online' WHERE id=$emp_id");
    } elseif ($leave_date_end && $today_check > $leave_date_end) {
        $effective_status = 'online';
        $conn->query("UPDATE employees SET status='online' WHERE id=$emp_id");
        $leave_date_start = $leave_date_end = null;
    } else {
        $effective_status = 'on_leave';
        if ($raw_status !== 'on_leave') $conn->query("UPDATE employees SET status='on_leave' WHERE id=$emp_id");
    }
} else {
    $effective_status = in_array($raw_status, ['online','on_leave','offline']) ? $raw_status : 'online';
}

// ── ตรวจสอบคำขอลาที่รออนุมัติ ──────────────────────
$pendingStmt = $conn->prepare("SELECT * FROM leave_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$pendingStmt->bind_param('i', $emp_id);
$pendingStmt->execute();
$pendingLeave = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();

// ── โควตาวันลาที่เหลือ ──────────────────────────────
$current_year = date('Y');
$quotaStmt = $conn->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
$quotaStmt->bind_param('is', $emp_id, $current_year);
$quotaStmt->execute();
$quotaRow = $quotaStmt->get_result()->fetch_assoc();
$used_leave_days = (int)($quotaRow['used_days'] ?? 0);
$quotaStmt->close();
$remaining_leave = max(0, 14 - $used_leave_days);

$stylist = [
    'name'             => $empRow ? $empRow['name']  : 'ช่างบอย',
    'role'             => $empRow ? $empRow['role']  : 'Expert',
    'status'           => $effective_status,
    'leave_date_start' => $leave_date_start,
    'leave_date_end'   => $leave_date_end,
];

// ── ฟังก์ชัน format วันที่ภาษาไทย ───────────────────
$thai_months_fmt = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
function formatDateTH(?string $d): string {
    global $thai_months_fmt;
    if (!$d) return '—';
    $dt = new DateTime($d);
    return $dt->format('d').'/'.$thai_months_fmt[(int)$dt->format('n')].'/'.(((int)$dt->format('Y'))+543);
}

// ── ดึง Tier จาก Points ──────────────────────────────
// points = SUM(price ของ completed bookings) / 5
// ทุก 2000 points เลื่อน 1 ระดับ: Bronze→Silver→Gold→Platinum
function calcTierFromPoints(int $points): array {
    $level = min(3, (int)floor($points / 2000));
    $tiers = [
        0 => ['key'=>'new',      'label'=>'New',      'cls'=>'tier-new'],
        1 => ['key'=>'silver',   'label'=>'Silver',   'cls'=>'tier-silver'],
        2 => ['key'=>'gold',     'label'=>'Gold',     'cls'=>'tier-gold'],
        3 => ['key'=>'platinum', 'label'=>'Platinum', 'cls'=>'tier-platinum'],
    ];
    return array_merge($tiers[$level], ['points'=>$points, 'level'=>$level]);
}

// ── ดึงลูกค้าทั้งหมดที่เคยจองกับช่างบอย ─────────────
$custSql = "
    SELECT
        u.id, u.first_name, u.last_name, u.phone, u.email,
        u.note,
        u.created_at                                                                              AS registered_at,
        COUNT(DISTINCT CASE WHEN b.status IN ('completed','done') THEN b.id END)                  AS visit_count,
        COALESCE(SUM(CASE WHEN b.status IN ('completed','done') THEN s.price ELSE 0 END), 0)      AS total_spend,
        MAX(CASE WHEN b.status IN ('completed','done') THEN b.booking_date END)                   AS last_visit
    FROM users u
    JOIN bookings b ON b.user_id = u.id AND b.employee_id = ?
    LEFT JOIN services s ON s.id = b.service_id
    GROUP BY u.id
    ORDER BY last_visit DESC
";
$custStmt = $conn->prepare($custSql);
$custStmt->bind_param('i', $emp_id);
$custStmt->execute();
$custResult = $custStmt->get_result();
$all_customers = [];

while ($c = $custResult->fetch_assoc()) {
    // คำนวณ points และ tier
    $points = (int)floor($c['total_spend'] / 5);
    $tierInfo = calcTierFromPoints($points);

    // ดึง skills/tags จาก bookings
    $tagSql = "SELECT DISTINCT s.name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.user_id=? AND b.employee_id=? AND b.status IN ('completed','done') LIMIT 5";
    $tagStmt = $conn->prepare($tagSql);
    $tagStmt->bind_param('ii', $c['id'], $emp_id);
    $tagStmt->execute();
    $tagRes = $tagStmt->get_result();
    $tags = [];
    while ($t = $tagRes->fetch_assoc()) $tags[] = $t['name'];
    $tagStmt->close();

    // ดึงประวัติการจองที่ completed/done
    $histSql = "
        SELECT b.booking_date, b.start_time, s.name AS service_name, s.price
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        WHERE b.user_id=? AND b.employee_id=? AND b.status IN ('completed','done')
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 20
    ";
    $histStmt = $conn->prepare($histSql);
    $histStmt->bind_param('ii', $c['id'], $emp_id);
    $histStmt->execute();
    $histRes = $histStmt->get_result();
    $history = [];
    while ($h = $histRes->fetch_assoc()) $history[] = $h;
    $histStmt->close();

    $all_customers[] = [
        'id'          => (int)$c['id'],
        'name'        => trim($c['first_name'].' '.$c['last_name']),
        'first_name'  => $c['first_name'],
        'phone'       => $c['phone'] ?? '',
        'email'       => $c['email'] ?? '',
        'visits'      => (int)$c['visit_count'],          // นับเฉพาะ completed
        'last'        => formatDateTH($c['last_visit']),  // วันที่ completed ล่าสุด
        'last_raw'    => $c['last_visit'],
        'since'       => formatDateTH(substr($c['registered_at'] ?? '', 0, 10)), // วันสร้างบัญชี
        'total_spend' => (int)$c['total_spend'],          // ยอดเฉพาะ completed
        'points'      => $points,
        'tier'        => $tierInfo['key'],
        'tier_label'  => $tierInfo['label'],
        'tier_cls'    => $tierInfo['cls'],
        'tier_level'  => $tierInfo['level'],
        'note'        => $c['note'] ?? '',
        'tags'        => $tags,
        'history'     => $history,
    ];
}
// ── นับคิว upcoming ของวันนี้ ────────────────────────
$today_str = date('Y-m-d');
$queueStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE employee_id = ? AND booking_date = ? AND status = 'upcoming'");
$queueStmt->bind_param('is', $emp_id, $today_str);
$queueStmt->execute();
$today_queue_count = (int)$queueStmt->get_result()->fetch_assoc()['cnt'];
$queueStmt->close();

$conn->close();

// ── Summary stats ─────────────────────────────────────
$stat_total    = count($all_customers);
$stat_gold     = count(array_filter($all_customers, fn($c) => in_array($c['tier'], ['gold','platinum'])));
$stat_visits   = array_sum(array_column($all_customers, 'visits'));
$stat_revenue  = array_sum(array_column($all_customers, 'total_spend'));

// ── วันภาษาไทย ──────────────────────────────────────
$thai_days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$thai_months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$now         = new DateTime();
$date_label  = 'วัน'.$thai_days[(int)$now->format('w')].' '.$now->format('d').' '.$thai_months[(int)$now->format('n')].' '.((int)$now->format('Y')+543);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Bright Hair — ลูกค้าของฉัน</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&family=Playfair+Display:wght@700&display=swap" rel="stylesheet"/>
<style>
:root {
  --amber:       #ff9f24;
  --amber-deep:  #e8860c;
  --amber-pale:  #fff8ed;
  --amber-mid:   #ffe4b0;
  --ink:         #1c1a17;
  --ink-soft:    #3d3a35;
  --stone:       #7a756d;
  --mist:        #b8b3ab;
  --rule:        #e8e4de;
  --paper:       #fdfcf9;
  --white:       #ffffff;
  --sage:        #4a7c6f;
  --sage-pale:   #eaf2f0;
  --rust:        #c0392b;
  --rust-pale:   #fdf0ee;
  --cobalt:      #2c5f8a;
  --cobalt-pale: #edf3f9;
  --gold:        #c9991a;
  --gold-pale:   #fef9ec;
  --sidebar-w:   256px;
  --r:           10px;
  --r-sm:        6px;
  --ease:        .2s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

body{
  font-family:'Sarabun',sans-serif;
  background:var(--paper);
  color:var(--ink);
  min-height:100vh;
  display:flex;
  font-size:14px;
  line-height:1.5;
}

/* ── SIDEBAR ─────────────────────────────────── */
.sidebar{
  width:var(--sidebar-w);
  min-height:100vh;
  background:var(--white);
  border-right:1px solid var(--rule);
  display:flex;
  flex-direction:column;
  position:fixed;
  top:0;left:0;
  z-index:200;
}
.logo-img{
  height:38px;
  width:auto;
  object-fit:contain;
  flex-shrink:0;
}
.sidebar-logo{
  padding:22px 20px 18px;
  border-bottom:1px solid var(--rule);
  display:flex;align-items:center;gap:11px;
}
.brand-mark{
  width:38px;height:38px;
  background:var(--amber);
  border-radius:9px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.brand-copy .brand-name{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);letter-spacing:-.2px;}
.brand-copy .brand-sub{font-size:10px;color:var(--mist);letter-spacing:.8px;text-transform:uppercase;margin-top:1px;}
.nav-group{padding:18px 12px 6px;}
.nav-group-label{font-size:9.5px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--mist);padding:0 8px 8px;}
.nav-link{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;border-radius:var(--r-sm);
  color:var(--stone);font-size:13.5px;font-weight:500;text-decoration:none;
  transition:var(--ease);position:relative;margin-bottom:1px;
}
.nav-link svg{opacity:.55;flex-shrink:0;transition:var(--ease);}
.nav-link:hover{background:var(--amber-pale);color:var(--amber-deep);}
.nav-link:hover svg{opacity:1;}
.nav-link.active{background:var(--amber-pale);color:var(--amber-deep);font-weight:600;}
.nav-link.active svg{opacity:1;}
.nav-link.active::before{
  content:'';position:absolute;left:-12px;top:50%;transform:translateY(-50%);
  width:3px;height:54%;background:var(--amber);border-radius:0 3px 3px 0;
}
.nav-badge{margin-left:auto;background:var(--amber);color:var(--white);font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
.sidebar-profile{margin-top:auto;padding:14px 16px;border-top:1px solid var(--rule);display:flex;align-items:center;gap:10px;}
.profile-avatar{
  width:36px;height:36px;background:var(--amber);border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  color:var(--white);font-weight:700;font-size:14px;flex-shrink:0;position:relative;
}
.online-ring{position:absolute;bottom:0;right:0;width:10px;height:10px;background:var(--sage);border-radius:50%;border:2px solid var(--white);}
.profile-name{font-weight:600;font-size:13px;color:var(--ink);}
.profile-role{font-size:11px;color:var(--stone);}

/* ── MAIN ─────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;padding:32px 32px 48px;max-width:calc(100vw - var(--sidebar-w));}

.page-head{
  display:flex;align-items:flex-end;justify-content:space-between;
  margin-bottom:26px;padding-bottom:20px;border-bottom:1px solid var(--rule);
}
.page-eyebrow{font-size:10.5px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--amber);margin-bottom:5px;}
.page-title{font-family:'Playfair Display',serif;font-size:26px;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.head-right{display:flex;gap:10px;align-items:center;}

/* ── TOOLBAR ──────────────────────────────────── */
.toolbar{
  display:flex;align-items:center;gap:12px;
  margin-bottom:20px;flex-wrap:wrap;
}
.search-box{
  position:relative;flex:1;min-width:220px;max-width:360px;
}
.search-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--mist);pointer-events:none;}
.search-input{
  width:100%;padding:9px 12px 9px 36px;
  border:1px solid var(--rule);border-radius:var(--r-sm);
  background:var(--white);font-family:'Sarabun',sans-serif;font-size:13px;color:var(--ink);
  outline:none;transition:var(--ease);
}
.search-input:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,159,36,.1);}

.filter-group{display:flex;gap:6px;flex-wrap:wrap;}
.filter-btn{
  padding:8px 16px;border:1px solid var(--rule);border-radius:100px;
  background:var(--white);font-family:'Sarabun',sans-serif;font-size:12px;font-weight:600;
  color:var(--stone);cursor:pointer;transition:var(--ease);
}
.filter-btn:hover{border-color:var(--amber);color:var(--amber-deep);}
.filter-btn.active{background:var(--amber);border-color:var(--amber);color:var(--white);}

.sort-select{
  padding:8px 12px;border:1px solid var(--rule);border-radius:var(--r-sm);
  background:var(--white);font-family:'Sarabun',sans-serif;font-size:12.5px;color:var(--stone);
  outline:none;cursor:pointer;margin-left:auto;
}

/* ── SUMMARY STRIP ────────────────────────────── */
.summary-strip{
  display:grid;grid-template-columns:repeat(4,1fr);gap:12px;
  margin-bottom:22px;
}
.sum-card{
  background:var(--white);border:1px solid var(--rule);border-radius:var(--r);
  padding:14px 18px;display:flex;align-items:center;gap:12px;
  transition:var(--ease);
}
.sum-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.06);transform:translateY(-1px);}
.sum-ico{
  width:32px;height:32px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sum-ico.a{background:var(--amber-pale);}
.sum-ico.s{background:var(--sage-pale);}
.sum-ico.c{background:var(--cobalt-pale);}
.sum-ico.g{background:var(--gold-pale);}
.sum-val{font-family:'Playfair Display',serif;font-size:22px;line-height:1;color:var(--ink);}
.sum-lbl{font-size:11px;color:var(--stone);margin-top:2px;}

/* ── CONTENT GRID ─────────────────────────────── */
.content-grid{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;}

/* ── CUSTOMER TABLE ───────────────────────────── */
.card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);overflow:hidden;}
.card-head{padding:16px 20px;border-bottom:1px solid var(--rule);display:flex;align-items:center;justify-content:space-between;}
.card-title{font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;letter-spacing:.1px;}
.card-hint{font-size:11.5px;color:var(--mist);font-style:italic;}

.cust-table{width:100%;border-collapse:collapse;font-size:13px;}
.cust-table th{
  padding:9px 18px;text-align:left;
  font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--mist);background:var(--paper);border-bottom:1px solid var(--rule);
}
.cust-table td{padding:13px 18px;border-bottom:1px solid var(--rule);vertical-align:middle;}
.cust-table tr:last-child td{border-bottom:none;}
.cust-table tbody tr{cursor:pointer;transition:background var(--ease);}
.cust-table tbody tr:hover td{background:var(--amber-pale);}
.cust-table tbody tr.is-sel td{background:var(--amber-pale);}

.cust-row-av{
  width:34px;height:34px;border-radius:50%;
  background:var(--amber-mid);color:var(--amber-deep);
  font-weight:700;font-size:13px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.cust-nm{font-weight:600;color:var(--ink);font-size:13.5px;}
.cust-phone{font-size:12px;color:var(--stone);margin-top:2px;}

/* Tier badges */
.tier{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:4px;letter-spacing:.3px;}
.tier-gold  {background:var(--gold-pale);color:var(--gold);}
.tier-silver{background:var(--cobalt-pale);color:var(--cobalt);}
.tier-new   {background:var(--paper);color:var(--mist);border:1px solid var(--rule);}
.tier-platinum{background:#f3f0ff;color:#6c3fc5;border:1px solid #d4c5f9;}

/* points progress bar */
.pts-wrap{margin-top:6px;}
.pts-label{font-size:10.5px;color:var(--stone);margin-bottom:4px;display:flex;justify-content:space-between;}
.pts-bar{height:5px;background:var(--rule);border-radius:10px;overflow:hidden;}
.pts-fill{height:100%;background:var(--amber);border-radius:10px;transition:.4s ease;}

/* leave-scheduled-badge */
.leave-scheduled-badge{display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 10px;border-radius:100px;background:var(--amber-pale);color:var(--amber-deep);font-size:11.5px;font-weight:600;border:1px solid rgba(217,119,6,.18);}
.leave-scheduled-badge.is-on-leave{background:var(--rust-pale);color:var(--rust);border-color:rgba(192,57,43,.18);}
.head-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;}

.tag-chip{display:inline-block;font-size:10.5px;padding:2px 8px;border-radius:4px;background:var(--amber-pale);color:var(--amber-deep);font-weight:600;margin-right:4px;margin-bottom:2px;}

.spend-num{font-family:'Playfair Display',serif;font-size:14px;color:var(--amber-deep);}
.visit-num{font-size:13px;font-weight:600;color:var(--ink-soft);}

/* ── RIGHT PANEL — DETAIL ─────────────────────── */
.right-panel{display:flex;flex-direction:column;gap:0;}

.detail-card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);overflow:hidden;}

.detail-hero{
  padding:22px 22px 16px;
  background:linear-gradient(135deg, var(--amber-pale) 0%, var(--white) 100%);
  border-bottom:1px solid var(--rule);
}
.detail-av{
  width:52px;height:52px;border-radius:50%;
  background:var(--amber);color:var(--white);
  font-weight:700;font-size:20px;
  display:flex;align-items:center;justify-content:center;
  margin-bottom:12px;box-shadow:0 4px 12px rgba(255,159,36,.3);
}
.detail-name{font-family:'Playfair Display',serif;font-size:20px;color:var(--ink);margin-bottom:3px;}
.detail-phone{font-size:12.5px;color:var(--stone);margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.detail-email{font-size:12px;color:var(--mist);display:flex;align-items:center;gap:5px;margin-bottom:12px;}
.detail-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
.d-chip{font-size:11px;font-weight:600;padding:4px 11px;border-radius:4px;}
.chip-v{background:var(--amber-pale);color:var(--amber-deep);}
.chip-d{background:var(--cobalt-pale);color:var(--cobalt);}
.chip-s{background:var(--sage-pale);color:var(--sage);}

.allergy-box{
  display:flex;gap:8px;align-items:flex-start;
  background:var(--rust-pale);border:1px solid #f5c4c0;border-radius:6px;
  padding:9px 12px;margin:12px 0 0;font-size:12.5px;color:var(--rust);line-height:1.4;
}

.detail-body{padding:16px 22px;}
.section-head{
  font-size:9.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--mist);padding-bottom:8px;border-bottom:1px solid var(--rule);margin-bottom:4px;
}

.h-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:9px 0;border-bottom:1px solid var(--rule);font-size:13px;
}
.h-row:last-child{border-bottom:none;}
.h-date{font-size:11px;color:var(--mist);margin-bottom:2px;}
.h-svc{font-weight:600;color:var(--ink-soft);}
.h-price{font-family:'Playfair Display',serif;font-size:14px;color:var(--amber-deep);}

.empty-state{
  text-align:center;padding:48px 20px;color:var(--mist);
  font-size:13px;line-height:1.6;
}
.empty-state svg{margin-bottom:12px;opacity:.35;}

/* ── NOTE BOX ─────────────────────────────────── */
.note-edit-wrap{margin-top:12px;}
.note-label{font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--mist);margin-bottom:5px;}
.note-textarea{
  width:100%;padding:8px 11px;border:1px solid var(--rule);border-radius:var(--r-sm);
  font-family:'Sarabun',sans-serif;font-size:12.5px;color:var(--ink);
  background:var(--paper);resize:none;height:60px;outline:none;transition:var(--ease);
}
.note-textarea:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,159,36,.1);}
.note-save-btn{
  margin-top:6px;float:right;
  padding:5px 14px;border-radius:var(--r-sm);border:none;
  background:var(--amber);color:var(--white);font-family:'Sarabun',sans-serif;
  font-size:12px;font-weight:600;cursor:pointer;transition:var(--ease);
}
.note-save-btn:hover{background:var(--amber-deep);}

/* ── PAGINATION ───────────────────────────────── */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px;border-top:1px solid var(--rule);}
.pg-btn{
  width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--rule);
  background:var(--white);font-family:'Sarabun',sans-serif;font-size:13px;
  color:var(--stone);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:var(--ease);
}
.pg-btn:hover{border-color:var(--amber);color:var(--amber-deep);}
.pg-btn.active{background:var(--amber);border-color:var(--amber);color:var(--white);font-weight:700;}

/* ── ANIMATIONS ───────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.fadein{animation:fadeUp .35s ease both;}
.fadein-1{animation-delay:.06s;}
.fadein-2{animation-delay:.12s;}
.fadein-3{animation-delay:.18s;}

/* ── MODAL ───────────────────────────────────── */
.overlay{display:none;position:fixed;inset:0;background:rgba(28,26,23,.5);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
.overlay.open{display:flex;}
.modal{background:var(--white);border-radius:var(--r);padding:28px 30px;width:400px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:mIn .22s ease;}
@keyframes mIn{from{transform:translateY(16px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.modal-title{font-family:'Playfair Display',serif;font-size:19px;color:var(--ink);margin-bottom:5px;}
.modal-sub{font-size:13px;color:var(--stone);margin-bottom:20px;line-height:1.5;}
.m-select,.m-textarea{width:100%;padding:9px 13px;border:1px solid var(--rule);border-radius:var(--r-sm);font-family:'Sarabun',sans-serif;font-size:13.5px;color:var(--ink);outline:none;transition:var(--ease);background:var(--paper);margin-bottom:12px;}
.m-select:focus,.m-textarea:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,159,36,.1);}
.m-textarea{height:76px;resize:none;margin-bottom:20px;}
.m-actions{display:flex;gap:8px;justify-content:flex-end;}
.btn{padding:8px 20px;border-radius:var(--r-sm);border:none;font-family:'Sarabun',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:var(--ease);}
.btn-ghost{background:transparent;border:1px solid var(--rule);color:var(--stone);}
.btn-ghost:hover{border-color:var(--mist);color:var(--ink);}
.btn-rust{background:var(--rust);color:var(--white);}
.btn-rust:hover{opacity:.9;}

/* ── STATUS PILL ──────────────────────────────── */
.status-pill{display:flex;align-items:center;background:var(--white);border:1px solid var(--rule);border-radius:100px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.pill-btn{padding:7px 18px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:12.5px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease);display:flex;align-items:center;gap:6px;}
.pill-btn.is-active{background:var(--sage-pale);color:var(--sage);}
.pill-btn.on-leave{background:var(--rust-pale);color:var(--rust);}
.pill-sep{width:1px;height:20px;background:var(--rule);}
.dot-online{width:7px;height:7px;border-radius:50%;background:var(--sage);display:inline-block;}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="logo-crop.png" alt="Bright Hair" class="logo-img"/>
    <div class="brand-copy">
      <div class="brand-name">Bright Hair</div>
      <div class="brand-sub">Studio</div>
    </div>
  </div>

  <nav class="nav-group">
    <div class="nav-group-label">หน้าหลัก</div>
    <a href="stylist-dashboard.php" class="nav-link">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      คิวของฉัน
      <span class="nav-badge"><?= $today_queue_count ?></span>
    </a>
    <a href="stylist-customers.php" class="nav-link active">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      ลูกค้าของฉัน
    </a>
    <a href="stylist-stats.php" class="nav-link">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      สถิติงาน
    </a>
  </nav>

  <nav class="nav-group">
    <div class="nav-group-label">อื่น ๆ</div>
    <a href="stylist-history.php" class="nav-link">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      ประวัติงาน
    </a>
    <a href="#" class="nav-link">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
      ตั้งค่า
    </a>
  </nav>

  <div class="sidebar-profile">
  <div class="profile-avatar">
    <div class="online-ring"></div>
  </div>
  <div style="flex: 1;">
    <div class="profile-name"><?= htmlspecialchars($stylist['name']) ?></div>
    <div class="profile-role"><?= htmlspecialchars($stylist['role']) ?></div>
  </div>
  <a href="logout.php" title="ออกจากระบบ" style="color: var(--mist); transition: var(--ease);" onmouseover="this.style.color='var(--rust)'" onmouseout="this.style.color='var(--mist)'">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
      <polyline points="16 17 21 12 16 7"></polyline>
      <line x1="21" y1="12" x2="9" y2="12"></line>
    </svg>
  </a>
</div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">

  <div class="page-head fadein">
    <div>
      <div class="page-eyebrow"><?= $date_label ?></div>
      <h1 class="page-title">ลูกค้าของฉัน</h1>
    </div>
    <div class="head-right">
      <div class="status-pill">
        <button class="pill-btn <?= $effective_status === 'online' && !$pendingLeave ? 'is-active' : '' ?>" id="btnOnline" onclick="setOnline()">
          <span class="dot-online"></span> ออนไลน์
        </button>
        <div class="pill-sep"></div>
        <?php if ($pendingLeave): ?>
        <button class="pill-btn" style="background:var(--amber-pale);color:var(--amber-deep);">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          รออนุมัติ
        </button>
        <?php else: ?>
        <button class="pill-btn <?= $effective_status === 'on_leave' ? 'on-leave' : '' ?>" id="btnLeave" onclick="openLeave()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <?= $effective_status === 'on_leave' ? 'ลาอยู่' : 'ขอลา' ?>
        </button>
        <?php endif; ?>
      </div>
      <?php if ($leave_date_start): ?>
      <div class="leave-scheduled-badge" id="leaveBadge">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        ลาตั้งแต่ <?= formatDateTH($leave_date_start) ?><?= $leave_date_end && $leave_date_end !== $leave_date_start ? ' – '.formatDateTH($leave_date_end) : '' ?>
        <button onclick="cancelLeave()" title="ยกเลิกวันลา" style="margin-left:6px;background:none;border:none;cursor:pointer;color:inherit;opacity:.7;padding:0;display:inline-flex;align-items:center;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.7">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <?php elseif ($pendingLeave): ?>
      <div class="leave-scheduled-badge" id="leaveBadge" style="background:#fffce8; color:#a17a0d; border-color:#faeaa0;">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        รออนุมัติการลา <?= formatDateTH($pendingLeave['leave_date_start']) ?><?= $pendingLeave['leave_date_end'] && $pendingLeave['leave_date_end'] !== $pendingLeave['leave_date_start'] ? ' – '.formatDateTH($pendingLeave['leave_date_end']) : '' ?>
        <button onclick="cancelLeave()" title="ยกเลิกคำขอลา" style="margin-left:6px;background:none;border:none;cursor:pointer;color:inherit;opacity:.7;padding:0;display:inline-flex;align-items:center;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.7">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary-strip fadein fadein-1">
    <div class="sum-card">
      <div class="sum-ico a"><svg width="15" height="15" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
      <div><div class="sum-val"><?= $stat_total ?></div><div class="sum-lbl">ลูกค้าทั้งหมด</div></div>
    </div>
    <div class="sum-card">
      <div class="sum-ico g"><svg width="15" height="15" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
      <div><div class="sum-val"><?= $stat_gold ?></div><div class="sum-lbl">Gold+ Member</div></div>
    </div>
    <div class="sum-card">
      <div class="sum-ico s"><svg width="15" height="15" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
      <div><div class="sum-val"><?= $stat_visits ?></div><div class="sum-lbl">ครั้งที่เสร็จสิ้น</div></div>
    </div>
    <div class="sum-card">
      <div class="sum-ico c"><svg width="15" height="15" fill="none" stroke="var(--cobalt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
      <div><div class="sum-val">฿<?= number_format($stat_revenue) ?></div><div class="sum-lbl">ยอดรวมทั้งหมด</div></div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar fadein fadein-1">
    <div class="search-box">
      <svg class="search-ico" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" class="search-input" placeholder="ค้นหาชื่อหรือเบอร์โทร..." id="searchInput" oninput="applyFilters()"/>
    </div>
    <div class="filter-group">
      <button class="filter-btn active" data-tier="all"    onclick="setTier(this,'all')">ทั้งหมด</button>
      <button class="filter-btn"        data-tier="gold"   onclick="setTier(this,'gold')">⭐ Gold</button>
      <button class="filter-btn"        data-tier="silver" onclick="setTier(this,'silver')">Silver</button>
      <button class="filter-btn"        data-tier="new"    onclick="setTier(this,'new')">ใหม่</button>
      <button class="filter-btn"        data-tier="note"   onclick="setTier(this,'note')">⚠ มีหมายเหตุ</button>
    </div>
    <select class="sort-select" id="sortSel" onchange="applyFilters()">
      <option value="visits-desc">เรียงตาม: มาบ่อยที่สุด</option>
      <option value="spend-desc">เรียงตาม: ยอดใช้จ่ายสูง</option>
      <option value="last-desc">เรียงตาม: ล่าสุดก่อน</option>
      <option value="name-asc">เรียงตาม: ชื่อ ก–ฮ</option>
    </select>
  </div>

  <!-- Main content -->
  <div class="content-grid fadein fadein-2">

    <!-- Table -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          รายชื่อลูกค้าของฉัน
        </div>
        <span class="card-hint" id="countLabel"><?= $stat_total ?> คน</span>
      </div>
      <?php if (empty($all_customers)): ?>
      <div class="empty-state" style="padding:48px 20px;">
        <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <p>ยังไม่มีลูกค้าที่จองกับคุณ</p>
      </div>
      <?php else: ?>
      <table class="cust-table" id="custTable">
        <thead>
          <tr>
            <th>ลูกค้า</th>
            <th>ระดับ / คะแนน</th>
            <th>บริการที่ใช้</th>
            <th>จำนวนครั้งที่ใช้บริการ</th>
            <th>ยอดรวม</th>
            <th>ใช้บริการล่าสุด</th>
          </tr>
        </thead>
        <tbody id="custTbody">
          <?php foreach ($all_customers as $i => $c):
            $pts_in_level  = $c['points'] % 2000;
            $pts_pct       = min(100, round($pts_in_level / 2000 * 100));
            $pts_next      = 2000 - $pts_in_level;
            $is_max        = $c['tier_level'] >= 3;
          ?>
          <tr class="<?= $i===0?'is-sel':'' ?>"
              data-id="<?= $c['id'] ?>"
              data-tier="<?= $c['tier'] ?>"
              data-name="<?= htmlspecialchars($c['name']) ?>"
              data-phone="<?= htmlspecialchars($c['phone']) ?>"
              data-note="<?= $c['note']?'1':'' ?>"
              onclick="selectCustomer(<?= $c['id'] ?>)">
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="cust-row-av"><?= mb_substr($c['name'],0,1,'UTF-8') ?></div>
                <div>
                  <div class="cust-nm"><?= htmlspecialchars($c['name']) ?></div>
                  <div class="cust-phone"><?= htmlspecialchars($c['phone']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="tier <?= $c['tier_cls'] ?>"><?= $c['tier_label'] ?></span>
              <div class="pts-wrap">
                <div class="pts-label">
                  <span><?= number_format($c['points']) ?> pts</span>
                  <span><?= $is_max ? 'MAX' : number_format($pts_next).' pts to next' ?></span>
                </div>
                <div class="pts-bar"><div class="pts-fill" style="width:<?= $is_max ? 100 : $pts_pct ?>%"></div></div>
              </div>
            </td>
            <td><?php foreach($c['tags'] as $tag): ?><span class="tag-chip"><?= htmlspecialchars($tag) ?></span><?php endforeach; ?></td>
            <td><span class="visit-num"><?= $c['visits'] ?> ครั้ง</span></td>
            <td><span class="spend-num">฿<?= number_format($c['total_spend']) ?></span></td>
            <td style="font-size:12px;color:var(--stone);"><?= $c['last'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="pagination" id="pagination">
        <button class="pg-btn active">1</button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Detail panel -->
    <div class="right-panel">
      <div class="detail-card" id="detailPanel">
        <?php if (!empty($all_customers)): $c0 = $all_customers[0];
          $pts0_in  = $c0['points'] % 2000;
          $pts0_pct = min(100, round($pts0_in / 2000 * 100));
          $is_max0  = $c0['tier_level'] >= 3;
        ?>
        <div class="detail-hero">
          <div class="detail-av"><?= mb_substr($c0['name'],0,1,'UTF-8') ?></div>
          <div class="detail-name"><?= htmlspecialchars($c0['name']) ?></div>
          <div class="detail-phone">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.6 3.42 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.36 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.79a16 16 0 0 0 5.8 5.8l.89-.89a2 2 0 0 1 2.11-.45c.907.34 1.85.573 2.81.7A2 2 0 0 1 21.22 16v.92z"/></svg>
            <?= htmlspecialchars($c0['phone']) ?>
          </div>
          <?php if($c0['email']): ?>
          <div class="detail-email">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?= htmlspecialchars($c0['email']) ?>
          </div>
          <?php endif; ?>
          <div class="detail-chips">
            <span class="d-chip chip-v">ใช้บริการแล้ว <?= $c0['visits'] ?> ครั้ง</span>
            <span class="d-chip chip-d">ใช้บริการล่าสุด <?= $c0['last'] !== '—' ? $c0['last'] : 'ยังไม่มี' ?></span>
            <span class="d-chip chip-s">สมาชิกตั้งแต่ <?= $c0['since'] ?></span>
          </div>
          <div style="margin:10px 0 4px;">
            <span class="tier <?= $c0['tier_cls'] ?>" style="margin-bottom:6px;display:inline-block;"><?= $c0['tier_label'] ?></span>
            <div class="pts-wrap">
              <div class="pts-label">
                <span><?= number_format($c0['points']) ?> คะแนน</span>
                <span><?= $is_max0 ? 'ระดับสูงสุด' : number_format(2000 - $pts0_in).' pts ถึงระดับถัดไป' ?></span>
              </div>
              <div class="pts-bar"><div class="pts-fill" style="width:<?= $is_max0 ? 100 : $pts0_pct ?>%"></div></div>
            </div>
          </div>
          <?php if($c0['note']): ?>
          <div class="allergy-box">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?= htmlspecialchars($c0['note']) ?>
          </div>
          <?php endif; ?>
          <div class="note-edit-wrap">
            <div class="note-label">บันทึกส่วนตัว</div>
            <textarea class="note-textarea" id="noteTextarea" placeholder="เพิ่มบันทึกหรือข้อสังเกต..."><?= htmlspecialchars($c0['note']) ?></textarea>
            <button class="note-save-btn" onclick="saveNote()">บันทึก</button>
            <div style="clear:both"></div>
          </div>
        </div>
        <div class="detail-body">
          <div class="section-head">ประวัติการใช้บริการกับช่างบอย (รวม ฿<?= number_format($c0['total_spend']) ?>)</div>
          <?php if(empty($c0['history'])): ?>
            <div style="padding:16px 0;color:var(--mist);font-size:12.5px;text-align:center">ยังไม่มีประวัติที่เสร็จสิ้น</div>
          <?php else: foreach ($c0['history'] as $h): ?>
          <div class="h-row">
            <div>
              <div class="h-date"><?= formatDateTH($h['booking_date']) ?></div>
              <div class="h-svc"><?= htmlspecialchars($h['service_name'] ?? '—') ?></div>
            </div>
            <div class="h-price">฿<?= $h['price'] ? number_format($h['price']) : '—' ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:48px 20px;"><p>ไม่มีลูกค้า</p></div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>

<!-- ══ LEAVE MODAL ══ -->
<div class="overlay" id="leaveOverlay">
  <div class="modal">
    <div class="modal-title">แจ้งลา / หยุดรับงาน</div>
    <p class="modal-sub">เลือกช่วงวันที่และประเภทการลา (สูงสุด 3 วัน) ระบบจะเปลี่ยนสถานะเป็น <strong>ลา</strong> อัตโนมัติ</p>

    <label class="m-label" style="display:flex; justify-content:space-between;">
        <span>ช่วงวันที่ขอลา</span>
        <span style="color:var(--sage); font-weight:600; font-size:11px; background:var(--sage-pale); padding:2px 8px; border-radius:12px;">โควตาคงเหลือ: <?= $remaining_leave ?>/14 วัน</span>
    </label>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
      <div style="flex:1;display:flex;flex-direction:column;gap:4px;">
        <span style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--mist);">วันเริ่ม</span>
        <input type="date" class="m-select" id="leaveDateStart"
               min="<?= date('Y-m-d') ?>"
               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
               value="<?= date('Y-m-d') ?>"
               onchange="onStartChange()" style="margin-bottom:0"/>
      </div>
      <div style="font-size:18px;color:var(--mist);flex-shrink:0;margin-top:16px;">→</div>
      <div style="flex:1;display:flex;flex-direction:column;gap:4px;">
        <span style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--mist);">วันสิ้นสุด</span>
        <input type="date" class="m-select" id="leaveDateEnd"
               min="<?= date('Y-m-d') ?>"
               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
               value="<?= date('Y-m-d') ?>"
               onchange="onRangeChange()" style="margin-bottom:0"/>
      </div>
    </div>
    <div id="leaveDayCount" style="font-size:12px;font-weight:700;color:var(--sage);margin-bottom:14px;text-align:center;background:var(--sage-pale);padding:5px 10px;border-radius:20px;display:block;">1 วัน</div>

    <label style="font-size:11.5px;font-weight:700;color:var(--stone);margin-bottom:5px;display:block;letter-spacing:.3px;">ประเภทการลา</label>
    <select class="m-select" id="leaveType">
      <option value="">— เลือกประเภทการลา —</option>
      <option value="ลาป่วย">ลาป่วย</option>
      <option value="ลากิจ">ลากิจ</option>
      <option value="วันหยุดพักผ่อน">วันหยุดพักผ่อน</option>
      <option value="เหตุฉุกเฉิน">เหตุฉุกเฉิน</option>
      <option value="อื่น ๆ">อื่น ๆ</option>
    </select>

    <label style="font-size:11.5px;font-weight:700;color:var(--stone);margin-bottom:5px;display:block;letter-spacing:.3px;">เหตุผล / หมายเหตุ</label>
    <textarea class="m-textarea" id="leaveReason" placeholder="ระบุเหตุผลเพิ่มเติม..."></textarea>

    <div id="leaveMsgBox"></div>
    <div class="m-actions">
      <button class="btn btn-ghost" onclick="closeLeave()">ยกเลิก</button>
      <button class="btn btn-rust" id="btnConfirmLeave" onclick="confirmLeave()">ยืนยันการลา</button>
    </div>
  </div>
</div>

<script>
const customers = <?= json_encode($all_customers, JSON_UNESCAPED_UNICODE) ?>;
let currentTier = 'all';
let selectedId  = customers.length ? customers[0].id : null;

const WARN_SVG  = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
const PHONE_SVG = `<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.6 3.42 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.36 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.79a16 16 0 0 0 5.8 5.8l.89-.89a2 2 0 0 1 2.11-.45c.907.34 1.85.573 2.81.7A2 2 0 0 1 21.22 16v.92z"/></svg>`;
const EMAIL_SVG = `<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>`;
const TIER_CLASSES = { new:'tier-new', silver:'tier-silver', gold:'tier-gold', platinum:'tier-platinum' };
const TIER_LABELS  = { new:'New', silver:'Silver', gold:'Gold', platinum:'Platinum' };
const LEAVE_ICON   = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;

function thaiMonths(n){ return ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][n]; }
function formatThaiDate(d) {
  if (!d) return '—';
  const p = d.split('-');
  return parseInt(p[2]) + '/' + thaiMonths(parseInt(p[1])) + '/' + (parseInt(p[0])+543);
}

function renderDetail(c) {
  const pts       = c.points || 0;
  const level     = Math.min(3, Math.floor(pts / 2000));
  const ptsInLvl  = pts % 2000;
  const ptsPct    = Math.min(100, Math.round(ptsInLvl / 2000 * 100));
  const ptsNext   = 2000 - ptsInLvl;
  const isMax     = level >= 3;
  const tierKey   = c.tier || 'new';
  const tierCls   = TIER_CLASSES[tierKey] || 'tier-new';
  const tierLabel = TIER_LABELS[tierKey]  || 'New';

  const allergyHtml = c.note ? `<div class="allergy-box">${WARN_SVG}${c.note}</div>` : '';
  const emailHtml   = c.email ? `<div class="detail-email">${EMAIL_SVG}${c.email}</div>` : '';

  const histHtml = c.history && c.history.length
    ? c.history.map(h => `
        <div class="h-row">
          <div>
            <div class="h-date">${formatThaiDate(h.booking_date)}</div>
            <div class="h-svc">${h.service_name || '—'}</div>
          </div>
          <div class="h-price">${h.price ? '฿' + Number(h.price).toLocaleString() : '—'}</div>
        </div>`).join('')
    : '<div style="padding:16px 0;color:var(--mist);font-size:12.5px;text-align:center">ยังไม่มีประวัติที่เสร็จสิ้น</div>';

  document.getElementById('detailPanel').innerHTML = `
    <div class="detail-hero">
      <div class="detail-av">${c.name[0]}</div>
      <div class="detail-name">${c.name}</div>
      <div class="detail-phone">${PHONE_SVG}${c.phone||'—'}</div>
      ${emailHtml}
      <div class="detail-chips">
        <span class="d-chip chip-v">ใช้บริการแล้ว ${c.visits} ครั้ง</span>
        <span class="d-chip chip-d">ใช้บริการล่าสุด ${c.last !== '—' ? c.last : 'ยังไม่มี'}</span>
        <span class="d-chip chip-s">สมาชิกตั้งแต่ ${c.since}</span>
      </div>
      <div style="margin:10px 0 4px;">
        <span class="tier ${tierCls}" style="margin-bottom:6px;display:inline-block;">${tierLabel}</span>
        <div class="pts-wrap">
          <div class="pts-label">
            <span>${pts.toLocaleString()} คะแนน</span>
            <span>${isMax ? 'ระดับสูงสุด' : ptsNext.toLocaleString() + ' pts ถึงระดับถัดไป'}</span>
          </div>
          <div class="pts-bar"><div class="pts-fill" style="width:${isMax?100:ptsPct}%"></div></div>
        </div>
      </div>
      ${allergyHtml}
      <div class="note-edit-wrap">
        <div class="note-label">บันทึกส่วนตัว</div>
        <textarea class="note-textarea" id="noteTextarea" placeholder="เพิ่มบันทึกหรือข้อสังเกต...">${c.note||''}</textarea>
        <button class="note-save-btn" onclick="saveNote()">บันทึก</button>
        <div style="clear:both"></div>
      </div>
    </div>
    <div class="detail-body">
      <div class="section-head">ประวัติการใช้บริการกับช่างบอย (รวม ฿${(c.total_spend||0).toLocaleString()})</div>
      ${histHtml}
    </div>`;
}

function selectCustomer(id) {
  selectedId = id;
  document.querySelectorAll('#custTbody tr').forEach(tr => {
    tr.classList.toggle('is-sel', parseInt(tr.dataset.id) === id);
  });
  const c = customers.find(x => x.id === id);
  if (c) renderDetail(c);
}

function setTier(btn, tier) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentTier = tier;
  applyFilters();
}

function applyFilters() {
  const q      = document.getElementById('searchInput').value.toLowerCase();
  const rows   = Array.from(document.querySelectorAll('#custTbody tr'));
  let   visible = 0;
  rows.forEach(tr => {
    const name  = (tr.dataset.name||'').toLowerCase();
    const phone = (tr.dataset.phone||'').toLowerCase();
    const tier  = tr.dataset.tier;
    const note  = tr.dataset.note;
    const matchQ    = !q || name.includes(q) || phone.includes(q);
    const matchTier = currentTier === 'all' ||
                      (currentTier === 'note' ? note === '1' : tier === currentTier);
    const show = matchQ && matchTier;
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('countLabel').textContent = visible + ' คน';
}

function saveNote() {
  const c = customers.find(x => x.id === selectedId);
  if (c) {
    c.note = document.getElementById('noteTextarea').value;
    const btn = document.querySelector('.note-save-btn');
    btn.textContent = '✓ บันทึกแล้ว';
    btn.style.background = 'var(--sage)';
    setTimeout(() => { btn.textContent = 'บันทึก'; btn.style.background = ''; }, 1800);
  }
}

// ── Leave modal ──────────────────────────────────────
function openLeave() {
  document.getElementById('leaveMsgBox').innerHTML = '';
  document.getElementById('btnConfirmLeave').disabled = false;
  document.getElementById('btnConfirmLeave').textContent = 'ยืนยันการลา';
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('leaveDateStart').value = today;
  document.getElementById('leaveDateEnd').value   = today;
  onStartChange();
  document.getElementById('leaveOverlay').classList.add('open');
}
function closeLeave() { document.getElementById('leaveOverlay').classList.remove('open'); }

function daysBetween(a, b) {
  if (!a || !b) return 0;
  return Math.round((new Date(b) - new Date(a)) / 86400000) + 1;
}
function onStartChange() {
  const s = document.getElementById('leaveDateStart').value;
  const eEl = document.getElementById('leaveDateEnd');
  eEl.min = s;
  const maxEnd = new Date(s); maxEnd.setDate(maxEnd.getDate() + 2);
  const hardMax = new Date('<?= date('Y-m-d', strtotime('+30 days')) ?>');
  eEl.max = (maxEnd < hardMax ? maxEnd : hardMax).toISOString().slice(0,10);
  if (eEl.value < s) eEl.value = s;
  onRangeChange();
}
function onRangeChange() {
  const s = document.getElementById('leaveDateStart').value;
  const e = document.getElementById('leaveDateEnd').value;
  const days = s && e ? daysBetween(s,e) : 1;
  const el = document.getElementById('leaveDayCount');
  el.textContent = days + ' วัน' + (days > 3 ? ' (เกินกำหนด)' : '');
  el.style.color = (days >= 1 && days <= 3) ? 'var(--sage)' : 'var(--rust)';
}

async function confirmLeave() {
  const leaveDateStart = document.getElementById('leaveDateStart').value;
  const leaveDateEnd   = document.getElementById('leaveDateEnd').value;
  const leaveType      = document.getElementById('leaveType').value;
  const leaveReason    = document.getElementById('leaveReason').value.trim();
  const msgBox         = document.getElementById('leaveMsgBox');

  if (!leaveDateStart || !leaveDateEnd) { msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาเลือกช่วงวันที่ขอลา</div>`; return; }
  const days = daysBetween(leaveDateStart, leaveDateEnd);
  if (days < 1 || days > 3) { msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ ลาได้สูงสุด 3 วันต่อครั้ง</div>`; return; }
  if (!leaveType)   { msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาเลือกประเภทการลา</div>`; return; }
  if (!leaveReason) { msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาระบุเหตุผลการลา</div>`; return; }

  const btn = document.getElementById('btnConfirmLeave');
  btn.disabled = true; btn.textContent = 'กำลังบันทึก...';

  try {
    const fd = new FormData();
    fd.append('action','request_leave');
    fd.append('leave_date_start', leaveDateStart);
    fd.append('leave_date_end',   leaveDateEnd);
    fd.append('type',   leaveType);
    fd.append('reason', leaveReason);
    const res  = await fetch(window.location.href, { method:'POST', body:fd });
    const data = await res.json();

    if (data.ok) {
      msgBox.innerHTML = `<div style="color:var(--sage);font-size:12.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> ส่งคำขอลางานเรียบร้อย — รออนุมัติ</div>`;
      setTimeout(() => { closeLeave(); location.reload(); }, 1500);
    } else {
      msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ ${data.msg}</div>`;
      btn.disabled = false; btn.textContent = 'ยืนยันการลา';
    }
  } catch(e) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ เกิดข้อผิดพลาด กรุณาลองใหม่</div>`;
    btn.disabled = false; btn.textContent = 'ยืนยันการลา';
  }
}

function setOnline() {
  document.getElementById('btnLeave').classList.remove('on-leave');
  document.getElementById('btnLeave').innerHTML = LEAVE_ICON + ' ขอลา';
  document.getElementById('btnOnline').classList.add('is-active');
}

async function cancelLeave() {
  if (!confirm('ยืนยันการยกเลิกวันลา?\nระบบจะลบข้อมูลการลาและเปลี่ยนสถานะกลับเป็นออนไลน์')) return;
  try {
    const fd = new FormData();
    fd.append('action', 'cancel_leave');
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const badge = document.getElementById('leaveBadge');
      if (badge) badge.remove();
      const bl = document.getElementById('btnLeave');
      const bo = document.getElementById('btnOnline');
      bl.classList.remove('on-leave');
      bl.innerHTML = LEAVE_ICON + ' ขอลา';
      bo.classList.add('is-active');
    } else {
      alert('เกิดข้อผิดพลาด กรุณาลองใหม่');
    }
  } catch(e) {
    alert('เกิดข้อผิดพลาด กรุณาลองใหม่');
  }
}

document.getElementById('leaveOverlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeLeave();
});
</script>
</body>
</html>