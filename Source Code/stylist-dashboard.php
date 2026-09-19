<?php
// ===================================================
// Bright Hair Studio — Stylist Dashboard
// stylist-dashboard.php  — เชื่อมต่อฐานข้อมูล MySQL
// ===================================================
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: employee_login.php');
    exit;
}

// ── TIMEZONE ───────────────────────────────────────
date_default_timezone_set('Asia/Bangkok');

// ── DB CONFIG ──────────────────────────────────────
require_once __DIR__ . '/env.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die('<div style="font-family:sans-serif;padding:40px;color:#c0392b;">
         ❌ เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . htmlspecialchars($conn->connect_error) . '
         </div>');
}

// ── ดึง employee_id จาก session ───────────────────
$emp_id  = (int)$_SESSION['employee_id'];
$empStmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
$empStmt->bind_param('i', $emp_id);
$empStmt->execute();
$empResult = $empStmt->get_result();
$empRow    = $empResult->fetch_assoc();
$empStmt->close();

// ดึงข้อมูลการลาที่ถึงคิว (Approved และยังไม่หมดเขต) จากตาราง leave_requests
$activeLeaveStmt = $conn->prepare("SELECT leave_date_start, leave_date_end FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND leave_date_end >= CURDATE() ORDER BY leave_date_start ASC LIMIT 1");
$activeLeaveStmt->bind_param('i', $emp_id);
$activeLeaveStmt->execute();
$activeLeave = $activeLeaveStmt->get_result()->fetch_assoc();
$activeLeaveStmt->close();

$leave_date_start  = $activeLeave ? $activeLeave['leave_date_start'] : null;
$leave_date_end    = $activeLeave ? $activeLeave['leave_date_end']   : null;

// ── คำนวณ effective status ─────────────────────────
$raw_status        = $empRow ? ($empRow['status'] ?? 'online') : 'online';

// ── คำนวณ effective_status จากวันที่จริง (ไม่ขึ้นกับค่า status ใน DB) ──
$today_check = date('Y-m-d');

