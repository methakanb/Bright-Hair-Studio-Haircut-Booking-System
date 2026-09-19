<?php
// ===================================================
// Bright Hair Studio — Stylist Stats Page
// stylist-stats.php — เชื่อมต่อ DB จริง
// ===================================================
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: employee_login.php');
    exit;
}

date_default_timezone_set('Asia/Bangkok');

// ── DB CONFIG ────────────────────────────────────────
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

// ── ดึงข้อมูลช่าง + effective status ────────────────
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

$pendingStmt = $conn->prepare("SELECT * FROM leave_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$pendingStmt->bind_param('i', $emp_id);
$pendingStmt->execute();
$pendingLeave = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();

$current_year = date('Y');
$quotaStmt = $conn->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
$quotaStmt->bind_param('is', $emp_id, $current_year);
$quotaStmt->execute();
$quotaRow = $quotaStmt->get_result()->fetch_assoc();
$remaining_leave = max(0, 14 - (int)($quotaRow['used_days'] ?? 0));
$quotaStmt->close();

$stylist = [
    'name'             => $empRow ? $empRow['name'] : 'ช่างบอย',
    'role'             => $empRow ? $empRow['role'] : 'Expert',
    'status'           => $effective_status,
    'leave_date_start' => $leave_date_start,
    'leave_date_end'   => $leave_date_end,
];

// ── Format helpers ───────────────────────────────────
$thai_months_fmt = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
function formatDateTH(?string $d): string {
    global $thai_months_fmt;
    if (!$d) return '—';
    $dt = new DateTime($d);
    return $dt->format('d').'/'.$thai_months_fmt[(int)$dt->format('n')].'/'.(((int)$dt->format('Y'))+543);
}

// ── KPI: เดือนนี้ vs เดือนที่แล้ว ──────────────────
$this_month_start = date('Y-m-01');
$this_month_end   = date('Y-m-t');
$prev_month_start = date('Y-m-01', strtotime('first day of last month'));
$prev_month_end   = date('Y-m-t',  strtotime('last day of last month'));

$kpiSql = "
    SELECT
        COALESCE(SUM(CASE WHEN b.booking_date BETWEEN ? AND ? AND b.status='completed' THEN s.price ELSE 0 END),0) AS this_revenue,
        COUNT(CASE        WHEN b.booking_date BETWEEN ? AND ? AND b.status='completed' THEN 1 END)                 AS this_jobs,
        COALESCE(SUM(CASE WHEN b.booking_date BETWEEN ? AND ? AND b.status='completed' THEN s.price ELSE 0 END),0) AS prev_revenue,
        COUNT(CASE        WHEN b.booking_date BETWEEN ? AND ? AND b.status='completed' THEN 1 END)                 AS prev_jobs
    FROM bookings b
    LEFT JOIN services s ON s.id = b.service_id
    WHERE b.employee_id = ?
";
$kpiStmt = $conn->prepare($kpiSql);
$kpiStmt->bind_param('ssssssssi',
    $this_month_start, $this_month_end,
    $this_month_start, $this_month_end,
    $prev_month_start, $prev_month_end,
    $prev_month_start, $prev_month_end,
    $emp_id
);
$kpiStmt->execute();
$kpiRow = $kpiStmt->get_result()->fetch_assoc();
$kpiStmt->close();

$this_revenue = (int)$kpiRow['this_revenue'];
$this_jobs    = (int)$kpiRow['this_jobs'];
$prev_revenue = (int)$kpiRow['prev_revenue'];
$prev_jobs    = (int)$kpiRow['prev_jobs'];
$avg_per_job  = $this_jobs > 0 ? (int)round($this_revenue / $this_jobs) : 0;

// repeat rate: ลูกค้าที่มาซ้ำ (มากกว่า 1 ครั้ง) / ลูกค้าทั้งหมด
$repeatSql = "
    SELECT
        COUNT(DISTINCT user_id) AS total_custs,
        COUNT(DISTINCT CASE WHEN cnt > 1 THEN user_id END) AS repeat_custs
    FROM (
        SELECT user_id, COUNT(*) AS cnt
        FROM bookings
        WHERE employee_id=? AND status='completed'
        GROUP BY user_id
    ) t
";
$repStmt = $conn->prepare($repeatSql);
$repStmt->bind_param('i', $emp_id);
$repStmt->execute();
$repRow = $repStmt->get_result()->fetch_assoc();
$repStmt->close();
$repeat_rate = ($repRow['total_custs'] > 0)
    ? (int)round($repRow['repeat_custs'] / $repRow['total_custs'] * 100)
    : 0;

// rating และ review_count — นับจากตาราง reviews ผ่าน bookings
$ratingRow = $conn->query("
    SELECT COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(*) AS cnt
    FROM reviews r
    JOIN bookings b ON b.id = r.booking_id
    WHERE b.employee_id = $emp_id
")->fetch_assoc();
$rating       = round((float)$ratingRow['avg_rating'], 1);
$rating_count = (int)$ratingRow['cnt'];

$rev_growth = $prev_revenue > 0 ? round(($this_revenue - $prev_revenue) / $prev_revenue * 100, 1) : 0;
$job_growth = $prev_jobs    > 0 ? round(($this_jobs    - $prev_jobs)    / $prev_jobs    * 100, 1) : 0;

$kpi = [
    'this_month_revenue' => $this_revenue,
    'this_month_jobs'    => $this_jobs,
    'avg_per_job'        => $avg_per_job,
    'repeat_rate'        => $repeat_rate,
    'prev_revenue'       => $prev_revenue,
    'prev_jobs'          => $prev_jobs,
    'rating'             => $rating,
    'rating_count'       => $rating_count,
];

// ── Monthly revenue (6 เดือนล่าสุด) ─────────────────
$monthly_revenue = [];
for ($i = 5; $i >= 0; $i--) {
    $ms = date('Y-m-01', strtotime("-$i months"));
    $me = date('Y-m-t',  strtotime("-$i months"));
    $mn = (int)date('n', strtotime($ms));
    $mStmt = $conn->prepare("SELECT COALESCE(SUM(s.price),0) AS rev, COUNT(*) AS jobs FROM bookings b LEFT JOIN services s ON s.id=b.service_id WHERE b.employee_id=? AND b.booking_date BETWEEN ? AND ? AND b.status='completed'");
    $mStmt->bind_param('iss', $emp_id, $ms, $me);
    $mStmt->execute();
    $mRow = $mStmt->get_result()->fetch_assoc();
    $mStmt->close();
    $monthly_revenue[] = ['month' => $thai_months_fmt[$mn], 'revenue' => (int)$mRow['rev'], 'jobs' => (int)$mRow['jobs']];
}

// ── Weekly data (7 วันล่าสุด) ───────────────────────
$weekly_data = [];
$thai_days_short = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
for ($i = 6; $i >= 0; $i--) {
    $dayStr = date('Y-m-d', strtotime("-$i days"));
    $dow    = (int)date('w', strtotime($dayStr));
    $wStmt  = $conn->prepare("SELECT COALESCE(SUM(s.price),0) AS rev, COUNT(*) AS jobs FROM bookings b LEFT JOIN services s ON s.id=b.service_id WHERE b.employee_id=? AND b.booking_date=? AND b.status='completed'");
    $wStmt->bind_param('is', $emp_id, $dayStr);
    $wStmt->execute();
    $wRow = $wStmt->get_result()->fetch_assoc();
    $wStmt->close();
    $weekly_data[] = ['day' => $thai_days_short[$dow], 'jobs' => (int)$wRow['jobs'], 'revenue' => (int)$wRow['rev']];
}

// ── Service breakdown ────────────────────────────────
$svcSql = "
    SELECT s.name AS label,
           COUNT(*) AS cnt,
           COALESCE(SUM(s.price),0) AS revenue
    FROM bookings b
    JOIN services s ON s.id = b.service_id
    WHERE b.employee_id=? AND b.status='completed'
    GROUP BY s.id, s.name
    ORDER BY cnt DESC
    LIMIT 5
";
$svcStmt = $conn->prepare($svcSql);
$svcStmt->bind_param('i', $emp_id);
$svcStmt->execute();
$svcRes = $svcStmt->get_result();
$service_breakdown = [];
$palette = ['#ff9f24','#2c5f8a','#4a7c6f','#c0392b','#b8b3ab'];
$si = 0;
while ($sv = $svcRes->fetch_assoc()) {
    $service_breakdown[] = [
        'label'   => $sv['label'],
        'count'   => (int)$sv['cnt'],
        'revenue' => (int)$sv['revenue'],
        'color'   => $palette[$si % count($palette)],
    ];
    $si++;
}
$svcStmt->close();

// ── Top customers ────────────────────────────────────
$topSql = "
    SELECT CONCAT(u.first_name,' ',u.last_name) AS name,
           COUNT(*) AS visits,
           COALESCE(SUM(s.price),0) AS spend
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    LEFT JOIN services s ON s.id = b.service_id
    WHERE b.employee_id=? AND b.status='completed'
    GROUP BY u.id
    ORDER BY spend DESC
    LIMIT 5
";
$topStmt = $conn->prepare($topSql);
$topStmt->bind_param('i', $emp_id);
$topStmt->execute();
$topRes = $topStmt->get_result();
$top_customers = [];
while ($tc = $topRes->fetch_assoc()) {
    $pts   = (int)floor($tc['spend'] / 5);
    $level = min(3, (int)floor($pts / 2000));
    $tierKeys = [0=>'new',1=>'silver',2=>'gold',3=>'platinum'];
    $top_customers[] = [
        'name'   => trim($tc['name']),
        'visits' => (int)$tc['visits'],
        'spend'  => (int)$tc['spend'],
        'tier'   => $tierKeys[$level],
    ];
}
$topStmt->close();

// ── Reviews จากตาราง reviews (JOIN ผ่าน bookings) ───
$revSql = "
    SELECT r.rating AS stars, r.comment AS quote, r.created_at,
           CONCAT(u.first_name,' ',u.last_name) AS name,
           s.name AS service
    FROM reviews r
    JOIN bookings b  ON b.id  = r.booking_id
    JOIN users u     ON u.id  = b.user_id
    LEFT JOIN services s ON s.id = b.service_id
    WHERE b.employee_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
";
$revStmt = $conn->prepare($revSql);
$revStmt->bind_param('i', $emp_id);
$revStmt->execute();
$revRes = $revStmt->get_result();
$customer_reviews = [];
while ($rv = $revRes->fetch_assoc()) {
    $customer_reviews[] = [
        'name'    => trim($rv['name']),
        'avatar'  => mb_substr(trim($rv['name']), 0, 1, 'UTF-8'),
        'tier'    => 'new',
        'stars'   => (int)$rv['stars'],
        'date'    => formatDateTH(substr($rv['created_at'], 0, 10)),
        'service' => $rv['service'] ?? '—',
        'quote'   => $rv['quote'] ?? '',
    ];
}
$revStmt->close();

// ── Rating breakdown (1-5 ดาว) ───────────────────────
$ratingBreakdown = [];
if ($rating_count > 0) {
    $rbSql = "
        SELECT r.rating, COUNT(*) AS cnt
        FROM reviews r
        JOIN bookings b ON b.id = r.booking_id
        WHERE b.employee_id = ?
        GROUP BY r.rating
        ORDER BY r.rating DESC
    ";
    $rbStmt = $conn->prepare($rbSql);
    $rbStmt->bind_param('i', $emp_id);
    $rbStmt->execute();
    $rbRes = $rbStmt->get_result();
    while ($rb = $rbRes->fetch_assoc()) {
        $ratingBreakdown[(int)$rb['rating']] = (int)$rb['cnt'];
    }
    $rbStmt->close();
}

// ── นับคิว upcoming ของวันนี้ ────────────────────────
$today_str = date('Y-m-d');
$queueStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE employee_id = ? AND booking_date = ? AND status = 'upcoming'");
$queueStmt->bind_param('is', $emp_id, $today_str);
$queueStmt->execute();
$today_queue_count = (int)$queueStmt->get_result()->fetch_assoc()['cnt'];
$queueStmt->close();

$conn->close();

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
<title>Bright Hair — สถิติงาน</title>
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

body{font-family:'Sarabun',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;display:flex;font-size:14px;line-height:1.5;}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);min-height:100vh;background:var(--white);border-right:1px solid var(--rule);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:200;}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid var(--rule);display:flex;align-items:center;gap:11px;}
.brand-mark{width:38px;height:38px;background:var(--amber);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.brand-copy .brand-name{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);letter-spacing:-.2px;}
.brand-copy .brand-sub{font-size:10px;color:var(--mist);letter-spacing:.8px;text-transform:uppercase;margin-top:1px;}
.nav-group{padding:18px 12px 6px;}
.nav-group-label{font-size:9.5px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--mist);padding:0 8px 8px;}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--r-sm);color:var(--stone);font-size:13.5px;font-weight:500;text-decoration:none;transition:var(--ease);position:relative;margin-bottom:1px;}
.nav-link svg{opacity:.55;flex-shrink:0;transition:var(--ease);}
.nav-link:hover{background:var(--amber-pale);color:var(--amber-deep);}
.nav-link:hover svg{opacity:1;}
.nav-link.active{background:var(--amber-pale);color:var(--amber-deep);font-weight:600;}
.nav-link.active svg{opacity:1;}
.nav-link.active::before{content:'';position:absolute;left:-12px;top:50%;transform:translateY(-50%);width:3px;height:54%;background:var(--amber);border-radius:0 3px 3px 0;}
.nav-badge{margin-left:auto;background:var(--amber);color:var(--white);font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
.sidebar-profile{margin-top:auto;padding:14px 16px;border-top:1px solid var(--rule);display:flex;align-items:center;gap:10px;}
.profile-avatar{width:36px;height:36px;background:var(--amber);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--white);font-weight:700;font-size:14px;flex-shrink:0;position:relative;}
.online-ring{position:absolute;bottom:0;right:0;width:10px;height:10px;background:var(--sage);border-radius:50%;border:2px solid var(--white);}
.profile-name{font-weight:600;font-size:13px;color:var(--ink);}
.profile-role{font-size:11px;color:var(--stone);}
.logo-img{
  height:38px;
  width:auto;
  object-fit:contain;
  flex-shrink:0;
}

