<?php
/**
 * Bright Hair Studio - Admin Dashboard
 * ตารางคิวงานประจำวัน (8:00-17:00) รีเซ็ตทุกสัปดาห์
 * ยังไม่เชื่อม Database - ใช้ข้อมูลจำลอง
 */

// ===== CONFIG & MOCK DATA =====
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once 'db.php';
    try {
        // ── เพิ่มช่างใหม่ ───────────────────────────────────
        if ($_POST['action'] === 'add_employee') {
            $name   = trim($_POST['name']   ?? '');
            $role   = trim($_POST['role']   ?? '');
            $phone  = trim($_POST['phone']  ?? '');
            $skills = trim($_POST['skills'] ?? '');
            if (!$name) { echo json_encode(['ok'=>false,'msg'=>'กรุณากรอกชื่อช่าง']); exit; }
            $stmt = $pdo->prepare("INSERT INTO employees (name, role, phone, skills, status, rating, review_count) VALUES (?, ?, ?, ?, 'online', 5.0, 0)");
            $stmt->execute([$name, $role, $phone, $skills]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true, 'id'=>$newId, 'msg'=>"เพิ่มช่าง \"$name\" เรียบร้อย"]);
            exit;
        }

        // ── แก้ไขข้อมูลช่าง ─────────────────────────────────
        if ($_POST['action'] === 'save_employee') {
            $id     = (int)($_POST['id']      ?? 0);
            $name   = trim($_POST['name']     ?? '');
            $role   = trim($_POST['role']     ?? '');
            $phone  = trim($_POST['phone']    ?? '');
            $skills = trim($_POST['skills']   ?? '');
            if (!$id || !$name) { echo json_encode(['ok'=>false,'msg'=>'ข้อมูลไม่ครบ']); exit; }
            $stmt = $pdo->prepare("UPDATE employees SET name=?, role=?, phone=?, skills=? WHERE id=?");
            $stmt->execute([$name, $role, $phone, $skills, $id]);
            echo json_encode(['ok'=>true, 'msg'=>"บันทึกข้อมูล \"$name\" เรียบร้อย"]);
            exit;
        }

        // ── เปลี่ยนสถานะช่าง ────────────────────────────────
        if ($_POST['action'] === 'set_status') {
            $id     = (int)($_POST['id']     ?? 0);
            $status = trim($_POST['status']  ?? '');
            $allowed = ['online', 'on_leave', 'offline'];
            if (!$id || !in_array($status, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'ข้อมูลไม่ถูกต้อง']); exit; }
            $pdo->prepare("UPDATE employees SET status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($_POST['action'] === 'approve_leave') {
            $id = (int)$_POST['id'];
            $force = (int)($_POST['force'] ?? 0);

            // ดึงข้อมูลคำขอลา
            $req = $pdo->prepare("SELECT * FROM leave_requests WHERE id=?");
            $req->execute([$id]);
            $lr = $req->fetch(PDO::FETCH_ASSOC);
            if (!$lr) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบคำขอลา']); exit; }

            // ── ตรวจสอบว่ามี booking ในช่วงวันลาหรือไม่ ──
            if (!$force) {
                $chkStmt = $pdo->prepare("
                    SELECT b.id, b.booking_code, b.booking_date, b.start_time,
                           s.name as service_name,
                           CONCAT(u.first_name, ' ', u.last_name) as customer_name
                    FROM bookings b
                    LEFT JOIN users u ON b.user_id = u.id
                    LEFT JOIN services s ON b.service_id = s.id
                    WHERE b.employee_id = ?
                      AND b.booking_date BETWEEN ? AND ?
                      AND b.status NOT IN ('cancelled', 'completed')
                    ORDER BY b.booking_date ASC, b.start_time ASC
                ");
                $chkStmt->execute([$lr['employee_id'], $lr['leave_date_start'], $lr['leave_date_end']]);
                $conflictBookings = $chkStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($conflictBookings)) {
                    echo json_encode([
                        'ok' => false,
                        'has_conflict' => true,
                        'leave_id' => $id,
                        'employee_name' => $lr['employee_id'],
                        'leave_start' => $lr['leave_date_start'],
                        'leave_end' => $lr['leave_date_end'],
                        'bookings' => $conflictBookings,
                        'msg' => 'มีตารางงานในวันที่ขอลา กรุณาเคลียร์ตารางงานก่อนอนุมัติ'
                    ]);
                    exit;
                }
            }

            // ── อนุมัติ (ไม่มี conflict หรือ force=1) ──
            $pdo->prepare("UPDATE leave_requests SET status='approved' WHERE id=?")->execute([$id]);
            if ($lr) {
                $pdo->prepare("UPDATE employees SET status='on_leave' WHERE id=?")
                    ->execute([$lr['employee_id']]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }
        if ($_POST['action'] === 'reject_leave') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE leave_requests SET status='rejected' WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        exit;
    }
}

date_default_timezone_set('Asia/Bangkok');

$currentDay = (int) date('w'); // 0=Sun, 1=Mon, ...
$currentHour = (int) date('G');
$year = date('Y');

// รับวันที่จาก GET parameter (format: Y-m-d) หรือใช้วันนี้
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selectedTimestamp = strtotime($selectedDate);
if (!$selectedTimestamp) {
    $selectedDate = date('Y-m-d');
    $selectedTimestamp = strtotime($selectedDate);
}

$selectedDay = (int) date('w', $selectedTimestamp);
$selectedDayOfMonth = (int) date('j', $selectedTimestamp);
$selectedMonth = (int) date('n', $selectedTimestamp);
$selectedYear = (int) date('Y', $selectedTimestamp);

// Thai month names
$thaiMonths = [
    1 => 'มกราคม',
    2 => 'กุมภาพันธ์',
    3 => 'มีนาคม',
    4 => 'เมษายน',
    5 => 'พฤษภาคม',
    6 => 'มิถุนายน',
    7 => 'กรกฎาคม',
    8 => 'สิงหาคม',
    9 => 'กันยายน',
    10 => 'ตุลาคม',
    11 => 'พฤศจิกายน',
    12 => 'ธันวาคม'
];

$thaiShortDays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

// สร้างข้อมูลปฏิทินสำหรับเดือนที่เลือก
$firstDayOfMonth = mktime(0, 0, 0, $selectedMonth, 1, $selectedYear);
$daysInMonth = (int) date('t', $firstDayOfMonth);
$startingDayOfWeek = (int) date('w', $firstDayOfMonth); // 0=Sun

// เดือนก่อนหน้าและถัดไป
$prevMonth = date('Y-m-d', mktime(0, 0, 0, $selectedMonth - 1, 1, $selectedYear));
$nextMonth = date('Y-m-d', mktime(0, 0, 0, $selectedMonth + 1, 1, $selectedYear));

$weekNumber = date('W', $selectedTimestamp);

// วันในสัปดาห์
$days = [
    1 => ['short' => 'จันทร์', 'full' => 'วันจันทร์', 'en' => 'Mon'],
    2 => ['short' => 'อังคาร', 'full' => 'วันอังคาร', 'en' => 'Tue'],
    3 => ['short' => 'พุธ', 'full' => 'วันพุธ', 'en' => 'Wed'],
    4 => ['short' => 'พฤหัส', 'full' => 'วันพฤหัสบดี', 'en' => 'Thu'],
    5 => ['short' => 'ศุกร์', 'full' => 'วันศุกร์', 'en' => 'Fri'],
    6 => ['short' => 'เสาร์', 'full' => 'วันเสาร์', 'en' => 'Sat'],
    0 => ['short' => 'อาทิตย์', 'full' => 'วันอาทิตย์', 'en' => 'Sun'],
];

// ช่างทำผม (ดึงจาก db แบบจำลองหรือจาก db ตรง)
$technicians = [
    ['id' => 1, 'name' => 'พี่โอ๋', 'initials' => 'อ', 'class' => 't1', 'role' => 'Senior Stylist'],
    ['id' => 2, 'name' => 'น้องมิ้นท์', 'initials' => 'ม', 'class' => 't2', 'role' => 'Color Specialist'],
    ['id' => 3, 'name' => 'พี่เจ', 'initials' => 'จ', 'class' => 't3', 'role' => 'Perm Expert'],
    ['id' => 4, 'name' => 'น้องบีม', 'initials' => 'บ', 'class' => 't4', 'role' => 'Junior Stylist'],
    ['id' => 5, 'name' => 'พี่นก', 'initials' => 'น', 'class' => 't1', 'role' => 'Barber Expert'],
    ['id' => 6, 'name' => 'น้องปาล์ม', 'initials' => 'ป', 'class' => 't2', 'role' => 'Stylist'],
    ['id' => 7, 'name' => 'พี่เล็ก', 'initials' => 'ล', 'class' => 't3', 'role' => 'Treatment Specialist'],
    ['id' => 8, 'name' => 'พี่ดา', 'initials' => 'ด', 'class' => 't4', 'role' => 'Senior Colorist'],
    ['id' => 9, 'name' => 'แจ็ค', 'initials' => 'จ', 'class' => 't1', 'role' => 'Stylist'],
    ['id' => 10, 'name' => 'แนนซี่', 'initials' => 'น', 'class' => 't2', 'role' => 'Nail & Hair Expert'],
];

