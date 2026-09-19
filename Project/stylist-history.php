<?php
// ===================================================
// Bright Hair Studio — Job History Page
// stylist-history.php — เชื่อมต่อ DB จริง
// ===================================================
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: employee_login.php');
    exit;
}

date_default_timezone_set('Asia/Bangkok');

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
    $min_date = date('Y-m-d'); $max_date = date('Y-m-d', strtotime('+30 days'));
    if (!$leave_start || !$leave_end) { echo json_encode(['ok'=>false,'msg'=>'กรุณาเลือกวันที่']); exit; }
    if ($leave_start < $min_date || $leave_end > $max_date || $leave_end < $leave_start) { echo json_encode(['ok'=>false,'msg'=>'ช่วงวันที่ไม่ถูกต้อง']); exit; }
    $diff = (new DateTime($leave_end))->diff(new DateTime($leave_start))->days + 1;
    if ($diff > 3) { echo json_encode(['ok'=>false,'msg'=>'ลาได้สูงสุด 3 วัน']); exit; }
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
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'ส่งคำขอลาหยุดเรียบร้อยแล้ว รอผู้ดูแลระบบอนุมัติ' : 'เกิดข้อผิดพลาด']);
    exit;
}

// ── ข้อมูลช่าง + effective status ───────────────────
$empStmt = $conn->prepare("SELECT * FROM employees WHERE id=?");
$empStmt->bind_param('i', $emp_id);
$empStmt->execute();
$empRow = $empStmt->get_result()->fetch_assoc();
$empStmt->close();

$activeLeaveStmt = $conn->prepare("SELECT leave_date_start, leave_date_end FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND leave_date_end >= CURDATE() ORDER BY leave_date_start ASC LIMIT 1");
$activeLeaveStmt->bind_param('i', $emp_id);
$activeLeaveStmt->execute();
$activeLeave = $activeLeaveStmt->get_result()->fetch_assoc();
$activeLeaveStmt->close();

$raw_status       = $empRow ? ($empRow['status'] ?? 'online') : 'online';
$leave_date_start = $activeLeave ? $activeLeave['leave_date_start'] : null;
$leave_date_end   = $activeLeave ? $activeLeave['leave_date_end']   : null;
$today_check      = date('Y-m-d');
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

$stylist = [
    'name'             => $empRow ? $empRow['name'] : 'ช่างบอย',
    'role'             => $empRow ? $empRow['role'] : 'Expert',
    'status'           => $effective_status,
    'leave_date_start' => $leave_date_start,
    'leave_date_end'   => $leave_date_end,
];

// ── ดึงข้อมูลคิวของวันนี้และคำขอลา ───────────────────────
$qCountResult = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE employee_id=$emp_id AND booking_date=CURDATE() AND status='upcoming'");
$today_q_count = $qCountResult ? (int)$qCountResult->fetch_assoc()['total'] : 0;