/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;padding:32px 32px 48px;max-width:calc(100vw - var(--sidebar-w));}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:26px;padding-bottom:20px;border-bottom:1px solid var(--rule);}
.page-eyebrow{font-size:10.5px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--amber);margin-bottom:5px;}
.page-title{font-family:'Playfair Display',serif;font-size:26px;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.head-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;}
.head-right-top{display:flex;gap:10px;align-items:center;}
.leave-scheduled-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:100px;background:var(--amber-pale);color:var(--amber-deep);font-size:11.5px;font-weight:600;border:1px solid rgba(217,119,6,.18);}
.tier-platinum{background:#f3f0ff;color:#6c3fc5;border:1px solid #d4c5f9;}

/* PERIOD TABS */
.period-tabs{display:flex;background:var(--paper);border:1px solid var(--rule);border-radius:var(--r-sm);overflow:hidden;}
.period-tab{padding:7px 18px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:12.5px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease);}
.period-tab.active{background:var(--amber);color:var(--white);}

/* KPI STRIP */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
.kpi-card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);padding:18px 20px;position:relative;overflow:hidden;transition:var(--ease);}
.kpi-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px);}
.kpi-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;}
.kpi-card.c-amber::after{background:var(--amber);}
.kpi-card.c-sage::after{background:var(--sage);}
.kpi-card.c-cobalt::after{background:var(--cobalt);}
.kpi-card.c-gold::after{background:var(--gold);}
.kpi-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.c-amber .kpi-icon{background:var(--amber-pale);}
.c-sage  .kpi-icon{background:var(--sage-pale);}
.c-cobalt .kpi-icon{background:var(--cobalt-pale);}
.c-gold  .kpi-icon{background:var(--gold-pale);}
.kpi-num{font-family:'Playfair Display',serif;font-size:28px;line-height:1;margin-bottom:3px;}
.c-amber .kpi-num{color:var(--amber-deep);}
.c-sage  .kpi-num{color:var(--sage);}
.c-cobalt .kpi-num{color:var(--cobalt);}
.c-gold  .kpi-num{color:var(--gold);}
.kpi-label{font-size:12px;color:var(--stone);font-weight:500;}
.kpi-trend{
  position:absolute;top:16px;right:16px;
  display:inline-flex;align-items:center;gap:3px;
  font-size:11px;font-weight:700;padding:3px 8px;border-radius:4px;
}
.kpi-trend.up{background:var(--sage-pale);color:var(--sage);}
.kpi-trend.down{background:var(--rust-pale);color:var(--rust);}