// ประเภทบริการ
$serviceTypes = [
    'haircut'   => ['name' => 'ตัดผมชาย/หญิง',       'icon' => '<span class="dot" style="background: var(--accent-1); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'color'     => ['name' => 'ทำสีผมแฟชั่น',         'icon' => '<span class="dot" style="background: var(--success); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'perm'      => ['name' => 'ดัดผม ดัดดิจิตอล',     'icon' => '<span class="dot" style="background: var(--info); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'stretch'   => ['name' => 'ยืดวอลลุ่ม',           'icon' => '<span class="dot" style="background: var(--warning); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'treatment' => ['name' => 'ทรีทเมนต์บำรุงล้ำลึก', 'icon' => '<span class="dot" style="background: var(--danger); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'wash'      => ['name' => 'สระไดร์',               'icon' => '<span class="dot" style="background: #a855f7; display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
];

require_once 'db.php';

// ── Sync employees.status จาก leave_requests ────────
// approved + วันนี้อยู่ในช่วงลา → on_leave
// หมดช่วงลา / ไม่มี approved leave → online
$_syncToday = date('Y-m-d');

// 1. ช่างที่วันนี้อยู่ในช่วง approved leave → on_leave
$pdo->exec("
    UPDATE employees e
    INNER JOIN leave_requests lr
           ON lr.employee_id     = e.id
          AND lr.status          = 'approved'
          AND lr.leave_date_start <= '{$_syncToday}'
          AND lr.leave_date_end   >= '{$_syncToday}'
    SET e.status = 'on_leave'
");

// 2. ช่างที่ on_leave แต่วันลาหมดแล้ว หรือไม่มี approved leave ครอบวันนี้ → online
$pdo->exec("
    UPDATE employees e
    SET e.status = 'online'
    WHERE e.status = 'on_leave'
      AND NOT EXISTS (
          SELECT 1 FROM leave_requests lr
          WHERE lr.employee_id     = e.id
            AND lr.status          = 'approved'
            AND lr.leave_date_start <= '{$_syncToday}'
            AND lr.leave_date_end   >= '{$_syncToday}'
      )
");

unset($_syncToday);
// ────────────────────────────────────────────────────

// โหลดคิวของวันที่เลือก
$stmt = $pdo->prepare("
    SELECT b.id, b.booking_code,
           u.first_name, u.last_name, u.member_tier,
           s.code as service_code, s.name as service_name, s.duration_min,
           e.id as emp_id, e.name as emp_name,
           TIME_FORMAT(b.start_time,'%H') as hour,
           b.status, b.notes
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    JOIN services s ON b.service_id = s.id
    LEFT JOIN employees e ON b.employee_id = e.id
    WHERE b.booking_date = ?
");
$stmt->execute([$selectedDate]);
$bookingRows = $stmt->fetchAll();

// สร้าง $mockData ในรูปแบบเดิม (day_hour_techId) จาก DB
$mockData = [];
foreach ($bookingRows as $b) {
    if (!$b['emp_id']) continue;
    $key = $selectedDay . '_' . (int)$b['hour'] . '_' . $b['emp_id'];
    $mockData[$key] = [
        'client'      => $b['first_name'] . ' ' . $b['last_name'],
        'client_tier' => $b['client_tier'] ?? 'Member',
        'service'     => $b['service_code'],
        'status'      => $b['status'] === 'upcoming' ? 'confirmed' : $b['status'],
        'duration'    => ceil($b['duration_min'] / 60) . ' ชม.',
        'note'        => $b['notes'] ?? '',
    ];
}
if (empty($mockData)) {
    @session_start();
    $mockData = $_SESSION['mockData'] ?? [];
}

// นับสถิติสำหรับวันที่เลือก
// removed badge clash
$confirmedCount = 0;
$walkinCount = 0;

if (is_array($mockData) && !empty($mockData)) {
    foreach ($mockData as $key => $booking) {
        $parts = explode('_', $key);
        // ตรวจสอบว่า $parts[0] มีค่าจริงไหมก่อนเทียบ
        if (isset($parts[0]) && (int) $parts[0] === $selectedDay) {
            // removed badge clash
            if ($booking['status'] === 'confirmed')
                $confirmedCount++;
            elseif ($booking['status'] === 'walkin')
                $walkinCount++;
        }
    }
}

// Status labels (removed pending, renamed confirmed to ออนไลน์)
$statusLabels = [
    'confirmed' => ['label' => 'ออนไลน์', 'icon' => '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:var(--success); margin-right:4px;"></span>'],
    'walkin' => ['label' => 'Walk-in', 'icon' => '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:var(--info); margin-right:4px;"></span>'],
];

// ช่วงเวลา 8:00 - 17:00
$timeSlots = range(8, 17);

// ── Mock job history data for History Modal ──────────────────────────
$job_history_mock = [];
require_once 'db.php';
// Build individual job history per employee from DB
$jStmt = $pdo->query("
    SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.duration_min, 
           u.first_name, u.member_tier, 
           s.name as service, s.price, 
           b.status, b.notes, b.employee_id
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.status = 'completed'
    ORDER BY b.booking_date DESC
");

while ($job = $jStmt->fetch()) {
    $stype = 'other';
    $sname = $job['service'] ?? '';
    if (strpos($sname, 'ตัด') !== false) $stype = 'cut';
    elseif (strpos($sname, 'สี') !== false) $stype = 'color';
    elseif (strpos($sname, 'ยืด') !== false) $stype = 'straight';
    elseif (strpos($sname, 'ดัด') !== false) $stype = 'perm';

    $dayKey = (int)date('w', strtotime($job['booking_date']));
    $dateTh = $GLOBALS['thaiShortDays'][$dayKey] . '. ' . (int)date('d', strtotime($job['booking_date'])) . ' ' . $GLOBALS['thaiMonths'][(int)date('n', strtotime($job['booking_date']))] . ' ' . (date('y', strtotime($job['booking_date'])) + 43);

    $job_history_mock[] = [
        'id'       => $job['booking_code'],
        'date'     => $job['booking_date'],
        'date_th'  => $dateTh,
        'time'     => date('H:i', strtotime($job['start_time'] ?? '00:00')),
        'duration' => (int)$job['duration_min'],
        'customer' => [
            'name' => $job['first_name'],
            'avatar' => mb_substr($job['first_name'], 0, 1),
            'tier' => strtolower($job['member_tier'] ?: 'new')
        ],
        'service'  => $sname,
        'service_type' => $stype,
        'note'     => $job['notes'] ?? '',
        'price'    => (float)$job['price'],
        'status'   => 'done',
        'rating'   => 5,
        'tip'      => 0,
        'emp_id'   => $job['employee_id']
    ];
}

// ── Group by date ────────────────────────────────
$grouped_history = [];
foreach ($job_history_mock as $job) {
    if(!isset($grouped_history[$job['date']])) $grouped_history[$job['date']] = [];
    $grouped_history[$job['date']][] = $job;
}

// ── Summary stats ────────────────────────────────
$h_total_jobs    = count($job_history_mock);
$h_total_revenue = array_sum(array_column($job_history_mock, 'price'));
$h_total_tips    = array_sum(array_column($job_history_mock, 'tip'));
$h_avg_rating    = $h_total_jobs > 0 ? round(array_sum(array_column($job_history_mock, 'rating')) / $h_total_jobs, 1) : 0;
$h_total_minutes = array_sum(array_column($job_history_mock, 'duration'));

$svc_meta = [
    'cut'      => ['label'=>'ตัดผม',       'color'=>'var(--accent-1)', 'bg'=>'rgba(255, 159, 36, 0.1)'],
    'color'    => ['label'=>'ทำสีผม',      'color'=>'var(--info)',     'bg'=>'rgba(96, 165, 250, 0.1)'],
    'straight' => ['label'=>'ยืดผม',       'color'=>'var(--success)',  'bg'=>'rgba(34, 197, 94, 0.1)'],
    'perm'     => ['label'=>'ดัดผม',       'color'=>'var(--danger)',   'bg'=>'rgba(248, 113, 113, 0.1)'],
    'other'    => ['label'=>'อื่นๆ',       'color'=>'var(--text-muted)', 'bg'=>'rgba(255, 255, 255, 0.05)'],
];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bright Hair Studio | Admin Dashboard</title>
    <meta name="description" content="ระบบจัดการคิวงานสำหรับ Bright Hair Studio - ดูตารางนัดหมายประจำวัน">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        /* ===== DARK MODE (default) ===== */
        :root {
            --bg-primary: #0d0d0d;
            --bg-secondary: #141414;
            --bg-card: #1c1c1c;
            --bg-glass: rgba(255, 159, 36, 0.08); 
            --border-glass: rgba(255, 159, 36, 0.25);
            --accent-1: #ff9f24;
            --accent-2: #ffb84a;
            --accent-gradient: linear-gradient(135deg, #ff9f24 0%, #ffb84a 50%, #ff9f24 100%);
            --text-primary: #f8f4ef;
            --text-secondary: #e4e4e4;
            --text-muted: #888888;
            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.15);
            --warning: #ff9f24;
            --warning-bg: rgba(255, 159, 36, 0.15);
            --danger: #f87171;
            --danger-bg: rgba(248, 113, 113, 0.15);
            --info: #60a5fa;
            --info-bg: rgba(96, 165, 250, 0.15);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --sidebar-bg: #141414;
            --topbar-bg: rgba(20, 20, 20, 0.9);
            --logo-filter: none;
            --btn-text: #0d0d0d;
        }

        /* ===== LIGHT MODE ===== */
        @media (prefers-color-scheme: light) {
            :root {
                --bg-primary: #f5f5f5;
                --bg-secondary: #e4e4e4;
                --bg-card: #ffffff;
                --bg-glass: rgba(255, 159, 36, 0.04);
                --border-glass: rgba(255, 159, 36, 0.12);
                --accent-1: #ff9f24;
                --accent-2: #ffb54d;
                --accent-gradient: linear-gradient(135deg, #ff9f24 0%, #ffb54d 50%, #ff9f24 100%);
                --text-primary: #111111;
                --text-secondary: #333333;
                --text-muted: #777777;
                --success: #22c55e;
                --success-bg: rgba(34, 197, 94, 0.1);
                --warning: #ff9f24;
                --warning-bg: rgba(255, 159, 36, 0.1);
                --danger: #f87171;
                --danger-bg: rgba(248, 113, 113, 0.1);
                --info: #60a5fa;
                --info-bg: rgba(96, 165, 250, 0.1);
                --shadow-sm: 0 2px 8px rgba(255, 159, 36, 0.08);
                --shadow-md: 0 8px 32px rgba(255, 159, 36, 0.1);
                --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.1);
                --sidebar-bg: #ffffff;
                --topbar-bg: rgba(255, 255, 255, 0.9);
                --logo-filter: none;
                --btn-text: #ffffff;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Jost', 'Noto Sans Thai', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 20% 20%, rgba(255, 159, 36, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(255, 181, 77, 0.03) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(255, 159, 36, 0.02) 0%, transparent 70%);
            z-index: 0;
            pointer-events: none;
            animation: bgShift 20s ease-in-out infinite alternate;
        }

        @keyframes bgShift {
            0% {
                transform: translate(0, 0);
            }

            100% {
                transform: translate(-5%, -3%);
            }
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-glass);
            box-shadow: 2px 0 20px rgba(255, 159, 36, 0.05);
            z-index: 100;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
        }

        .logo-text .brand {
            font-family: 'Cormorant Garamond', serif;
            font-size: 18px;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 0.5px;
        }

        .logo-text .sub {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            padding: 12px 12px 8px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .nav-item:hover {
            background: rgba(255, 159, 36, 0.06);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 60%;
            background: var(--accent-gradient);
            border-radius: 0 4px 4px 0;
        }

        .nav-item .icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .nav-item .badge {
            margin-left: auto;
            background: var(--accent-gradient);
            color: var(--btn-text);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid rgba(255, 159, 36, 0.1);
        }

        .technician-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 159, 36, 0.04);
            border-radius: var(--radius-md);
            border: 1px solid rgba(255, 159, 36, 0.1);
        }

        .technician-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: var(--btn-text);
            flex-shrink: 0;
        }

        .technician-info .name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .technician-info .role {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            position: relative;
            
            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 16px 32px;
            background: var(--topbar-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-glass);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-toggle {
            display: none;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.15);
            color: var(--text-primary);
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 159, 36, 0.1);
            border-color: var(--accent-1);
        }

        .page-title h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text-primary);
        }

        .page-title p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-btn {
            position: relative;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.12);
            color: var(--text-secondary);
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .topbar-btn:hover {
            background: rgba(255, 159, 36, 0.12);
            color: var(--accent-1);
            border-color: rgba(255, 159, 36, 0.3);
        }

        .topbar-btn .dot {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid #fff;
        }

        /* ===== CONTENT AREA ===== */
        .content {
            padding: 28px 32px;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.1);
            border-radius: var(--radius-lg);
            padding: 22px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .stat-card.primary::before {
            background: var(--accent-gradient);
        }

        .stat-card.green::before {
            background: linear-gradient(90deg, #4ade80, #22c55e);
        }

        .stat-card.blue::before {
            background: linear-gradient(90deg, #60a5fa, #3b82f6);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 159, 36, 0.2);
            box-shadow: var(--shadow-md);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-card.primary .stat-icon {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .stat-card.green .stat-icon {
            background: var(--success-bg);
            color: var(--success);
        }

        .stat-card.blue .stat-icon {
            background: var(--info-bg);
            color: var(--info);
        }

        .stat-change {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .stat-change.up {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .stat-change.down {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .stat-value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -1px;
            margin-
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ===== SCHEDULE SECTION ===== */
        .schedule-section {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.1);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .schedule-header {
            padding: 22px 28px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .schedule-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .schedule-title h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .schedule-title .icon {
            font-size: 22px;
        }

        /* ===== CALENDAR NAVIGATION ===== */
        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .cal-arrow {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid rgba(255, 159, 36, 0.2);
            background: rgba(255, 159, 36, 0.06);
            color: var(--accent-1);
            font-size: 22px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            line-height: 1;
        }

        .cal-arrow:hover {
            background: var(--accent-1);
            color: var(--btn-text);
            border-color: var(--accent-1);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.3);
            transform: scale(1.05);
        }

        .cal-month-label {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            min-width: 160px;
            text-align: center;
        }

        /* ===== MINI CALENDAR ===== */
        .mini-calendar-wrapper {
            padding: 16px 28px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            background: rgba(255, 159, 36, 0.02);
        }

        .mini-calendar {
            max-width: 420px;
            margin: 0 auto;
        }

        .cal-header-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-bottom: 8px;
        }

        .cal-header-cell {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 0;
        }

        .cal-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .cal-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .cal-cell.empty,
        .cal-cell.disabled {
            cursor: default;
        }

        .cal-cell.disabled {
            opacity: 0.3;
        }

        .cal-cell:not(.empty):not(.disabled):hover {
            background: rgba(255, 159, 36, 0.1);
            transform: scale(1.05);
        }

        .cal-cell.today {
            background: rgba(255, 159, 36, 0.08);
            font-weight: 700;
        }

        .cal-cell.today .cal-day-num {
            color: var(--accent-1);
        }

        .cal-cell.selected {
            background: var(--accent-1);
            color: var(--btn-text);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.3);
            transform: scale(1.05);
        }

        .cal-cell.selected .cal-day-num {
            color: var(--btn-text);
        }

        .cal-cell.selected .cal-dot {
            background: var(--bg-card);
        }

        .cal-day-num {
            font-size: 13px;
            line-height: 1;
        }

        .cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--accent-1);
            margin-top: 3px;
        }

        .schedule-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--accent-gradient);
            color: var(--btn-text);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.25);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(255, 159, 36, 0.4);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(255, 159, 36, 0.2);
        }

        .btn-outline:hover {
            background: rgba(255, 159, 36, 0.06);
            color: var(--accent-1);
            border-color: rgba(255, 159, 36, 0.3);
        }

        /* ===== SCHEDULE TABLE ===== */
        .schedule-table-wrapper {
            overflow-x: auto;
        }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .schedule-table thead th {
            padding: 14px 20px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            background: rgba(255, 159, 36, 0.03);
            white-space: nowrap;
        }

        .schedule-table thead th:first-child {
            width: 100px;
        }

        .schedule-table tbody tr {
            transition: background 0.15s ease;
        }

        .schedule-table tbody tr:hover {
            background: rgba(255, 159, 36, 0.03);
        }

        .schedule-table tbody td {
            padding: 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: top;
            height: 72px;
        }

        .time-cell {
            padding: 14px 20px !important;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            border-right: 1px solid rgba(255, 159, 36, 0.08);
            white-space: nowrap;
            position: relative;
        }

        .time-cell .period {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
            display: block;
            margin-top: 2px;
        }

        .time-cell.current-time {
            color: var(--accent-1);
        }

        .time-cell.current-time::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 3px;
            height: 70%;
            transform: translateY(-50%);
            background: var(--accent-1);
            border-radius: 0 4px 4px 0;
        }

                .slot-cell {
            padding: 4px !important;
            min-width: 180px;
            height: 80px;
            border-right: 1px solid rgba(255, 159, 36, 0.05);
            vertical-align: top;
        }

        /* ===== BOOKING CARDS ===== */
        .booking-card {
            
            
            
            
            
            box-sizing: border-box;
            
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            border-left: 3px solid;
        }

        .booking-card::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            background: rgba(255, 159, 36, 0.05);
            transition: opacity 0.2s ease;
        }

        .booking-card:hover::before {
            opacity: 1;
        }

        .booking-card:hover {
            transform: scale(1.02);
            box-shadow: var(--shadow-sm);
        }

        .booking-card.haircut {
            background: rgba(255, 159, 36, 0.08);
            border-color: var(--accent-1);
        }

        .booking-card.color {
            background: rgba(168, 85, 247, 0.08);
            border-color: #a855f7;
        }

        .booking-card.perm {
            background: rgba(236, 72, 153, 0.08);
            border-color: #ec4899;
        }

        .booking-card.treatment {
            background: rgba(34, 197, 94, 0.08);
            border-color: #22c55e;
        }

        .booking-card.wash {
            background: rgba(96, 165, 250, 0.08);
            border-color: #60a5fa;
        }

        .booking-card .client-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .booking-card .service-name {
            color: var(--text-secondary);
            font-size: 11px;
        }

        .booking-card .booking-time {
            color: var(--text-muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .slot-empty {
            
            
            
            
            
            box-sizing: border-box;
            
            width: 100%;
            
            min-
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px dashed rgba(255, 159, 36, 0.1);
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            opacity: 0;
        }

        .slot-cell:hover .slot-empty {
            
            
            
            
            
            box-sizing: border-box;
            
            width: 100%;
            
            min-
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px dashed rgba(255, 159, 36, 0.1);
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            opacity: 0;
        }

        /* ===== STATUS BADGES ===== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-badge.confirmed {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-badge.walkin {
            background: var(--info-bg);
            color: var(--info);
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(8px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-xl);
            width: 480px;
            max-width: 90vw;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 24px 28px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 159, 36, 0.1);
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.12);
            color: var(--text-secondary);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: rgba(248, 113, 113, 0.3);
        }

        .modal-body {
            padding: 24px 28px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 11px 16px;
            background: rgba(255, 159, 36, 0.03);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus,
        .form-select:focus {
            border-color: var(--accent-1);
            box-shadow: 0 0 0 3px rgba(255, 159, 36, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238888a0' viewBox='0 0 16 16'%3E%3Cpath d='M1.5 5.5l6.5 6.5 6.5-6.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }

        .form-select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .modal-footer {
            padding: 16px 28px 24px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-lg {
            padding: 12px 28px;
            font-size: 14px;
        }

        /* ===== RESET BANNER ===== */
        .reset-banner {
            background: linear-gradient(135deg, rgba(255, 159, 36, 0.06), rgba(255, 181, 77, 0.04));
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-lg);
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .reset-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .reset-info .icon {
            font-size: 22px;
        }

        .reset-info .text strong {
            font-size: 14px;
            color: var(--accent-1);
        }

        .reset-info .text p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .btn-reset {
            background: rgba(248, 113, 113, 0.08);
            color: var(--danger);
            border: 1px solid rgba(248, 113, 113, 0.2);
            white-space: nowrap;
        }

        .btn-reset:hover {
            background: rgba(248, 113, 113, 0.15);
            border-color: rgba(248, 113, 113, 0.4);
        }

        /* ===== TOAST ===== */
        .toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 300;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-md);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: var(--shadow-md);
            animation: toastIn 0.3s ease forwards;
            min-width: 280px;
        }

        .toast.removing {
            animation: toastOut 0.3s ease forwards;
        }

        @keyframes toastIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast .toast-icon {
            font-size: 20px;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast.info .toast-icon {
            color: var(--accent-1);
        }

        .toast .toast-message {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: flex;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px 16px;
            }

            .topbar {
                padding: 12px 16px;
            }

            .schedule-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .reset-banner {
                flex-direction: column;
                align-items: flex-start;
            }

            .mini-calendar-wrapper {
                padding: 12px 16px;
            }

            .mini-calendar {
                max-width: 100%;
            }
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 159, 36, 0.2);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 159, 36, 0.35);
        }

        /* ===== TECHNICIAN COLUMN HEADERS ===== */
        .tech-header {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tech-avatar-sm {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--btn-text);
            flex-shrink: 0;
        }

        .tech-avatar-sm.t1 {
            background: linear-gradient(135deg, #ff9f24, #ffb54d);
        }

        .tech-avatar-sm.t2 {
            background: linear-gradient(135deg, #a855f7, #c084fc);
        }

        .tech-avatar-sm.t3 {
            background: linear-gradient(135deg, #ec4899, #f472b6);
        }

        .tech-avatar-sm.t4 {
            background: linear-gradient(135deg, #60a5fa, #93c5fd);
        }

        .tech-name {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            text-transform: none;
            letter-spacing: 0;
        }

        /* ===== PULSE DOT FOR LIVE ===== */
        .pulse-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.5;
                transform: scale(0.8);
            }
        }
    
        /* Multi-hour spanning cells */
        .slot-cell.spanned {
            position: relative;
            padding: 0 !important;
            vertical-align: top;
        }
        .slot-cell.spanned .booking-card {
            position: absolute;
            top: 4px;
            left: 4px;
            right: 4px;
            bottom: 4px;
            margin: 0;
            box-sizing: border-box;
        }
    </style>
    
</head>

<body>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
                <div class="sidebar-header">
            <a href="admin_home.php" class="logo" style="justify-content: flex-start; gap: 10px; padding: 0 4px;">
                <div class="logo-icon">
                    <img src="logo.png" alt="Bright Hair Studio">
                </div>
                <div style="display:flex; flex-direction:column; line-height:1.2;">
                    <span style="font-family:'Cormorant Garamond',serif; font-size:14px; font-weight:700; color:var(--text-primary); letter-spacing:0.3px;">Bright Hair Studio</span>
                    <span style="font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:var(--accent-1); margin-top:2px;">ADMIN</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="admin_home.php" class="nav-item">
                <span class="icon"><i class="fas fa-chart-bar" style="color: var(--accent-1);"></i></span>
                <span>ตารางคิว</span>
                <span class="badge"><?= $todayBookings ?></span>
            </a>
            <a href="admin_customers.php" class="nav-item">
                <span class="icon"><i class="fas fa-users" style="color: var(--accent-1);"></i></span>
                <span>ลูกค้า</span>
            </a>
            <a href="admin_services.php" class="nav-item">
                <span class="icon"><i class="fas fa-cut" style="color: var(--accent-1);"></i></span>
                <span>บริการ</span>
            </a>
            <a href="admin_employees.php" class="nav-item active">
                <span class="icon"><i class="fas fa-user-tie" style="color: var(--accent-1);"></i></span>
                <span>ช่าง</span>
            </a>
            <a href="admin_reports.php" class="nav-item">
                <span class="icon"><i class="fas fa-chart-line" style="color: var(--accent-1);"></i></span>
                <span>รายงาน</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="technician-card">
                <div class="technician-avatar">A</div>
                <div class="technician-info">
                    <div class="name">Admin</div>
                    <div class="role">ผู้ดูแลระบบ</div>
                </div>
            </div>
            <a href="logout.php" style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; padding: 10px; border-radius: 8px; color: var(--danger); background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.2); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(248, 113, 113, 0.2)'" onmouseout="this.style.background='rgba(248, 113, 113, 0.1)'">
                <i class="fas fa-sign-out-alt"></i>
                <span>ออกจากระบบ</span>
            </a>
        </div>
    </aside>

    
    <main class="main-content">
        <style>
            .nav-tab { background: transparent; border: none; font-size: 13px; font-weight: 600; color: var(--text-muted); cursor: pointer; padding: 12px 24px; border-bottom: 2px solid transparent; transition: all 0.2s; text-transform: uppercase; font-family: "Jost", sans-serif; letter-spacing: 0.5px; }
            .nav-tab:hover { color: var(--white); background: rgba(255,255,255,0.02); }
            .nav-tab.active { color: var(--accent-1); border-bottom-color: var(--accent-1); }
            .btn-outline { background: transparent; border: 1px solid currentColor; color: inherit; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; margin-right: 4px; transition: opacity 0.2s; font-family: "Jost", sans-serif; }
            .btn-outline:hover { opacity: 0.8; background: rgba(255,255,255,0.05); }
        </style>
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">☰</button>
                <div class="page-title">
                    <h1><i class="fas fa-user-tie" style="color: var(--info); margin-right: 8px;"></i>ระบบจัดการช่าง</h1>
                    
                </div>
            </div>
            <div class="topbar-right">
                <button class="btn btn-primary" onclick="openAddStaffModal()"><i class="fas fa-user-plus" style="margin-right:6px;"></i>เพิ่มช่าง</button>
            </div>
        </header>
        <div class="content">
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    </div>
                    <?php
                    $empCntStmt = $pdo->query("SELECT COUNT(*) FROM employees");
                    $totalEmployees = $empCntStmt->fetchColumn();
                    ?>
                    <div class="stat-value"><?= $totalEmployees ?></div>
                    <div class="stat-label">ช่างทั้งหมด</div>
                </div>
            </div>
            
            <div class="tab-container" style="margin-top:24px; display:flex; gap:16px; border-bottom:1px solid var(--border-glass);">
                <button class="nav-tab active" onclick="switchTab('emp')" id="tab-emp">รายชื่อช่าง</button>
                <button class="nav-tab" onclick="switchTab('dayoff')" id="tab-dayoff">คำขอลาหยุด</button>
                <button class="nav-tab" onclick="switchTab('review')" id="tab-review">รีวิวและคะแนน</button>
            </div>

            <div id="section-emp" class="schedule-section" style="margin-top: 24px; border-top-left-radius: 0;">
                <div class="schedule-header">
                    <h2>ทีมช่างผู้เชี่ยวชาญ</h2>
                    <input type="text" class="form-input" id="empSearch" placeholder="ค้นหาช่าง..." oninput="filterStaff(this.value)" style="width:220px;">
                </div>
                <div style="padding: 24px; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px;" id="staffGrid">
                    <?php
                    require_once 'db.php';
                    $empStmt = $pdo->query("SELECT id, name, role, phone, status, rating, review_count, skills FROM employees");
                    $mockTechs = [];
                    $colors = ['ff9f24', '22c55e', '3b82f6', 'ef4444'];
                    $idx = 0;

                    // ── ดึงสถานะการลาที่ approved และอยู่ในช่วงวันลาจริง ──
                    $today = date('Y-m-d');
                    $leaveStmt = $pdo->prepare("
                        SELECT employee_id,
                               leave_date_start,
                               leave_date_end
                        FROM leave_requests
                        WHERE status = 'approved'
                          AND leave_date_end >= ?
                        ORDER BY leave_date_start ASC
                    ");
                    $leaveStmt->execute([$today]);
                    $leaveRows = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

                    // สร้าง map: employee_id → สถานะที่คำนวณแล้ว
                    // 'on_leave'  = approved + อยู่ในช่วงวันลาวันนี้
                    // 'scheduled' = approved + ยังไม่ถึงวันลา (แสดงเป็น "เปิดรับ" แต่มี badge แจ้ง)
                    // 'online'    = ไม่มี approved leave
                    $leaveStatusMap = []; // emp_id → ['state'=>, 'start'=>, 'end'=>]
                    foreach ($leaveRows as $lr) {
                        $eid = (int)$lr['employee_id'];
                        if (!isset($leaveStatusMap[$eid])) {
                            if ($today >= $lr['leave_date_start'] && $today <= $lr['leave_date_end']) {
                                $leaveStatusMap[$eid] = ['state' => 'on_leave', 'start' => $lr['leave_date_start'], 'end' => $lr['leave_date_end']];
                            } elseif ($today < $lr['leave_date_start']) {
                                $leaveStatusMap[$eid] = ['state' => 'scheduled', 'start' => $lr['leave_date_start'], 'end' => $lr['leave_date_end']];
                            }
                        }
                    }

                    while ($e = $empStmt->fetch()) {
                        $parsedSkills = preg_split('/,\s*/', $e['skills'] ?: '');
                        $color = $colors[$idx % 4];
                        $eid   = (int)$e['id'];
                        $leaveInfo = $leaveStatusMap[$eid] ?? null;
                        // คำนวณ display status จาก leave_requests (ไม่ใช่จาก employees.status)
                        if ($leaveInfo && $leaveInfo['state'] === 'on_leave') {
                            $displayStatus = 'on_leave';
                        } elseif ($leaveInfo && $leaveInfo['state'] === 'scheduled') {
                            $displayStatus = 'scheduled'; // เปิดรับ แต่มีวันลาที่จะมาถึง
                        } else {
                            $displayStatus = 'online';
                        }
                        $mockTechs[] = [
                            "id"            => $eid,
                            "name"          => $e['name'],
                            "role"          => $e['role'] ?: 'ช่างทั่วไป',
                            "phone"         => $e['phone'] ?: '-',
                            "img"           => "https://ui-avatars.com/api/?name=" . urlencode($e['name']) . "&background=1c1c1c&color={$color}",
                            "rating"        => (float)$e['rating'],
                            "reviews"       => (int)$e['review_count'],
                            "skills"        => array_filter($parsedSkills),
                            "status"        => $displayStatus,
                            "leave_start"   => $leaveInfo['start'] ?? null,
                            "leave_end"     => $leaveInfo['end']   ?? null,
                        ];
                        $idx++;
                    }
                    // คำนวณ badge และป้ายสถานะ
                    function fmtTH($d) {
                        if (!$d) return '';
                        static $m = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                        $dt = new DateTime($d);
                        return $dt->format('d').'/'.$m[(int)$dt->format('n')].'/'.(((int)$dt->format('Y'))+543);
                    }
                    foreach($mockTechs as $ti => $t):
                        $isOnLeave   = $t['status'] === 'on_leave';
                        $isScheduled = $t['status'] === 'scheduled';
                        if ($isOnLeave) {
                            $badgeStyle = 'background:rgba(248,113,113,0.12); color:var(--danger); border:1px solid rgba(248,113,113,0.25);';
                            $badgeText  = 'ลาหยุด';
                        } elseif ($isScheduled) {
                            $badgeStyle = 'background:rgba(255,159,36,0.12); color:var(--accent-1); border:1px solid rgba(255,159,36,0.25);';
                            $badgeText  = 'มีวันลา';
                        } else {
                            $badgeStyle = 'background:rgba(34,197,94,0.12); color:var(--success); border:1px solid rgba(34,197,94,0.25);';
                            $badgeText  = 'Online';
                        }
                        $leaveRangeText = '';
                        if ($t['leave_start']) {
                            $leaveRangeText = fmtTH($t['leave_start']);
                            if ($t['leave_end'] && $t['leave_end'] !== $t['leave_start'])
                                $leaveRangeText .= ' – ' . fmtTH($t['leave_end']);
                        }
                    ?>
                    <div class="staff-card" id="staff-<?= $ti ?>" data-name="<?= htmlspecialchars($t['name']) ?>" style="background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); padding: 20px; display: flex; flex-direction: column; align-items: center; text-align: center; position:relative;">
                        <!-- Status badge (read-only) -->
                        <div style="position:absolute; top:14px; right:14px;">
                            <span style="font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; letter-spacing:0.4px; <?= $badgeStyle ?>"><?= $badgeText ?></span>
                        </div>
                        <img src="<?= $t["img"] ?>" style="width: 80px; height: 80px; border-radius: 50%; border: 3px solid var(--accent-1); margin-bottom: 16px; margin-top:8px;">
                        <h3 style="font-size: 18px; color: var(--text-primary); margin-bottom: 4px;"><?= $t["name"] ?></h3>
                        <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;"><?= $t["role"] ?></p>
                        <div style="color: #fbbf24; font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                            <i class="fas fa-star"></i> <span style="color: var(--text-primary); font-weight: 600;"><?= number_format($t["rating"], 1) ?></span> <span style="color: var(--text-muted); font-size: 11px;">(<?= $t["reviews"] ?> รีวิว)</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom:16px;">
                            <?php foreach($t["skills"] as $s): ?>
                                <span style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); font-size: 11px; padding: 6px 14px; border-radius: 20px; font-weight: 500; color: var(--text-primary);"><?= $s ?></span>
                            <?php endforeach; ?>
                        </div>

                        <!-- สถานะ (read-only จาก leave_requests) -->
                        <div style="width:100%; margin-bottom:8px;">
                            <?php if ($isOnLeave): ?>
                            <div style="padding:8px 0; border-radius:6px; font-size:12px; font-weight:600;
                                        border:1px solid rgba(248,113,113,0.4); background:rgba(248,113,113,0.12);
                                        color:var(--danger); text-align:center;">
                                <i class="fas fa-moon" style="font-size:10px; margin-right:5px;"></i>ปิดรับ (กำลังลาหยุด)
                            </div>
                            <?php else: ?>
                            <div style="padding:8px 0; border-radius:6px; font-size:12px; font-weight:600;
                                        border:1px solid rgba(34,197,94,0.3); background:rgba(34,197,94,0.08);
                                        color:var(--success); text-align:center;">
                                <i class="fas fa-circle" style="font-size:8px; margin-right:5px;"></i>เปิดรับ
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($leaveRangeText): ?>
                        <div style="width:100%; margin-bottom:10px; padding:6px 10px; border-radius:6px;
                                    background:<?= $isOnLeave ? 'rgba(248,113,113,0.08)' : 'rgba(255,159,36,0.08)' ?>;
                                    border:1px solid <?= $isOnLeave ? 'rgba(248,113,113,0.2)' : 'rgba(255,159,36,0.2)' ?>;
                                    font-size:11px; color:<?= $isOnLeave ? 'var(--danger)' : 'var(--accent-1)' ?>; text-align:center;">
                            <i class="fas fa-calendar-alt" style="margin-right:5px;"></i>
                            <?= $isOnLeave ? 'ลาหยุด' : 'วันลาที่จะมาถึง' ?>: <?= htmlspecialchars($leaveRangeText) ?>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex; gap:8px; width:100%;">
                            <button onclick="openHistoryModal(<?= $ti ?>)" style="flex:1; padding:7px 0; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; border:1px solid rgba(96, 165, 250, 0.3); background:rgba(96, 165, 250, 0.08); color:var(--info); font-family:inherit;">
                                <i class="fas fa-history" style="margin-right:5px;"></i>ประวัติงาน
                            </button>
                            <button onclick="openEditStaffModal(<?= $ti ?>)" style="flex:1; padding:7px 0; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; border:1px solid var(--border-glass); background:rgba(255,159,36,0.06); color:var(--accent-1); font-family:inherit;">
                                <i class="fas fa-edit" style="margin-right:5px;"></i>แก้ไข
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="section-dayoff" class="schedule-section" style="margin-top: 24px; display: none; border-top-left-radius: 0;">
                <div class="schedule-header"><h2>คำขอลาหยุดรอการอนุมัติ</h2></div>
                <div style="padding: 24px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border-glass); color: var(--text-muted);">
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">ช่าง</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">วันที่ขอลา</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">เหตุผล</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px; text-align: right;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmtPending = $pdo->query("
                                SELECT l.id, l.leave_date_start, l.leave_date_end, l.leave_note, l.status, e.name as stylist_name
                                FROM leave_requests l
                                JOIN employees e ON l.employee_id = e.id
                                WHERE l.status = 'pending'
                                ORDER BY l.created_at ASC
                            ");
                            $pendingList = $stmtPending->fetchAll();
                            foreach($pendingList as $idx => $l):
                                $dateStr = date('d M Y', strtotime($l['leave_date_start']));
                                if ($l['leave_date_start'] !== $l['leave_date_end']) {
                                    $dateStr .= ' - ' . date('d M Y', strtotime($l['leave_date_end']));
                                }
                                $color = ['ff9f24', '22c55e', '3b82f6', 'ef4444', 'a855f7'][$idx % 5];
                            ?>
                            <tr style="border-bottom: 1px dashed var(--border-glass);" id="req-<?= $l['id'] ?>">
                                <td style="padding: 16px 12px; display: flex; align-items: center; gap: 12px;">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($l['stylist_name']) ?>&background=1c1c1c&color=<?= $color ?>" style="width: 32px; height: 32px; border-radius: 50%;"> 
                                    <?= htmlspecialchars($l['stylist_name']) ?>
                               </td>
                                <td style="padding: 16px 12px; font-size: 13px;"><?= $dateStr ?></td>
                                <td style="padding: 16px 12px; color: var(--text-muted); font-size: 13px;"><?= htmlspecialchars($l['leave_note'] ?: 'ไม่ระบุ') ?></td>
                                <td style="padding: 16px 12px; text-align: right;">
                                    <button class="btn-outline" style="color: var(--success); border-color: rgba(34,197,94,0.3);" onclick="handleLeave(<?= $l['id'] ?>, 'approve')"><i class="fas fa-check" style="margin-right:4px;"></i> อนุมัติ</button>
                                    <button class="btn-outline" style="color: var(--error); border-color: rgba(239,68,68,0.3);" onclick="handleLeave(<?= $l['id'] ?>, 'reject')"><i class="fas fa-times" style="margin-right:4px;"></i> ปฏิเสธ</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($pendingList)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">ไม่มีคำขอที่รออนุมัติ</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="schedule-header" style="margin-top:24px; border-top: 1px solid var(--border-glass); padding-top: 24px;"><h2>ประวัติการลาและโควตาประจำปี (<?= date('Y') ?>)</h2></div>
                <div style="padding: 24px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border-glass); color: var(--text-muted);">
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">ช่าง</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">วันที่ลา</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">จำนวนวัน</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">ประเภทการลา</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">เหตุผล</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px;">สถานะ</th>
                                <th style="padding: 12px; font-weight: 500; font-size: 12px; text-align: right;">โควตาคงเหลือ</th>
                            </tr>
                        </thead>
                        <?php
                            $quotaStmt = $pdo->query("
                                SELECT e.id, e.name, IFNULL(SUM(DATEDIFF(l.leave_date_end, l.leave_date_start) + 1), 0) as used_days
                                FROM employees e
                                LEFT JOIN leave_requests l ON e.id = l.employee_id AND l.status = 'approved' AND YEAR(l.leave_date_start) = YEAR(CURDATE())
                                GROUP BY e.id
                            ");
                            $quotas = [];
                            while ($q = $quotaStmt->fetch()) {
                                $quotas[$q['id']] = 14 - $q['used_days'];
                            }
                            
                            $stmtHist = $pdo->query("
                                SELECT l.id, l.leave_date_start, l.leave_date_end, l.leave_type, l.leave_note, l.status, l.employee_id, e.name as stylist_name
                                FROM leave_requests l
                                JOIN employees e ON l.employee_id = e.id
                                WHERE l.status IN ('approved', 'rejected', 'cancelled')
                                ORDER BY e.name ASC, l.leave_date_start DESC
                            ");
                            $rawHistList = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
                            $histList = [];
                            foreach ($rawHistList as $h) {
                                $dateStr = date('d M Y', strtotime($h['leave_date_start']));
                                if ($h['leave_date_start'] !== $h['leave_date_end']) {
                                    $dateStr .= ' - ' . date('d M Y', strtotime($h['leave_date_end']));
                                }
                                $remain = max(0, $quotas[$h['employee_id']] ?? 14);
                                $days = (strtotime($h['leave_date_end']) - strtotime($h['leave_date_start'])) / (60 * 60 * 24) + 1;
                                $h['dateStr']   = $dateStr;
                                $h['days']      = $days;
                                $h['remain']    = $remain;
                                $h['leave_type'] = $h['leave_type'] ?: '—';
                                $h['leave_note'] = $h['leave_note'] ?: '—';
                                $histList[] = $h;
                            }
                            ?>
                        <tbody id="leaveHistBody">
                        </tbody>
                    </table>
                    <div id="leaveHistPagination" style="margin-top: 16px; display: flex; justify-content: flex-end; gap: 4px;"></div>
                </div>

            </div>

            <div id="section-review" class="schedule-section" style="margin-top: 24px; display: none; border-top-left-radius: 0;">
                <div class="schedule-header"><h2>รีวิวจากลูกค้าล่าสุด</h2></div>
                <div style="padding: 24px;">
                    <div style="display: grid; gap: 16px;">
                        <?php
                        $stmtReviews = $pdo->query("
                            SELECT r.rating, r.comment, r.created_at, 
                                   u.first_name, u.last_name, 
                                   s.name as service_name, 
                                   e.name as stylist_name 
                            FROM reviews r
                            JOIN bookings b ON r.booking_id = b.id
                            LEFT JOIN users u ON b.user_id = u.id
                            LEFT JOIN services s ON b.service_id = s.id
                            LEFT JOIN employees e ON b.employee_id = e.id
                            ORDER BY r.created_at DESC LIMIT 20
                        ");
                        $reviewsList = $stmtReviews->fetchAll();
                        
                        // Use consistent colors for stylists
                        $colors = ['ff9f24', '22c55e', '3b82f6', 'ef4444', 'a855f7'];
                        foreach ($reviewsList as $idx => $r):
                            $cname = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                            if (!$cname) $cname = 'ลูกค้าทั่วไป';
                            $stylistName = $r['stylist_name'] ?: 'ไม่ระบุ';
                            $color = $colors[$idx % 5];
                            $starHtml = str_repeat('<i class="fas fa-star"></i>', (int)$r['rating']) . 
                                        str_repeat('<i class="far fa-star"></i>', 5 - (int)$r['rating']);
                        ?>
                        <div style="background: rgba(255,159,36,0.02); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 16px; display: flex; gap: 16px;">
                            <div style="flex-shrink: 0; text-align: center; width: 60px;">
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($stylistName) ?>&background=1c1c1c&color=<?= $color ?>" style="width: 48px; height: 48px; border-radius: 50%; border: 2px solid #<?= $color ?>;">
                                <div style="font-size: 11px; margin-top: 4px; color: var(--text-muted);"><?= htmlspecialchars($stylistName) ?></div>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 14px; color: var(--text-primary);"><?= htmlspecialchars($cname) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);">บริการ: <?= htmlspecialchars($r['service_name'] ?? 'ไม่ระบุ') ?> • 
                                            <?= date('d M Y', strtotime($r['created_at'])) ?>
                                        </div>
                                    </div>
                                    <div style="color: #fbbf24; font-size: 12px;"><?= $starHtml ?></div>
                                </div>
                                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;"><?= htmlspecialchars($r['comment']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if(empty($reviewsList)): ?>
                            <div style="text-align:center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-comment-slash fa-3x" style="margin-bottom:16px; opacity:0.5;"></i>
                                <p>ยังไม่มีรีวิวจากลูกค้าในขณะนี้</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <script>
            function switchTab(tab) {
                ["emp", "dayoff", "review"].forEach(t => {
                    document.getElementById("tab-" + t).classList.remove("active");
                    document.getElementById("section-" + t).style.display = "none";
                });
                document.getElementById("tab-" + tab).classList.add("active");
                document.getElementById("section-" + tab).style.display = "block";
            }

            function filterStaff(q) {
                document.querySelectorAll(".staff-card").forEach(card => {
                    const name = card.dataset.name || '';
                    card.style.display = name.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
                });
            }

            const staffData = <?= json_encode(array_map(function($t) {
                return ['id' => $t['id'], 'name' => $t['name'], 'role' => $t['role'], 'phone' => $t['phone'], 'skills' => $t['skills']];
            }, $mockTechs), JSON_UNESCAPED_UNICODE) ?>;
            const globalJobHistory = <?= json_encode($job_history_mock, JSON_UNESCAPED_UNICODE) ?>;
            const globalSvcMeta = <?= json_encode($svc_meta, JSON_UNESCAPED_UNICODE) ?>;

            function openEditStaffModal(idx) {
                const t = staffData[idx];
                document.getElementById('edit-staff-idx').value    = idx;
                document.getElementById('edit-staff-id').value     = t.id;
                document.getElementById('edit-staff-name').value   = t.name;
                document.getElementById('edit-staff-role').value   = t.role;
                document.getElementById('edit-staff-phone').value  = t.phone || '';
                document.getElementById('edit-staff-skills').value = t.skills.join(', ');
                document.getElementById('editStaffModal').classList.add('active');
            }

            async function saveEditStaff() {
                const idx    = parseInt(document.getElementById('edit-staff-idx').value);
                const empId  = parseInt(document.getElementById('edit-staff-id').value);
                const name   = document.getElementById('edit-staff-name').value.trim();
                const role   = document.getElementById('edit-staff-role').value.trim();
                const phone  = document.getElementById('edit-staff-phone').value.trim();
                const skills = document.getElementById('edit-staff-skills').value.trim();
                if (!name) { showToast('กรุณากรอกชื่อช่าง', 'error'); return; }

                const btn = document.querySelector('#editStaffModal .btn-primary');
                btn.disabled = true; btn.textContent = 'กำลังบันทึก...';

                try {
                    const fd = new FormData();
                    fd.append('action', 'save_employee');
                    fd.append('id', empId);
                    fd.append('name', name);
                    fd.append('role', role);
                    fd.append('phone', phone);
                    fd.append('skills', skills);
                    const res  = await fetch('admin_employees.php', { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.ok) {
                        // อัปเดต staffData ใน memory
                        staffData[idx].name   = name;
                        staffData[idx].role   = role;
                        staffData[idx].phone  = phone;
                        staffData[idx].skills = skills.split(',').map(s => s.trim()).filter(Boolean);
                        // อัปเดต card ใน DOM
                        const card = document.getElementById('staff-' + idx);
                        if (card) {
                            card.querySelector('h3').textContent = name;
                            card.querySelector('p').textContent  = role;
                            const skillsDiv = card.querySelectorAll('div[style*="flex-wrap"]')[0];
                            if (skillsDiv) {
                                skillsDiv.innerHTML = staffData[idx].skills.map(s =>
                                    `<span style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); font-size: 11px; padding: 6px 14px; border-radius: 20px; font-weight: 500; color: var(--text-primary);">${s}</span>`
                                ).join('');
                            }
                        }
                        showToast(data.msg, 'success');
                        document.getElementById('editStaffModal').classList.remove('active');
                    } else {
                        showToast(data.msg || 'เกิดข้อผิดพลาด', 'error');
                    }
                } catch(e) {
                    showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                }
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-check" style="margin-right:6px;"></i>บันทึก';
            }

            function openAddStaffModal() {
                document.getElementById('new-staff-name').value   = '';
                document.getElementById('new-staff-role').value   = '';
                document.getElementById('new-staff-skills').value = '';
                document.getElementById('new-staff-phone').value  = '';
                document.getElementById('addStaffModal').classList.add('active');
            }

            async function saveNewStaff() {
                const name   = document.getElementById('new-staff-name').value.trim();
                const role   = document.getElementById('new-staff-role').value.trim();
                const skills = document.getElementById('new-staff-skills').value.trim();
                const phone  = document.getElementById('new-staff-phone').value.trim();
                if (!name) { showToast('กรุณากรอกชื่อช่าง', 'error'); return; }

                const btn = document.querySelector('#addStaffModal .btn-primary');
                btn.disabled = true; btn.textContent = 'กำลังบันทึก...';

                try {
                    const fd = new FormData();
                    fd.append('action', 'add_employee');
                    fd.append('name', name);
                    fd.append('role', role);
                    fd.append('phone', phone);
                    fd.append('skills', skills);
                    const res  = await fetch('admin_employees.php', { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.ok) {
                        showToast(data.msg, 'success');
                        document.getElementById('addStaffModal').classList.remove('active');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showToast(data.msg || 'เกิดข้อผิดพลาด', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check" style="margin-right:6px;"></i>บันทึก';
                    }
                } catch(e) {
                    showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check" style="margin-right:6px;"></i>บันทึก';
                }
            }

            function openHistoryModal(idx) {
                const t = staffData[idx];
                document.getElementById('historyModalTitle').textContent = `ประวัติงาน - ${t.name}`;
                document.getElementById('historyModal').classList.add('active');
                
                // Filter jobs for this employee
                const myJobs = globalJobHistory.filter(j => parseInt(j.emp_id) === t.id);
                
                document.getElementById('h-sum-jobs').textContent = myJobs.length;
                document.getElementById('h-sum-rev').textContent = '฿' + myJobs.reduce((s,j)=>s+j.price, 0).toLocaleString();
                document.getElementById('h-sum-tip').textContent = '฿' + myJobs.reduce((s,j)=>s+j.tip, 0).toLocaleString();
                document.getElementById('h-sum-rating').textContent = myJobs.length ? (myJobs.reduce((s,j)=>s+j.rating, 0)/myJobs.length).toFixed(1) + ' ★' : '-';

                // Group by date
                const grouped = {};
                myJobs.forEach(j => {
                    if(!grouped[j.date]) grouped[j.date] = [];
                    grouped[j.date].push(j);
                });

                const timeline = document.getElementById('h-timeline-container');
                if(myJobs.length === 0) {
                    timeline.innerHTML = `<div style="text-align:center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-inbox fa-3x" style="margin-bottom:16px; opacity:0.5;"></i>
                        <p>ไม่มีประวัติการทำงานในเดือนนี้</p>
                    </div>`;
                    return;
                }

                let html = '';
                for(const date in grouped) {
                    const jobs = grouped[date];
                    html += `<div class="h-day-group">
                        <div class="h-day-header"><span class="h-day-label">${jobs[0].date_th}</span><div class="h-day-line"></div></div>`;
                    jobs.forEach(job => {
                        const sm = globalSvcMeta[job.service_type] || globalSvcMeta.other;
                        html += `
                        <div class="h-job-row" onclick="toggleJobDetail('${job.id}')" style="--accent-1: ${sm.color};">
                            <div class="h-time-block">
                                <div class="h-time">${job.time}</div>
                                <div class="h-duration">${job.duration} นาที</div>
                            </div>
                            <div class="h-cust-block">
                                <div class="h-cust-av" style="color:${sm.color}; border-color:${sm.color};">${job.customer.avatar}</div>
                                <div>
                                    <div class="h-cust-name">${job.customer.name}</div>
                                    <div class="h-cust-svc">${job.note || '-'}</div>
                                </div>
                            </div>
                            <div class="h-svc-badge" style="color: ${sm.color}; background: ${sm.bg}; border-color: ${sm.color};">${job.service}</div>
                            <div class="h-price-block">
                                <div class="h-price">฿${job.price.toLocaleString()}</div>
                                ${job.tip > 0 ? `<div class="h-tip">+฿${job.tip.toLocaleString()}</div>` : ''}
                            </div>
                        </div>
                        <div class="h-job-detail" id="history-detail-${job.id}">
                            <div class="h-det-group"><label>รหัสงาน</label><span>${job.id}</span></div>
                            <div class="h-det-group"><label>รวมรายรับสุทธิ</label><span style="color:var(--success); font-weight:bold;">฿${(job.price+job.tip).toLocaleString()}</span></div>
                            <div class="h-det-group"><label>คะแนน</label><span style="color:var(--accent-1);">${'★'.repeat(job.rating)}${'☆'.repeat(5-job.rating)} (${job.rating}/5)</span></div>
                        </div>`;
                    });
                    html += `</div>`;
                }
                timeline.innerHTML = html;
            }

            function toggleJobDetail(id) {
                const det = document.getElementById('history-detail-' + id);
                if (det) det.classList.toggle('open');
            }
            </script>
        </div>
    </main>

    <!-- ===== EDIT STAFF MODAL ===== -->
    <div class="modal-overlay" id="editStaffModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit" style="color:var(--accent-1);margin-right:8px;"></i>แก้ไขข้อมูลช่าง</h3>
                <button class="modal-close" onclick="document.getElementById('editStaffModal').classList.remove('active')">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-staff-idx">
                <input type="hidden" id="edit-staff-id">
                <div class="form-group">
                    <label class="form-label">ชื่อ-นามสกุล</label>
                    <input type="text" class="form-input" id="edit-staff-name" placeholder="เช่น น้องบีม">
                </div>
                <div class="form-group">
                    <label class="form-label">ตำแหน่ง/บทบาท</label>
                    <input type="text" class="form-input" id="edit-staff-role" placeholder="เช่น ช่างตัดผม">
                </div>
                <div class="form-group">
                    <label class="form-label">ทักษะ (คั่นด้วยจุลภาค)</label>
                    <input type="text" class="form-input" id="edit-staff-skills" placeholder="เช่น ตัดผม, ทำสีผม, ดัดผม">
                    <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:6px;" id="edit-skills-preview"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-input" id="edit-staff-phone" placeholder="0xx-xxx-xxxx">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('editStaffModal').classList.remove('active')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" onclick="saveEditStaff()"><i class="fas fa-check" style="margin-right:6px;"></i>บันทึก</button>
            </div>
        </div>
    </div>

    <!-- ===== ADD STAFF MODAL ===== -->
    <div class="modal-overlay" id="addStaffModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color:var(--accent-1);margin-right:8px;"></i>เพิ่มช่างใหม่</h3>
                <button class="modal-close" onclick="document.getElementById('addStaffModal').classList.remove('active')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">ชื่อ-นามสกุล</label>
                    <input type="text" class="form-input" id="new-staff-name" placeholder="เช่น ช่างผำ">
                </div>
                <div class="form-group">
                    <label class="form-label">ตำแหน่ง/บทบาท</label>
                    <input type="text" class="form-input" id="new-staff-role" placeholder="เช่น ช่างตัดผม, ผู้ช่วยช่าง">
                </div>
                <div class="form-group">
                    <label class="form-label">ทักษะ (คั่นด้วยจุลภาค)</label>
                    <input type="text" class="form-input" id="new-staff-skills" placeholder="เช่น ตัดผม, ทำสีผม, ดัดผม">
                </div>
                <div class="form-group">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-input" id="new-staff-phone" placeholder="0xx-xxx-xxxx">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('addStaffModal').classList.remove('active')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" onclick="saveNewStaff()"><i class="fas fa-check" style="margin-right:6px;"></i>บันทึก</button>
            </div>
        </div>
    </div>

    <!-- ===== HISTORY MODAL ===== -->
    <style>
        .history-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
        .h-sum-card { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-sm); padding: 16px; text-align: center; }
        .h-sum-val { font-size: 20px; font-weight: 700; font-family: 'Cormorant Garamond', serif; margin-bottom: 4px; color: var(--text-primary); }
        .h-sum-lbl { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .h-timeline { margin-top: 16px; max-height: 480px; overflow-y: auto; padding-right: 8px; }
        .h-day-group { margin-bottom: 24px; }
        .h-day-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .h-day-label { font-size: 12px; font-weight: 700; color: var(--accent-1); }
        .h-day-line { flex: 1; height: 1px; background: var(--border-glass); }

        .h-job-row { display: grid; grid-template-columns: 80px 1fr 100px 80px; align-items: center; gap: 16px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; position: relative; }
        .h-job-row::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:3px 0 0 3px; background:var(--accent-1); }
        .h-job-row:hover { background: rgba(255,159,36,0.03); border-color: rgba(255,159,36,0.15); transform: translateX(2px); }

        .h-time-block { text-align: center; }
        .h-time { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        .h-duration { font-size: 11px; color: var(--text-muted); }

        .h-cust-block { display: flex; align-items: center; gap: 12px; }
        .h-cust-av { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); }
        .h-cust-name { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
        .h-cust-svc { font-size: 11px; color: var(--text-muted); }

        .h-svc-badge { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; border: 1px solid; text-align: center; white-space: nowrap; }
        
        .h-price-block { text-align: right; }
        .h-price { font-size: 15px; font-weight: 700; color: var(--success); }
        .h-tip { font-size: 10px; color: var(--warning); margin-top:2px; }

        .h-job-detail { display: none; grid-template-columns: 1fr 1fr 1fr; gap: 16px; padding: 16px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.03); border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm); margin-top: -8px; margin-bottom: 12px; }
        .h-job-detail.open { display: grid; }
        .h-det-group label { display: block; font-size: 10px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; }
        .h-det-group span { font-size: 13px; color: var(--text-secondary); }

        @media(max-width: 768px) {
            .history-summary { grid-template-columns: 1fr 1fr; }
            .h-job-row { grid-template-columns: 60px 1fr auto; }
            .h-svc-badge { display:none; } /* Hide service badge on mobile to save space */
            .h-job-detail { grid-template-columns: 1fr; }
        }
    </style>
    <div class="modal-overlay" id="historyModal">
        <div class="modal" style="max-width: 800px; width: 90%;">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-glass);">
                <h3 id="historyModalTitle"><i class="fas fa-history" style="color:var(--accent-1);margin-right:8px;"></i>ประวัติงาน</h3>
                <button class="modal-close" onclick="document.getElementById('historyModal').classList.remove('active')">×</button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                
                <!-- Summary -->
                <div class="history-summary">
                    <div class="h-sum-card"><div class="h-sum-val" id="h-sum-jobs">-</div><div class="h-sum-lbl">งานทั้งหมด</div></div>
                    <div class="h-sum-card"><div class="h-sum-val" style="color:var(--success);" id="h-sum-rev">-</div><div class="h-sum-lbl">รายได้</div></div>
                    <div class="h-sum-card"><div class="h-sum-val" style="color:var(--warning);" id="h-sum-tip">-</div><div class="h-sum-lbl">ทิปรวม</div></div>
                    <div class="h-sum-card"><div class="h-sum-val" style="color:var(--accent-1);" id="h-sum-rating">-</div><div class="h-sum-lbl">คะแนนเฉลี่ย</div></div>
                </div>

                <!-- Timeline -->
                <div class="h-timeline" id="h-timeline-container">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-lg" onclick="document.getElementById('historyModal').classList.remove('active')" style="width: 100%;">ตกลง</button>
            </div>
        </div>
    </div>

    <!-- ===== CONFLICT WARNING MODAL ===== -->
    <style>
        .conflict-modal-content { max-width: 560px; width: 90%; }
        .conflict-header-icon {
            width: 56px; height: 56px; border-radius: 50%;
            background: rgba(248, 113, 113, 0.12); border: 2px solid rgba(248, 113, 113, 0.25);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
        }
        .conflict-header-icon i { font-size: 24px; color: var(--danger); }
        .conflict-booking-list { max-height: 280px; overflow-y: auto; padding-right: 4px; }
        .conflict-footer { display: flex; flex-direction: column; gap: 8px; padding: 16px 28px 24px; }
        .conflict-footer .btn-go-schedule {
            width: 100%; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 700;
            cursor: pointer; border: none; font-family: inherit;
            background: var(--accent-gradient); color: var(--btn-text);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.25); transition: all 0.2s;
        }
        .conflict-footer .btn-go-schedule:hover { box-shadow: 0 6px 20px rgba(255, 159, 36, 0.4); transform: translateY(-1px); }

        .conflict-footer .btn-cancel-conflict {
            width: 100%; padding: 10px 24px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: 1px solid var(--border-glass); font-family: inherit;
            background: transparent; color: var(--text-muted); transition: all 0.2s;
        }
        .conflict-footer .btn-cancel-conflict:hover { background: rgba(255,255,255,0.03); color: var(--text-primary); }
    </style>
    <div class="modal-overlay" id="conflictModal">
        <div class="modal conflict-modal-content">
            <div class="modal-header" style="border-bottom: 1px solid rgba(248,113,113,0.2); background: rgba(248,113,113,0.04);">
                <h3 style="color:var(--danger);"><i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>พบตารางงานที่ยังไม่ได้เคลียร์</h3>
                <button class="modal-close" onclick="document.getElementById('conflictModal').classList.remove('active')">×</button>
            </div>
            <div class="modal-body" style="padding: 24px 28px;">
                <div style="text-align:center; margin-bottom:20px;">
                    <div class="conflict-header-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <p style="font-size:15px; font-weight:600; color:var(--text-primary); margin-bottom:6px;">
                        ช่างมีนัดหมายในช่วงวันลา
                    </p>
                    <p style="font-size:13px; color:var(--text-muted);">
                        วันที่ <span id="conflictDateRange" style="font-weight:600; color:var(--danger);"></span>
                        มีการนัดหมาย <span id="conflictCount" style="font-weight:700; color:var(--danger);"></span> รายการ
                    </p>
                    <p style="font-size:12px; color:var(--warning); margin-top:8px;">
                        <i class="fas fa-info-circle" style="margin-right:4px;"></i>กรุณาเคลียร์ตารางงานก่อนอนุมัติวันลา
                    </p>
                </div>
                <div class="conflict-booking-list" id="conflictBookingList"></div>
            </div>
            <div class="conflict-footer">
                <button class="btn-go-schedule" id="conflictSchedBtn">
                    <i class="fas fa-calendar-alt" style="margin-right:8px;"></i>ไปจัดการตารางงาน
                </button>

                <button class="btn-cancel-conflict" onclick="document.getElementById('conflictModal').classList.remove('active')">
                    ยกเลิก
                </button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST CONTAINER ===== -->
    <div class="toast-container" id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

    <script>
        // Data for pagination
        const leaveHistData = <?= json_encode($histList) ?>;
        const itemsPerPage = 8;
        let currentPage = 1;

        function renderLeaveHist() {
            const body = document.getElementById('leaveHistBody');
            const pag = document.getElementById('leaveHistPagination');
            
            if (leaveHistData.length === 0) {
                body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">ไม่มีประวัติการลา</td></tr>';
                pag.innerHTML = '';
                return;
            }

            const totalPages = Math.ceil(leaveHistData.length / itemsPerPage);
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIdx = (currentPage - 1) * itemsPerPage;
            const currentItems = leaveHistData.slice(startIdx, startIdx + itemsPerPage);

            const colors = ['ff9f24', '22c55e', '3b82f6', 'ef4444', 'a855f7'];
            let html = '';
            
            currentItems.forEach((h, idx) => {
                const color = colors[idx % 5];
                const statusColor = h.status === 'approved' ? 'var(--success)' : (h.status === 'rejected' ? 'var(--danger)' : 'var(--text-muted)');
                const statusText  = h.status === 'approved' ? 'อนุมัติแล้ว' : (h.status === 'rejected' ? 'ปฏิเสธ' : 'ยกเลิก');
                const leaveType   = h.leave_type || '—';
                const leaveNote   = h.leave_note || '—';
                
                html += `
                <tr style="border-bottom: 1px dashed var(--border-glass);">
                    <td style="padding: 16px 12px; display: flex; align-items: center; gap: 12px;">
                        <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(h.stylist_name)}&background=1c1c1c&color=${color}" style="width: 32px; height: 32px; border-radius: 50%;"> 
                        ${h.stylist_name}
                   </td>
                    <td style="padding: 16px 12px; font-size: 13px;">${h.dateStr}</td>
                    <td style="padding: 16px 12px; font-size: 13px;">${h.days} วัน</td>
                    <td style="padding: 16px 12px; font-size: 13px;">
                        <span style="background:rgba(255,159,36,0.08); border:1px solid rgba(255,159,36,0.2); color:var(--accent-1); padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap;">${leaveType}</span>
                    </td>
                    <td style="padding: 16px 12px; font-size: 13px; color: var(--text-muted); max-width:200px; word-break:break-word;">${leaveNote}</td>
                    <td style="padding: 16px 12px; font-size: 13px; font-weight:600; color: ${statusColor};">${statusText}</td>
                    <td style="padding: 16px 12px; font-size: 13px; text-align: right;">${h.remain} / 14 วัน</td>
                </tr>`;
            });
            body.innerHTML = html;

            // Render pagination buttons
            let phtml = '';
            if (totalPages > 1) {
                phtml += `<button class="btn-outline" style="border-radius:4px; ${currentPage===1 ? 'opacity:0.3;cursor:not-allowed;' : ''}" onclick="if(${currentPage}>1){currentPage--; renderLeaveHist();}"><i class="fas fa-chevron-left"></i></button>`;
                
                for(let i=1; i<=totalPages; i++) {
                    if(i===1 || i===totalPages || (i >= currentPage-1 && i <= currentPage+1)) {
                        phtml += `<button class="${i===currentPage ? 'btn-primary' : 'btn-outline'}" style="border-radius:4px; padding:6px 10px;" onclick="currentPage=${i}; renderLeaveHist();">${i}</button>`;
                    } else if (i === currentPage-2 || i === currentPage+2) {
                        phtml += `<span style="color:var(--text-muted); align-self:end;">...</span>`;
                    }
                }
                
                phtml += `<button class="btn-outline" style="border-radius:4px; ${currentPage===totalPages ? 'opacity:0.3;cursor:not-allowed;' : ''}" onclick="if(${currentPage}<${totalPages}){currentPage++; renderLeaveHist();}"><i class="fas fa-chevron-right"></i></button>`;
            }
            pag.innerHTML = phtml;
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderLeaveHist();
        });

        function toggleSidebar() { document.getElementById("sidebar").classList.toggle("open"); }
        document.addEventListener("click", function(e) {
            const sidebar = document.getElementById("sidebar");
            const toggle = document.getElementById("menuToggle");
            if(window.innerWidth <= 768 && sidebar && sidebar.classList.contains("open") && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove("open");
            }
        });
        // Close modals when clicking the backdrop
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); });
        });
        function showToast(message, type = "info") {
            const container = document.getElementById("toastContainer");
            const toast = document.createElement("div");
            toast.className = `toast ${type}`;
            const icons = { success: "<i class='fas fa-check-circle' style='color:var(--success);'></i>", error: "<i class='fas fa-times-circle' style='color:var(--error);'></i>", info: "<i class='fas fa-info-circle' style='color:var(--info);'></i>" };
            toast.style.cssText = "background: var(--dark2); border: 1px solid var(--border); padding: 12px 20px; border-radius: 8px; font-size: 13px; color: var(--text-primary); display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); animation: slideInRight 0.3s ease forwards;";
            toast.innerHTML = `<span class="toast-icon">${icons[type] || ""}</span><span class="toast-message">${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.style.animation = "slideOutRight 0.3s ease forwards"; setTimeout(() => toast.remove(), 300); }, 3000);
        }
        
        function handleLeave(id, actionType) {
            fetch('admin_employees.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${actionType}_leave&id=${id}`
            }).then(r => r.json()).then(data => {
                if(data.ok) {
                    showToast(actionType === 'approve' ? 'อนุมัติคำขอลาหยุดเรียบร้อย' : 'ปฏิเสธคำขอลาหยุดเรียบร้อย', 'success');
                    const row = document.getElementById('req-' + id);
                    if (row) row.remove();
                    setTimeout(() => location.reload(), 10000);
                } else if (data.has_conflict) {
                    // ── แสดง Modal เตือนว่ามีตารางงานชนกับวันลา ──
                    showConflictModal(data);
                } else {
                    showToast('เกิดข้อผิดพลาด: ' + (data.msg || data.error || 'ไม่ทราบสาเหตุ'), 'error');
                }
            }).catch(e => {
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'error');
            });
        }

        // ── แสดง Modal เตือนตารางงานชนวันลา ──
        function showConflictModal(data) {
            const modal = document.getElementById('conflictModal');
            const listEl = document.getElementById('conflictBookingList');
            const countEl = document.getElementById('conflictCount');
            const dateEl = document.getElementById('conflictDateRange');
            const schedBtn = document.getElementById('conflictSchedBtn');

            // แสดงช่วงวันลา
            let dateRange = data.leave_start;
            if (data.leave_end && data.leave_end !== data.leave_start) {
                dateRange += ' ถึง ' + data.leave_end;
            }
            dateEl.textContent = dateRange;
            countEl.textContent = data.bookings.length;

            // สร้างรายการ booking ที่ชน
            let html = '';
            data.bookings.forEach(b => {
                const time = b.start_time ? b.start_time.substring(0,5) : '-';
                html += `
                <div style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:rgba(248,113,113,0.06); border:1px solid rgba(248,113,113,0.15); border-radius:8px; margin-bottom:8px;">
                    <div style="width:40px; height:40px; border-radius:50%; background:rgba(248,113,113,0.12); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-calendar-times" style="color:var(--danger); font-size:14px;"></i>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:2px;">${b.customer_name || 'ลูกค้า'}</div>
                        <div style="font-size:11px; color:var(--text-muted);">${b.service_name || 'บริการ'}</div>
                    </div>
                    <div style="text-align:right; flex-shrink:0;">
                        <div style="font-size:12px; font-weight:600; color:var(--danger);">${b.booking_date}</div>
                        <div style="font-size:11px; color:var(--text-muted);">${time} น.</div>
                    </div>
                </div>`;
            });
            listEl.innerHTML = html;

            // ปุ่ม "ไปจัดการตารางงาน" → ไปหน้า admin_home ที่วันนั้น
            schedBtn.onclick = () => {
                window.location.href = 'admin_home.php?date=' + data.leave_start;
            };



            modal.classList.add('active');
        }
        const style = document.createElement("style");
        style.innerHTML = `@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } } @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }`;
        document.head.appendChild(style);
    </script>
</body>
</html>