$pendingStmt = $conn->prepare("SELECT * FROM leave_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$pendingStmt->bind_param('i', $emp_id);
$pendingStmt->execute();
$pendingLeave = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();

// โควตาวันลาที่เหลือ
$current_year = date('Y');
$quotaStmt = $conn->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
$quotaStmt->bind_param('is', $emp_id, $current_year);
$quotaStmt->execute();
$quotaRow = $quotaStmt->get_result()->fetch_assoc();
$remaining_leave = max(0, 14 - (int)($quotaRow['used_days'] ?? 0));
$quotaStmt->close();

// ── Format helpers ───────────────────────────────────
$thai_months_fmt = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$thai_days_short = ['อา','จ','อ','พ','พฤ','ศ','ส'];

function formatDateTH(?string $d): string {
    global $thai_months_fmt;
    if (!$d) return '—';
    $dt = new DateTime($d);
    return $dt->format('d').'/'.$thai_months_fmt[(int)$dt->format('n')].'/'.(((int)$dt->format('Y'))+543);
}

// ── Month filter ─────────────────────────────────────
// สร้าง dropdown 6 เดือนล่าสุด
$month_options = [];
for ($i = 0; $i < 6; $i++) {
    $ts  = strtotime("-$i months");
    $month_options[] = [
        'value' => date('Y-m', $ts),
        'label' => $thai_months_fmt[(int)date('n', $ts)].' '.((int)date('Y', $ts)+543),
    ];
}
$selected_month = $_GET['month'] ?? $month_options[0]['value'];
[$sel_y, $sel_m] = explode('-', $selected_month);
$month_start = "$sel_y-$sel_m-01";
$month_end   = date('Y-m-t', strtotime($month_start));

// ── ดึงประวัติงาน (completed) ────────────────────────
$histSql = "
    SELECT
        b.id,
        b.booking_code,
        b.booking_date,
        TIME_FORMAT(b.start_time,'%H:%i') AS start_time,
        b.duration_min,
        b.notes,
        s.name  AS service_name,
        s.code  AS service_code,
        s.price AS service_price,
        CONCAT(u.first_name,' ',u.last_name) AS customer_name,
        r.rating AS review_rating,
        r.comment AS review_comment
    FROM bookings b
    LEFT JOIN services  s ON s.id = b.service_id
    LEFT JOIN users     u ON u.id = b.user_id
    LEFT JOIN reviews   r ON r.booking_id = b.id
    WHERE b.employee_id = ?
      AND b.status IN ('completed','done')
      AND b.booking_date BETWEEN ? AND ?
    ORDER BY b.booking_date DESC, b.start_time DESC
";
$histStmt = $conn->prepare($histSql);
$histStmt->bind_param('iss', $emp_id, $month_start, $month_end);
$histStmt->execute();
$histResult = $histStmt->get_result();

// ── Map service name → type ──────────────────────────
function guessServiceType(string $name): string {
    $name = mb_strtolower($name);
    if (str_contains($name,'ตัด')) return 'cut';
    if (str_contains($name,'สี') || str_contains($name,'ไฮไลท์') || str_contains($name,'ไฮไลต์') || str_contains($name,'ฟอก')) return 'color';
    if (str_contains($name,'ยืด') || str_contains($name,'เคราติน')) return 'straight';
    if (str_contains($name,'ดัด')) return 'perm';
    return 'other';
}

$job_history = [];
while ($row = $histResult->fetch_assoc()) {
    $dt      = new DateTime($row['booking_date']);
    $dow     = (int)$dt->format('w');
    $day     = (int)$dt->format('j');
    $month   = (int)$dt->format('n');
    $year    = (int)$dt->format('Y');
    $svcName = $row['service_name'] ?? ($row['notes'] ?? 'บริการ');

    $job_history[] = [
        'id'           => $row['booking_code'],
        'db_id'        => (int)$row['id'],
        'date'         => $row['booking_date'],
        'date_th'      => $GLOBALS['thai_days_short'][$dow].'. '.$day.' '.$GLOBALS['thai_months_fmt'][$month].' '.($year+543),
        'time'         => $row['start_time'],
        'duration'     => (int)$row['duration_min'],
        'customer'     => [
            'name'   => trim($row['customer_name']),
            'avatar' => mb_substr(trim($row['customer_name']), 0, 1, 'UTF-8'),
            'tier'   => 'new',
        ],
        'service'      => $svcName,
        'service_type' => guessServiceType($svcName),
        'note'         => $row['review_comment'] ?? '',
        'price'        => (int)$row['service_price'],
        'status'       => 'done',
        'rating'       => (int)($row['review_rating'] ?? 0),
        'tip'          => 0,
    ];
}
$histStmt->close();
$conn->close();

// ── Group by date ────────────────────────────────────
$grouped = [];
foreach ($job_history as $job) {
    $grouped[$job['date']][] = $job;
}

// ── Summary stats ────────────────────────────────────
$total_jobs    = count($job_history);
$total_revenue = array_sum(array_column($job_history, 'price'));
$total_tips    = 0;
$rated_jobs    = array_filter($job_history, fn($j) => $j['rating'] > 0);
$avg_rating    = count($rated_jobs) > 0
    ? round(array_sum(array_column($rated_jobs,'rating')) / count($rated_jobs), 1)
    : 0;
$total_minutes = array_sum(array_column($job_history, 'duration'));

// service type → label / color
$svc_meta = [
    'cut'      => ['label'=>'ตัดผม',   'color'=>'#ff9f24', 'bg'=>'#fff8ed', 'text'=>'#e8860c'],
    'color'    => ['label'=>'ทำสีผม',  'color'=>'#2c5f8a', 'bg'=>'#edf3f9', 'text'=>'#2c5f8a'],
    'straight' => ['label'=>'ยืดผม',   'color'=>'#4a7c6f', 'bg'=>'#eaf2f0', 'text'=>'#4a7c6f'],
    'perm'     => ['label'=>'ดัดผม',   'color'=>'#c0392b', 'bg'=>'#fdf0ee', 'text'=>'#c0392b'],
    'other'    => ['label'=>'อื่นๆ',   'color'=>'#b8b3ab', 'bg'=>'#f5f3f0', 'text'=>'#7a756d'],
];

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
<title>Bright Hair — ประวัติงาน</title>
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

*,*::before,*::after { box-sizing:border-box;margin:0;padding:0 }
body { font-family:'Sarabun',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;display:flex;font-size:14px;line-height:1.5; }

/* ── SIDEBAR ─────────────────────────────────── */
.sidebar { width:var(--sidebar-w);min-height:100vh;background:var(--white);border-right:1px solid var(--rule);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:200; }
.sidebar-logo { padding:22px 20px 18px;border-bottom:1px solid var(--rule);display:flex;align-items:center;gap:11px; }
.brand-copy .brand-name { font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);letter-spacing:-.2px; }
.brand-copy .brand-sub  { font-size:10px;color:var(--mist);letter-spacing:.8px;text-transform:uppercase;margin-top:1px; }
.nav-group       { padding:18px 12px 6px; }
.nav-group-label { font-size:9.5px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--mist);padding:0 8px 8px; }
.nav-link { display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--r-sm);color:var(--stone);font-size:13.5px;font-weight:500;text-decoration:none;transition:var(--ease);position:relative;margin-bottom:1px; }
.nav-link svg { opacity:.55;flex-shrink:0;transition:var(--ease); }
.nav-link:hover  { background:var(--amber-pale);color:var(--amber-deep); }
.nav-link:hover svg { opacity:1; }
.nav-link.active { background:var(--amber-pale);color:var(--amber-deep);font-weight:600; }
.nav-link.active svg { opacity:1; }
.nav-link.active::before { content:'';position:absolute;left:-12px;top:50%;transform:translateY(-50%);width:3px;height:54%;background:var(--amber);border-radius:0 3px 3px 0; }
.nav-badge { margin-left:auto;background:var(--amber);color:var(--white);font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px; }
.sidebar-profile { margin-top:auto;padding:14px 16px;border-top:1px solid var(--rule);display:flex;align-items:center;gap:10px; }
.profile-avatar  { width:36px;height:36px;background:var(--amber);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--white);font-weight:700;font-size:14px;flex-shrink:0;position:relative; }
.online-ring { position:absolute;bottom:0;right:0;width:10px;height:10px;background:var(--sage);border-radius:50%;border:2px solid var(--white); }
.profile-name { font-weight:600;font-size:13px;color:var(--ink); }
.profile-role { font-size:11px;color:var(--stone); }
.logo-img { height:38px;width:auto;object-fit:contain;flex-shrink:0; }