if ($leave_date_start) {
    if ($today_check < $leave_date_start) {
        // ยังไม่ถึงวันลา → online (เก็บ leave_date_* ไว้แสดง badge)
        $effective_status = 'online';
        if ($raw_status !== 'online') {
            $conn->query("UPDATE employees SET status='online' WHERE id=$emp_id");
        }

    } elseif ($leave_date_end && $today_check > $leave_date_end) {
        // พ้นวันลาแล้ว → online + ล้างข้อมูลลา
        $effective_status = 'online';
        $conn->query("UPDATE employees
                      SET status='online'
                      WHERE id=$emp_id");
        $leave_date_start = null;
        $leave_date_end   = null;

    } else {
        // อยู่ในช่วงลาจริง → on_leave
        $effective_status = 'on_leave';
        if ($raw_status !== 'on_leave') {
            $conn->query("UPDATE employees SET status='on_leave' WHERE id=$emp_id");
        }
    }
} else {
    // ไม่มีข้อมูลลา → ใช้ค่าจาก DB ตรงๆ
    $effective_status = in_array($raw_status, ['online','on_leave','offline']) ? $raw_status : 'online';
}

// ── คำนวณจำนวนวันลาที่ใช้ไปแล้วในปีนี้ (โควตา 14 วัน) ──
$current_year = date('Y');
$quotaStmt = $conn->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
$quotaStmt->bind_param('is', $emp_id, $current_year);
$quotaStmt->execute();
$quotaRow = $quotaStmt->get_result()->fetch_assoc();
$used_leave_days = (int)($quotaRow['used_days'] ?? 0);
$quotaStmt->close();
$leave_quota_total = 14;
$remaining_leave = max(0, $leave_quota_total - $used_leave_days);

// ── ตรวจสอบคำขอลาหยุดที่รออนุมัติ ──
$qCountResult = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE employee_id=$emp_id AND booking_date=CURDATE()");
$pendingStmt = $conn->prepare("SELECT * FROM leave_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$pendingStmt->bind_param('i', $emp_id);
$pendingStmt->execute();
$pendingLeave = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();

// ── ดึงประวัติการลาของตนเองทั้งหมด ──
$leaveHistoryStmt = $conn->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC");
$leaveHistoryStmt->bind_param('i', $emp_id);
$leaveHistoryStmt->execute();
$leaveHistoryRes = $leaveHistoryStmt->get_result();
$leave_history = [];
while ($lh = $leaveHistoryRes->fetch_assoc()) {
    $leave_history[] = $lh;
}
$leaveHistoryStmt->close();

$stylist = [
    'name'   => $empRow ? $empRow['name']   : 'ช่างบอย',
    'role'   => $empRow ? $empRow['role']   : 'Expert',
    'status' => $effective_status,
    'leave_date_start' => $leave_date_start,
    'leave_date_end'   => $leave_date_end,
];
$stylist_initial = mb_substr($stylist['name'], 0, 1, 'UTF-8');

// ── ดึงการจองของวันนี้ สำหรับ employee_id = 5 ──────
$today = date('Y-m-d');

$bookSql = "
    SELECT
        b.id,
        b.booking_code,
        b.booking_date,
        b.start_time,
        b.duration_min,
        b.status,
        b.notes,
        u.id          AS user_id,
        u.first_name,
        u.last_name,
        u.phone       AS user_phone,
        u.email,
        u.member_tier,
        u.points,
        s.name        AS service_name,
        s.price       AS service_price
    FROM bookings b
    LEFT JOIN users     u ON u.id = b.user_id
    LEFT JOIN services  s ON s.id = b.service_id
    WHERE b.employee_id = ?
      AND b.booking_date = ?
    ORDER BY b.start_time ASC
";

$bookStmt = $conn->prepare($bookSql);
$bookStmt->bind_param('is', $emp_id, $today);
$bookStmt->execute();
$bookResult = $bookStmt->get_result();

$today_queues = [];
$user_ids     = [];

while ($row = $bookResult->fetch_assoc()) {
    $today_queues[] = $row;
    if ($row['user_id']) {
        $user_ids[] = (int)$row['user_id'];
    }
}
$bookStmt->close();

// ── ดึงข้อมูลลูกค้าที่จองวันนี้ (+ ประวัติการจองทั้งหมดกับช่างบอย) ──
$customers = [];

if (!empty($user_ids)) {
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $types        = str_repeat('i', count($user_ids));

    $custSql = "
        SELECT
            u.id, u.first_name, u.last_name, u.phone, u.email,
            u.member_tier, u.points, u.total_spend, u.note,
            COUNT(b2.id)   AS visit_count,
            MAX(b2.booking_date) AS last_visit
        FROM users u
        LEFT JOIN bookings b2
               ON b2.user_id = u.id
              AND b2.employee_id = ?
              AND b2.status IN ('completed','done')
        WHERE u.id IN ($placeholders)
        GROUP BY u.id
    ";

    $allParams = array_merge([$emp_id], $user_ids);
    $allTypes  = 'i' . $types;

    $custStmt = $conn->prepare($custSql);
    $custStmt->bind_param($allTypes, ...$allParams);
    $custStmt->execute();
    $custResult = $custStmt->get_result();

    while ($c = $custResult->fetch_assoc()) {
        // ดึงประวัติการจองที่ completed/done กับช่างบอย
        $histSql = "
            SELECT b.booking_date, s.name AS service_name, s.price
            FROM bookings b
            LEFT JOIN services s ON s.id = b.service_id
            WHERE b.user_id      = ?
              AND b.employee_id  = ?
              AND b.status IN ('completed','done')
            ORDER BY b.booking_date DESC
            LIMIT 10
        ";
        $histStmt = $conn->prepare($histSql);
        $histStmt->bind_param('ii', $c['id'], $emp_id);
        $histStmt->execute();
        $histResult = $histStmt->get_result();
        $history    = [];
        while ($h = $histResult->fetch_assoc()) {
            $history[] = $h;
        }
        $histStmt->close();

        $c['history'] = $history;
        $customers[$c['id']] = $c;
    }
    $custStmt->close();
}

// ── Auto-sync booking status จากเวลาจริง ──────────
$now_ts = time(); // timestamp ปัจจุบัน (Asia/Bangkok แล้ว)

foreach ($today_queues as &$q) {
    if (in_array($q['status'], ['cancelled', 'completed'])) continue;

    // คำนวณ start และ end timestamp
    $start_ts = strtotime($q['booking_date'] . ' ' . $q['start_time']);
    $end_ts   = $start_ts + ((int)$q['duration_min'] * 60);

    // normalize DB status เป็น canonical values
    $db_normalized = match($q['status']) {
        'active', 'in_progress'              => 'in_progress',
        'done', 'completed'                  => 'completed',
        'upcoming', 'waiting', 'pending'     => 'pending',
        'cancelled'                          => 'cancelled',
        default                              => 'pending',
    };

    // ถ้าช่างเลือก status เองแล้ว (in_progress/completed) → ไม่ override
    // auto-sync เฉพาะกรณียังเป็น pending แล้วถึงเวลาแล้ว
    if ($db_normalized === 'pending' && $now_ts >= $start_ts && $now_ts < $end_ts) {
        // ถึงเวลาแล้ว เปลี่ยนเป็น in_progress อัตโนมัติ
        $conn->query("UPDATE bookings SET status='in_progress' WHERE id=".(int)$q['id']);
        $q['status'] = 'in_progress';
    } elseif ($db_normalized === 'pending') {
        $q['status'] = 'pending';
    } else {
        $q['status'] = $db_normalized;
    }
}
unset($q);

// ── สถิติ ──────────────────────────────────────────
$stats = [
    'total'  => count($today_queues),
    'done'   => count(array_filter($today_queues, fn($q) => in_array($q['status'], ['done','completed']))),
    'active' => count(array_filter($today_queues, fn($q) => in_array($q['status'], ['active','in_progress']))),
    'wait'   => count(array_filter($today_queues, fn($q) => in_array($q['status'], ['waiting','upcoming','pending']))),
];

// ── วันภาษาไทย ─────────────────────────────────────
$thai_days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$thai_months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$now         = new DateTime();
$date_label  = 'วัน'.$thai_days[(int)$now->format('w')].' '.$now->format('d').' '
              .$thai_months[(int)$now->format('n')].' '.((int)$now->format('Y')+543);

// ── Helper: map DB status → display ────────────────
function statusDisplay(string $s): array {
    return match($s) {
        'done','completed'           => ['s-done',    'เสร็จสิ้น'],
        'active','in_progress'       => ['s-active',  'กำลังทำอยู่'],
        'cancelled'                  => ['s-cancel',  'ยกเลิก'],
        'pending','upcoming','waiting' => ['s-waiting', 'รอคิว'],
        default                      => ['s-waiting', 'รอคิว'],
    };
}

// ── Format date TH ─────────────────────────────────
function formatDateTH(?string $d): string {
    global $thai_months;
    if (!$d) return '—';
    $dt = new DateTime($d);
    return $dt->format('d').'/'.$thai_months[(int)$dt->format('n')].'/'.(((int)$dt->format('Y'))+543);
}

// ── Handle AJAX: อัปเดต booking status ────────────
// ── Handle AJAX: ยกเลิกวันลา ───────────────────────
// ── Handle AJAX: ยกเลิกวันลา ───────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'cancel_leave') {
    header('Content-Type: application/json; charset=utf-8');
    $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn2->set_charset('utf8mb4');
    
    // Clear in employees
    $upd = $conn2->prepare("UPDATE employees SET status='online' WHERE id=?");
    $upd->bind_param('i', $emp_id);
    $upd->execute();
    $ok1 = $upd->affected_rows >= 0;
    $upd->close();

    // Cancel pending or approved upcoming leave requests
    $upd2 = $conn2->prepare("UPDATE leave_requests SET status='cancelled' WHERE employee_id=? AND status IN ('pending', 'approved') AND leave_date_end >= CURDATE()");
    $upd2->bind_param('i', $emp_id);
    $upd2->execute();
    $upd2->close();

    $conn2->close();
    echo json_encode(['ok' => $ok1]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_booking_status') {
    header('Content-Type: application/json; charset=utf-8');
    $booking_id     = (int)($_POST['booking_id'] ?? 0);
    $new_status     = $_POST['status'] ?? '';
    $allowed        = ['pending', 'in_progress', 'completed', 'cancelled'];

    if (!$booking_id || !in_array($new_status, $allowed)) {
        echo json_encode(['ok' => false, 'msg' => 'ข้อมูลไม่ถูกต้อง']);
        exit;
    }
    $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn2->set_charset('utf8mb4');
    $upd = $conn2->prepare("UPDATE bookings SET status = ? WHERE id = ? AND employee_id = ?");
    $upd->bind_param('sii', $new_status, $booking_id, $emp_id);
    $upd->execute();
    $ok = $upd->affected_rows > 0;
    $upd->close();
    $conn2->close();
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── Handle AJAX: ขอลา ──────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'request_leave') {
    header('Content-Type: application/json; charset=utf-8');
    $leave_start  = $_POST['leave_date_start'] ?? '';
    $leave_end    = $_POST['leave_date_end']   ?? '';
    $leave_reason = trim($_POST['reason']      ?? '');
    $leave_type   = trim($_POST['type']        ?? '');

    // Validate dates
    $min_date = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));

    if (!$leave_start || !$leave_end) {
        echo json_encode(['ok' => false, 'msg' => 'กรุณาเลือกวันที่ขอลา']);
        exit;
    }
    if ($leave_start < $min_date || $leave_end > $max_date || $leave_end < $leave_start) {
        echo json_encode(['ok' => false, 'msg' => 'ช่วงวันที่ไม่ถูกต้อง']);
        exit;
    }
    // จำกัดสูงสุด 3 วัน
    $diff = (new DateTime($leave_end))->diff(new DateTime($leave_start))->days + 1;
    if ($diff > 3) {
        echo json_encode(['ok' => false, 'msg' => 'ลาได้สูงสุด 3 วันต่อครั้ง']);
        exit;
    }

    // ตรวจสอบโควตาวันลา 14 วัน
    $current_year_req = date('Y', strtotime($leave_start));
    $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn2->set_charset('utf8mb4');
    $qStmt = $conn2->prepare("SELECT SUM(DATEDIFF(leave_date_end, leave_date_start) + 1) AS used_days FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND YEAR(leave_date_start) = ?");
    $qStmt->bind_param('is', $emp_id, $current_year_req);
    $qStmt->execute();
    $used_this_year = (int)($qStmt->get_result()->fetch_assoc()['used_days'] ?? 0);
    $qStmt->close();

    $remaining_req = max(0, 14 - $used_this_year);
    if ($diff > $remaining_req) {
        $conn2->close();
        echo json_encode(['ok' => false, 'msg' => "เกินโควตา! คุณเหลือวันลาอีกเพียง {$remaining_req} วันในปีนี้"]);
        exit;
    }

    // Insert pending leave into leave_requests
    $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn2->set_charset('utf8mb4');
    $ins = $conn2->prepare("INSERT INTO leave_requests (employee_id, leave_date_start, leave_date_end, leave_type, leave_note, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $ins->bind_param('issss', $emp_id, $leave_start, $leave_end, $leave_type, $leave_reason);
    $ins->execute();
    $ok = $ins->affected_rows > 0;
    $ins->close();
    $conn2->close();

    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'ส่งคำขอลาหยุดเรียบร้อยแล้ว รอผู้ดูแลระบบอนุมัติ' : 'เกิดข้อผิดพลาด']);
    exit;
}

