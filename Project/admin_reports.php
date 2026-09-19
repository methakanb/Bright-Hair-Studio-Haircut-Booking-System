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
require_once 'db.php';

// ==========================================
// การคัดกรองช่วงเวลา (Time Period Filter)
// ==========================================
$period = $_GET['period'] ?? 'this_month';

$periodWhere = "MONTH(b.booking_date) = MONTH(CURRENT_DATE()) AND YEAR(b.booking_date) = YEAR(CURRENT_DATE()) AND DATE(b.booking_date) <= CURRENT_DATE()";
$periodWhereNoB = "MONTH(booking_date) = MONTH(CURRENT_DATE()) AND YEAR(booking_date) = YEAR(CURRENT_DATE()) AND DATE(booking_date) <= CURRENT_DATE()";
$periodLabel = "เดือนปัจจุบัน";

if ($period === '1d') {
    $periodWhere = "DATE(b.booking_date) = CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) = CURRENT_DATE()";
    $periodLabel = "วันนี้";
} elseif ($period === '3d') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "3 วันหลังสุด";
} elseif ($period === '7d') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "7 วันหลังสุด";
} elseif ($period === '1m') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "1 เดือนที่ผ่านมา";
} elseif ($period === '3m') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 3 MONTH) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 3 MONTH) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "3 เดือนที่ผ่านมา";
} elseif ($period === '6m') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "6 เดือนที่ผ่านมา";
} elseif ($period === '1y') {
    $periodWhere = "DATE(b.booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR) AND DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR) AND DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "1 ปีที่ผ่านมา";
} elseif ($period === 'all') {
    $periodWhere = "DATE(b.booking_date) <= CURRENT_DATE()";
    $periodWhereNoB = "DATE(booking_date) <= CURRENT_DATE()";
    $periodLabel = "ทั้งหมด";
}

// สถิติยอดขายตามช่วงเวลา
$stmtIncome = $pdo->prepare("
    SELECT SUM(s.price) 
    FROM bookings b 
    JOIN services s ON b.service_id = s.id 
    WHERE {$periodWhere} AND b.status = 'completed'
");
$stmtIncome->execute();
$monthlyIncome = (int)$stmtIncome->fetchColumn();

// ลูกค้าตามช่วงเวลา
$stmtCust = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) 
    FROM bookings 
    WHERE {$periodWhereNoB} AND status = 'completed'
");
$stmtCust->execute();
$monthlyCust = (int)$stmtCust->fetchColumn();