/* CHART GRID */
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
.chart-grid-3{display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:20px;}

/* CARD */
.card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);overflow:hidden;}
.card-head{padding:16px 20px;border-bottom:1px solid var(--rule);display:flex;align-items:center;justify-content:space-between;}
.card-title{font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;letter-spacing:.1px;}
.card-hint{font-size:11.5px;color:var(--mist);font-style:italic;}
.card-body{padding:20px;}

/* CANVAS WRAPPER */
.chart-wrap{position:relative;padding:16px 20px 12px;}
.chart-wrap canvas{display:block;}

/* BAR CHART */
.bar-chart{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;height:140px;padding:0 4px;}
.bar-col{display:flex;flex-direction:column;align-items:center;flex:1;gap:4px;}
.bar-fill{
  width:100%;border-radius:4px 4px 0 0;
  background:var(--amber);transition:height .5s cubic-bezier(.4,0,.2,1);
  position:relative;min-height:4px;
  cursor:pointer;
}
.bar-fill:hover{background:var(--amber-deep);}
.bar-fill .bar-tip{
  position:absolute;bottom:100%;left:50%;transform:translateX(-50%);
  background:var(--ink);color:var(--white);font-size:10.5px;font-weight:600;
  padding:3px 8px;border-radius:4px;white-space:nowrap;
  opacity:0;transition:opacity .15s;pointer-events:none;margin-bottom:6px;
}
.bar-fill:hover .bar-tip{opacity:1;}
.bar-lbl{font-size:10.5px;color:var(--mist);font-weight:600;}
.bar-val{font-size:11px;color:var(--stone);font-weight:600;}