// ── ดึงตารางงาน 30 วันข้างหน้า ─────────────────────
$date_from = date('Y-m-d');
$date_to   = date('Y-m-d', strtotime('+30 days'));

$schedSql = "
    SELECT
        b.booking_date,
        b.start_time,
        b.duration_min,
        b.status,
        CONCAT(u.first_name,' ',u.last_name) AS customer_name,
        s.name AS service_name
    FROM bookings b
    LEFT JOIN users    u ON u.id = b.user_id
    LEFT JOIN services s ON s.id = b.service_id
    WHERE b.employee_id = ?
      AND b.booking_date BETWEEN ? AND ?
      AND b.status NOT IN ('cancelled')
    ORDER BY b.booking_date ASC, b.start_time ASC
";
$schedStmt = $conn->prepare($schedSql);
$schedStmt->bind_param('iss', $emp_id, $date_from, $date_to);
$schedStmt->execute();
$schedResult = $schedStmt->get_result();
$schedule_rows = [];
while ($r = $schedResult->fetch_assoc()) {
    $schedule_rows[] = $r;
}
$schedStmt->close();

// Group by date
$schedule_by_date = [];
foreach ($schedule_rows as $r) {
    $schedule_by_date[$r['booking_date']][] = $r;
}

// ── นับคิว upcoming ของวันนี้สำหรับ badge ────────────
$upcomingResult = $conn->query("SELECT COUNT(*) AS cnt FROM bookings WHERE employee_id=$emp_id AND booking_date=CURDATE() AND status='upcoming'");
$today_upcoming_count = $upcomingResult ? (int)$upcomingResult->fetch_assoc()['cnt'] : 0;

$conn->close();

// ── ลูกค้าคนแรก (สำหรับ initial render) ──────────
$first_customer = !empty($customers) ? array_values($customers)[0] : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Bright Hair — Stylist Dashboard</title>
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
  width:var(--sidebar-w);min-height:100vh;
  background:var(--white);border-right:1px solid var(--rule);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;z-index:200;
}
.sidebar-logo{
  padding:22px 20px 18px;border-bottom:1px solid var(--rule);
  display:flex;align-items:center;gap:11px;
}
.logo-img{height:38px;width:auto;object-fit:contain;flex-shrink:0;}
.brand-copy .brand-name{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);letter-spacing:-.2px;}
.brand-copy .brand-sub{font-size:10px;color:var(--mist);letter-spacing:.8px;text-transform:uppercase;margin-top:1px;}
.nav-group{padding:18px 12px 6px;}
.nav-group-label{font-size:9.5px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--mist);padding:0 8px 8px;}
.nav-link{
  display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--r-sm);
  color:var(--stone);font-size:13.5px;font-weight:500;text-decoration:none;transition:var(--ease);
  position:relative;margin-bottom:1px;
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
.sidebar-profile{
  margin-top:auto;padding:14px 16px;border-top:1px solid var(--rule);
  display:flex;align-items:center;gap:10px;
}
.profile-avatar{
  width:36px;height:36px;background:var(--amber);border-radius:50%;
  display:flex;align-items:center;justify-content:center;color:var(--white);
  font-weight:700;font-size:14px;flex-shrink:0;position:relative;
}
.online-ring{position:absolute;bottom:0;right:0;width:10px;height:10px;background:var(--sage);border-radius:50%;border:2px solid var(--white);}
.profile-name{font-weight:600;font-size:13px;color:var(--ink);}
.profile-role{font-size:11px;color:var(--stone);}

