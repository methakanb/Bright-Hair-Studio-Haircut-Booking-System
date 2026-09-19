<?php
/**
 * Bright Hair Studio - Admin Dashboard
 * ตารางคิวงานประจำวัน (8:00-17:00) รีเซ็ตทุกสัปดาห์
 * ยังไม่เชื่อม Database - ใช้ข้อมูลจำลอง
 */

// ===== CONFIG & MOCK DATA =====
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

// ดึงข้อมูลช่างจาก DB
require_once 'db.php';
$stmtEmp = $pdo->query("SELECT id, name, LEFT(name, 1) as initials, CONCAT('t', (id % 4)+1) as class, role FROM employees ORDER BY id ASC");
$technicians = $stmtEmp->fetchAll();

// ดึงข้อมูลประเภทบริการ
try {
    $stmtSvc = $pdo->query("SELECT * FROM services ORDER BY id ASC");
    $dbServices = $stmtSvc->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbServices = [];
}

$dbPromotions = [];
try {
    $stmtPromo = $pdo->query("SELECT * FROM promotions ORDER BY id ASC");
    $promos = $stmtPromo->fetchAll(PDO::FETCH_ASSOC);
    foreach ($promos as $p) {
        $stmtMap = $pdo->prepare("SELECT service_id FROM promotion_services WHERE promotion_id = ?");
        $stmtMap->execute([$p['id']]);
        $p['service_ids'] = array_map('intval', $stmtMap->fetchAll(PDO::FETCH_COLUMN));
        $dbPromotions[] = $p;
    }
} catch (PDOException $e) {
    $dbPromotions = [];
}

$serviceTypes = [];
foreach ($dbServices as $s) {
    $c = isset($s['code']) ? $s['code'] : 'S00'.$s['id'];
    $serviceTypes[$c] = [
        'name' => $s['name'],
        'icon' => '<span class="dot" style="background: '.(isset($s['color_code']) ? $s['color_code'] : '#555').'; display:inline-block; width:10px; height:10px; border-radius:50%;"></span>',
    ];
}

// ตารางเวลา
$mockData = [];
// removed badge clash
$confirmedCount = 0;
$walkinCount = 0;