/* DONUT */
.donut-wrap{display:flex;align-items:center;gap:20px;padding:16px 20px;}
.donut-chart{position:relative;width:120px;height:120px;flex-shrink:0;}
.donut-chart svg{transform:rotate(-90deg);}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-c-num{font-family:'Playfair Display',serif;font-size:20px;color:var(--ink);}
.donut-c-lbl{font-size:10px;color:var(--mist);}
.donut-legend{flex:1;}
.legend-row{display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--rule);font-size:12.5px;}
.legend-row:last-child{border-bottom:none;}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.legend-lbl{flex:1;margin-left:8px;color:var(--ink-soft);}
.legend-pct{font-weight:700;color:var(--ink);margin-right:8px;}
.legend-rev{color:var(--amber-deep);font-family:'Playfair Display',serif;font-size:13px;}

/* LINE CHART */
#revenueChart{width:100% !important;height:180px !important;}

/* TOP CUSTOMERS TABLE */
.rank-table{width:100%;border-collapse:collapse;font-size:13px;}
.rank-table th{padding:9px 18px;text-align:left;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--mist);background:var(--paper);border-bottom:1px solid var(--rule);}
.rank-table td{padding:11px 18px;border-bottom:1px solid var(--rule);vertical-align:middle;}
.rank-table tr:last-child td{border-bottom:none;}
.rank-table tbody tr:hover td{background:var(--amber-pale);}
.rank-num{font-family:'Playfair Display',serif;font-size:18px;color:var(--mist);}
.rank-av{width:30px;height:30px;border-radius:50%;background:var(--amber-mid);color:var(--amber-deep);font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;}
.rank-name{font-weight:600;color:var(--ink);font-size:13.5px;}
.tier{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:4px;}
.tier-gold{background:var(--gold-pale);color:var(--gold);}
.tier-silver{background:var(--cobalt-pale);color:var(--cobalt);}
.tier-new{background:var(--paper);color:var(--mist);border:1px solid var(--rule);}
.rank-spend{font-family:'Playfair Display',serif;font-size:14px;color:var(--amber-deep);}

/* RATING */
.rating-big{display:flex;align-items:center;gap:16px;padding:20px;}
.rating-num{font-family:'Playfair Display',serif;font-size:52px;color:var(--amber-deep);line-height:1;}
.rating-stars{display:flex;gap:3px;margin-bottom:4px;}
.star{color:var(--amber);font-size:18px;}
.rating-sub{font-size:12px;color:var(--stone);}