/* ── MAIN ──────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;padding:32px 32px 48px;max-width:calc(100vw - var(--sidebar-w));}

/* ── PAGE HEADER ───────────────────────────── */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:26px;padding-bottom:20px;border-bottom:1px solid var(--rule);}
.page-eyebrow{font-size:10.5px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--amber);margin-bottom:5px;}
.page-title{font-family:'Playfair Display',serif;font-size:26px;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.head-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;}
.status-pill{display:flex;align-items:center;background:var(--white);border:1px solid var(--rule);border-radius:100px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.pill-btn{padding:7px 18px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:12.5px;font-weight:600;color:var(--stone);cursor:pointer;transition:var(--ease);display:flex;align-items:center;gap:6px;}
.pill-btn.is-active{background:var(--sage-pale);color:var(--sage);}
.pill-btn.on-leave{background:var(--rust-pale);color:var(--rust);}
.pill-sep{width:1px;height:20px;background:var(--rule);}
.dot-online{width:7px;height:7px;border-radius:50%;background:var(--sage);display:inline-block;}
.leave-scheduled-badge{display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 10px;border-radius:100px;background:var(--amber-pale,#fff8ec);color:var(--amber,#d97706);font-size:11.5px;font-weight:600;border:1px solid rgba(217,119,6,.18);}
.leave-scheduled-badge.is-on-leave{background:var(--rust-pale);color:var(--rust);border-color:rgba(192,57,43,.18);}

/* ── STATS ─────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
.stat-card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);padding:18px 20px;position:relative;overflow:hidden;transition:var(--ease);}
.stat-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;}
.stat-card.c-amber::after{background:var(--amber);}
.stat-card.c-sage::after{background:var(--sage);}
.stat-card.c-cobalt::after{background:var(--cobalt);}
.stat-card.c-rust::after{background:var(--rust);}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px);}
.stat-icon-wrap{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;}
.c-amber .stat-icon-wrap{background:var(--amber-pale);}
.c-sage  .stat-icon-wrap{background:var(--sage-pale);}
.c-cobalt .stat-icon-wrap{background:var(--cobalt-pale);}
.c-rust  .stat-icon-wrap{background:var(--rust-pale);}
.stat-num{font-family:'Playfair Display',serif;font-size:30px;line-height:1;margin-bottom:3px;}
.c-amber .stat-num{color:var(--amber-deep);}
.c-sage  .stat-num{color:var(--sage);}
.c-cobalt .stat-num{color:var(--cobalt);}
.c-rust  .stat-num{color:var(--rust);}
.stat-label{font-size:12px;color:var(--stone);font-weight:500;}

/* ── GRID ──────────────────────────────────── */
.content-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;}
.card{background:var(--white);border:1px solid var(--rule);border-radius:var(--r);overflow:hidden;}
.card-head{padding:16px 20px;border-bottom:1px solid var(--rule);display:flex;align-items:center;justify-content:space-between;}
.card-title{font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;letter-spacing:.1px;}
.live-pip{width:7px;height:7px;border-radius:50%;background:var(--sage);animation:pip 2.4s ease-in-out infinite;flex-shrink:0;}
@keyframes pip{0%,100%{box-shadow:0 0 0 0 rgba(74,124,111,.5);}50%{box-shadow:0 0 0 5px rgba(74,124,111,0);}}
.card-hint{font-size:11.5px;color:var(--mist);font-style:italic;}

/* ── EMPTY STATE ───────────────────────────── */
.empty-state{padding:40px 20px;text-align:center;color:var(--mist);}
.empty-state svg{margin-bottom:12px;opacity:.4;}
.empty-state p{font-size:13px;}

/* ── QUEUE TABLE ───────────────────────────── */
.q-table{width:100%;border-collapse:collapse;font-size:13px;}
.q-table th{padding:9px 20px;text-align:left;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--mist);background:var(--paper);border-bottom:1px solid var(--rule);}
.q-table td{padding:13px 20px;border-bottom:1px solid var(--rule);vertical-align:middle;}
.q-table tr:last-child td{border-bottom:none;}
.q-table tbody tr{cursor:pointer;transition:background var(--ease);}
.q-table tbody tr:hover td{background:var(--amber-pale);}
.q-table tbody tr.is-active td{background:var(--amber-pale);}
.q-time{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);white-space:nowrap;}
.q-cname{font-weight:600;color:var(--ink);font-size:13.5px;}
.q-svc{font-size:12px;color:var(--stone);margin-top:2px;}
.tag{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.4px;padding:3px 9px;border-radius:4px;text-transform:uppercase;}
.tag-online{background:var(--cobalt-pale);color:var(--cobalt);}
.tag-walkin{background:var(--amber-pale);color:var(--amber-deep);}
.status-pip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:4px;letter-spacing:.2px;}
.s-done   {background:var(--sage-pale);color:var(--sage);}
.s-active {background:var(--amber-pale);color:var(--amber-deep);}
.s-waiting{background:var(--paper);color:var(--mist);border:1px solid var(--rule);}
.s-cancel {background:var(--rust-pale);color:var(--rust);}

/* Status select dropdown */
.status-select{
  padding:5px 10px;border-radius:4px;border:1px solid var(--rule);
  font-family:'Sarabun',sans-serif;font-size:11px;font-weight:700;
  cursor:pointer;outline:none;transition:var(--ease);letter-spacing:.2px;
  appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23b8b3ab' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 8px center;
  padding-right:24px;
}
.status-select.status-select-pending,
.status-select.status-select-upcoming,
.status-select.status-select-waiting  { background-color:var(--paper);color:var(--stone); }
.status-select.status-select-in_progress,
.status-select.status-select-active   { background-color:var(--amber-pale);color:var(--amber-deep);border-color:var(--amber-mid); }
.status-select.status-select-completed,
.status-select.status-select-done     { background-color:var(--sage-pale);color:var(--sage);border-color:#c5ddd9; }
.note-flag{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--rust);background:var(--rust-pale);padding:3px 8px;border-radius:4px;border-left:2px solid var(--rust);}