$stmt = $pdo->prepare("
    SELECT b.id, b.booking_code,
           u.first_name, u.last_name, u.member_tier,
           s.code as service_code, s.name as service_name, s.duration_min,
           e.id as emp_id,
           TIME_FORMAT(b.start_time,'%H') as hour,
           b.status, b.notes
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN employees e ON b.employee_id = e.id
    WHERE DATE(b.booking_date) = ?
");
$stmt->execute([$selectedDate]);
$bookingRows = $stmt->fetchAll();

foreach ($bookingRows as $b) {
    if (!$b['emp_id']) continue;
    $key = $selectedDay . '_' . (int)$b['hour'] . '_' . $b['emp_id'];
    $mockData[$key] = [
        'client'      => $b['first_name'] . ' ' . $b['last_name'],
        'client_tier' => $b['member_tier'] ?? 'Member',
        'service'     => $b['service_code'],
        'service_name'=> $b['service_name'],
        'status'      => $b['status'] == 'walkin' ? 'walkin' : 'confirmed',
        'duration'    => ($b['duration_min'] / 60) . ' ชม.',
        'note'        => $b['notes'],
        'id'          => $b['id']
    ];
    // removed badge clash
    if ($mockData[$key]['status'] === 'confirmed') $confirmedCount++;
    if ($mockData[$key]['status'] === 'walkin') $walkinCount++;
}

// Status labels (removed pending, renamed confirmed to ออนไลน์)
$statusLabels = [
    'confirmed' => ['label' => 'ออนไลน์', 'icon' => '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:var(--success); margin-right:4px;"></span>'],
    'walkin' => ['label' => 'Walk-in', 'icon' => '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:var(--info); margin-right:4px;"></span>'],
];

// ช่วงเวลา 8:00 - 17:00
$timeSlots = range(8, 17);
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
            z-index: 1;
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
            margin-bottom: 4px;
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

        .cal-cell.empty {
            cursor: default;
        }

        .cal-cell:not(.empty):hover {
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
            padding: 6px 10px !important;
            min-width: 180px;
            border-right: 1px solid rgba(0, 0, 0, 0.03);
        }

        /* ===== BOOKING CARDS ===== */
        .booking-card {
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            margin: 2px 0;
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
            width: 100%;
            height: 100%;
            min-height: 60px;
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
            opacity: 1;
            background: rgba(255, 159, 36, 0.03);
            border-color: rgba(255, 159, 36, 0.2);
            color: var(--accent-1);
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
            <a href="admin_services.php" class="nav-item active">
                <span class="icon"><i class="fas fa-cut" style="color: var(--accent-1);"></i></span>
                <span>บริการ</span>
            </a>
            <a href="admin_employees.php" class="nav-item">
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

    
    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">
        <style>
            .btn-outline { background: transparent; border: 1px solid currentColor; color: inherit; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; margin-right: 4px; transition: opacity 0.2s; font-family: "Jost", sans-serif; }
            .btn-outline:hover { opacity: 0.8; background: rgba(255,255,255,0.05); }
            .form-input.inline { background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); color: var(--text-primary); padding: 6px 10px; font-size: 14px; width: 100%; border-radius: 4px; font-family: "Jost", sans-serif; }
        </style>
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">☰</button>
                <div class="page-title">
                    <h1><i class="fas fa-cut" style="color: var(--gold); margin-right: 8px;"></i>บริการของเรา</h1>
                    <p>จัดการบริการและราคา</p>
                </div>
            </div>
            <div class="topbar-right">
                <!-- Buttons have been moved to the bottom of the respective tables -->
            </div>
        </header>
        <div class="content">
            <div class="schedule-section" style="margin-bottom: 28px;">
                <div class="schedule-header"><h2>รายการบริการ</h2></div>
                <div style="padding: 24px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border-glass); color: var(--text-muted);">
                                
                                <th style="padding: 12px;">ชื่อบริการ</th>
                                <th style="padding: 12px; width: 140px;">เวลาโดยประมาณ</th>
                                <th style="padding: 12px; width: 140px;">ราคาเริ่มต้น</th>
                                <th style="padding: 12px; text-align: right; width: 140px;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="servicesList">
                        </tbody>
                    </table>
                    <button class="btn btn-outline" style="width: 100%; border: 1px dashed rgba(255, 159, 36, 0.4); color: var(--accent-1); margin-top: 16px; padding: 14px; font-size: 14px;" onclick="addNewService()">
                        <i class="fas fa-plus" style="margin-right: 8px;"></i> เพิ่มบริการใหม่
                    </button>
                </div>
            </div>

            <div class="schedule-section">
                <div class="schedule-header"><h2>รายการโปรโมชั่น</h2></div>
                <div style="padding: 24px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border-glass); color: var(--text-muted);">
                                <th style="padding: 12px;">ชื่อโปรโมชั่น</th>
                                <th style="padding: 12px; width: 140px;">ช่วงเวลาโปรโมชั่น</th>
                                <th style="padding: 12px; width: 140px;">ราคาโปรโมชั่น</th>
                                <th style="padding: 12px; text-align: right; width: 140px;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="promotionsList">
                        </tbody>
                    </table>
                    <button class="btn btn-outline" style="width: 100%; border: 1px dashed rgba(255, 159, 36, 0.4); color: var(--accent-1); margin-top: 16px; padding: 14px; font-size: 14px;" onclick="addNewPromotion()">
                        <i class="fas fa-plus" style="margin-right: 8px;"></i> เพิ่มโปรโมชั่นใหม่
                    </button>
                </div>
            </div>
            
            <script>
                // Initial State loaded from DB
                let services = <?= json_encode(array_map(function($s) {
                    return [
                        'id' => $s['id'],
                        'code' => $s['code'] ?? '',
                        'name' => $s['name'],
                        'name_en' => $s['name_en'] ?? '',
                        'description' => $s['description'] ?? '',
                        'duration' => $s['duration_min'] ?? '',
                        'price' => $s['price'] ?? '',
                        'isEditing' => false,
                        'isNew' => false
                    ];
                }, $dbServices), JSON_UNESCAPED_UNICODE) ?>;

                function formatTime(min) {
                    if (!min) return '';
                    let hr = Math.floor(min / 60);
                    let rem = min % 60;
                    if (hr > 0) return `${hr} ชม.${rem>0 ? ' '+rem+' นาที' : ''}`;
                    return `${rem} นาที`;
                }

                function formatPrice(price) {
                    if (!price) return '฿0';
                    return '฿' + Number(price).toLocaleString('en');
                }

                function renderServices() {
                    const tbody = document.getElementById("servicesList");
                    tbody.innerHTML = "";
                    services.forEach((s) => {
                        const tr = document.createElement("tr");
                        tr.style.cssText = "border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;";
                        let buttons = `<button class="btn-outline" style="color:var(--text-primary)" onclick="editService(${s.id})"><i class="fas fa-edit"></i></button>
                                       <button class="btn-outline" style="color:var(--error)" onclick="deleteService(${s.id})"><i class="fas fa-trash"></i></button>`;
                        
                        if (s.isEditing) {
                            tr.innerHTML = `
                                <td style="padding: 16px 12px; display: flex; flex-direction: column; gap: 8px;">
                                    <input type="text" class="form-input inline" id="edit_code_${s.id}" value="${s.code}" placeholder="Code (e.g. S015)">
                                    <input type="text" class="form-input inline" id="edit_name_${s.id}" value="${s.name}" placeholder="ชื่อไทย">
                                    <input type="text" class="form-input inline" id="edit_name_en_${s.id}" value="${s.name_en}" placeholder="English Name">
                                    <textarea class="form-input inline" id="edit_desc_${s.id}" placeholder="รายละเอียด" style="resize:vertical;min-height:40px;">${s.description}</textarea>
                                </td>
                                <td style="padding: 16px 12px; vertical-align:top;"><input type="number" class="form-input inline" id="edit_time_${s.id}" value="${s.duration}" placeholder="นาที"></td>
                                <td style="padding: 16px 12px; vertical-align:top;"><input type="number" class="form-input inline" id="edit_price_${s.id}" value="${s.price}"></td>
                                <td style="padding: 16px 12px; text-align: right; vertical-align:top;">
                                    <button class="btn-outline" style="color:var(--success)" onclick="saveService(${s.id})"><i class="fas fa-check"></i></button>
                                    <button class="btn-outline" style="color:var(--text-muted)" onclick="cancelEdit(${s.id})"><i class="fas fa-times"></i></button>
                                </td>
                            `;
                        } else {
                            tr.innerHTML = `
                                <td style="padding: 16px 12px;">
                                    <div style="font-weight: 500; font-size: 16px; color: var(--text-primary);"><span style="color:var(--accent-1);font-size:12px;margin-right:6px;">[${s.code}]</span>${s.name}</div>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top:2px;">${s.name_en}</div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top:6px; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${s.description}</div>
                                </td>
                                <td style="padding: 16px 12px; color: var(--text-muted); vertical-align:top;"><i class="fas fa-clock" style="font-size: 11px; margin-right: 6px; color: var(--accent-1);"></i>${formatTime(s.duration)}</td>
                                <td style="padding: 16px 12px; color: var(--accent-1); font-weight: 600; vertical-align:top;">${formatPrice(s.price)}</td>
                                <td style="padding: 16px 12px; text-align: right; vertical-align:top;">${buttons}</td>
                            `;
                        }
                        tbody.appendChild(tr);
                    });
                }

                function deleteService(id) {
                    if(!confirm("ยืนยันการลบบริการ?")) return;
                    fetch('api_services_manage.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'delete_service', id: id })
                    }).then(r=>r.json()).then(res => {
                        if (res.success) {
                            services = services.filter(s => s.id !== id);
                            renderServices();
                            showToast("ลบบริการเรียบร้อย", "error");
                        } else {
                            showToast("Error: " + res.message, "error");
                        }
                    });
                }

                function editService(id) {
                    const s = services.find(x => x.id === id);
                    if(s) s.isEditing = true;
                    renderServices();
                }

                function cancelEdit(id) {
                    const s = services.find(x => x.id === id);
                    if(s) {
                        if(s.isNew) services = services.filter(x => x.id !== id);
                        else s.isEditing = false;
                    }
                    renderServices();
                }

                function saveService(id) {
                    const s = services.find(x => x.id === id);
                    if(!s) return;
                    const action = s.isNew ? 'add_service' : 'edit_service';
                    const payload = {
                        action: action, id: id,
                        code: document.getElementById("edit_code_"+id).value,
                        name: document.getElementById("edit_name_"+id).value,
                        name_en: document.getElementById("edit_name_en_"+id).value,
                        description: document.getElementById("edit_desc_"+id).value,
                        duration_min: document.getElementById("edit_time_"+id).value,
                        price: document.getElementById("edit_price_"+id).value
                    };

                    fetch('api_services_manage.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(payload)
                    }).then(r=>r.json()).then(res => {
                        if(res.success) {
                            if(action==='add_service') s.id = res.id;
                            s.code = payload.code; s.name = payload.name; s.name_en = payload.name_en;
                            s.description = payload.description; s.duration = payload.duration_min; s.price = payload.price;
                            s.isEditing = false; s.isNew = false;
                            renderServices();
                            showToast("บันทึกบริการเรียบร้อย", "success");
                        } else {
                            showToast("Error: " + res.message, "error");
                        }
                    });
                }

                function addNewService() {
                    services.push({ id: Date.now(), code: "", name: "", name_en: "", description: "", duration: "", price: "", isEditing: true, isNew: true });
                    renderServices();
                }

                // ================= PROMOTIONS LOGIC =================
                let promotions = <?= json_encode(array_map(function($p) {
                    return [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'type' => $p['type'] ?? 'bundle',
                        'start_date' => $p['start_date'] ?? date('Y-m-d'),
                        'end_date' => $p['end_date'] ?? date('Y-m-d'),
                        'price_old' => $p['price_old'] ?? '',
                        'price_new' => $p['price_new'] ?? '',
                        'description' => $p['description'] ?? '',
                        'service_ids' => $p['service_ids'] ?? [],
                        'isEditing' => false,
                        'isNew' => false
                    ];
                }, $dbPromotions), JSON_UNESCAPED_UNICODE) ?>;

                if (promotions.length === 0) {
                    // Fallback to empty if DB failed to fetch
                }

                function renderPromotions() {
                    const tbody = document.getElementById("promotionsList");
                    tbody.innerHTML = "";
                    promotions.forEach((p) => {
                        const tr = document.createElement("tr");
                        tr.style.cssText = "border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;";
                        let buttons = `<button class="btn-outline" style="color:var(--text-primary)" onclick="editPromotion(${p.id})"><i class="fas fa-edit"></i></button>
                                       <button class="btn-outline" style="color:var(--error)" onclick="deletePromotion(${p.id})"><i class="fas fa-trash"></i></button>`;
                                       
                        if (p.isEditing) {
                            tr.innerHTML = `
                                <td style="padding: 16px 12px; display: flex; flex-direction: column; gap: 8px;">
                                    <input type="text" class="form-input inline" id="edit_pname_${p.id}" value="${p.name}" placeholder="ชื่อโปรโมชั่น">
                                    <select class="form-select inline" id="edit_ptype_${p.id}" style="padding:6px; font-size:12px;">
                                        <option value="bundle" ${p.type==='bundle'?'selected':''}>Bundle</option>
                                        <option value="limited" ${p.type==='limited'?'selected':''}>Limited</option>
                                        <option value="new" ${p.type==='new'?'selected':''}>New</option>
                                        <option value="sale" ${p.type==='sale'?'selected':''}>Sale</option>
                                    </select>
                                    <textarea class="form-input inline" id="edit_pdesc_${p.id}" placeholder="รายละเอียด" style="resize:vertical;min-height:40px;">${p.description}</textarea>
                                </td>
                                <td style="padding: 16px 12px; vertical-align:top;">
                                    <div style="font-size:11px; margin-bottom:4px; color:var(--text-muted);">เริ่ม:</div>
                                    <input type="date" class="form-input inline" id="edit_pstart_${p.id}" value="${p.start_date}" style="margin-bottom:8px;">
                                    <div style="font-size:11px; margin-bottom:4px; color:var(--text-muted);">สิ้นสุด:</div>
                                    <input type="date" class="form-input inline" id="edit_pend_${p.id}" value="${p.end_date}" style="margin-bottom:8px;">
                                    <div style="font-size:11px; margin-bottom:4px; margin-top:8px; color:var(--text-muted);">บริกาที่เข้าร่วม:</div>
                                    <div id="edit_pservices_${p.id}" style="max-height:100px; overflow-y:auto; border:1px solid rgba(255,255,255,0.1); padding:8px; border-radius:4px; background:rgba(0,0,0,0.2);">
                                        ${services.map(s => `
                                            <label style="display:flex; align-items:center; gap:6px; font-size:11px; margin-bottom:4px; cursor:pointer;">
                                                <input type="checkbox" value="${s.id}" ${p.service_ids && p.service_ids.includes(s.id) ? 'checked' : ''}>
                                                [${s.code}] ${s.name}
                                            </label>
                                        `).join('')}
                                    </div>
                                </td>
                                <td style="padding: 16px 12px; vertical-align:top; display: flex; flex-direction: column; gap: 8px;">
                                    <input type="number" class="form-input inline" id="edit_ppriceold_${p.id}" value="${p.price_old}" placeholder="ราคาเต็ม">
                                    <input type="number" class="form-input inline" id="edit_ppricenew_${p.id}" value="${p.price_new}" placeholder="ราคาโปร">
                                </td>
                                <td style="padding: 16px 12px; text-align: right; vertical-align:top;">
                                    <button class="btn-outline" style="color:var(--success)" onclick="savePromotion(${p.id})"><i class="fas fa-check"></i></button>
                                    <button class="btn-outline" style="color:var(--text-muted)" onclick="cancelEditPromotion(${p.id})"><i class="fas fa-times"></i></button>
                                </td>
                            `;
                        } else {
                            tr.innerHTML = `
                                <td style="padding: 16px 12px;">
                                    <div style="font-weight: 500; font-size: 16px; color: var(--text-primary);"><i class="fas fa-star" style="font-size: 11px; margin-right: 8px; color: var(--accent-1);"></i>${p.name} <span style="font-size:10px; background:rgba(255,255,255,0.1); padding:2px 6px; border-radius:4px; margin-left:8px; text-transform:uppercase;">${p.type}</span></div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top:6px; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${p.description}</div>
                                </td>
                                <td style="padding: 16px 12px; color: var(--text-muted); vertical-align:top;"><i class="fas fa-calendar-days" style="font-size: 11px; margin-right: 6px; color: var(--text-muted);"></i>${p.start_date} - ${p.end_date}</td>
                                <td style="padding: 16px 12px; vertical-align:top;">
                                    <div style="color: var(--text-muted); text-decoration: line-through; font-size:11px;">${formatPrice(p.price_old)}</div>
                                    <div style="color: var(--accent-1); font-weight: 600;">${formatPrice(p.price_new)}</div>
                                </td>
                                <td style="padding: 16px 12px; text-align: right; vertical-align:top;">${buttons}</td>
                            `;
                        }
                        tbody.appendChild(tr);
                    });
                }

                function deletePromotion(id) {
                    if(!confirm("ยืนยันการลบโปรโมชั่น?")) return;
                    fetch('api_services_manage.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'delete_promotion', id: id })
                    }).then(r=>r.json()).then(res => {
                        if (res.success) {
                            promotions = promotions.filter(p => p.id !== id);
                            renderPromotions();
                            showToast("ลบโปรโมชั่นเรียบร้อย", "error");
                        } else {
                            showToast("Error: " + res.message, "error");
                        }
                    });
                }

                function editPromotion(id) {
                    const p = promotions.find(x => x.id === id);
                    if(p) p.isEditing = true;
                    renderPromotions();
                }

                function cancelEditPromotion(id) {
                    const p = promotions.find(x => x.id === id);
                    if(p) {
                        if(p.isNew) promotions = promotions.filter(x => x.id !== id);
                        else p.isEditing = false;
                    }
                    renderPromotions();
                }

                function savePromotion(id) {
                    const p = promotions.find(x => x.id === id);
                    if(!p) return;
                    const action = p.isNew ? 'add_promotion' : 'edit_promotion';
                    const sIds = Array.from(document.querySelectorAll(`#edit_pservices_${id} input[type="checkbox"]:checked`)).map(cb => parseInt(cb.value));
                    const payload = {
                        action: action, id: id,
                        name: document.getElementById("edit_pname_"+id).value,
                        type: document.getElementById("edit_ptype_"+id).value,
                        description: document.getElementById("edit_pdesc_"+id).value,
                        start_date: document.getElementById("edit_pstart_"+id).value,
                        end_date: document.getElementById("edit_pend_"+id).value,
                        price_old: document.getElementById("edit_ppriceold_"+id).value,
                        price_new: document.getElementById("edit_ppricenew_"+id).value,
                        service_ids: sIds
                    };

                    fetch('api_services_manage.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(payload)
                    }).then(r=>r.json()).then(res => {
                        if(res.success) {
                            if(action==='add_promotion') p.id = res.id;
                            p.name = payload.name; p.type = payload.type; p.description = payload.description;
                            p.start_date = payload.start_date; p.end_date = payload.end_date; p.price_old = payload.price_old; p.price_new = payload.price_new;
                            p.service_ids = payload.service_ids;
                            p.isEditing = false; p.isNew = false;
                            renderPromotions();
                            showToast("บันทึกโปรโมชั่นเรียบร้อย", "success");
                        } else {
                            showToast("Error: " + res.message, "error");
                        }
                    });
                }

                function addNewPromotion() {
                    const today = new Date().toISOString().split('T')[0];
                    promotions.push({ id: Date.now(), name: "", type: "bundle", start_date: today, end_date: today, price_old: "", price_new: "", description: "", service_ids: [], isEditing: true, isNew: true });
                    renderPromotions();
                }


                // Initial render
                renderServices();
                renderPromotions();
            </script>
        </div>
    </main>

    <!-- ===== TOAST CONTAINER ===== -->
    <div class="toast-container" id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

    <script>
        function toggleSidebar() { document.getElementById("sidebar").classList.toggle("open"); }
        document.addEventListener("click", function(e) {
            const sidebar = document.getElementById("sidebar");
            const toggle = document.getElementById("menuToggle");
            if(window.innerWidth <= 768 && sidebar && sidebar.classList.contains("open") && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove("open");
            }
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
        const style = document.createElement("style");
        style.innerHTML = `@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } } @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }`;
        document.head.appendChild(style);
    </script>
</body>
</html>