/* STATUS PILL */
.status-pill{display:flex;align-items:center;background:var(--white);border:1px solid var(--rule);border-radius:100px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.pill-btn{padding:7px 18px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:12.5px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease);display:flex;align-items:center;gap:6px;}
.pill-btn.is-active{background:var(--sage-pale);color:var(--sage);}
.pill-btn.on-leave{background:var(--rust-pale);color:var(--rust);}
.pill-sep{width:1px;height:20px;background:var(--rule);}
.dot-online{width:7px;height:7px;border-radius:50%;background:var(--sage);display:inline-block;}

/* MODAL */
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

/* ANIMATIONS */
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.fadein{animation:fadeUp .35s ease both;}
.fadein-1{animation-delay:.06s;}
.fadein-2{animation-delay:.12s;}
.fadein-3{animation-delay:.18s;}
.fadein-4{animation-delay:.24s;}

/* PROGRESS BAR */
.prog-wrap{margin:4px 0 10px;}
.prog-bar{height:6px;border-radius:3px;background:var(--rule);overflow:hidden;margin-top:4px;}
.prog-fill{height:100%;border-radius:3px;background:var(--amber);transition:width 1s cubic-bezier(.4,0,.2,1);}
.prog-label{display:flex;justify-content:space-between;font-size:11.5px;color:var(--stone);}

/* ═══════════════════════════════════════════════
   REVIEW SECTION — NEW
═══════════════════════════════════════════════ */
.reviews-section{margin-bottom:0;}

.reviews-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:16px;
}
.reviews-title-group{display:flex;align-items:center;gap:10px;}
.reviews-main-title{
  font-family:'Playfair Display',serif;
  font-size:17px;color:var(--ink);
}
.reviews-count-pill{
  background:var(--amber-pale);color:var(--amber-deep);
  font-size:11px;font-weight:700;
  padding:2px 10px;border-radius:20px;
}

.reviews-filter{
  display:flex;gap:6px;
}
.filter-chip{
  padding:5px 13px;border-radius:20px;border:1px solid var(--rule);
  font-family:'Sarabun',sans-serif;font-size:11.5px;font-weight:600;
  color:var(--stone);background:var(--white);cursor:pointer;
  transition:var(--ease);
}
.filter-chip:hover{border-color:var(--amber);color:var(--amber-deep);}
.filter-chip.active{background:var(--amber);color:var(--white);border-color:var(--amber);}

/* Scrollable carousel row */
.reviews-scroll{
  display:flex;gap:14px;
  overflow-x:auto;
  padding-bottom:6px;
  scroll-snap-type:x mandatory;
  -webkit-overflow-scrolling:touch;
  /* hide scrollbar but keep scroll */
  scrollbar-width:thin;
  scrollbar-color:var(--amber-mid) transparent;
}
.reviews-scroll::-webkit-scrollbar{height:4px;}
.reviews-scroll::-webkit-scrollbar-track{background:transparent;}
.reviews-scroll::-webkit-scrollbar-thumb{background:var(--amber-mid);border-radius:4px;}

/* Individual review card */
.review-card{
  flex:0 0 280px;
  scroll-snap-align:start;
  background:var(--white);
  border:1px solid var(--rule);
  border-radius:var(--r);
  padding:18px;
  display:flex;flex-direction:column;gap:12px;
  transition:var(--ease);
  position:relative;
  overflow:hidden;
}
.review-card:hover{
  box-shadow:0 6px 20px rgba(0,0,0,.08);
  transform:translateY(-2px);
  border-color:var(--amber-mid);
}
/* accent bar top */
.review-card::before{
  content:'';
  position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--amber),var(--amber-mid));
}

/* big quotation mark decoration */
.review-card::after{
  content:'\201C';
  position:absolute;bottom:-10px;right:14px;
  font-family:'Playfair Display',serif;
  font-size:72px;color:var(--amber-mid);
  line-height:1;pointer-events:none;
  opacity:.55;
}