/* ── MEMBER TIER BADGE ─────────────────────── */
.tier-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;}
.tier-member{background:#f0f0f0;color:#666;}
.tier-bronze{background:#f4e4d4;color:#8b5e3c;}
.tier-silver{background:#e8e8e8;color:#666;}
.tier-gold  {background:#fff3cc;color:#b8860b;}

/* ── RIGHT PANEL ───────────────────────────── */
.right-panel{display:flex;flex-direction:column;gap:16px;}
.search-wrap{padding:12px 16px;border-bottom:1px solid var(--rule);position:relative;}
.search-input{width:100%;padding:8px 12px 8px 34px;border:1px solid var(--rule);border-radius:var(--r-sm);background:var(--paper);font-family:'Sarabun',sans-serif;font-size:13px;color:var(--ink);outline:none;transition:var(--ease);}
.search-input:focus{border-color:var(--amber);background:var(--white);box-shadow:0 0 0 3px rgba(255,159,36,.1);}
.search-ico{position:absolute;left:27px;top:50%;transform:translateY(-50%);color:var(--mist);pointer-events:none;}
.cust-item{display:flex;align-items:center;gap:11px;padding:11px 16px;cursor:pointer;transition:var(--ease);border-bottom:1px solid var(--rule);}
.cust-item:last-child{border-bottom:none;}
.cust-item:hover{background:var(--amber-pale);}
.cust-item.sel{background:var(--amber-pale);border-left:2px solid var(--amber);padding-left:14px;}
.cust-av{width:36px;height:36px;border-radius:50%;background:var(--amber-mid);color:var(--amber-deep);font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.cust-nm{font-weight:600;font-size:13.5px;color:var(--ink);}
.cust-meta{font-size:11px;color:var(--stone);margin-top:1px;}

/* Customer detail */
.cust-detail{padding:18px;}
.d-name{font-family:'Playfair Display',serif;font-size:18px;color:var(--ink);margin-bottom:3px;}
.d-phone{font-size:12.5px;color:var(--stone);margin-bottom:12px;}
.d-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:13px;}
.d-chip{font-size:11px;font-weight:600;padding:4px 11px;border-radius:4px;}
.chip-v{background:var(--amber-pale);color:var(--amber-deep);}
.chip-d{background:var(--cobalt-pale);color:var(--cobalt);}
.chip-tier{background:#f4e4d4;color:#8b5e3c;}
.allergy-box{display:flex;gap:8px;align-items:flex-start;background:var(--rust-pale);border:1px solid #f5c4c0;border-radius:6px;padding:9px 12px;margin-bottom:14px;font-size:12.5px;color:var(--rust);line-height:1.4;}
.history-head{font-size:9.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--mist);padding-bottom:8px;border-bottom:1px solid var(--rule);margin-bottom:4px;}
.h-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--rule);font-size:13px;}
.h-row:last-child{border-bottom:none;}
.h-date{font-size:11px;color:var(--mist);margin-bottom:2px;}
.h-svc{font-weight:600;color:var(--ink-soft);}
.h-price{font-family:'Playfair Display',serif;font-size:14px;color:var(--amber-deep);}

/* ── MODAL ─────────────────────────────────── */
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

/* ── TABS ───────────────────────────────────────── */
.tab-bar{display:flex;gap:0;border-bottom:1px solid var(--rule);padding:0 20px;}
.tab-btn{padding:12px 16px;border:none;background:transparent;font-family:'Sarabun',sans-serif;font-size:13px;font-weight:600;color:var(--stone);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:var(--ease);}
.tab-btn.active{color:var(--amber-deep);border-bottom-color:var(--amber);}
.tab-btn:hover:not(.active){color:var(--ink);}
.tab-pane{display:none;}
.tab-pane.active{display:block;}

/* ── SCHEDULE CALENDAR ─────────────────────────── */
.sched-container{padding:16px 20px 20px;}
.sched-month-label{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--mist);padding:12px 0 6px;border-top:1px solid var(--rule);margin-top:8px;}
.sched-month-label:first-child{border-top:none;margin-top:0;padding-top:0;}
.sched-day{display:flex;align-items:flex-start;gap:14px;padding:10px 0;border-bottom:1px solid var(--rule);}
.sched-day:last-child{border-bottom:none;}
.sched-day-label{min-width:56px;text-align:center;flex-shrink:0;}
.sched-day-num{font-family:'Playfair Display',serif;font-size:22px;line-height:1;color:var(--ink);}
.sched-day-num.today{color:var(--amber-deep);}
.sched-day-name{font-size:10px;color:var(--stone);margin-top:2px;}
.sched-jobs{flex:1;display:flex;flex-direction:column;gap:6px;}
.sched-job{background:var(--paper);border:1px solid var(--rule);border-radius:6px;padding:7px 11px;display:flex;gap:10px;align-items:center;}
.sched-job-time{font-size:12px;font-weight:700;color:var(--amber-deep);min-width:42px;}
.sched-job-info{flex:1;}
.sched-job-cust{font-size:13px;font-weight:600;color:var(--ink);}
.sched-job-svc{font-size:11px;color:var(--stone);margin-top:1px;}
.sched-job-dur{font-size:11px;color:var(--mist);white-space:nowrap;}
.sched-empty{font-size:12px;color:var(--mist);font-style:italic;padding:4px 0;}
.sched-today-badge{display:inline-block;background:var(--amber);color:var(--white);font-size:9px;font-weight:700;padding:1px 6px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;margin-left:6px;vertical-align:middle;}

/* ── LEAVE MODAL DATE ──────────────────────────── */
.m-date{width:100%;padding:9px 13px;border:1px solid var(--rule);border-radius:var(--r-sm);font-family:'Sarabun',sans-serif;font-size:13.5px;color:var(--ink);outline:none;transition:var(--ease);background:var(--paper);margin-bottom:12px;}
.m-date:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,159,36,.1);}
.m-label{font-size:11.5px;font-weight:700;color:var(--stone);margin-bottom:5px;display:block;letter-spacing:.3px;}
.m-date-range{display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.m-date-field{flex:1;display:flex;flex-direction:column;gap:4px;}
.m-date-lbl{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--mist);}
.m-date-arrow{font-size:18px;color:var(--mist);flex-shrink:0;margin-top:16px;}
.m-day-count{font-size:12px;font-weight:700;color:var(--sage);margin-bottom:14px;text-align:center;background:var(--sage-pale);padding:5px 10px;border-radius:20px;display:block;}
.leave-success{display:flex;align-items:center;gap:8px;background:var(--sage-pale);border:1px solid #c3ddd9;border-radius:6px;padding:10px 14px;margin-top:10px;font-size:13px;color:var(--sage);font-weight:600;}

/* ── FADE ──────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.fadein{animation:fadeUp .35s ease both;}
.fadein-1{animation-delay:.06s;}
.fadein-2{animation-delay:.12s;}
.fadein-3{animation-delay:.18s;}
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
    <a href="stylist-dashboard.php" class="nav-link active">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      คิวของฉัน
      <span class="nav-badge"><?= $today_upcoming_count ?></span>
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
      <?= htmlspecialchars($stylist_initial) ?>
      <div class="online-ring"></div>
    </div>
    <div>
      <div class="profile-name"><?= htmlspecialchars($stylist['name']) ?></div>
      <div class="profile-role"><?= htmlspecialchars($stylist['role']) ?></div>
    </div>
    <a href="logout.php" title="ออกจากระบบ" style="margin-left:auto;color:var(--mist);display:flex;align-items:center;transition:var(--ease);" onmouseover="this.style.color='var(--rust)'" onmouseout="this.style.color='var(--mist)'">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">

  <div class="page-head fadein">
    <div>
      <div class="page-eyebrow"><?= $date_label ?></div>
      <h1 class="page-title">ตารางคิวของฉัน</h1>
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

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card c-amber fadein fadein-1">
      <div class="stat-icon-wrap"><svg width="16" height="16" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <div class="stat-num"><?= $stats['total'] ?></div>
      <div class="stat-label">รายการทั้งหมด</div>
    </div>
    <div class="stat-card c-sage fadein fadein-1">
      <div class="stat-icon-wrap"><svg width="16" height="16" fill="none" stroke="var(--sage)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
      <div class="stat-num"><?= $stats['done'] ?></div>
      <div class="stat-label">เสร็จสิ้น</div>
    </div>
    <div class="stat-card c-cobalt fadein fadein-2">
      <div class="stat-icon-wrap"><svg width="16" height="16" fill="none" stroke="var(--cobalt)" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M6 3v18M18 3c0 4-3 6-3 9s3 5 3 9M9 3c0 4-3 6-3 9s3 5 3 9"/></svg></div>
      <div class="stat-num"><?= $stats['active'] ?></div>
      <div class="stat-label">กำลังทำอยู่</div>
    </div>
    <div class="stat-card c-rust fadein fadein-3">
      <div class="stat-icon-wrap"><svg width="16" height="16" fill="none" stroke="var(--rust)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
      <div class="stat-num"><?= $stats['wait'] ?></div>
      <div class="stat-label">รอคิว</div>
    </div>
  </div>

  <!-- Content -->
  <div class="content-grid fadein fadein-2">

    <!-- Queue table + Schedule tabs -->
    <div class="card">
      <div class="tab-bar">
        <button class="tab-btn active" id="tab-today" onclick="switchTab('today')">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:5px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          คิววันนี้
        </button>
        <button class="tab-btn" id="tab-schedule" onclick="switchTab('schedule')">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          ตารางงานล่วงหน้า
        </button>
      </div>

      <!-- TAB: คิววันนี้ -->
      <div class="tab-pane active" id="pane-today">
        <?php if (empty($today_queues)): ?>
        <div class="empty-state">
          <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <p>ไม่มีคิวสำหรับวันนี้</p>
        </div>
        <?php else: ?>
        <table class="q-table">
          <thead>
            <tr>
              <th>เวลา</th><th>ลูกค้า</th><th>บริการ</th><th>สถานะ</th><th>หมายเหตุ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($today_queues as $q):
              [$scls, $stxt] = statusDisplay($q['status']);
              $full_name     = trim($q['first_name'].' '.$q['last_name']);
              $time_display  = substr($q['start_time'], 0, 5);
              $is_active     = in_array($q['status'], ['active','in_progress']);
              $is_cancelled  = $q['status'] === 'cancelled';
              $start_ts_val  = strtotime($q['booking_date'] . ' ' . $q['start_time']);
              $end_ts_val    = $start_ts_val + ((int)$q['duration_min'] * 60);
            ?>
            <tr class="<?= $is_active ? 'is-active' : '' ?>"
                id="qrow-<?= (int)$q['id'] ?>"
                data-booking-id="<?= (int)$q['id'] ?>"
                data-status="<?= htmlspecialchars($q['status']) ?>"
                data-start-ts="<?= $start_ts_val ?>"
                data-end-ts="<?= $end_ts_val ?>"
                onclick="pickCustomer(<?= (int)$q['user_id'] ?>)">
              <td><div class="q-time"><?= htmlspecialchars($time_display) ?></div></td>
              <td>
                <div class="q-cname"><?= htmlspecialchars($full_name) ?></div>
                <div class="q-svc"><?= htmlspecialchars($q['email'] ?? '') ?></div>
              </td>
              <td>
                <div class="q-cname"><?= htmlspecialchars($q['service_name'] ?? '—') ?></div>
                <div class="q-svc"><?= $q['duration_min'] ? $q['duration_min'].' นาที' : '' ?></div>
              </td>
              <td onclick="event.stopPropagation()">
                <?php if ($is_cancelled): ?>
                  <span class="status-pip s-cancel">ยกเลิก</span>
                <?php else: ?>
                  <select class="status-select status-select-<?= $q['status'] ?>"
                          data-booking-id="<?= (int)$q['id'] ?>"
                          onchange="changeStatus(this)"
                          onclick="event.stopPropagation()">
                    <option value="pending"     <?= in_array($q['status'],['pending','upcoming','waiting']) ? 'selected' : '' ?>>รอคิว</option>
                    <option value="in_progress" <?= in_array($q['status'],['in_progress','active'])         ? 'selected' : '' ?>>กำลังทำอยู่</option>
                    <option value="completed"   <?= in_array($q['status'],['completed','done'])              ? 'selected' : '' ?>>เสร็จสิ้น</option>
                  </select>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($q['notes']): ?>
                  <span class="note-flag">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?= htmlspecialchars($q['notes']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--rule);font-size:16px">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- TAB: ตารางงานล่วงหน้า -->
      <div class="tab-pane" id="pane-schedule">
        <div class="sched-container">
          <?php
          // Build 30 days
          $cur_month = '';
          $thai_days_full   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
          $thai_months_full = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
          for ($d = 0; $d <= 30; $d++):
              $dt_obj  = new DateTime("+$d days");
              $dt_str  = $dt_obj->format('Y-m-d');
              $month_k = $thai_months_full[(int)$dt_obj->format('n')].' '.((int)$dt_obj->format('Y')+543);
              $day_num = $dt_obj->format('j');
              $day_name= $thai_days_full[(int)$dt_obj->format('w')];
              $is_today= $d === 0;
              $jobs    = $schedule_by_date[$dt_str] ?? [];
          ?>
          <?php if ($month_k !== $cur_month): $cur_month = $month_k; ?>
          <div class="sched-month-label"><?= $cur_month ?></div>
          <?php endif; ?>
          <div class="sched-day">
            <div class="sched-day-label">
              <div class="sched-day-num <?= $is_today ? 'today' : '' ?>"><?= $day_num ?></div>
              <div class="sched-day-name"><?= $day_name ?><?= $is_today ? '<br><span class="sched-today-badge">วันนี้</span>' : '' ?></div>
            </div>
            <div class="sched-jobs">
              <?php if (empty($jobs)): ?>
                <div class="sched-empty">ว่าง</div>
              <?php else: foreach ($jobs as $j): ?>
                <div class="sched-job">
                  <div class="sched-job-time"><?= substr($j['start_time'],0,5) ?></div>
                  <div class="sched-job-info">
                    <div class="sched-job-cust"><?= htmlspecialchars(trim($j['customer_name'])) ?></div>
                    <div class="sched-job-svc"><?= htmlspecialchars($j['service_name'] ?? '—') ?></div>
                  </div>
                  <?php if ($j['duration_min']): ?>
                  <div class="sched-job-dur"><?= (int)$j['duration_min'] ?> นาที</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>

    </div>

    <!-- Right panel -->
    <div class="right-panel">

      <!-- Customer list -->
      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            ลูกค้าวันนี้
          </div>
        </div>
        <div class="search-wrap">
          <svg class="search-ico" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" placeholder="ค้นหาชื่อลูกค้า..." oninput="filterCust(this.value)"/>
        </div>

        <div id="custList">
          <?php if (empty($customers)): ?>
          <div class="empty-state" style="padding:24px 16px;">
            <p>ไม่มีลูกค้าวันนี้</p>
          </div>
          <?php else: ?>
          <?php $ci = 0; foreach ($customers as $uid => $c):
            $full = trim($c['first_name'].' '.$c['last_name']);
            $init = mb_substr($c['first_name'], 0, 1, 'UTF-8');
          ?>
          <div class="cust-item <?= $ci===0?'sel':'' ?>"
               id="ci<?= $uid ?>"
               data-name="<?= htmlspecialchars($full) ?>"
               onclick="pickById(<?= $uid ?>)">
            <div class="cust-av"><?= htmlspecialchars($init) ?></div>
            <div>
              <div class="cust-nm"><?= htmlspecialchars($full) ?></div>
              <div class="cust-meta">
                มาแล้ว <?= (int)$c['visit_count'] ?> ครั้ง
                <?php if ($c['last_visit']): ?> &middot; ล่าสุด <?= formatDateTH($c['last_visit']) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <?php $ci++; endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Customer detail -->
      <div class="card" id="custDetail">
        <?php if ($first_customer):
          $c0   = $first_customer;
          $fn0  = trim($c0['first_name'].' '.$c0['last_name']);
        ?>
        <div class="cust-detail">
          <div class="d-name"><?= htmlspecialchars($fn0) ?></div>
          <div class="d-phone"><?= htmlspecialchars($c0['phone'] ?? '—') ?></div>
          <div class="d-chips">
            <span class="d-chip chip-v">มาแล้ว <?= (int)$c0['visit_count'] ?> ครั้ง</span>
            <?php if ($c0['last_visit']): ?>
            <span class="d-chip chip-d">ล่าสุด <?= formatDateTH($c0['last_visit']) ?></span>
            <?php endif; ?>
            <?php if ($c0['member_tier'] ?? ''): ?>
            <span class="d-chip chip-tier"><?= htmlspecialchars($c0['member_tier']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($c0['note'] ?? ''): ?>
          <div class="allergy-box">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?= htmlspecialchars($c0['note']) ?>
          </div>
          <?php endif; ?>
          <div class="history-head">ประวัติการใช้บริการกับช่างบอย</div>
          <?php if (empty($c0['history'])): ?>
            <p style="font-size:12px;color:var(--mist);padding:12px 0;">ยังไม่มีประวัติ</p>
          <?php else: ?>
            <?php foreach ($c0['history'] as $h): ?>
            <div class="h-row">
              <div>
                <div class="h-date"><?= formatDateTH($h['booking_date']) ?></div>
                <div class="h-svc"><?= htmlspecialchars($h['service_name'] ?? '—') ?></div>
              </div>
              <div class="h-price"><?= $h['price'] ? '฿'.number_format($h['price']) : '—' ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:32px 16px;">
          <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          <p>คลิกแถวคิวเพื่อดูข้อมูลลูกค้า</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Leave History -->
      <div class="card" style="margin-top:20px;">
        <div class="card-head">
          <div class="card-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            ประวัติการลา (5 ครั้งล่าสุด)
          </div>
        </div>
        <div style="padding: 12px 20px;">
            <?php
            $conn3 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn3->set_charset('utf8mb4');
            $stmtHist = $conn3->prepare("
                SELECT leave_date_start, leave_date_end, status 
                FROM leave_requests 
                WHERE employee_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $stmtHist->bind_param('i', $emp_id);
            $stmtHist->execute();
            $histList = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtHist->close();
            $conn3->close();

            if (empty($histList)):
            ?>
            <div class="empty-state" style="padding:16px 8px;">
                <p>ยังไม่มีประวัติการพิจารณาลา</p>
            </div>
            <?php else: ?>
                <?php foreach ($histList as $h):
                    $dateStr = formatDateTH($h['leave_date_start']);
                    if ($h['leave_date_start'] !== $h['leave_date_end']) {
                        $dateStr .= ' - ' . formatDateTH($h['leave_date_end']);
                    }
                    $statusText = 'รออนุมัติ';
                    $statusColor = 'var(--amber-deep)';
                    $statusBg = 'var(--amber-pale)';
                    if ($h['status'] === 'approved') {
                        $statusText = 'อนุมัติ';
                        $statusColor = 'var(--sage)';
                        $statusBg = 'var(--sage-pale)';
                    } elseif ($h['status'] === 'rejected') {
                        $statusText = 'ปฏิเสธ';
                        $statusColor = 'var(--rust)';
                        $statusBg = 'var(--rust-pale)';
                    } elseif ($h['status'] === 'cancelled') {
                        $statusText = 'ยกเลิก';
                        $statusColor = 'var(--mist)';
                        $statusBg = 'var(--paper)';
                    }
                ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding: 12px 0; border-bottom: 1px dashed var(--rule);">
                    <div style="font-size: 13px; color: var(--ink);"><?= $dateStr ?></div>
                    <div style="font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 4px; color: <?= $statusColor ?>; background: <?= $statusBg ?>;">
                        <?= $statusText ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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
    <div class="m-date-range">
      <div class="m-date-field">
        <span class="m-date-lbl">วันเริ่ม</span>
        <input type="date" class="m-date" id="leaveDateStart"
               min="<?= date('Y-m-d') ?>"
               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
               value="<?= date('Y-m-d') ?>"
               oninput="onStartChange()"/>
      </div>
      <div class="m-date-arrow">→</div>
      <div class="m-date-field">
        <span class="m-date-lbl">วันสิ้นสุด</span>
        <input type="date" class="m-date" id="leaveDateEnd"
               min="<?= date('Y-m-d') ?>"
               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
               value="<?= date('Y-m-d') ?>"
               oninput="onRangeChange()"/>
      </div>
    </div>
    <div id="leaveDayCount" class="m-day-count">1 วัน</div>

    <label class="m-label">ประเภทการลา</label>
    <select class="m-select" id="leaveType">
      <option value="">— เลือกประเภทการลา —</option>
      <option value="ลาป่วย">ลาป่วย</option>
      <option value="ลากิจ">ลากิจ</option>
      <option value="วันหยุดพักผ่อน">วันหยุดพักผ่อน</option>
      <option value="เหตุฉุกเฉิน">เหตุฉุกเฉิน</option>
      <option value="อื่น ๆ">อื่น ๆ</option>
    </select>

    <label class="m-label">เหตุผล / หมายเหตุ</label>
    <textarea class="m-textarea" id="leaveReason" placeholder="ระบุเหตุผลเพิ่มเติม..."></textarea>

    <div id="leaveMsgBox"></div>
    <div class="m-actions">
      <button class="btn btn-ghost" onclick="closeLeave()">ยกเลิก</button>
      <button class="btn btn-rust" id="btnConfirmLeave" onclick="confirmLeave()">ยืนยันการลา</button>
    </div>
  </div>
</div>

<script>
// ── ส่ง PHP customers array มาให้ JS ──────────────
const customersData = <?= json_encode(array_values($customers), JSON_UNESCAPED_UNICODE) ?>;

const WARN_SVG = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;

function formatThaiDate(d) {
  if (!d) return '—';
  const months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  const parts = d.split('-');
  return parseInt(parts[2]) + '/' + months[parseInt(parts[1])] + '/' + (parseInt(parts[0])+543);
}

function renderDetail(uid) {
  const c = customersData.find(x => x.id == uid);
  if (!c) return;

  const fullName  = (c.first_name + ' ' + c.last_name).trim();
  const allergyHtml = c.note
    ? `<div class="allergy-box">${WARN_SVG}${c.note}</div>` : '';

  const tierHtml = c.member_tier
    ? `<span class="d-chip chip-tier">${c.member_tier}</span>` : '';

  const lastHtml = c.last_visit
    ? `<span class="d-chip chip-d">ล่าสุด ${formatThaiDate(c.last_visit)}</span>` : '';

  const histHtml = c.history && c.history.length
    ? c.history.map(h =>
        `<div class="h-row">
          <div>
            <div class="h-date">${formatThaiDate(h.booking_date)}</div>
            <div class="h-svc">${h.service_name || '—'}</div>
          </div>
          <div class="h-price">${h.price ? '฿' + Number(h.price).toLocaleString() : '—'}</div>
        </div>`).join('')
    : '<p style="font-size:12px;color:var(--mist);padding:12px 0;">ยังไม่มีประวัติ</p>';

  document.getElementById('custDetail').innerHTML =
    `<div class="cust-detail">
      <div class="d-name">${fullName}</div>
      <div class="d-phone">${c.phone || '—'}</div>
      <div class="d-chips">
        <span class="d-chip chip-v">มาแล้ว ${c.visit_count} ครั้ง</span>
        ${lastHtml}${tierHtml}
      </div>
      ${allergyHtml}
      <div class="history-head">ประวัติการใช้บริการกับช่างบอย</div>
      ${histHtml}
    </div>`;
}

function pickById(uid) {
  document.querySelectorAll('.cust-item').forEach(el => {
    el.classList.toggle('sel', el.id === 'ci' + uid);
  });
  renderDetail(uid);
}

function pickCustomer(uid) {
  if (uid) pickById(uid);
}

function filterCust(q) {
  document.querySelectorAll('.cust-item').forEach(el => {
    const name = el.dataset.name || '';
    el.style.display = name.includes(q) ? '' : 'none';
  });
}

// ── Tab switching ──────────────────────────────
function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.getElementById('pane-' + tab).classList.add('active');
}

// ── Leave modal ────────────────────────────────
const LEAVE_ICON = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;

function openLeave()  {
  document.getElementById('leaveMsgBox').innerHTML = '';
  document.getElementById('btnConfirmLeave').disabled = false;
  document.getElementById('btnConfirmLeave').textContent = 'ยืนยันการลา';
  // reset dates to today
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('leaveDateStart').value = today;
  document.getElementById('leaveDateEnd').value   = today;
  onStartChange();
  document.getElementById('leaveOverlay').classList.add('open');
}
function closeLeave() { document.getElementById('leaveOverlay').classList.remove('open'); }

// ── Date range helpers ────────────────────────────
function daysBetween(a, b) {
  if (!a || !b) return 0;
  return Math.round((new Date(b) - new Date(a)) / 86400000) + 1;
}

function onStartChange() {
  const s = document.getElementById('leaveDateStart').value;
  const eEl = document.getElementById('leaveDateEnd');
  // end must be >= start and <= start+2
  eEl.min = s;
  const maxEnd = new Date(s);
  maxEnd.setDate(maxEnd.getDate() + 2);
  const hardMax = new Date('<?= date('Y-m-d', strtotime('+30 days')) ?>');
  eEl.max = (maxEnd < hardMax ? maxEnd : hardMax).toISOString().slice(0,10);
  if (eEl.value < s) eEl.value = s;
  onRangeChange();
}

function onRangeChange() {
  const s = document.getElementById('leaveDateStart').value;
  const e = document.getElementById('leaveDateEnd').value;
  const days = s && e ? daysBetween(s, e) : 1;
  const countEl = document.getElementById('leaveDayCount');
  const ok = days >= 1 && days <= 3;
  countEl.textContent = days + ' วัน' + (days > 3 ? ' (เกินกำหนด)' : '');
  countEl.style.color = ok ? 'var(--sage)' : 'var(--rust)';
}

async function confirmLeave() {
  const leaveDateStart = document.getElementById('leaveDateStart').value;
  const leaveDateEnd   = document.getElementById('leaveDateEnd').value;
  const leaveType      = document.getElementById('leaveType').value;
  const leaveReason    = document.getElementById('leaveReason').value.trim();
  const msgBox         = document.getElementById('leaveMsgBox');

  if (!leaveDateStart || !leaveDateEnd) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาเลือกช่วงวันที่ขอลา</div>`;
    return;
  }
  const days = daysBetween(leaveDateStart, leaveDateEnd);
  if (days < 1 || days > 3) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ ลาได้สูงสุด 3 วันต่อครั้ง</div>`;
    return;
  }
  if (!leaveType) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาเลือกประเภทการลา</div>`;
    return;
  }
  if (!leaveReason) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ กรุณาระบุเหตุผลการลา</div>`;
    return;
  }

  const btn = document.getElementById('btnConfirmLeave');
  btn.disabled = true;
  btn.textContent = 'กำลังบันทึก...';

  try {
    const fd = new FormData();
    fd.append('action', 'request_leave');
    fd.append('leave_date_start', leaveDateStart);
    fd.append('leave_date_end',   leaveDateEnd);
    fd.append('type', leaveType);
    fd.append('reason', leaveReason);

    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      const rangeLabel = leaveDateStart === leaveDateEnd
        ? formatThaiDate(leaveDateStart)
        : formatThaiDate(leaveDateStart) + ' – ' + formatThaiDate(leaveDateEnd);

      // Show success in modal then close and reload
      msgBox.innerHTML = `<div class="leave-success">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        ส่งคำขอลางานเรียบร้อย — รออนุมัติ
      </div>`;
      setTimeout(() => {
        closeLeave();
        location.reload();
      }, 1500);
    } else {
      msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ ${data.msg}</div>`;
      btn.disabled = false;
      btn.textContent = 'ยืนยันการลา';
    }
  } catch (e) {
    msgBox.innerHTML = `<div style="color:var(--rust);font-size:12.5px;margin-bottom:8px;">⚠ เกิดข้อผิดพลาด กรุณาลองใหม่</div>`;
    btn.disabled = false;
    btn.textContent = 'ยืนยันการลา';
  }
}

function setOnline() {
  const bl = document.getElementById('btnLeave');
  const bo = document.getElementById('btnOnline');
  bl.classList.remove('on-leave');
  bl.innerHTML = LEAVE_ICON + ' ขอลา';
  bo.classList.add('is-active');
}

async function cancelLeave() {
  if (!confirm('ยืนยันการยกเลิกวันลา?\nระบบจะลบข้อมูลการลาและเปลี่ยนสถานะกลับเป็นออนไลน์')) return;
  try {
    const fd = new FormData();
    fd.append('action', 'cancel_leave');
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      // ลบ badge
      const badge = document.getElementById('leaveBadge');
      if (badge) badge.remove();
      // รีเซ็ตปุ่ม
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

// ── Status select: ช่างกดเลือกเอง ──────────────────
const STATUS_COLOR = {
  pending:     'status-select-pending',
  in_progress: 'status-select-in_progress',
  completed:   'status-select-completed',
};

async function changeStatus(sel) {
  const bookingId = sel.dataset.bookingId;
  const newStatus = sel.value;
  const row = document.getElementById('qrow-' + bookingId);

  // อัปเดต class สี
  sel.className = 'status-select ' + (STATUS_COLOR[newStatus] || 'status-select-pending');

  // อัปเดต row highlight
  if (row) {
    row.classList.toggle('is-active', newStatus === 'in_progress');
    row.dataset.status = newStatus;
  }

  // อัปเดต DB
  try {
    const fd = new FormData();
    fd.append('action',     'update_booking_status');
    fd.append('booking_id', bookingId);
    fd.append('status',     newStatus);
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      console.warn('update booking status failed', bookingId);
    }
  } catch(e) { console.warn('update booking status error', e); }
}

// ── Auto-sync: เฉพาะ pending ที่ถึงเวลาแล้ว → เปลี่ยนเป็น in_progress อัตโนมัติ ──
async function syncBookingStatus(row) {
  const bookingId = row.dataset.bookingId;
  const startTs   = parseInt(row.dataset.startTs) * 1000;
  const curStatus = row.dataset.status;

  // auto-sync เฉพาะกรณียัง pending แล้วถึงเวลา
  if (curStatus !== 'pending' && curStatus !== 'upcoming' && curStatus !== 'waiting') return;
  if (Date.now() < startTs) return;

  // ถึงเวลาแล้ว → เปลี่ยน dropdown เป็น in_progress
  const sel = row.querySelector('.status-select');
  if (sel) {
    sel.value = 'in-process';
    sel.className = 'status-select status-select-in_progress';
  }
  row.classList.add('is-active');
  row.dataset.status = 'in-progress';

  try {
    const fd = new FormData();
    fd.append('action',     'update_booking_status');
    fd.append('booking_id', bookingId);
    fd.append('status',     'in_progress');
    await fetch(window.location.href, { method: 'POST', body: fd });
  } catch(e) { console.warn('auto-sync failed', e); }
}

function tickAllRows() {
  document.querySelectorAll('tr[data-booking-id]').forEach(syncBookingStatus);
}

tickAllRows();
setInterval(tickAllRows, 30000);
</script>

</body>
</html>