/* ── MAIN ────────────────────────────────────── */
.main { margin-left:var(--sidebar-w);flex:1;padding:32px 32px 56px;max-width:calc(100vw - var(--sidebar-w)); }

/* ── PAGE HEAD ───────────────────────────────── */
.page-head { display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:26px;padding-bottom:20px;border-bottom:1px solid var(--rule); }
.page-eyebrow { font-size:10.5px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--amber);margin-bottom:5px; }
.page-title { font-family:'Playfair Display',serif;font-size:26px;color:var(--ink);letter-spacing:-.4px;line-height:1.1; }
.head-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;}
.head-right-top{display:flex;gap:10px;align-items:center;}
.leave-scheduled-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:100px;background:var(--amber-pale);color:var(--amber-deep);font-size:11.5px;font-weight:600;border:1px solid rgba(217,119,6,.18);}

/* ── ANIMATIONS ──────────────────────────────── */
@keyframes fadeUp { from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);} }
.fadein   { animation:fadeUp .35s ease both; }
.fadein-1 { animation-delay:.06s; }
.fadein-2 { animation-delay:.12s; }
.fadein-3 { animation-delay:.18s; }

/* ── SUMMARY STRIP ───────────────────────────── */
.summary-strip { display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px; }
.sum-card { background:var(--white);border:1px solid var(--rule);border-radius:var(--r);padding:14px 16px;position:relative;overflow:hidden;transition:var(--ease); }
.sum-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.07);transform:translateY(-1px); }
.sum-card::after { content:'';position:absolute;bottom:0;left:0;right:0;height:2px; }
.sum-card.c-amber::after  { background:var(--amber); }
.sum-card.c-sage::after   { background:var(--sage); }
.sum-card.c-cobalt::after { background:var(--cobalt); }
.sum-card.c-gold::after   { background:var(--gold); }
.sum-card.c-rust::after   { background:var(--rust); }
.sum-num   { font-family:'Playfair Display',serif;font-size:22px;line-height:1;margin-bottom:2px; }
.sum-label { font-size:11.5px;color:var(--stone);font-weight:500; }
.c-amber  .sum-num { color:var(--amber-deep); }
.c-sage   .sum-num { color:var(--sage); }
.c-cobalt .sum-num { color:var(--cobalt); }
.c-gold   .sum-num { color:var(--gold); }
.c-rust   .sum-num { color:var(--rust); }