// จำนวนงานทั้งหมด (รายการนัดหมาย/Walk-in) ตามช่วงเวลา
$stmtJobs = $pdo->prepare("
    SELECT COUNT(*) 
    FROM bookings 
    WHERE {$periodWhereNoB} AND status = 'completed'
");
$stmtJobs->execute();
$totalJobs = (int)$stmtJobs->fetchColumn();

// นับคิวสำหรับ sidebar ของวันนี้
// removed badge clash
$stmtBookings = $pdo->prepare("SELECT status FROM bookings WHERE DATE(booking_date) = ?");
$stmtBookings->execute([date('Y-m-d')]); // Sidebar usually shows today's count
// removed badge clash

// 7 วันย้อนหลัง
$stmt7Day = $pdo->prepare("
    SELECT DATE(b.booking_date) as dt, SUM(s.price) as total
    FROM bookings b JOIN services s ON b.service_id = s.id
    WHERE b.booking_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY) AND b.status = 'completed'
    GROUP BY DATE(b.booking_date)
    ORDER BY dt ASC
");
$stmt7Day->execute();
$raw7Days = $stmt7Day->fetchAll(PDO::FETCH_ASSOC);
$income7DaysMap = [];
foreach($raw7Days as $r) $income7DaysMap[$r['dt']] = (int)$r['total'];

$last7DaysLabels = [];
$last7DaysData = [];
$thaiDays = ['Sun'=>'อา.', 'Mon'=>'จ.', 'Tue'=>'อ.', 'Wed'=>'พ.', 'Thu'=>'พฤ.', 'Fri'=>'ศ.', 'Sat'=>'ส.'];
for($i=6; $i>=0; $i--) {
    $dt = date('Y-m-d', strtotime("-$i days"));
    $enDay = date('D', strtotime($dt));
    $last7DaysLabels[] = $thaiDays[$enDay];
    $last7DaysData[] = $income7DaysMap[$dt] ?? 0;
}

// ข้อมูลสำหรับ Chart รายได้ตามช่วงเวลา
$chartLabels = [];
$chartData = [];

if (in_array($period, ['1m', '3m', '6m', '1y', 'all'])) {
    $stmtChart = $pdo->prepare("
        SELECT DATE_FORMAT(b.booking_date, '%Y-%m') as dt, SUM(s.price) as total
        FROM bookings b JOIN services s ON b.service_id = s.id
        WHERE {$periodWhere} AND b.status = 'completed'
        GROUP BY DATE_FORMAT(b.booking_date, '%Y-%m')
        ORDER BY dt ASC
    ");
    $stmtChart->execute();
    $rawChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);
    foreach($rawChart as $r) {
        $chartLabels[] = 'ด. ' . $r['dt'];
        $chartData[] = (int)$r['total'];
    }
} else {
    // ช่วงเวลาสั้น ๆ
    $stmtChart = $pdo->prepare("
        SELECT DATE(b.booking_date) as dt, SUM(s.price) as total
        FROM bookings b JOIN services s ON b.service_id = s.id
        WHERE {$periodWhere} AND b.status = 'completed'
        GROUP BY DATE(b.booking_date)
        ORDER BY dt ASC
    ");
    $stmtChart->execute();
    $rawChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);
    foreach($rawChart as $r) {
        $enDay = date('D', strtotime($r['dt']));
        $chartLabels[] = $thaiDays[$enDay] . ' ' . date('d/m', strtotime($r['dt']));
        $chartData[] = (int)$r['total'];
    }
}
if(empty($chartLabels)) {
    $chartLabels[] = 'ไม่มีข้อมูล';
    $chartData[] = 0;
}

// บริการยอดฮิต (อิงตามช่วงเวลาที่เลือก)
$stmtTopSvc = $pdo->prepare("
    SELECT s.name, COUNT(b.id) as cnt
    FROM bookings b JOIN services s ON b.service_id = s.id
    WHERE {$periodWhere} AND b.status = 'completed'
    GROUP BY s.id
    ORDER BY cnt DESC LIMIT 6
");
$stmtTopSvc->execute();
$topSvc = $stmtTopSvc->fetchAll(PDO::FETCH_ASSOC);
$topSvcLabels = [];
$topSvcData = [];
$topSvcColors = [];
// ชุดสีที่แตกต่างกันชัดเจน เรียบหรู
$colorPalette = ['#ff9f24', '#14b8a6', '#a855f7', '#ec4899', '#3b82f6', '#facc15'];
foreach($topSvc as $index => $s) {
    if ((int)$s['cnt'] > 0) {
        $topSvcLabels[] = $s['name'];
        $topSvcData[] = (int)$s['cnt'];
        $topSvcColors[] = $colorPalette[$index % count($colorPalette)];
    }
}

// ช่างทำผม (Mock Data)
$technicians = [
    ['id' => 1, 'name' => 'พี่โอ๋', 'initials' => 'อ', 'class' => 't1', 'role' => 'Senior Stylist'],
    ['id' => 2, 'name' => 'น้องมิ้นท์', 'initials' => 'ม', 'class' => 't2', 'role' => 'Color Specialist'],
    ['id' => 3, 'name' => 'พี่เจ', 'initials' => 'จ', 'class' => 't3', 'role' => 'Perm Expert'],
    ['id' => 4, 'name' => 'น้องบีม', 'initials' => 'บ', 'class' => 't4', 'role' => 'Junior Stylist'],
];

// ประเภทบริการ
$serviceTypes = [
    'haircut' => ['name' => 'ตัดผม', 'icon' => '<span class="dot" style="background: var(--accent-1); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'color' => ['name' => 'ทำสีผม', 'icon' => '<span class="dot" style="background: var(--success); display:inline-block; width:10px; height:10px; border-radius:50%;"></span>'],
    'perm' => ['name' => 'ดัดผม', 'icon' => '🌀'],
    'treatment' => ['name' => 'ทรีทเมนต์', 'icon' => '💆'],
    'wash' => ['name' => 'สระผม', 'icon' => '💧'],
];

// นับสถิติสำหรับวันที่เลือก
// removed badge clash
$confirmedCount = 0;
$walkinCount = 0;

// mock loop removed

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
    
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script></head>

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
            <a href="admin_employees.php" class="nav-item">
                <span class="icon"><i class="fas fa-user-tie" style="color: var(--accent-1);"></i></span>
                <span>ช่าง</span>
            </a>
            <a href="admin_reports.php" class="nav-item active">
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
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">☰</button>
                <div class="page-title">
                    <h1><i class="fas fa-chart-line" style="color: var(--success); margin-right: 8px;"></i>รายงานและสถิติ</h1>
                    <p>แสดงข้อมูลช่วง: <?= $periodLabel ?></p>
                </div>
            </div>
            <div class="topbar-right">
                <form action="admin_reports.php" method="GET" style="display: flex; gap: 8px; align-items: center;">
                    <select name="period" class="form-select" style="background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border-glass); padding: 8px 12px; border-radius: var(--radius-sm); font-family: inherit; font-size: 13px; outline: none; cursor: pointer; min-width: 150px;" onchange="this.form.submit()">
                        <option value="1d" <?= $period == '1d' ? 'selected' : '' ?>>1 วัน (วันนี้)</option>
                        <option value="3d" <?= $period == '3d' ? 'selected' : '' ?>>3 วันหลังสุด</option>
                        <option value="7d" <?= $period == '7d' ? 'selected' : '' ?>>7 วันหลังสุด</option>
                        <option value="this_month" <?= $period == 'this_month' ? 'selected' : '' ?>>เดือนนี้</option>
                        <option value="1m" <?= $period == '1m' ? 'selected' : '' ?>>1 เดือน</option>
                        <option value="3m" <?= $period == '3m' ? 'selected' : '' ?>>3 เดือน</option>
                        <option value="6m" <?= $period == '6m' ? 'selected' : '' ?>>6 เดือน</option>
                        <option value="1y" <?= $period == '1y' ? 'selected' : '' ?>>1 ปี</option>
                        <option value="all" <?= $period == 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                    </select>
                </form>
            </div>
        </header>
        <div class="content">
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-coins"></i></div>
                    </div>
                    <div class="stat-value">฿<?= number_format($monthlyIncome) ?></div>
                    <div class="stat-label">รายได้ (<?= $periodLabel ?>)</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($monthlyCust) ?></div>
                    <div class="stat-label">ลูกค้าทั้งหมด (<?= $periodLabel ?>)</div>
                </div>
                <!-- Card จำนวนงานทั้งหมด -->
                <div class="stat-card blue" style="border-top-color: #3b82f6;">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalJobs) ?></div>
                    <div class="stat-label">จำนวนงานทั้งหมด (<?= $periodLabel ?>)</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 24px;">
                <div class="schedule-section">
                    <div class="schedule-header"><h2>รายได้ (<?= $periodLabel ?>)</h2></div>
                    <div style="padding: 24px; position: relative; height: 340px; width: 100%;">
                        <canvas id="incomeChart"></canvas>
                    </div>
                </div>
                <div class="schedule-section">
                    <div class="schedule-header"><h2>บริการยอดฮิต (<?= $periodLabel ?>)</h2></div>
                    <div style="padding: 24px; position: relative; height: 340px; width: 100%; display: flex; align-items: center; justify-content: center;">
                        <canvas id="serviceChart"></canvas>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    const isLightMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
                    Chart.defaults.color = isLightMode ? "#777777" : "#aaaaaa";
                    Chart.defaults.font.family = "'Jost', 'Noto Sans Thai', sans-serif";
                    Chart.defaults.font.size = 13;
                    
                    const gridLineColor = isLightMode ? "rgba(0,0,0,0.06)" : "rgba(255,255,255,0.06)";
                    const tooltipBg = isLightMode ? "rgba(255,255,255,0.95)" : "rgba(28,28,28,0.95)";
                    const tooltipText = isLightMode ? "#333333" : "#f8f4ef";

                    var ctxIncome = document.getElementById("incomeChart").getContext("2d");
                    
                    // Gradient fill for Bar Chart
                    let gradientIncome = ctxIncome.createLinearGradient(0, 0, 0, 340);
                    gradientIncome.addColorStop(0, 'rgba(255, 159, 36, 0.9)');
                    gradientIncome.addColorStop(1, 'rgba(255, 181, 77, 0.4)');

                    new Chart(ctxIncome, {
                        type: "bar",
                        data: {
                            labels: <?= json_encode($chartLabels) ?>,
                            datasets: [{
                                label: "รายได้",
                                data: <?= json_encode($chartData) ?>,
                                backgroundColor: gradientIncome,
                                hoverBackgroundColor: "#ff8c00",
                                borderRadius: 6,
                                borderSkipped: false,
                                barPercentage: 0.55
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: tooltipBg,
                                    titleColor: tooltipText,
                                    bodyColor: tooltipText,
                                    borderColor: 'rgba(255, 159, 36, 0.3)',
                                    borderWidth: 1,
                                    padding: 12,
                                    cornerRadius: 8,
                                    displayColors: false,
                                    callbacks: {
                                        label: function(context) {
                                            let val = context.parsed.y || 0;
                                            return "รายได้: ฿" + new Intl.NumberFormat('th-TH').format(val);
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: { 
                                    beginAtZero: true, 
                                    grid: { color: gridLineColor, drawBorder: false },
                                    ticks: { padding: 12, maxTicksLimit: 6 }
                                },
                                x: { 
                                    grid: { display: false, drawBorder: false },
                                    ticks: { padding: 10 }
                                }
                            },
                            animation: { y: { duration: 1000, easing: 'easeOutQuart' } }
                        }
                    });

                    var ctxSvc = document.getElementById("serviceChart").getContext("2d");
                    new Chart(ctxSvc, {
                        type: "doughnut",
                        data: {
                            labels: <?= json_encode($topSvcLabels, JSON_UNESCAPED_UNICODE) ?>,
                            datasets: [{
                                data: <?= json_encode($topSvcData) ?>,
                                backgroundColor: <?= json_encode($topSvcColors) ?>,
                                borderWidth: 4,
                                borderColor: isLightMode ? '#ffffff' : '#1c1c1c',
                                hoverOffset: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '72%',
                            layout: { padding: 8 },
                            plugins: {
                                legend: { 
                                    position: "bottom",
                                    labels: { padding: 20, usePointStyle: true, pointStyle: 'circle' }
                                },
                                tooltip: {
                                    backgroundColor: tooltipBg,
                                    titleColor: tooltipText,
                                    bodyColor: tooltipText,
                                    borderColor: 'rgba(255, 159, 36, 0.2)',
                                    borderWidth: 1,
                                    padding: 12,
                                    cornerRadius: 8
                                }
                            },
                            animation: { animateScale: true, animateRotate: true }
                        }
                    });
                });
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