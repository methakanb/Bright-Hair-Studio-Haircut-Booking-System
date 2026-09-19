<?php
/**
 * Bright Hair Studio - Admin Dashboard
 * ตารางคิวงานประจำวัน (8:00-17:00) รีเซ็ตทุกสัปดาห์
 * ยังไม่เชื่อม Database - ใช้ข้อมูลจำลอง
 */

// ===== CONFIG & MOCK DATA =====
require_once 'db.php';
date_default_timezone_set('Asia/Bangkok');
$year = date('Y');

// รับวันที่จาก GET parameter (format: Y-m-d) หรือใช้วันนี้
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$highlightUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
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


?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bright Hair Studio | Admin Dashboard</title>
    <meta name="description" content="ระบบจัดการคิวงานสำหรับ Bright Hair Studio - ดูตารางนัดหมายประจำวัน">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap"
        rel="stylesheet">
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

        select option {
            background-color: var(--bg-card);
            color: var(--text-primary);
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
            <a href="admin_customers.php" class="nav-item active">
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
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">☰</button>
                <div class="page-title">
                    <h1><i class="fas fa-users" style="color: var(--accent-1); margin-right: 8px;"></i>ระบบจัดการลูกค้า</h1>
                </div>
            </div>
            <div class="topbar-right">
                <button class="btn btn-primary" onclick="openAddCustomerModal()"><i class="fas fa-user-plus" style="margin-right:6px;"></i>เพิ่มลูกค้า</button>
            </div>
        </header>
        <div class="content">
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-value" id="stat-val-total">0</div>
                    <div class="stat-label">ลูกค้าทั้งหมด</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-check-circle" style="font-size:16px;"></i></div>
                    </div>
                    <div class="stat-value" id="stat-val-online">0</div>
                    <div class="stat-label">สมาชิก</div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-walking" style="font-size:16px;"></i></div>
                    </div>
                    <div class="stat-value" id="stat-val-walkin">0</div>
                    <div class="stat-label">ลูกค้า Walk-in</div>
                </div>
            </div>

            <!-- List + Slide Panel Layout -->
            <div class="schedule-section" style="margin-top: 24px;">
                <div class="schedule-header" style="flex-direction:row; align-items:flex-start; justify-content:space-between;">
                    <div>
                        <h2>รายชื่อลูกค้า</h2>
                        <div style="display:flex; gap:10px; margin-top:12px;">
                            <button id="tab-online" class="btn btn-primary" style="font-size:12px; padding:6px 12px; border-radius:16px;" onclick="switchTab('online')">สมาชิก</button>
                            <button id="tab-walkin" class="btn btn-outline" style="font-size:12px; padding:6px 12px; border-radius:16px;" onclick="switchTab('walkin')">ลูกค้า Walk-in</button>
                        </div>
                    </div>
                    <input type="text" class="form-input" placeholder="ค้นหาลูกค้า..." onkeyup="filterCustomers(this.value)" style="width: 250px;">
                </div>

                <style>
                    /* ===== CUSTOMER LIST ===== */
                    .customer-layout { display: flex; gap: 0; min-height: 400px; }
                    .customer-list { flex: 1; border-right: 1px solid var(--border-glass); }
                    .customer-list-item {
                        display: flex; align-items: center; gap: 16px;
                        padding: 16px 24px;
                        border-bottom: 1px solid rgba(255,255,255,0.04);
                        cursor: pointer;
                        transition: background 0.15s ease;
                    }
                    .customer-list-item:hover { background: rgba(255,159,36,0.05); }
                    .customer-list-item.active { background: rgba(255,159,36,0.08); border-left: 3px solid var(--accent-1); }
                    .customer-list-item.active .cli-name { color: var(--accent-1); }
                    .cli-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-glass); flex-shrink: 0; }
                    .cli-info { flex: 1; }
                    .cli-name { font-size: 15px; font-weight: 600; color: var(--text-primary); }
                    .cli-visits { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
                    .cli-arrow { color: var(--text-muted); font-size: 12px; }

                    /* ===== PROFILE PANEL ===== */
                    .profile-panel {
                        width: 340px; flex-shrink: 0;
                        padding: 28px 24px;
                        background: var(--bg-secondary);
                        display: none;
                        animation: slideInRight 0.2s ease forwards;
                    }
                    .profile-panel.visible { display: block; }
                    .profile-top { display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: 24px; }
                    .profile-avatar { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-1); margin-bottom: 14px; }
                    .profile-name { font-size: 20px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
                    .profile-hist-title { font-size: 12px; font-weight: 600; color: var(--accent-1); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--border-glass); }
                    .profile-hist-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 12px; background: rgba(0,0,0,0.2); border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.04); margin-bottom: 10px; }
                    .phi-service { font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
                    .phi-tech { font-size: 11px; color: var(--text-muted); }
                    .phi-date { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
                    @keyframes slideInRight { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

                    /* ===== TIER BADGES ===== */
                    .tier-badge {
                        display: inline-block;
                        padding: 3px 8px;
                        border-radius: 4px;
                        font-size: 9px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                        margin-left: 8px;
                        vertical-align: middle;
                        line-height: 1;
                    }
                    .tier-member { background: rgba(255,255,255,0.06); color: #999; border: 1px solid rgba(255,255,255,0.15); }
                    .tier-silver { background: rgba(192,192,192,0.12); color: #c0c0c0; border: 1px solid rgba(192,192,192,0.25); }
                    .tier-gold { background: rgba(255,184,74,0.12); color: #ffb84a; border: 1px solid rgba(255,184,74,0.3); }
                    .tier-platinum { background: rgba(229,228,226,0.12); color: #e5e4e2; border: 1px solid rgba(229,228,226,0.3); }
                </style>

                <div class="customer-layout">
                    <!-- LIST -->
                    <div class="customer-list" id="customerList">
                        <?php
                        require_once 'db.php';
                        $stmt = $pdo->query("
                            SELECT 
                                u.id, u.first_name, u.last_name, u.member_tier as tier, u.phone, u.email, u.total_spend, u.note
                            FROM users u
                        ");
                        $users = $stmt->fetchAll();

                        $mockCustomers = [];
                        $colors = ['ff9f24', '22c55e', '60a5fa', 'ef4444'];
                        foreach ($users as $idx => $u) {
                            $histStmt = $pdo->prepare("
                                SELECT b.booking_date, s.name as service, e.name as tech, s.price
                                FROM bookings b
                                LEFT JOIN services s ON b.service_id = s.id
                                LEFT JOIN employees e ON b.employee_id = e.id
                                WHERE b.user_id = ? AND b.status = 'completed'
                                ORDER BY b.booking_date DESC
                            ");
                            $histStmt->execute([$u['id']]);
                            $hist = $histStmt->fetchAll();

                            $total_spend_calculated = 0;
                            $history = [];
                            foreach ($hist as $h) {
                                $p = (float)($h['price'] ?? 0);
                                $total_spend_calculated += $p;
                                $history[] = [
                                    "date" => (int)date('d', strtotime($h['booking_date'])) . ' ' . $GLOBALS['thaiMonths'][(int)date('n', strtotime($h['booking_date']))] . ' ' . ((int)date('Y', strtotime($h['booking_date'])) + 543),
                                    "service" => $h['service'] ?? '-',
                                    "tech" => $h['tech'] ?? '-',
                                    "price" => $p
                                ];
                            }

                            $color = $colors[$idx % 4];
                            $mockCustomers[] = [
                                "id" => $u['id'],
                                "name" => ($u['first_name'] . ' ' . $u['last_name']),
                                "tier" => !empty($u['tier']) ? $u['tier'] : 'Member',
                                "phone" => $u['phone'] ?? '-',
                                "email" => $u['email'] ?? '-',
                                "total_spend" => $total_spend_calculated,
                                "img" => "https://ui-avatars.com/api/?name=" . urlencode($u['first_name']) . "&background=1c1c1c&color={$color}&size=128",
                                "note" => $u['note'] ?? '',
                                "history" => $history
                            ];
                        }
                        ?>
                    </div>

                    <!-- PROFILE PANEL -->
                    <div class="profile-panel" id="profilePanel">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                            <span style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;">โปรไฟล์ลูกค้า</span>
                            <button onclick="openEditCustomerModal()" style="background:rgba(255,159,36,0.08); border:1px solid rgba(255,159,36,0.2); color:var(--accent-1); padding:4px 12px; border-radius:6px; font-size:12px; cursor:pointer;"><i class="fas fa-edit" style="margin-right:4px;"></i>แก้ไข</button>
                        </div>
                        <div class="profile-top">
                            <img id="pp-avatar" src="" alt="avatar" class="profile-avatar">
                            <div class="profile-name" id="pp-name"></div>
                        </div>
                        <!-- Contact + spend info -->
                        <div id="pp-contact" style="margin-bottom:14px; display:flex; flex-direction:column; gap:6px;"></div>
                        <div id="pp-note-box"></div>
                        <div class="profile-hist-title">ประวัติรับบริการ</div>
                        <div id="pp-history"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ===== TOAST CONTAINER ===== -->
    <div class="toast-container" id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

    <!-- ===== ADD CUSTOMER MODAL ===== -->
    <div class="modal-overlay" id="addCustomerModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color:var(--accent-1);margin-right:8px;"></i>เพิ่มลูกค้าใหม่</h3>
                <button class="modal-close" onclick="closeModal('addCustomerModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">ชื่อ-นามสกุล</label>
                    <input type="text" class="form-input" id="new-cust-name" placeholder="เช่น คุณสมหญิง ดีมาก">
                </div>
                <div class="form-group">
                    <label class="form-label">ระดับสมาชิก (Tier)</label>
                    <select class="form-input" id="new-cust-tier" style="background:var(--input-bg); color:var(--text-primary); border:1px solid var(--input-border);">
                        <option value="Bronze">Bronze</option>
                        <option value="Member">Member</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-input" id="new-cust-phone" placeholder="0xx-xxx-xxxx">
                </div>
                <div class="form-group">
                    <label class="form-label">โน้ต / แพ้สารเคมี <span style="color:var(--danger);">(ถ้ามี)</span></label>
                    <textarea class="form-input" id="new-cust-note" rows="3" placeholder="เช่น แพ้น้ำยาไฮดรอก, ผมบาง ระวังแรงดึง..." style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('addCustomerModal')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" onclick="saveNewCustomer()"><i class="fas fa-check" style="margin-right:6px;"></i>บันทึก</button>
            </div>
        </div>
    </div>

    <!-- ===== EDIT CUSTOMER MODAL ===== -->
    <div class="modal-overlay" id="editCustomerModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color:var(--accent-1);margin-right:8px;"></i>แก้ไขข้อมูลลูกค้า</h3>
                <button class="modal-close" onclick="closeModal('editCustomerModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">ชื่อ-นามสกุล</label>
                    <input type="text" class="form-input" id="edit-cust-name">
                </div>
                <div class="form-group">
                    <label class="form-label">ระดับสมาชิก (Tier)</label>
                    <select class="form-input" id="edit-cust-tier" style="background:var(--input-bg); color:var(--text-primary); border:1px solid var(--input-border);">
                        <option value="Bronze">Bronze</option>
                        <option value="Member">Member</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-input" id="edit-cust-phone">
                </div>
                <div class="form-group">
                    <label class="form-label">โน้ต / แพ้สารเคมี</label>
                    <textarea class="form-input" id="edit-cust-note" rows="3" style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('editCustomerModal')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" onclick="saveEditCustomer()"><i class="fas fa-check" style="margin-right:6px;"></i>บันทึก</button>
            </div>
        </div>
    </div>

    <script>
        // ===== Customer Data =====
        const customers = <?= json_encode(array_values(array_map(function($c, $idx) {
            return [
                 'originalIdx' => $idx,
                 'id' => $c['id'], 'name' => $c['name'], 'tier' => $c['tier'], 'phone' => $c['phone'], 'email' => $c['email'],
                 'total_spend' => $c['total_spend'], 'img' => $c['img'],
                 'note' => $c['note'], 'history' => $c['history']
            ];
        }, $mockCustomers, array_keys($mockCustomers))), JSON_UNESCAPED_UNICODE) ?>;

        const highlightUserId = <?= $highlightUserId ?>;

        let activeIdx = null;

        function toggleSidebar() { document.getElementById("sidebar").classList.toggle("open"); }
        document.addEventListener("click", function(e) {
            const sidebar = document.getElementById("sidebar");
            const toggle  = document.getElementById("menuToggle");
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains("open") && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove("open");
            }
        });

        // ===== Pagination and Tabs =====
        let currentTab = 'online';
        let currentPage = 1;
        const itemsPerPage = 8;
        let filteredCustomers = [];
        let searchQuery = '';

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-online').className = (tab === 'online') ? 'btn btn-primary' : 'btn btn-outline';
            document.getElementById('tab-walkin').className = (tab === 'walkin') ? 'btn btn-primary' : 'btn btn-outline';
            currentPage = 1;
            applyFilters();
        }

        function filterCustomers(query) {
            searchQuery = query.toLowerCase();
            currentPage = 1;
            applyFilters();
        }

        function applyFilters() {
            filteredCustomers = customers.filter(c => {
                const isWalkin = (c.email && (c.email.includes("walkin_") || c.email.includes("@brighthair.local")));
                if (currentTab === 'online' && isWalkin) return false;
                if (currentTab === 'walkin' && !isWalkin) return false;

                if (searchQuery && !c.name.toLowerCase().includes(searchQuery)) return false;
                return true;
            });
            renderList();
        }

        // Initial render logic
        function initRender() {
            // Count total online vs walkin
            let countOnline = 0;
            let countWalkin = 0;
            customers.forEach(c => {
                const isWt = (c.email && (c.email.includes("walkin_") || c.email.includes("@brighthair.local")));
                if (isWt) countWalkin++;
                else countOnline++;
            });
            document.getElementById('stat-val-online').textContent = countOnline;
            document.getElementById('stat-val-walkin').textContent = countWalkin;
            document.getElementById('stat-val-total').textContent = customers.length;

            renderList();

            // Auto-select customer from URL parameter (?user_id=)
            if (highlightUserId) {
                const match = customers.find(c => c.id == highlightUserId);
                if (match) {
                    const isWalkin = match.email && (match.email.includes('walkin_') || match.email.includes('@brighthair.local'));
                    if (isWalkin && currentTab !== 'walkin') switchTab('walkin');
                    else if (!isWalkin && currentTab !== 'online') switchTab('online');
                    else applyFilters();

                    const idxInFiltered = filteredCustomers.findIndex(c => c.id == highlightUserId);
                    if (idxInFiltered >= 0) {
                        currentPage = Math.ceil((idxInFiltered + 1) / itemsPerPage);
                        renderList();
                    }
                    selectCustomer(match.originalIdx);
                    setTimeout(() => {
                        const el = document.getElementById('cli-' + match.originalIdx);
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 150);
                }
            }
        }
        initRender();

        function renderList() {
            const listDiv = document.getElementById('customerList');
            if (filteredCustomers.length === 0) {
                listDiv.innerHTML = '<div style="padding:40px; text-align:center; color:var(--text-muted);">ไม่พบข้อมูลลูกค้า</div>';
                return;
            }

            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageItems = filteredCustomers.slice(startIndex, endIndex);

            let html = '';
            pageItems.forEach(c => {
                const isActive = (activeIdx === c.originalIdx) ? 'active' : '';
                const noteIcon = c.note ? ' <span style="color:var(--danger);font-size:10px;"><i class="fas fa-exclamation-triangle"></i> มีโน้ต</span>' : '';
                html += `
                    <div class="customer-list-item ${isActive}" id="cli-${c.originalIdx}" onclick="selectCustomer(${c.originalIdx})">
                        <img src="${c.img}" alt="avatar" class="cli-avatar">
                        <div class="cli-info">
                            <div class="cli-name">${c.name} <span class="tier-badge tier-${c.tier.toLowerCase()}">${c.tier}</span></div>
                            <div class="cli-visits">${c.history.length} ครั้ง &nbsp;·&nbsp; ฿${c.total_spend.toLocaleString()}${noteIcon}</div>
                        </div>
                        <i class="fas fa-chevron-right cli-arrow"></i>
                    </div>
                `;
            });

            // Pagination Controls
            const totalPages = Math.ceil(filteredCustomers.length / itemsPerPage);
            if (totalPages > 1) {
                html += `<div style="display:flex; justify-content:center; align-items:center; gap:16px; padding:20px; border-top:1px solid rgba(255,255,255,0.05);">
                    <button class="btn btn-outline" style="padding:6px 12px; font-size:12px; border-radius:6px;" onclick="changePage(-1)" ${currentPage === 1 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''}><i class="fas fa-chevron-left" style="margin-right:4px;"></i> ก่อนหน้า</button>
                    <span style="font-size:13px; font-weight:600; color:var(--text-primary);">หน้า ${currentPage} / ${totalPages}</span>
                    <button class="btn btn-outline" style="padding:6px 12px; font-size:12px; border-radius:6px;" onclick="changePage(1)" ${currentPage === totalPages ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''}>ถัดไป <i class="fas fa-chevron-right" style="margin-left:4px;"></i></button>
                </div>`;
            }

            listDiv.innerHTML = html;
        }

        function changePage(delta) {
            currentPage += delta;
            renderList();
        }

        // Initialize view on load
        document.addEventListener("DOMContentLoaded", () => {
            applyFilters();
        });

        // ===== Select Customer =====
        function selectCustomer(idx) {
            activeIdx = idx;
            document.querySelectorAll(".customer-list-item").forEach(el => el.classList.remove("active"));
            const row = document.getElementById("cli-" + idx);
            row.classList.add("active");

            const c = customers[idx];
            document.getElementById("pp-name").innerHTML = c.name + ` <span class="tier-badge tier-${c.tier.toLowerCase()}">${c.tier}</span>`;
            document.getElementById("pp-avatar").src       = c.img;

            // Contact & spend row
            const contact = document.getElementById("pp-contact");
            let contactHtml = '';
            if (c.phone) contactHtml += `<div style="font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:8px;"><i class="fas fa-phone" style="color:var(--accent-1);font-size:11px;width:14px;"></i>${c.phone}</div>`;
            if (c.email) contactHtml += `<div style="font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:8px;"><i class="fas fa-envelope" style="color:var(--accent-1);font-size:11px;width:14px;"></i>${c.email}</div>`;
            if (c.total_spend) contactHtml += `<div style="font-size:13px;display:flex;align-items:center;gap:8px;"><i class="fas fa-coins" style="color:var(--accent-1);font-size:11px;width:14px;"></i><span style="color:var(--text-muted);">ยอดสะสม</span><span style="font-weight:700;color:var(--accent-1);">฿${c.total_spend.toLocaleString()}</span></div>`;
            contact.innerHTML = contactHtml;

            // Allergy / note box
            const noteBox = document.getElementById("pp-note-box");
            if (c.note) {
                noteBox.innerHTML = `<div style="background:rgba(248,113,113,0.08); border:1px solid rgba(248,113,113,0.25); border-left:3px solid var(--danger); border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px;">
                    <i class="fas fa-exclamation-triangle" style="color:var(--danger);margin-top:2px;flex-shrink:0;"></i>
                    <div><div style="font-size:11px;font-weight:700;color:var(--danger);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">โน้ต / ข้อควรระวัง</div>
                    <div style="font-size:13px;color:var(--text-secondary);">${c.note}</div></div>
                </div>`;
            } else {
                noteBox.innerHTML = '';
            }

            const histDiv = document.getElementById("pp-history");
            const totalSpend = c.history.reduce((s, h) => s + (h.price || 0), 0);
            histDiv.innerHTML = c.history.map(h => `
                <div class="profile-hist-item">
                    <div>
                        <div class="phi-service">${h.service}</div>
                        <div class="phi-tech"><i class="fas fa-user-tie" style="color:var(--accent-1);font-size:10px;margin-right:4px;"></i>${h.tech}</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; color:var(--accent-1); font-size:13px;">${h.price ? '฿' + h.price.toLocaleString() : ''}</div>
                        <div class="phi-date">${h.date}</div>
                    </div>
                </div>`).join("");

            const panel = document.getElementById("profilePanel");
            panel.classList.remove("visible");
            void panel.offsetWidth;
            panel.classList.add("visible");
        }

        // ===== Modals =====
        function openAddCustomerModal() {
            document.getElementById('new-cust-name').value  = '';
            document.getElementById('new-cust-tier').value  = 'Member';
            document.getElementById('new-cust-phone').value = '';
            document.getElementById('new-cust-note').value  = '';
            document.getElementById('addCustomerModal').classList.add('active');
        }

        function openEditCustomerModal() {
            if (activeIdx === null) return;
            const c = customers[activeIdx];
            document.getElementById('edit-cust-name').value  = c.name;
            document.getElementById('edit-cust-tier').value  = c.tier || 'Member';
            document.getElementById('edit-cust-phone').value = c.phone || '';
            document.getElementById('edit-cust-note').value  = c.note || '';
            document.getElementById('editCustomerModal').classList.add('active');
        }

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        async function saveNewCustomer() {
            const name  = document.getElementById('new-cust-name').value.trim();
            const tier  = document.getElementById('new-cust-tier').value;
            const phone = document.getElementById('new-cust-phone').value.trim();
            const note  = document.getElementById('new-cust-note').value.trim();
            if (!name) { showToast('กรุณากรอกชื่อลูกค้า', 'error'); return; }
            
            const btn = document.querySelector('#addCustomerModal .btn-primary');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>กำลังบันทึก...';

            try {
                const res = await fetch('api_customer_manage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', name, tier, phone, note })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`เพิ่มลูกค้า "${name}" เรียบร้อย หน้าต่างจะรีเฟรชในอีก 12 วินาที`, 'success');
                    closeModal('addCustomerModal');
                    setTimeout(() => { location.reload(); }, 12000); // Wait 12 seconds to prevent rapid loop
                } else {
                    showToast('ผิดพลาด: ' + (data.message || 'Unknown'), 'error');
                }
            } catch (err) {
                showToast('ผิดพลาดในการเชื่อมต่อ', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        async function saveEditCustomer() {
            if (activeIdx === null) return;
            const c = customers[activeIdx];
            
            const name  = document.getElementById('edit-cust-name').value.trim();
            const tier  = document.getElementById('edit-cust-tier').value;
            const phone = document.getElementById('edit-cust-phone').value.trim();
            const note  = document.getElementById('edit-cust-note').value.trim();

            if (!name) { showToast('กรุณากรอกชื่อลูกค้า', 'error'); return; }

            const btn = document.querySelector('#editCustomerModal .btn-primary');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>กำลังบันทึก...';

            try {
                const res = await fetch('api_customer_manage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'edit', id: c.id, name, tier, phone, note })
                });
                const data = await res.json();
                
                if (data.success) {
                    c.name = name;
                    c.tier = tier;
                    c.phone = phone;
                    c.note = note;
                    showToast('บันทึกข้อมูลลูกค้าเรียบร้อย', 'success');
                    closeModal('editCustomerModal');
                    
                    // Update DOM gracefully instead of reloading
                    const el = document.getElementById('cli-' + activeIdx);
                    if (el) {
                        el.querySelector('.cli-name').innerHTML = c.name + ` <span class="tier-badge tier-${c.tier.toLowerCase()}">${c.tier}</span>`;
                        const vStr = el.querySelector('.cli-visits').innerHTML.split('฿')[0]; 
                        const totalSpend = c.total_spend ? c.total_spend.toLocaleString() : '0';
                        const noteIcon = c.note ? ' <span style="color:var(--danger);font-size:10px;"><i class="fas fa-exclamation-triangle"></i> มีโน้ต</span>' : '';
                        el.querySelector('.cli-visits').innerHTML = vStr + '฿' + totalSpend + noteIcon;
                    }
                    selectCustomer(activeIdx); // Refresh panel safely
                } else {
                    showToast('ผิดพลาด: ' + (data.message || 'Unknown'), 'error');
                }
            } catch (err) {
                showToast('ผิดพลาดในการเชื่อมต่อ', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        // Close modals on backdrop click
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); });
        });

        function showToast(message, type = "info") {
            const container = document.getElementById("toastContainer");
            const toast = document.createElement("div");
            const icons = { success: "<i class='fas fa-check-circle' style='color:var(--success);'></i>", error: "<i class='fas fa-times-circle' style='color:var(--danger);'></i>", info: "<i class='fas fa-info-circle' style='color:var(--info);'></i>" };
            const styleMap = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--info)' };
            toast.style.cssText = `background: var(--bg-card); border: 1px solid ${styleMap[type] || 'var(--border-glass)'}33; padding: 14px 20px; border-radius: 10px; font-size: 13px; color: var(--text-primary); display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.4);`;
            toast.innerHTML = `<span>${icons[type] || ""}</span><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3000);
        }

        const style = document.createElement("style");
        style.innerHTML = `@keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }`;
        document.head.appendChild(style);
    </script>
</body>
</html>