/* ── TOOLBAR ─────────────────────────────────── */
.toolbar { display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap; }
.search-wrap { position:relative;flex:1;min-width:200px;max-width:320px; }
.search-wrap svg { position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mist);pointer-events:none; }
.search-input { width:100%;padding:8px 12px 8px 34px;border:1px solid var(--rule);border-radius:var(--r-sm);font-family:'Sarabun',sans-serif;font-size:13px;color:var(--ink);background:var(--white);outline:none;transition:var(--ease); }
.search-input:focus { border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,159,36,.1); }
.filter-chips { display:flex;gap:6px;flex-wrap:wrap; }
.chip { padding:6px 14px;border-radius:20px;border:1px solid var(--rule);font-family:'Sarabun',sans-serif;font-size:12px;font-weight:600;color:var(--stone);background:var(--white);cursor:pointer;transition:var(--ease);display:flex;align-items:center;gap:5px; }
.chip:hover { border-color:var(--amber);color:var(--amber-deep); }
.chip.active { background:var(--amber);color:var(--white);border-color:var(--amber); }
.chip-dot { width:7px;height:7px;border-radius:50%; }
.sort-select { padding:7px 12px;border:1px solid var(--rule);border-radius:var(--r-sm);font-family:'Sarabun',sans-serif;font-size:12.5px;color:var(--stone);background:var(--white);outline:none;cursor:pointer;transition:var(--ease);margin-left:auto; }
.sort-select:focus { border-color:var(--amber); }

/* ── TIMELINE ────────────────────────────────── */
.timeline { display:flex;flex-direction:column;gap:0; }

.day-group { margin-bottom:28px; }
.day-header { display:flex;align-items:center;gap:12px;margin-bottom:14px; }
.day-label { font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--mist);white-space:nowrap; }
.day-line  { flex:1;height:1px;background:var(--rule); }
.day-badge { background:var(--paper);border:1px solid var(--rule);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;color:var(--stone);white-space:nowrap; }

/* ── JOB ROW ─────────────────────────────────── */
.job-rows { display:flex;flex-direction:column;gap:8px; }

.job-row {
  display:grid;
  grid-template-columns: 60px 1fr auto auto auto auto;
  align-items:center;
  gap:14px;
  background:var(--white);
  border:1px solid var(--rule);
  border-radius:var(--r);
  padding:14px 18px;
  transition:var(--ease);
  cursor:pointer;
  position:relative;
  overflow:hidden;
}
.job-row::before {
  content:'';
  position:absolute;left:0;top:0;bottom:0;
  width:3px;
  border-radius:3px 0 0 3px;
  transition:var(--ease);
}
.job-row:hover { box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateX(2px); }
.job-row:hover::before { width:4px; }

/* colour-coded left border per service */
.job-row.svc-cut::before      { background:var(--amber); }
.job-row.svc-color::before    { background:var(--cobalt); }
.job-row.svc-straight::before { background:var(--sage); }
.job-row.svc-perm::before     { background:var(--rust); }
.job-row.svc-other::before    { background:var(--mist); }

/* hover bg tint */
.job-row.svc-cut:hover      { background:var(--amber-pale); }
.job-row.svc-color:hover    { background:var(--cobalt-pale); }
.job-row.svc-straight:hover { background:var(--sage-pale); }
.job-row.svc-perm:hover     { background:var(--rust-pale); }
.job-row.svc-other:hover    { background:var(--paper); }

/* time col */
.job-time { text-align:center; }
.job-time-val  { font-family:'Playfair Display',serif;font-size:16px;color:var(--ink);line-height:1; }
.job-time-dur  { font-size:10.5px;color:var(--mist);margin-top:2px; }