.review-meta{display:flex;align-items:center;gap:10px;}
.review-av{
  width:34px;height:34px;border-radius:50%;
  background:var(--amber-mid);color:var(--amber-deep);
  font-weight:700;font-size:13px;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.review-av.av-gold  {background:var(--gold-pale);color:var(--gold);}
.review-av.av-silver{background:var(--cobalt-pale);color:var(--cobalt);}
.review-av.av-new   {background:var(--paper);color:var(--mist);border:1px solid var(--rule);}

.review-meta-right{flex:1;min-width:0;}
.review-name{font-weight:700;font-size:13px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.review-service-date{
  display:flex;align-items:center;gap:6px;
  font-size:11px;color:var(--mist);margin-top:1px;
}
.review-service-tag{
  background:var(--amber-pale);color:var(--amber-deep);
  font-size:10px;font-weight:700;
  padding:1px 7px;border-radius:3px;
}

.review-stars{display:flex;gap:2px;}
.review-star{font-size:13px;color:var(--amber);}
.review-star.empty{color:var(--rule);}

.review-quote{
  font-size:13px;color:var(--ink-soft);
  line-height:1.65;
  position:relative;z-index:1;
  /* clamp to 3 lines */
  display:-webkit-box;
  -webkit-line-clamp:3;
  -webkit-box-orient:vertical;
  overflow:hidden;
}

.review-date{
  font-size:10.5px;color:var(--mist);
  margin-top:auto;
  padding-top:4px;
  border-top:1px solid var(--rule);
}

/* "See all" ghost card */
.review-card-more{
  flex:0 0 120px;
  scroll-snap-align:start;
  background:var(--paper);
  border:1px dashed var(--rule);
  border-radius:var(--r);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:8px;cursor:pointer;
  transition:var(--ease);color:var(--stone);
  font-size:12.5px;font-weight:600;
  text-decoration:none;
}
.review-card-more:hover{border-color:var(--amber);color:var(--amber-deep);background:var(--amber-pale);}
.review-card-more svg{opacity:.5;}
.review-card-more:hover svg{opacity:1;}
</style>
</head>
<body>

<!-- SIDEBAR -->
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
    <a href="stylist-customers.php" class="nav-link">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      ลูกค้าของฉัน
    </a>
    <a href="stylist-stats.php" class="nav-link active">
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

<!-- MAIN -->
<main class="main">

  <div class="page-head fadein">
    <div>
      <div class="page-eyebrow"><?= $date_label ?></div>
      <h1 class="page-title">สถิติงานของฉัน</h1>
    </div>
    <div class="head-right">
      <div class="head-right-top">
        <div class="period-tabs">
          <button class="period-tab" onclick="setPeriod(this,'week')">สัปดาห์นี้</button>
          <button class="period-tab active" onclick="setPeriod(this,'month')">เดือนนี้</button>
          <button class="period-tab" onclick="setPeriod(this,'6m')">6 เดือน</button>
        </div>
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

  <!-- KPI Cards -->
  <div class="kpi-strip fadein fadein-1">

    <div class="kpi-card c-amber">
      <div class="kpi-icon"><svg width="15" height="15" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
      <div class="kpi-num">฿<?= number_format($kpi['this_month_revenue']) ?></div>
      <div class="kpi-label">รายได้เดือนนี้</div>
      <div class="kpi-trend <?= $rev_growth >= 0 ? 'up' : 'down' ?>">
        <?= $rev_growth >= 0 ? '↑' : '↓' ?> <?= abs($rev_growth) ?>%
      </div>
    </div>

    <div class="kpi-card c-sage">
      <div class="kpi-icon"><svg width="15" height="15" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 3v18M18 3c0 4-3 6-3 9s3 5 3 9"/></svg></div>
      <div class="kpi-num"><?= $kpi['this_month_jobs'] ?></div>
      <div class="kpi-label">งานเดือนนี้</div>
      <div class="kpi-trend <?= $job_growth >= 0 ? 'up' : 'down' ?>">
        <?= $job_growth >= 0 ? '↑' : '↓' ?> <?= abs($job_growth) ?>%
      </div>
    </div>

    <div class="kpi-card c-cobalt">
      <div class="kpi-icon"><svg width="15" height="15" fill="none" stroke="var(--cobalt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
      <div class="kpi-num">฿<?= number_format($kpi['avg_per_job']) ?></div>
      <div class="kpi-label">เฉลี่ยต่องาน</div>
    </div>

    <div class="kpi-card c-gold">
      <div class="kpi-icon"><svg width="15" height="15" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
      <div class="kpi-num"><?= $kpi['repeat_rate'] ?>%</div>
      <div class="kpi-label">ลูกค้ากลับมาซ้ำ</div>
    </div>

  </div>

  <!-- Revenue trend + Donut -->
  <div class="chart-grid-3 fadein fadein-2">

    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          รายได้รายเดือน (6 เดือนล่าสุด)
        </div>
        <span class="card-hint">บาท</span>
      </div>
      <div class="chart-wrap" style="padding-bottom:8px;">
        <canvas id="revenueChart"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          สัดส่วนบริการ
        </div>
      </div>
      <div class="donut-wrap">
        <div class="donut-chart">
          <svg viewBox="0 0 36 36" width="120" height="120">
            <?php
            $total_jobs = max(1, array_sum(array_column($service_breakdown,'count')));
            $offset = 0;
            if (!empty($service_breakdown)) {
              foreach ($service_breakdown as $svc) {
                $pct   = $svc['count'] / $total_jobs * 100;
                $dash  = round($pct * 100.53 / 100, 2);
                $gap   = round(100.53 - $dash, 2);
                echo '<circle cx="18" cy="18" r="15.9" fill="none" stroke="'.$svc['color'].'" stroke-width="4" stroke-dasharray="'.$dash.' '.$gap.'" stroke-dashoffset="-'.$offset.'" />';
                $offset += $dash;
              }
            } else {
              // วงกลมเปล่าเมื่อไม่มีข้อมูล
              echo '<circle cx="18" cy="18" r="15.9" fill="none" stroke="#e8e4de" stroke-width="4" stroke-dasharray="100.53 0" />';
            }
            $total_jobs_display = array_sum(array_column($service_breakdown,'count'));
            ?>
          </svg>
          <div class="donut-center">
            <div class="donut-c-num"><?= $total_jobs_display ?></div>
            <div class="donut-c-lbl">งานรวม</div>
          </div>
        </div>
        <div class="donut-legend">
          <?php if (empty($service_breakdown)): ?>
          <div style="font-size:12px;color:var(--mist);padding:8px 0;">ยังไม่มีข้อมูลบริการ</div>
          <?php else: foreach($service_breakdown as $svc): ?>
          <div class="legend-row">
            <div class="legend-dot" style="background:<?= $svc['color'] ?>"></div>
            <div class="legend-lbl"><?= htmlspecialchars($svc['label']) ?></div>
            <span class="legend-pct"><?= round($svc['count']/$total_jobs*100) ?>%</span>
            <span class="legend-rev">฿<?= number_format($svc['revenue']) ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Weekly bar + Top customers -->
  <div class="chart-grid fadein fadein-3">

    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          งานรายวัน (สัปดาห์นี้)
        </div>
        <span class="card-hint">จำนวนงาน / รายได้</span>
      </div>
      <div class="chart-wrap">
        <div class="bar-chart" id="weeklyBars">
          <?php
          $max_jobs = max(1, max(array_column($weekly_data,'jobs')));
          foreach ($weekly_data as $day):
            $h = round($day['jobs'] / $max_jobs * 120);
          ?>
          <div class="bar-col">
            <div class="bar-fill" style="height:<?= $h ?>px">
              <div class="bar-tip"><?= $day['jobs'] ?> งาน · ฿<?= number_format($day['revenue']) ?></div>
            </div>
            <div class="bar-lbl"><?= $day['day'] ?></div>
            <div class="bar-val"><?= $day['jobs'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- Service progress bars -->
      <div class="card-body" style="border-top:1px solid var(--rule);padding-top:16px;">
        <div style="font-size:9.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--mist);margin-bottom:12px;">สัดส่วนบริการสัปดาห์นี้</div>
        <?php if (empty($service_breakdown)): ?>
        <div style="font-size:12px;color:var(--mist);">ยังไม่มีข้อมูล</div>
        <?php else:
          $pb_total = max(1, array_sum(array_column($service_breakdown,'count')));
          foreach(array_slice($service_breakdown,0,3) as $svc): ?>
        <div class="prog-wrap">
          <div class="prog-label">
            <span><?= htmlspecialchars($svc['label']) ?></span>
            <span><?= round($svc['count']/$pb_total*100) ?>%</span>
          </div>
          <div class="prog-bar"><div class="prog-fill" style="width:<?= round($svc['count']/$pb_total*100) ?>%;background:<?= $svc['color'] ?>"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Top customers + Rating -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Top ลูกค้าของฉัน
          </div>
          <span class="card-hint">ยอดใช้จ่าย</span>
        </div>
        <table class="rank-table">
          <thead><tr><th>#</th><th>ลูกค้า</th><th>ระดับ</th><th>ยอดรวม</th></tr></thead>
          <tbody>
            <?php if (empty($top_customers)): ?>
            <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--mist);font-size:12.5px;">ยังไม่มีข้อมูลลูกค้า</td></tr>
            <?php else: foreach($top_customers as $rank => $c):
              $tier_map = ['gold'=>['tier-gold','Gold'],'silver'=>['tier-silver','Silver'],'new'=>['tier-new','New'],'platinum'=>['tier-platinum','Platinum']];
              [$tier_cls, $tier_lbl] = $tier_map[$c['tier']] ?? ['tier-new','New'];
            ?>
            <tr>
              <td><div class="rank-num"><?= $rank+1 ?></div></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <div class="rank-av"><?= mb_substr($c['name'],0,1,'UTF-8') ?></div>
                  <div>
                    <div class="rank-name"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="font-size:11px;color:var(--stone);"><?= $c['visits'] ?> ครั้ง</div>
                  </div>
                </div>
              </td>
              <td><span class="tier <?= $tier_cls ?>"><?= $tier_lbl ?></span></td>
              <td><span class="rank-spend">฿<?= number_format($c['spend']) ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Rating card -->
      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            รีวิวและคะแนน
          </div>
        </div>
        <div class="rating-big">
          <div class="rating-num"><?= $rating > 0 ? number_format($rating,1) : '—' ?></div>
          <div>
            <div class="rating-stars">
              <?php for($s=0;$s<5;$s++): ?>
              <span class="star" style="color:<?= $rating > 0 ? 'var(--amber)' : 'var(--rule)' ?>">★</span>
              <?php endfor; ?>
            </div>
            <div class="rating-sub"><?= $rating_count > 0 ? 'จากรีวิว '.number_format($rating_count).' รายการ' : 'ยังไม่มีรีวิว' ?></div>
            <?php if ($rating_count > 0): ?>
            <div style="margin-top:10px;font-size:12px;color:var(--stone);line-height:1.6;">
              <?php foreach([5,4,3,2,1] as $star):
                $cnt = $ratingBreakdown[$star] ?? 0;
                $pct = $rating_count > 0 ? round($cnt / $rating_count * 100) : 0;
                $barColor = $star >= 4 ? 'var(--amber)' : ($star == 3 ? 'var(--amber-mid)' : 'var(--mist)');
              ?>
              <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
                <div style="font-size:11px;color:var(--mist);width:16px;"><?= $star ?>★</div>
                <div style="flex:1;height:5px;background:var(--rule);border-radius:3px;overflow:hidden;">
                  <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px;"></div>
                </div>
                <div style="font-size:11px;color:var(--stone);width:28px;text-align:right;"><?= $pct ?>%</div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       CUSTOMER REVIEW QUOTES — NEW SECTION
  ════════════════════════════════════════════ -->
  <div class="reviews-section fadein fadein-4">

    <div class="reviews-header">
      <div class="reviews-title-group">
        <svg width="16" height="16" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span class="reviews-main-title">เสียงจากลูกค้า</span>
        <span class="reviews-count-pill"><?= count($customer_reviews) > 0 ? count($customer_reviews).' รีวิวล่าสุด' : 'ยังไม่มีรีวิว' ?></span>
      </div>
      <div class="reviews-filter">
        <button class="filter-chip active" onclick="filterReviews(this,'all')">ทั้งหมด</button>
        <button class="filter-chip" onclick="filterReviews(this,'5')">5 ดาว</button>
        <button class="filter-chip" onclick="filterReviews(this,'4')">4 ดาว</button>
      </div>
    </div>

    <div class="reviews-scroll" id="reviewsScroll">

      <?php if (empty($customer_reviews)): ?>
      <div style="padding:32px 20px;color:var(--mist);font-size:13px;text-align:center;flex:1;">
        <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="margin-bottom:8px;opacity:.35;display:block;margin-left:auto;margin-right:auto"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        ยังไม่มีรีวิวจากลูกค้า
      </div>
      <?php else: foreach($customer_reviews as $rv):
        $av_cls = ['gold'=>'av-gold','silver'=>'av-silver','new'=>'av-new','platinum'=>'av-gold'][$rv['tier']] ?? 'av-new';
      ?>
      <div class="review-card" data-stars="<?= $rv['stars'] ?>">
        <!-- meta row -->
        <div class="review-meta">
          <div class="review-av <?= $av_cls ?>"><?= htmlspecialchars($rv['avatar']) ?></div>
          <div class="review-meta-right">
            <div class="review-name"><?= htmlspecialchars($rv['name']) ?></div>
            <div class="review-service-date">
              <span class="review-service-tag"><?= htmlspecialchars($rv['service']) ?></span>
              <span><?= htmlspecialchars($rv['date']) ?></span>
            </div>
          </div>
        </div>
        <!-- stars -->
        <div class="review-stars">
          <?php for($s=1;$s<=5;$s++): ?>
          <span class="review-star <?= $s > $rv['stars'] ? 'empty' : '' ?>">★</span>
          <?php endfor; ?>
        </div>
        <!-- quote text -->
        <div class="review-quote"><?= htmlspecialchars($rv['quote']) ?></div>
        <!-- date footer -->
        <div class="review-date"><?= htmlspecialchars($rv['date']) ?></div>
      </div>
      <?php endforeach; endif; ?>

      <!-- See all ghost card -->
      <a href="#" class="review-card-more">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 8 16 12 12 16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        ดูทั้งหมด
      </a>

    </div>
  </div>
  <!-- ─────────────────────────────────────────── -->

</main>

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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── DATA from PHP ──────────────────────────────
const monthlyData = <?= json_encode($monthly_revenue, JSON_UNESCAPED_UNICODE) ?>;

// ── Revenue Line Chart ─────────────────────────
const ctx = document.getElementById('revenueChart').getContext('2d');

const gradient = ctx.createLinearGradient(0, 0, 0, 180);
gradient.addColorStop(0, 'rgba(255,159,36,0.22)');
gradient.addColorStop(1, 'rgba(255,159,36,0)');

new Chart(ctx, {
  type: 'line',
  data: {
    labels: monthlyData.map(d => d.month),
    datasets: [{
      label: 'รายได้ (฿)',
      data: monthlyData.map(d => d.revenue),
      borderColor: '#ff9f24',
      borderWidth: 2.5,
      pointBackgroundColor: '#ff9f24',
      pointRadius: 4,
      pointHoverRadius: 6,
      fill: true,
      backgroundColor: gradient,
      tension: 0.35,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => ' ฿' + ctx.parsed.y.toLocaleString() + '  (' + monthlyData[ctx.dataIndex].jobs + ' งาน)'
        },
        backgroundColor: '#1c1a17',
        titleColor: '#b8b3ab',
        bodyColor: '#fdfcf9',
        padding: 10,
        cornerRadius: 6,
      }
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { font: { family: 'Sarabun', size: 11 }, color: '#b8b3ab' }
      },
      y: {
        grid: { color: '#e8e4de', lineWidth: 1 },
        ticks: {
          font: { family: 'Sarabun', size: 11 }, color: '#b8b3ab',
          callback: v => '฿' + (v/1000).toFixed(0) + 'K'
        }
      }
    }
  }
});

// ── Period tabs ────────────────────────────────
function setPeriod(btn, period) {
  document.querySelectorAll('.period-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// ── Leave modal ────────────────────────────────
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

// ── Review filter ──────────────────────────────
function filterReviews(btn, val) {
  document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#reviewsScroll .review-card').forEach(card => {
    card.style.display = (val === 'all' || card.dataset.stars === val) ? 'flex' : 'none';
  });
}
</script>
</body>
</html>