/* customer col */
.job-customer  { display:flex;align-items:center;gap:10px;min-width:0; }
.job-av        { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0; }
.av-gold       { background:var(--gold-pale);color:var(--gold); }
.av-silver     { background:var(--cobalt-pale);color:var(--cobalt); }
.av-new        { background:var(--paper);color:var(--mist);border:1px solid var(--rule); }
.job-cname     { font-weight:600;font-size:13.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.job-cnote     { font-size:11.5px;color:var(--stone);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px; }

/* service badge */
.svc-badge { display:inline-flex;align-items:center;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:5px;white-space:nowrap; }

/* price col */
.job-price     { text-align:right; }
.job-price-val { font-family:'Playfair Display',serif;font-size:15px;color:var(--amber-deep); }
.job-tip       { font-size:10.5px;color:var(--sage);margin-top:1px; }

/* stars col */
.job-stars { display:flex;gap:2px; }
.jstar     { font-size:12px;color:var(--amber); }
.jstar.empty { color:var(--rule); }

/* job id col */
.job-id { font-size:10.5px;color:var(--mist);font-weight:600;letter-spacing:.5px;text-align:right; }

/* ── EXPAND PANEL ────────────────────────────── */
.job-detail {
  display:none;
  background:var(--paper);
  border:1px solid var(--rule);
  border-top:none;
  border-radius:0 0 var(--r) var(--r);
  padding:16px 20px 16px 24px;
  font-size:13px;
  color:var(--stone);
  gap:24px;
  grid-template-columns:1fr 1fr 1fr;
  margin-top:-8px;
  margin-bottom:8px;
}
.job-detail.open { display:grid; }
.detail-group label { display:block;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--mist);margin-bottom:4px; }
.detail-group span  { font-size:13.5px;color:var(--ink-soft); }

/* ── EMPTY STATE ─────────────────────────────── */
.empty-state { text-align:center;padding:60px 20px;color:var(--mist); }
.empty-state svg { margin-bottom:14px;opacity:.35; }
.empty-state p { font-size:14px; }

/* ── LOAD MORE ───────────────────────────────── */
.load-more-wrap { text-align:center;margin-top:24px; }
.btn-load-more { padding:9px 28px;border:1px solid var(--rule);border-radius:var(--r-sm);background:var(--white);font-family:'Sarabun',sans-serif;font-size:13px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease); }
.btn-load-more:hover { border-color:var(--amber);color:var(--amber-deep);background:var(--amber-pale); }

/* ── STATUS PILL ─────────────────────────────── */
.status-pill{display:flex;align-items:center;background:var(--white);border:1px solid var(--rule);border-radius:100px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.pill-btn{padding:7px 18px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:12.5px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease);display:flex;align-items:center;gap:6px;}
.pill-btn.is-active{background:var(--sage-pale);color:var(--sage);}
.pill-btn.on-leave{background:var(--rust-pale);color:var(--rust);}
.pill-sep{width:1px;height:20px;background:var(--rule);}
.dot-online{width:7px;height:7px;border-radius:50%;background:var(--sage);display:inline-block;}

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
</style>
</head>
<body>

<!-- ═══ SIDEBAR ══════════════════════════════════ -->
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
      <span class="nav-badge"><?= $today_q_count ?></span>
    </a>
    <a href="stylist-customers.php" class="nav-link">
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
    <a href="stylist-history.php" class="nav-link active">
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
    <?= mb_substr($stylist['name'], 0, 1, 'UTF-8') ?>
    <div class="online-ring" style="background:<?= $effective_status === 'on_leave' ? 'var(--rust)' : 'var(--sage)' ?>"></div>
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

<!-- ═══ MAIN ══════════════════════════════════════ -->
<main class="main">

  <!-- Page header -->
  <div class="page-head fadein">
    <div>
      <div class="page-eyebrow"><?= $date_label ?></div>
      <h1 class="page-title">ประวัติงาน</h1>
    </div>
    <div class="head-right">
      <div class="head-right-top">
        <!-- Month picker จริงจาก DB -->
        <form method="get" style="display:inline;">
          <select class="sort-select" style="margin-left:0;" name="month" onchange="this.form.submit()">
            <?php foreach ($month_options as $mo): ?>
            <option value="<?= $mo['value'] ?>" <?= $mo['value'] === $selected_month ? 'selected' : '' ?>>
              <?= $mo['label'] ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
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

  <!-- Summary strip -->
  <div class="summary-strip fadein fadein-1">
    <div class="sum-card c-amber">
      <div class="sum-num"><?= $total_jobs ?></div>
      <div class="sum-label">งานทั้งหมด</div>
    </div>
    <div class="sum-card c-sage">
      <div class="sum-num">฿<?= number_format($total_revenue) ?></div>
      <div class="sum-label">รายได้รวม</div>
    </div>
    <div class="sum-card c-gold">
      <div class="sum-num">฿<?= number_format($total_tips) ?></div>
      <div class="sum-label">ทิปรวม</div>
    </div>
    <div class="sum-card c-cobalt">
      <div class="sum-num"><?= $avg_rating ?> ★</div>
      <div class="sum-label">คะแนนเฉลี่ย</div>
    </div>
    <div class="sum-card c-rust">
      <div class="sum-num"><?= floor($total_minutes/60) ?>h <?= $total_minutes%60 ?>m</div>
      <div class="sum-label">เวลาทำงานรวม</div>
    </div>
  </div>

  <!-- Toolbar: search + filter chips -->
  <div class="toolbar fadein fadein-2">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input class="search-input" id="searchInput" type="text" placeholder="ค้นหาชื่อลูกค้า, รหัสงาน..."/>
    </div>
    <div class="filter-chips">
      <button class="chip active" data-filter="all" onclick="setFilter(this)">ทั้งหมด</button>
      <button class="chip" data-filter="cut" onclick="setFilter(this)">
        <span class="chip-dot" style="background:#ff9f24"></span>ตัดผม
      </button>
      <button class="chip" data-filter="color" onclick="setFilter(this)">
        <span class="chip-dot" style="background:#2c5f8a"></span>ทำสีผม
      </button>
      <button class="chip" data-filter="straight" onclick="setFilter(this)">
        <span class="chip-dot" style="background:#4a7c6f"></span>ยืดผม
      </button>
      <button class="chip" data-filter="perm" onclick="setFilter(this)">
        <span class="chip-dot" style="background:#c0392b"></span>ดัดผม
      </button>
    </div>
    <select class="sort-select" id="sortSelect" onchange="sortJobs()">
      <option value="date-desc">ล่าสุดก่อน</option>
      <option value="date-asc">เก่าสุดก่อน</option>
      <option value="price-desc">ราคาสูงสุด</option>
      <option value="rating-desc">คะแนนสูงสุด</option>
    </select>
  </div>

  <!-- Timeline -->
  <div class="timeline fadein fadein-3" id="timeline">

    <?php if (empty($job_history)): ?>
    <div class="empty-state">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <p>ยังไม่มีประวัติงานในเดือนนี้</p>
    </div>
    <?php else: ?>
    <?php foreach ($grouped as $date => $jobs):
      $day_total = array_sum(array_column($jobs,'price'));
    ?>
    <div class="day-group" data-date="<?= $date ?>">
      <div class="day-header">
        <span class="day-label"><?= $jobs[0]['date_th'] ?></span>
        <div class="day-line"></div>
        <span class="day-badge"><?= count($jobs) ?> งาน · ฿<?= number_format($day_total) ?></span>
      </div>

      <div class="job-rows">
        <?php foreach ($jobs as $job):
          $sm = $svc_meta[$job['service_type']];
          $tc = ['gold'=>'av-gold','silver'=>'av-silver','new'=>'av-new'][$job['customer']['tier']];
          $tip_txt = $job['tip'] > 0 ? '+฿'.number_format($job['tip']).' ทิป' : '';
        ?>
        <!-- Job row -->
        <div
          class="job-row svc-<?= $job['service_type'] ?>"
          data-svc="<?= $job['service_type'] ?>"
          data-name="<?= htmlspecialchars(mb_strtolower($job['customer']['name'])) ?>"
          data-id="<?= $job['id'] ?>"
          data-price="<?= $job['price'] ?>"
          data-rating="<?= $job['rating'] ?>"
          data-date="<?= $job['date'] ?>"
          onclick="toggleDetail('<?= $job['id'] ?>')"
        >
          <!-- Time -->
          <div class="job-time">
            <div class="job-time-val"><?= $job['time'] ?></div>
            <div class="job-time-dur"><?= $job['duration'] ?> นาที</div>
          </div>

          <!-- Customer -->
          <div class="job-customer">
            <div class="job-av <?= $tc ?>"><?= htmlspecialchars($job['customer']['avatar']) ?></div>
            <div>
              <div class="job-cname"><?= htmlspecialchars($job['customer']['name']) ?></div>
              <div class="job-cnote"><?= htmlspecialchars($job['note']) ?></div>
            </div>
          </div>

          <!-- Service badge -->
          <div>
            <span class="svc-badge" style="background:<?= $sm['bg'] ?>;color:<?= $sm['text'] ?>">
              <?= htmlspecialchars($job['service']) ?>
            </span>
          </div>

          <!-- Price -->
          <div class="job-price">
            <div class="job-price-val">฿<?= number_format($job['price']) ?></div>
            <?php if($job['tip']>0): ?>
            <div class="job-tip">+฿<?= number_format($job['tip']) ?> ทิป</div>
            <?php endif; ?>
          </div>

          <!-- Stars -->
          <div class="job-stars">
            <?php for($s=1;$s<=5;$s++): ?>
            <span class="jstar <?= $s>$job['rating']?'empty':'' ?>">★</span>
            <?php endfor; ?>
          </div>

          <!-- Job ID -->
          <div class="job-id"><?= $job['id'] ?></div>
        </div>

        <!-- Expandable detail panel -->
        <div class="job-detail" id="detail-<?= $job['id'] ?>">
          <div class="detail-group">
            <label>บริการ</label>
            <span><?= htmlspecialchars($job['service']) ?></span>
          </div>
          <div class="detail-group">
            <label>ระยะเวลา</label>
            <span><?= $job['duration'] ?> นาที (<?= $job['time'] ?> น.)</span>
          </div>
          <div class="detail-group">
            <label>ราคา + ทิป</label>
            <span>฿<?= number_format($job['price']) ?><?= $job['tip']>0?' + ฿'.number_format($job['tip']).' ทิป':'' ?></span>
          </div>
          <div class="detail-group">
            <label>หมายเหตุ</label>
            <span><?= htmlspecialchars($job['note']) ?></span>
          </div>
          <div class="detail-group">
            <label>คะแนนรีวิว</label>
            <span><?= $job['rating'] ?> / 5 ★</span>
          </div>
          <div class="detail-group">
            <label>รหัสงาน</label>
            <span><?= $job['id'] ?></span>
          </div>
        </div>

        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Empty state (hidden by default, for search filter) -->
    <div class="empty-state" id="emptyState" style="display:none;">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <p>ไม่พบงานที่ตรงกับการค้นหา</p>
    </div>

  </div>

  <div class="load-more-wrap">
    <button class="btn-load-more">โหลดประวัติเพิ่มเติม</button>
  </div>

</main>

<script>
// ── Filter & Search ────────────────────────────
let currentFilter = 'all';

function setFilter(btn) {
  document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = btn.dataset.filter;
  applyFilters();
}

document.getElementById('searchInput').addEventListener('input', applyFilters);

function applyFilters() {
  const q   = document.getElementById('searchInput').value.trim().toLowerCase();
  const rows = document.querySelectorAll('.job-row');
  let anyVisible = false;

  rows.forEach(row => {
    const svc     = row.dataset.svc;
    const name    = row.dataset.name;
    const id      = row.dataset.id.toLowerCase();
    const svcMatch  = currentFilter === 'all' || svc === currentFilter;
    const textMatch = !q || name.includes(q) || id.includes(q);
    const show = svcMatch && textMatch;
    row.style.display = show ? '' : 'none';
    // hide detail too
    const det = document.getElementById('detail-' + row.dataset.id);
    if (det && !show) det.classList.remove('open');
    if (show) anyVisible = true;
  });

  // show/hide day-group headers if all rows inside are hidden
  document.querySelectorAll('.day-group').forEach(grp => {
    const visible = [...grp.querySelectorAll('.job-row')].some(r => r.style.display !== 'none');
    grp.style.display = visible ? '' : 'none';
  });

  document.getElementById('emptyState').style.display = anyVisible ? 'none' : 'block';
}

// ── Sort ───────────────────────────────────────
function sortJobs() {
  const val  = document.getElementById('sortSelect').value;
  const tl   = document.getElementById('timeline');
  const rows = [...document.querySelectorAll('.job-row')];

  rows.sort((a, b) => {
    if (val === 'date-desc')  return (b.dataset.date + b.dataset.id).localeCompare(a.dataset.date + a.dataset.id);
    if (val === 'date-asc')   return (a.dataset.date + a.dataset.id).localeCompare(b.dataset.date + b.dataset.id);
    if (val === 'price-desc') return Number(b.dataset.price) - Number(a.dataset.price);
    if (val === 'rating-desc')return Number(b.dataset.rating) - Number(a.dataset.rating);
  });

  // Re-append in sorted order (flat list, bypass day grouping when sorted)
  const container = document.createElement('div');
  container.className = 'job-rows';
  rows.forEach(r => {
    container.appendChild(r);
    const det = document.getElementById('detail-' + r.dataset.id);
    if (det) container.appendChild(det);
  });

  // Replace timeline content
  [...tl.querySelectorAll('.day-group')].forEach(g => g.remove());
  tl.insertBefore(container, tl.querySelector('#emptyState'));
}

// ── Expand/Collapse detail ─────────────────────
function toggleDetail(id) {
  const det = document.getElementById('detail-' + id);
  if (!det) return;
  det.classList.toggle('open');
}

// ── Leave modal ─────────────────────────────────
const LEAVE_ICON = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;

function thaiMonths(n){ return ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][n]; }
function formatThaiDate(d) {
  if (!d) return '—';
  const p = d.split('-');
  return parseInt(p[2]) + '/' + thaiMonths(parseInt(p[1])) + '/' + (parseInt(p[0])+543);
}

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
  if (!leaveDateStart || !leaveDateEnd) { msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาเลือกช่วงวันที่</div>`; return; }
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
      msgBox.innerHTML = `<div style="color:var(--sage);font-size:12.5px;margin-bottom:8px;">✓ ส่งคำขอลางานเรียบร้อย — รออนุมัติ</div>`;
      setTimeout(() => { closeLeave(); location.reload(); }, 1500);
    } else {
      msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ ${data.msg}</div>`;
      btn.disabled = false; btn.textContent = 'ยืนยันการลา';
    }
  } catch(e) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ เกิดข้อผิดพลาด</div>`;
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
    const fd = new FormData(); fd.append('action','cancel_leave');
    const res  = await fetch(window.location.href, { method:'POST', body:fd });
    const data = await res.json();
    if (data.ok) {
      const badge = document.getElementById('leaveBadge'); if(badge) badge.remove();
      document.getElementById('btnLeave').classList.remove('on-leave');
      document.getElementById('btnLeave').innerHTML = LEAVE_ICON + ' ขอลา';
      document.getElementById('btnOnline').classList.add('is-active');
    } else { alert('เกิดข้อผิดพลาด'); }
  } catch(e) { alert('เกิดข้อผิดพลาด'); }
}

document.getElementById('leaveOverlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeLeave();
});
</script>

<!-- LEAVE MODAL -->
<div class="overlay" id="leaveOverlay">
  <div class="modal">
    <div class="modal-title">แจ้งลา / หยุดรับงาน</div>
    <p class="modal-sub">เลือกช่วงวันที่และประเภทการลา (สูงสุด 3 วัน) ระบบจะเปลี่ยนสถานะเป็น <strong>ลา</strong> อัตโนมัติ</p>

    <label style="font-size:11.5px;font-weight:700;color:var(--stone);margin-bottom:5px;display:flex;justify-content:space-between;letter-spacing:.3px;">
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
    <select class="m-select" id="leaveType">
      <option value="">— เลือกประเภทการลา —</option>
      <option value="ลาป่วย">ลาป่วย</option>
      <option value="ลากิจ">ลากิจ</option>
      <option value="วันหยุดพักผ่อน">วันหยุดพักผ่อน</option>
      <option value="เหตุฉุกเฉิน">เหตุฉุกเฉิน</option>
      <option value="อื่น ๆ">อื่น ๆ</option>
    </select>
    <textarea class="m-textarea" id="leaveReason" placeholder="หมายเหตุเพิ่มเติม / เหตุผลการลา..."></textarea>
    <div id="leaveMsgBox"></div>
    <div class="m-actions">
      <button class="btn btn-ghost" onclick="closeLeave()">ยกเลิก</button>
      <button class="btn btn-rust" id="btnConfirmLeave" onclick="confirmLeave()">ยืนยันการลา</button>
    </div>
  </div>
</div>
</body>
</html>