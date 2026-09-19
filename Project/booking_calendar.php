<?php
date_default_timezone_set('Asia/Bangkok');
session_start();
if(!isset($_SESSION['user'])){
    header("Location: customer_login.php");
    exit();
}

require 'db.php';
$user_id = $_SESSION['user_id'] ?? 0;

// ===== USER INFO =====
$stmtUser = $pdo->prepare("
    SELECT first_name, last_name, email, member_tier
    FROM users
    WHERE id = ?
");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch();

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$email    = $user['email'] ?? '';
$tier     = $user['member_tier'] ?? 'Member';

// Fetch DB Services
try {
    $stmtSvc = $pdo->query("SELECT * FROM services WHERE active = 1 ORDER BY id ASC");
    $dbServices = $stmtSvc->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbServices = [];
}

// Fetch DB Employees — ดึงทุกคน แล้วคำนวณสถานะจาก leave_requests
try {
    $stmtEmp = $pdo->query("SELECT id, name, role, skills FROM employees ORDER BY id ASC");
    $dbEmployees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbEmployees = [];
}

// ── ดึงวันลาที่ approved ของช่างแต่ละคน (60 วันข้างหน้า) ──
$leaveDatesMap   = [];  // map: emp_id → ["YYYY-MM-DD", ...]
$onLeaveTodaySet = [];  // emp_id ที่กำลังลาวันนี้
try {
    $today30 = date('Y-m-d');
    $max30   = date('Y-m-d', strtotime('+60 days'));
    $stmtLeave = $pdo->prepare("
        SELECT employee_id, leave_date_start, leave_date_end
        FROM leave_requests
        WHERE status = 'approved'
          AND leave_date_end >= ?
          AND leave_date_start <= ?
    ");
    $stmtLeave->execute([$today30, $max30]);
    foreach ($stmtLeave->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $eid   = (int)$lr['employee_id'];
        $start = new DateTime($lr['leave_date_start']);
        $end   = new DateTime($lr['leave_date_end']);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($start, $interval, $end);
        foreach ($period as $dt) {
            $dayStr = $dt->format('Y-m-d');
            $leaveDatesMap[$eid][] = $dayStr;
            if ($dayStr === $today30) {
                $onLeaveTodaySet[$eid] = true;
            }
        }
    }
} catch (PDOException $e) {
    $leaveDatesMap   = [];
    $onLeaveTodaySet = [];
}

$preService = $_GET['service'] ?? '';

// ส่งข้อมูลวันลาไป JS
$leaveDatesMapJson = json_encode($leaveDatesMap, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Appointment - Bright Hair Studio</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ===== DARK MODE (default) ===== */
:root {
    --gold: #ff9f24;
    --gold-light: #ffb84a;
    --black: #0d0d0d;
    --dark: #141414;
    --dark2: #1c1c1c;
    --dark3: #242424;
    --white: #f8f4ef;
    --muted: #888;
    --border: rgba(255,159,36,0.25);
    --dropdown-border-item: rgba(255,255,255,0.04);
    --logo-filter: none;
    --footer-bottom-color: #444;
    --card-bg: #1c1c1c;
    --input-bg: #1c1c1c;
    --input-border: rgba(255,159,36,0.2);
    --input-focus: rgba(255,159,36,0.5);
    --day-bg: #242424;
    --day-hover: rgba(255,159,36,0.15);
    --day-past: #181818;
    --day-past-text: #333;
    --day-selected-text: #0d0d0d;
    --step-inactive: #242424;
    --divider: rgba(255,255,255,0.06);
    --summary-bg: #141414;
}

@media (prefers-color-scheme: light) {
    :root {
        --gold: #ff9f24;
        --gold-light: #ffb84a;
        --black: #ffffff;
        --dark: #f5f5f5;
        --dark2: #efefef;
        --dark3: #e4e4e4;
        --white: #111111;
        --muted: #777;
        --border: rgba(255,159,36,0.3);
        --dropdown-border-item: rgba(0,0,0,0.05);
        --logo-filter: none;
        --footer-bottom-color: #bbb;
        --card-bg: #f5f5f5;
        --input-bg: #ffffff;
        --input-border: rgba(255,159,36,0.3);
        --input-focus: rgba(255,159,36,0.5);
        --day-bg: #ebebeb;
        --day-hover: rgba(255,159,36,0.18);
        --day-past: #f0f0f0;
        --day-past-text: #ccc;
        --day-selected-text: #ffffff;
        --step-inactive: #e8e8e8;
        --divider: rgba(0,0,0,0.07);
        --summary-bg: #f0f0f0;
    }
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Jost', sans-serif;
    background: var(--black);
    color: var(--white);
    overflow-x: hidden;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ===== ANNOUNCEMENT BAR ===== */
.announcement-bar {
    background: var(--gold);
    color: var(--black);
    text-align: center;
    padding: 8px 20px;
    font-size: 11px;
    letter-spacing: 2px;
    font-weight: 600;
    text-transform: uppercase;
}

/* ===== HEADER ===== */
.header {
    position: sticky;
    top: 0;
    z-index: 200;
    background: var(--black);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 40px;
    height: 70px;
}
.header-logo img { height: 36px; object-fit: contain; filter: var(--logo-filter); }
.header-nav { display: flex; align-items: center; height: 100%; }
.nav-tab {
    display: flex; align-items: center; gap: 7px;
    height: 100%; padding: 0 22px;
    color: var(--muted); font-size: 11px; letter-spacing: 1.5px;
    text-transform: uppercase; font-weight: 500; text-decoration: none;
    border-bottom: 2px solid transparent; transition: all 0.2s; white-space: nowrap;
}
.nav-tab:hover, .nav-tab.active { color: var(--white); border-bottom-color: var(--gold); }
.header-right { display: flex; align-items: center; gap: 8px; }
.btn-book-header {
    background: var(--gold); color: var(--black); border: none;
    padding: 10px 24px; font-size: 11px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    text-decoration: none; transition: background 0.2s;
    display: flex; align-items: center; gap: 8px;
}
.btn-book-header:hover { background: var(--gold-light); }
.profile-wrapper { position: relative; }
.btn-profile {
    width: 40px; height: 40px; background: var(--dark3);
    border: 1px solid var(--border); color: var(--gold); font-size: 16px;
    cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;
}
.btn-profile:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }
.profile-dropdown {
    position: absolute; top: calc(100% + 12px); right: 0; width: 240px;
    background: var(--dark2); border: 1px solid var(--border); z-index: 300;
    display: none; animation: fadeDown 0.2s ease;
}
@keyframes fadeDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.profile-wrapper.open .profile-dropdown { display: block; }
.dropdown-header { padding: 18px 20px 14px; border-bottom: 1px solid var(--border); }
.dropdown-header .name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 18px; font-weight: 600; color: var(--white); letter-spacing: 0.5px;
}
.dropdown-header .email { font-size: 11px; color: var(--muted); margin-top: 2px; }
.dropdown-item {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 20px; font-size: 12px; letter-spacing: 1px; text-transform: uppercase;
    color: var(--muted); text-decoration: none; transition: all 0.2s;
    border-bottom: 1px solid var(--dropdown-border-item);
}
.dropdown-item i { width: 16px; color: var(--gold); font-size: 13px; }
.dropdown-item:hover { background: var(--dark3); color: var(--white); }
.dropdown-item.logout { color: #cc5555; }
.dropdown-item.logout i { color: #cc5555; }

/* ===== PAGE LAYOUT ===== */
.page-wrap {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 0;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: 56px 40px 80px;
    align-items: start;
}

/* ===== PAGE HEADER ===== */
.page-eyebrow {
    font-size: 10px; letter-spacing: 4px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px;
    display: flex; align-items: center; gap: 12px;
}
.page-eyebrow::before { content: ''; display: block; width: 30px; height: 1px; background: var(--gold); }
.page-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 44px; font-weight: 300; color: var(--white);
    line-height: 1; margin-bottom: 40px;
}
.page-title em { font-style: italic; color: var(--gold); }

/* ===== STEP INDICATOR ===== */
.steps {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 40px;
}
.step {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    position: relative;
}
.step-num {
    width: 32px; height: 32px;
    background: var(--step-inactive);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600;
    color: var(--muted);
    flex-shrink: 0;
    transition: all 0.3s;
}
.step.active .step-num {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
}
.step.done .step-num {
    background: rgba(255,159,36,0.15);
    border-color: var(--gold);
    color: var(--gold);
}
.step-label {
    font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted); transition: color 0.3s;
}
.step.active .step-label { color: var(--white); }
.step.done .step-label { color: var(--gold); }
.step-line {
    flex: 1; height: 1px;
    background: var(--border);
    margin: 0 12px;
}

/* ===== SECTION LABEL ===== */
.form-section-label {
    font-size: 10px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
}
.form-section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ===== SERVICE SELECT ===== */
.service-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    background: var(--border);
    margin-bottom: 32px;
}
.service-opt {
    background: var(--card-bg);
    padding: 16px 14px;
    cursor: pointer;
    transition: background 0.2s;
    border: 2px solid transparent;
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
}
.service-opt:hover { background: var(--dark3); }
.service-opt.selected {
    border: 2px solid var(--gold);
    background: rgba(255,159,36,0.06);
}
.service-opt .opt-id { font-size: 9px; letter-spacing: 2px; color: var(--gold); text-transform: uppercase; }
.service-opt .opt-name { font-size: 13px; font-weight: 500; color: var(--white); }
.service-opt .opt-price { font-size: 11px; color: var(--muted); font-weight: 300; }
.service-opt .opt-dur {
    font-size: 10px; color: var(--muted);
    display: flex; align-items: center; gap: 4px;
}
.service-opt .opt-dur i { color: var(--gold); font-size: 9px; }
.service-opt .check {
    position: absolute; top: 8px; right: 8px;
    width: 16px; height: 16px;
    background: var(--gold);
    display: none;
    align-items: center; justify-content: center;
    font-size: 9px; color: var(--black);
}
.service-opt.selected .check { display: flex; }

/* ===== STYLIST SELECT ===== */
.stylist-options {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1px;
    background: var(--border);
    margin-bottom: 32px;
}
.stylist-opt {
    background: var(--card-bg);
    padding: 14px 10px;
    cursor: pointer;
    transition: background 0.2s;
    border: 2px solid transparent;
    text-align: center;
}
.stylist-opt:hover { background: var(--dark3); }
.stylist-opt.selected {
    border: 2px solid var(--gold);
    background: rgba(255,159,36,0.06);
}
.stylist-avatar {
    width: 34px; height: 34px;
    background: var(--dark3);
    border: 1px solid var(--border);
    border-radius: 0;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 8px;
    font-size: 14px; color: var(--muted);
    transition: all 0.2s;
}
.stylist-opt.selected .stylist-avatar {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
}
.stylist-name {
    font-size: 12px; font-weight: 500; color: var(--white); margin-bottom: 5px;
}
.stylist-role-badge {
    display: inline-block;
    font-size: 8px;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    background: rgba(255,159,36,0.10);
    color: var(--gold);
    border: 1px solid rgba(255,159,36,0.28);
    padding: 2px 7px;
    margin-bottom: 5px;
    line-height: 1.6;
    white-space: nowrap;
}
.stylist-opt.selected .stylist-role-badge {
    background: rgba(255,159,36,0.22);
    border-color: var(--gold);
}
.stylist-skills {
    font-size: 9px;
    color: var(--muted);
    letter-spacing: 0.3px;
    line-height: 1.4;
}
.stylist-opt.stylist-hidden { display: none; }
.stylist-opt.on-leave {
    cursor: not-allowed;
    opacity: 0.55;
    border: 2px solid rgba(248,113,113,0.3);
    background: rgba(248,113,113,0.04);
    pointer-events: none;
}
.stylist-opt.on-leave .stylist-avatar {
    background: rgba(248,113,113,0.15);
    border-color: rgba(248,113,113,0.3);
    color: rgba(248,113,113,0.7);
}
.stylist-opt.on-leave .stylist-name {
    color: rgba(248,113,113,0.8);
}
.stylist-leave-badge {
    display: inline-block;
    font-size: 8px;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    background: rgba(248,113,113,0.15);
    color: #f87171;
    border: 1px solid rgba(248,113,113,0.35);
    padding: 2px 7px;
    margin-bottom: 5px;
    line-height: 1.6;
    white-space: nowrap;
}
.stylist-filter-note {
    font-size: 11px; color: var(--gold); margin-bottom: 10px;
    display: none; align-items: center; gap: 6px;
}
.stylist-filter-note.visible { display: flex; }

/* ===== CALENDAR ===== */
.calendar-wrap { margin-bottom: 32px; }
.cal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
}
.cal-month {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px; font-weight: 400; color: var(--white);
}
.cal-nav {
    width: 34px; height: 34px; background: var(--dark3);
    border: 1px solid var(--border); color: var(--muted);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 12px; transition: all 0.2s;
}
.cal-nav:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }
.cal-nav[disabled], .cal-nav.hidden { visibility: hidden; }
.weekdays-row {
    display: grid; grid-template-columns: repeat(7,1fr); margin-bottom: 8px; text-align: center;
}
.weekdays-row span {
    font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); padding: 6px 0;
}
.weekdays-row span:first-child { color: #cc5555; }
.weekdays-row span:last-child { color: #5588cc; }
.calendar-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 4px; }
.cal-day {
    aspect-ratio: 1; background: var(--day-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; cursor: pointer; transition: background 0.15s; position: relative;
}
.cal-day:hover { background: var(--day-hover); }
.cal-day.past { background: var(--day-past); color: var(--day-past-text); cursor: not-allowed; }
.cal-day.on-leave { background: rgba(248,113,113,0.12); color: rgba(248,113,113,0.5); cursor: not-allowed; position: relative; }
.cal-day.on-leave::before { content: '✕'; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); font-size: 10px; color: rgba(248,113,113,0.6); pointer-events: none; }
.cal-day.selected { background: var(--gold); color: var(--day-selected-text); font-weight: 600; }
.cal-day.today::after {
    content: ''; position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%);
    width: 4px; height: 4px; background: var(--gold); border-radius: 50%;
}
.cal-day.selected.today::after { background: var(--black); }
.cal-empty { aspect-ratio: 1; }

/* ===== TIME GRID ===== */
.time-grid-wrap { position: relative; min-height: 60px; }

/* Loading overlay สำหรับ time grid */
.time-loading {
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(13,13,13,0.65);
    z-index: 10;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 12px;
    color: var(--gold);
    letter-spacing: 1px;
}
.time-loading.show { display: flex; }
.time-loading i { font-size: 16px; }

.time-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin-bottom: 16px;
}
.time-slot {
    background: var(--card-bg);
    border: 1px solid var(--border);
    padding: 11px 6px;
    text-align: center;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--white);
    position: relative;
    line-height: 1.3;
}
.time-slot:hover:not(.disabled):not(.no-space):not(.buffer):not(.booked) {
    border-color: var(--gold);
    color: var(--gold);
}
.time-slot.selected {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
    font-weight: 700;
}
.time-slot.buffer {
    background: rgba(255,159,36,0.04);
    border-color: rgba(255,159,36,0.12);
    color: var(--muted);
    cursor: not-allowed;
    font-style: italic;
    font-size: 11px;
}
.time-slot.buffer::after {
    content: 'Buffer';
    display: block;
    font-size: 8px;
    letter-spacing: 1px;
    color: rgba(255,159,36,0.45);
    text-transform: uppercase;
    margin-top: 2px;
    font-style: normal;
}
.time-slot.no-space {
    background: var(--day-past);
    border-color: transparent;
    color: var(--day-past-text);
    cursor: not-allowed;
}
.time-slot.no-space::after {
    content: 'เสร็จหลัง 20:00';
    display: block;
    font-size: 8px;
    letter-spacing: 0.5px;
    color: #555;
    margin-top: 2px;
}
.time-slot.disabled {
    background: var(--day-past);
    color: var(--day-past-text);
    cursor: not-allowed;
    border-color: transparent;
}

/* ===== *** BOOKED SLOT (ใหม่) *** ===== */
.time-slot.booked {
    background: rgba(204, 85, 85, 0.08);
    border-color: rgba(204, 85, 85, 0.3);
    color: rgba(204, 85, 85, 0.55);
    cursor: not-allowed;
    font-style: italic;
}
.time-slot.booked::after {
    content: 'จองแล้ว';
    display: block;
    font-size: 8px;
    letter-spacing: 0.5px;
    color: rgba(204, 85, 85, 0.5);
    text-transform: uppercase;
    margin-top: 2px;
    font-style: normal;
}

.time-legend {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 28px;
    padding: 12px 16px;
    background: var(--card-bg);
    border: 1px solid var(--border);
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 0.5px;
}
.legend-dot {
    width: 12px; height: 12px;
    border: 1px solid;
    flex-shrink: 0;
}
.legend-dot.available   { background: var(--card-bg);  border-color: var(--border); }
.legend-dot.selected-d  { background: var(--gold);     border-color: var(--gold); }
.legend-dot.booked-d    { background: rgba(204,85,85,0.08); border-color: rgba(204,85,85,0.3); }
.legend-dot.no-space-d  { background: var(--day-past); border-color: transparent; }
.legend-dot.buffer-d    { background: rgba(255,159,36,0.04); border-color: rgba(255,159,36,0.15); }
.legend-dot.leave-d     { background: rgba(248,113,113,0.12); border-color: rgba(248,113,113,0.4); }

/* ===== CONFIRM BTN ===== */
.btn-confirm {
    width: 100%; background: var(--gold); color: var(--black); border: none;
    padding: 16px; font-size: 12px; letter-spacing: 2.5px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-confirm:hover { background: var(--gold-light); }
.btn-confirm:disabled { background: var(--dark3); color: var(--muted); cursor: not-allowed; }

/* ===== SUMMARY PANEL ===== */
.summary-panel {
    position: sticky; top: 100px;
    background: var(--summary-bg); border: 1px solid var(--border);
    margin-left: 40px; padding: 32px 28px;
}
.summary-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px; font-weight: 400; color: var(--white);
    margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
}
.summary-row {
    display: flex; flex-direction: column; gap: 4px;
    margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--divider);
}
.summary-row:last-of-type { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.summary-key { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: var(--gold); }
.summary-val { font-size: 14px; color: var(--white); font-weight: 400; }
.summary-val.placeholder { color: var(--muted); font-size: 12px; font-weight: 300; font-style: italic; }
.summary-price { font-family: 'Cormorant Garamond', serif; font-size: 32px; color: var(--white); }
.summary-price span { font-family: 'Jost', sans-serif; font-size: 13px; color: var(--muted); font-weight: 300; }

/* ===== FOOTER ===== */
.footer {
    background: var(--black); border-top: 1px solid var(--border);
    padding: 20px 40px; display: flex; justify-content: space-between;
    align-items: center; flex-wrap: wrap; gap: 10px;
}
.footer p { font-size: 11px; color: var(--footer-bottom-color); }
.footer-links { display: flex; gap: 20px; }
.footer-links a { font-size: 11px; color: var(--footer-bottom-color); text-decoration: none; transition: color 0.2s; }
.footer-links a:hover { color: var(--gold); }

/* ===== MOBILE ===== */
@media (max-width: 860px) {
    .page-wrap { grid-template-columns: 1fr; padding: 36px 20px 60px; }
    .summary-panel { margin-left: 0; margin-top: 40px; position: static; }
    .header { padding: 0 16px; }
    .header-nav { display: none; }
    .service-options { grid-template-columns: repeat(2,1fr); }
    .time-grid { grid-template-columns: repeat(4, 1fr); }
    .footer { padding: 16px 20px; }
}
@media (max-width: 420px) {
    .service-options { grid-template-columns: 1fr 1fr; }
    .time-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>
</head>
<body>

<!-- ANNOUNCEMENT BAR -->
<div class="announcement-bar">
    ✦ &nbsp; New Season Collection — Book Your Appointment Today &nbsp; ✦
</div>

<!-- HEADER -->
<div class="header">
    <div class="header-logo">
        <img src="logo-crop.png" alt="Bright Hair Studio">
    </div>
    <nav class="header-nav">
        <a href="customer_home.php" class="nav-tab"><i class="fa fa-house"></i> Home</a>
        <a href="services.php" class="nav-tab"><i class="fa fa-scissors"></i> Services</a>
        <a href="promotions.php" class="nav-tab"><i class="fa fa-star"></i> Promotions</a>
        <a href="customer_gallery.php" class="nav-tab"><i class="fa fa-images"></i> Gallery</a>
        <a href="customer_about.php" class="nav-tab"><i class="fa fa-circle-info"></i> About</a>
    </nav>
    <div class="header-right">
        <a href="booking_calendar.php" class="btn-book-header active">
            <i class="fa fa-calendar-check"></i> Book Now
        </a>
        <div class="profile-wrapper" id="profileWrapper">
            <button class="btn-profile" id="profileBtn"><i class="fa fa-user"></i></button>
            <div class="profile-dropdown">
                <div class="dropdown-header">
                    <div class="name"><?= htmlspecialchars($fullName ?: 'Guest') ?></div>
                    <div class="email"><?= htmlspecialchars($email) ?></div>
                </div>
                <a href="my_profile.php" class="dropdown-item"><i class="fa fa-user-circle"></i> My Profile</a>
                <a href="customer_my_booking.php" class="dropdown-item"><i class="fa fa-calendar-check"></i> My Bookings</a>
                <a href="booking_history.php" class="dropdown-item"><i class="fa fa-clock-rotate-left"></i> Booking History</a>
                <a href="my_rewards.php" class="dropdown-item"><i class="fa fa-gift"></i> Rewards & Points</a>
                <a href="logout.php" class="dropdown-item logout"><i class="fa fa-arrow-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- PAGE -->
<div class="page-wrap">
    <div class="booking-form-col">

        <div class="page-eyebrow">Appointment</div>
        <h1 class="page-title">Book a <em>Visit</em></h1>

        <!-- Step Indicator -->
        <div class="steps">
            <div class="step active" id="step1">
                <div class="step-num">1</div>
                <div class="step-label">บริการ</div>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step2">
                <div class="step-num">2</div>
                <div class="step-label">ช่าง</div>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step3">
                <div class="step-num">3</div>
                <div class="step-label">วันที่</div>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step4">
                <div class="step-num">4</div>
                <div class="step-label">เวลา</div>
            </div>
        </div>

        <!-- STEP 1: SERVICE -->
        <div class="form-section-label">เลือกบริการ</div>
        <div class="service-options" id="serviceOptions">
            <?php foreach ($dbServices as $s): ?>
            <div class="service-opt" 
                 data-id="<?= htmlspecialchars($s['code']) ?>" 
                 data-name="<?= htmlspecialchars($s['name']) ?>" 
                 data-price="<?= htmlspecialchars($s['price']) ?>" 
                 data-dur="<?= htmlspecialchars($s['duration_min']) ?>" 
                 data-slots="<?= ceil((int)$s['duration_min'] / 30) ?>" 
                 data-skills="<?= htmlspecialchars($s['name']) ?>,ทุกรายการ" 
                 onclick="selectService(this)">
                <div class="check"><i class="fa fa-check"></i></div>
                <div class="opt-id"><?= htmlspecialchars($s['code']) ?></div>
                <div class="opt-name"><?= htmlspecialchars($s['name']) ?></div>
                <div class="opt-price">฿<?= number_format($s['price'], 0) ?></div>
                <div class="opt-dur"><i class="fa fa-clock"></i> <?= htmlspecialchars($s['duration_min']) ?> นาที</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- STEP 2: STYLIST -->
        <div class="form-section-label">เลือกช่าง</div>
        <div class="stylist-filter-note" id="stylistNote">
            <i class="fa fa-circle-check"></i>
            <span id="stylistNoteText">แสดงเฉพาะช่างที่รองรับบริการที่เลือก</span>
        </div>
        <div class="stylist-options">
            <?php foreach ($dbEmployees as $emp):
                $isOnLeave = isset($onLeaveTodaySet[(int)$emp['id']]);
            ?>
            <?php if ($isOnLeave): ?>
            <div class="stylist-opt on-leave"
                 data-name="<?= htmlspecialchars($emp['name']) ?>"
                 data-id="<?= htmlspecialchars($emp['id']) ?>"
                 data-skills="<?= htmlspecialchars($emp['skills']) ?>"
                 title="ช่างลาหยุดวันนี้ ไม่สามารถจองได้">
                <div class="stylist-avatar"><i class="fa fa-user"></i></div>
                <div class="stylist-name"><?= htmlspecialchars($emp['name']) ?></div>
                <div class="stylist-leave-badge">&#x1F534; ลาหยุด</div>
                <div class="stylist-skills"><?= htmlspecialchars($emp['skills']) ?></div>
            </div>
            <?php else: ?>
            <div class="stylist-opt"
                 data-name="<?= htmlspecialchars($emp['name']) ?>"
                 data-id="<?= htmlspecialchars($emp['id']) ?>"
                 data-skills="<?= htmlspecialchars($emp['skills']) ?>"
                 onclick="selectStylist(this)">
                <div class="stylist-avatar"><i class="fa fa-user"></i></div>
                <div class="stylist-name"><?= htmlspecialchars($emp['name']) ?></div>
                <div class="stylist-role-badge"><?= htmlspecialchars($emp['role'] ?: 'Stylist') ?></div>
                <div class="stylist-skills"><?= htmlspecialchars($emp['skills']) ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <div class="stylist-opt" data-name="ใครก็ได้" data-id="ANY" data-skills="ทุกรายการ" onclick="selectStylist(this)" style="grid-column: span 4;">
                <div class="stylist-avatar"><i class="fa fa-shuffle"></i></div>
                <div class="stylist-name">ใครก็ได้</div>
                <div class="stylist-role-badge">Any Available</div>
                <div class="stylist-skills">Any Available Stylist</div>
            </div>
        </div>

        <!-- STEP 3: DATE -->
        <div class="form-section-label">เลือกวันที่</div>
        <div class="calendar-wrap">
            <div class="cal-header">
                <button class="cal-nav" id="prevBtn" onclick="prevMonth()"><i class="fa fa-chevron-left"></i></button>
                <div class="cal-month" id="monthYear"></div>
                <button class="cal-nav" id="nextBtn" onclick="nextMonth()"><i class="fa fa-chevron-right"></i></button>
            </div>
            <div class="weekdays-row">
                <span>Sun</span><span>Mon</span><span>Tue</span>
                <span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
        </div>

        <!-- STEP 4: TIME -->
        <div class="form-section-label">เลือกเวลา</div>
        <div class="time-grid-wrap">
            <!-- Loading overlay -->
            <div class="time-loading" id="timeLoading">
                <i class="fa fa-spinner fa-spin"></i> กำลังโหลดเวลาว่าง...
            </div>
            <div class="time-grid" id="timeGrid"></div>
        </div>

        <div class="time-legend">
            <div class="legend-item">
                <div class="legend-dot available"></div>
                <span>จองได้</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot selected-d"></div>
                <span>เลือกแล้ว</span>
            </div>
            <!-- *** Legend ใหม่: จองแล้ว *** -->
            <div class="legend-item">
                <div class="legend-dot booked-d"></div>
                <span>จองแล้ว (ช่างไม่ว่าง)</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot leave-d"></div>
                <span>ช่างลาหยุด</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot no-space-d"></div>
                <span>เสร็จหลัง 20:00 (ปิดร้าน)</span>
            </div>
        </div>

        <input type="hidden" id="hiddenService" name="service">
        <input type="hidden" id="hiddenStylist" name="stylist">
        <input type="hidden" id="hiddenDate" name="date">
        <input type="hidden" id="hiddenTime" name="time">

        <button class="btn-confirm" id="confirmBtn" disabled onclick="confirmBooking()">
            <i class="fa fa-calendar-check"></i>
            ยืนยันการจอง
        </button>

    </div>

    <!-- SUMMARY PANEL -->
    <div class="summary-panel">
        <div class="summary-title">Booking Summary</div>
        <div class="summary-row">
            <div class="summary-key">บริการ</div>
            <div class="summary-val placeholder" id="sumService">ยังไม่ได้เลือก</div>
        </div>
        <div class="summary-row">
            <div class="summary-key">ช่าง</div>
            <div class="summary-val placeholder" id="sumStylist">ยังไม่ได้เลือก</div>
        </div>
        <div class="summary-row">
            <div class="summary-key">วันที่</div>
            <div class="summary-val placeholder" id="sumDate">ยังไม่ได้เลือก</div>
        </div>
        <div class="summary-row">
            <div class="summary-key">เวลา</div>
            <div class="summary-val placeholder" id="sumTime">ยังไม่ได้เลือก</div>
        </div>
        <div class="summary-row">
            <div class="summary-key">ระยะเวลา</div>
            <div class="summary-val placeholder" id="sumDur">—</div>
        </div>
        <div class="summary-row" style="margin-top:20px; padding-top:20px; border-top: 1px solid var(--border);">
            <div class="summary-key">ราคา</div>
            <div class="summary-price" id="sumPrice">— <span>บาท</span></div>
        </div>
    </div>
</div>

<!-- FOOTER -->
<footer class="footer">
    <p>© <?php echo date('Y'); ?> Bright Hair Studio. All rights reserved.</p>
    <div class="footer-links">
        <a href="#">Terms & Conditions</a>
        <a href="#">Privacy Policy</a>
    </div>
</footer>

<script>
// ===== STATE =====
let state = { service: null, stylist: null, stylistId: null, date: null, time: null };
let currentMonth, currentYear;
const today = new Date();
today.setHours(0,0,0,0);

const maxDate = new Date(today);
maxDate.setMonth(maxDate.getMonth() + 2);

// วันลาของช่าง (approved) จาก DB — map: { employeeId: ["YYYY-MM-DD", ...] }
const LEAVE_DATES_MAP = <?= $leaveDatesMapJson ?>;

const MONTHS_EN = ['January','February','March','April','May','June',
                   'July','August','September','October','November','December'];
const MONTHS_TH_SHORT = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
                          'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
const DAYS_TH = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัส','ศุกร์','เสาร์'];

const ALL_SLOTS = (() => {
    const slots = [];
    // สร้าง slot ตั้งแต่ 10:00 ถึง 19:30 (slot สุดท้ายที่เร็วที่สุดสำหรับบริการ 30 นาที)
    for (let m = 600; m < 20 * 60; m += 30) {
        const h   = String(Math.floor(m / 60)).padStart(2, '0');
        const min = String(m % 60).padStart(2, '0');
        slots.push({ time: `${h}:${min}`, minutes: m });
    }
    return slots;
})();

const CLOSING_TIME_MINUTES = 20 * 60; // 20:00 = 1200 นาที

// ===== booked slots cache: key = "stylistId|YYYY-MM-DD" => array =====
const bookedCache = {};

// ===== INIT =====
currentMonth = today.getMonth();
currentYear  = today.getFullYear();
renderCalendar();
renderTimeSlots();

const urlParams = new URLSearchParams(window.location.search);
const preService = urlParams.get('service');
if (preService) {
    const el = document.querySelector(`.service-opt[data-id="${preService}"]`);
    if (el) selectService(el);
}

// ===== SERVICE =====
function selectService(el) {
    document.querySelectorAll('.service-opt').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    state.service = {
        id:    el.dataset.id,
        name:  el.dataset.name,
        price: el.dataset.price,
        dur:   el.dataset.dur,
        slots: parseInt(el.dataset.slots)
    };
    document.getElementById('hiddenService').value = el.dataset.id;
    filterStylistsByService(el.dataset.skills || '');
    if (state.date) {
        state.time = null;
        document.getElementById('hiddenTime').value = '';
        // re-fetch เพราะ slotsNeeded อาจเปลี่ยน
        fetchBookedAndRender();
    }
    updateSummary();
    updateSteps();
    checkConfirm();
}

function filterStylistsByService(serviceSkills) {
    const required = serviceSkills.split(',').map(s => s.trim());
    let visibleCount = 0;
    document.querySelectorAll('.stylist-opt').forEach(opt => {
        const barberSkills = (opt.dataset.skills || '').split(',').map(s => s.trim());
        const canDo = required.some(req =>
            barberSkills.some(skill => skill === req || skill === 'ทุกรายการ')
        );
        if (canDo) {
            opt.classList.remove('stylist-hidden');
            visibleCount++;
        } else {
            opt.classList.add('stylist-hidden');
            if (opt.classList.contains('selected')) {
                opt.classList.remove('selected');
                state.stylist   = null;
                state.stylistId = null;
                document.getElementById('hiddenStylist').value = '';
            }
        }
    });
    const note = document.getElementById('stylistNote');
    note.classList.add('visible');
    document.getElementById('stylistNoteText').textContent =
        `${visibleCount} คนที่รองรับบริการนี้`;
}

// ===== STYLIST =====
function selectStylist(el) {
    document.querySelectorAll('.stylist-opt').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    state.stylist   = el.dataset.name;
    state.stylistId = el.dataset.id;
    document.getElementById('hiddenStylist').value = el.dataset.name;

    // ถ้าวันที่ที่เลือกไว้เป็นวันลาของช่างคนใหม่ → ล้างวันที่
    if (state.date && state.stylistId !== 'ANY') {
        const empNum = parseInt(state.stylistId);
        const yyyy   = state.date.getFullYear();
        const mm2    = String(state.date.getMonth()+1).padStart(2,'0');
        const dd2    = String(state.date.getDate()).padStart(2,'0');
        const dStr   = `${yyyy}-${mm2}-${dd2}`;
        if (LEAVE_DATES_MAP[empNum] && LEAVE_DATES_MAP[empNum].includes(dStr)) {
            state.date = null;
            state.time = null;
            document.getElementById('hiddenDate').value = '';
            document.getElementById('hiddenTime').value = '';
        }
    }

    // re-render calendar เพื่ออัปเดตวันลาของช่างที่เลือก
    renderCalendar();

    updateSummary();
    updateSteps();
    // ถ้าเลือกวันแล้ว → re-fetch ทันที
    if (state.date) {
        state.time = null;
        document.getElementById('hiddenTime').value = '';
        fetchBookedAndRender();
    }
    checkConfirm();
}

// ===== CALENDAR =====
function renderCalendar() {
    const firstDay    = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';

    document.getElementById('monthYear').textContent = MONTHS_EN[currentMonth] + ' ' + currentYear;

    const isMinMonth = (currentMonth === today.getMonth() && currentYear === today.getFullYear());
    const isMaxMonth = (currentMonth === maxDate.getMonth() && currentYear === maxDate.getFullYear());
    document.getElementById('prevBtn').classList.toggle('hidden', isMinMonth);
    document.getElementById('nextBtn').classList.toggle('hidden', isMaxMonth);

    for (let i = 0; i < firstDay; i++) {
        const empty = document.createElement('div');
        empty.className = 'cal-empty';
        grid.appendChild(empty);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(currentYear, currentMonth, d);
        const div  = document.createElement('div');
        div.className = 'cal-day';
        div.textContent = d;

        if (date < today) {
            div.classList.add('past');
        } else {
            // ตรวจสอบว่าช่างที่เลือกลาหยุดในวันนี้หรือไม่
            const yyyy   = date.getFullYear();
            const mm2    = String(date.getMonth()+1).padStart(2,'0');
            const dd2    = String(date.getDate()).padStart(2,'0');
            const dStr   = `${yyyy}-${mm2}-${dd2}`;
            const empNum = parseInt(state.stylistId);
            const isOnLeave = state.stylistId && state.stylistId !== 'ANY'
                && LEAVE_DATES_MAP[empNum]
                && LEAVE_DATES_MAP[empNum].includes(dStr);

            if (isOnLeave) {
                div.classList.add('on-leave');
                div.title = 'ช่างลาหยุดในวันนี้';
            } else {
                if (date.toDateString() === today.toDateString()) div.classList.add('today');
                if (state.date && date.toDateString() === state.date.toDateString()) div.classList.add('selected');
                div.onclick = () => selectDate(date, div);
            }
        }
        grid.appendChild(div);
    }
}

function selectDate(date, el) {
    document.querySelectorAll('.cal-day').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    state.date = date;
    state.time = null;
    const yyyy = date.getFullYear();
    const mm   = String(date.getMonth()+1).padStart(2,'0');
    const dd   = String(date.getDate()).padStart(2,'0');
    document.getElementById('hiddenDate').value  = `${yyyy}-${mm}-${dd}`;
    document.getElementById('hiddenTime').value  = '';
    fetchBookedAndRender();   // ← fetch แล้ว render
    updateSummary();
    updateSteps();
    checkConfirm();
}

function prevMonth() {
    currentMonth--;
    if (currentMonth < 0) { currentMonth = 11; currentYear--; }
    state.date = null;
    renderCalendar();
}
function nextMonth() {
    currentMonth++;
    if (currentMonth > 11) { currentMonth = 0; currentYear++; }
    state.date = null;
    renderCalendar();
}

// ===== FETCH BOOKED SLOTS แล้ว RENDER =====
/**
 * ดึงการจองที่มีอยู่ของช่าง + วันที่ปัจจุบันจาก get_booked_slots.php
 * ใช้ cache เพื่อไม่ fetch ซ้ำในคู่ (stylistId + date) เดิม
 */
async function fetchBookedAndRender() {
    // ถ้ายังไม่มีช่างหรือวันที่ → render ปกติ (disabled)
    if (!state.stylistId || !state.date) {
        renderTimeSlots([]);
        return;
    }

    // ถ้าเลือก "ใครก็ได้" → ไม่ block
    if (state.stylistId === 'ANY') {
        renderTimeSlots([]);
        return;
    }

    const yyyy    = state.date.getFullYear();
    const mm      = String(state.date.getMonth()+1).padStart(2,'0');
    const dd      = String(state.date.getDate()).padStart(2,'0');
    const dateStr = `${yyyy}-${mm}-${dd}`;
    const cacheKey = `${state.stylistId}|${dateStr}`;

    // ถ้ามี cache แล้ว → ใช้เลย
    if (bookedCache[cacheKey] !== undefined) {
        renderTimeSlots(bookedCache[cacheKey]);
        return;
    }

    // แสดง loading
    document.getElementById('timeLoading').classList.add('show');

    try {
        const res  = await fetch(`get_booked_slots.php?stylist_id=${encodeURIComponent(state.stylistId)}&date=${dateStr}`);
        const data = await res.json();
        bookedCache[cacheKey] = data.booked || [];
        renderTimeSlots(bookedCache[cacheKey]);
    } catch (e) {
        // ถ้า fetch ไม่ได้ → render โดยไม่ block (fail-open เพื่อ UX ไม่พัง)
        console.warn('get_booked_slots failed:', e);
        renderTimeSlots([]);
    } finally {
        document.getElementById('timeLoading').classList.remove('show');
    }
}

// ===== TIME SLOTS =====
/**
 * @param {Array} bookedRanges - array of { start_min, end_min }
 *
 * Logic การ block:
 *   slot ที่ start_min อยู่ใน range [booked.start_min, booked.end_min)
 *   → ช่างยังไม่ว่าง ให้ class "booked"
 *
 *   นอกจากนี้ ถ้า slot ที่เลือกจะทำให้ overlap กับ booking ที่มีอยู่
 *   → ให้ class "booked" ด้วย (ป้องกัน booking ที่ start ก่อน แต่ end ทับ)
 */
function renderTimeSlots(bookedRanges = []) {
    const grid = document.getElementById('timeGrid');
    grid.innerHTML = '';

    const slotsNeeded   = state.service ? state.service.slots : 1;
    const serviceDurMin = slotsNeeded * 30; // ระยะเวลาบริการในหน่วยนาที
    const dateSelected  = !!state.date;

    // คำนวณเวลาปัจจุบัน (สำหรับ block past time ถ้าเลือกวันนี้)
    const now = new Date();
    const todayStr = now.getFullYear() + '-' +
                     String(now.getMonth()+1).padStart(2,'0') + '-' +
                     String(now.getDate()).padStart(2,'0');
    const currentTotalMinutes = (now.getHours() * 60) + now.getMinutes();
    const selectedDateStr = state.date
        ? state.date.getFullYear() + '-' +
          String(state.date.getMonth()+1).padStart(2,'0') + '-' +
          String(state.date.getDate()).padStart(2,'0')
        : '';

    ALL_SLOTS.forEach(slot => {
        const div = document.createElement('div');
        div.className = 'time-slot';
        div.textContent = slot.time;

        if (!dateSelected) {
            // ยังไม่เลือกวัน → disabled
            div.classList.add('disabled');
        } else {
            // เวลาที่จะเสร็จ = เวลาเริ่ม + ระยะเวลาบริการ
            const slotEndMin = slot.minutes + serviceDurMin;

            // เช็ค past time (วันนี้ + เวลาผ่านไปแล้ว)
            const isPastTime = (selectedDateStr === todayStr && slot.minutes <= currentTotalMinutes);

            if (isPastTime) {
                // เวลาผ่านไปแล้ว
                div.classList.add('disabled');
                div.title = 'เวลาผ่านไปแล้ว';
            } else if (slotEndMin > CLOSING_TIME_MINUTES) {
                // ถ้าบริการนี้จะเสร็จหลัง 20:00 → ไม่สามารถจองได้
                div.classList.add('no-space');
                div.title = `บริการนี้ใช้เวลา ${serviceDurMin} นาที จะเสร็จหลัง 20:00`;
            } else if (isSlotBooked(slot.minutes, slotEndMin, bookedRanges)) {
                // ช่างถูกจองแล้ว
                div.classList.add('booked');
            } else {
                // ว่าง → จองได้
                if (state.time === slot.time) div.classList.add('selected');
                div.onclick = () => selectTime(slot.time, div);
            }
        }

        grid.appendChild(div);
    });
}

/**
 * ตรวจว่า slot ที่ต้องการ (slotStart → slotEnd) overlap กับ booking ใด ๆ หรือไม่
 *
 * Overlap ถ้า:  slotStart < booked.end_min  AND  slotEnd > booked.start_min
 */
function isSlotBooked(slotStartMin, slotEndMin, bookedRanges) {
    return bookedRanges.some(b =>
        slotStartMin < b.end_min && slotEndMin > b.start_min
    );
}

function selectTime(t, el) {
    document.querySelectorAll('.time-slot').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    state.time = t;
    document.getElementById('hiddenTime').value = t;
    updateSummary();
    updateSteps();
    checkConfirm();
}

// ===== SUMMARY =====
function updateSummary() {
    const set = (id, val, isPlaceholder = false) => {
        const el = document.getElementById(id);
        el.textContent = val;
        el.className = 'summary-val' + (isPlaceholder ? ' placeholder' : '');
    };

    if (state.service) {
        set('sumService', state.service.name);
        document.getElementById('sumDur').textContent = state.service.dur + ' นาที';
        document.getElementById('sumDur').className = 'summary-val';
        document.getElementById('sumPrice').innerHTML = parseInt(state.service.price).toLocaleString() + ' <span>บาท</span>';
    } else {
        set('sumService', 'ยังไม่ได้เลือก', true);
        document.getElementById('sumDur').textContent = '—';
        document.getElementById('sumDur').className = 'summary-val placeholder';
        document.getElementById('sumPrice').innerHTML = '— <span>บาท</span>';
    }

    state.stylist ? set('sumStylist', state.stylist) : set('sumStylist', 'ยังไม่ได้เลือก', true);

    if (state.date) {
        const d = state.date;
        const label = `${DAYS_TH[d.getDay()]} ${d.getDate()} ${MONTHS_TH_SHORT[d.getMonth()+1]} ${d.getFullYear()+543}`;
        set('sumDate', label);
    } else {
        set('sumDate', 'ยังไม่ได้เลือก', true);
    }

    if (state.time && state.service) {
        const startMin  = ALL_SLOTS.find(s => s.time === state.time)?.minutes ?? 0;
        const endMin    = startMin + (state.service.slots * 30);
        const endH      = String(Math.floor(endMin / 60)).padStart(2, '0');
        const endM      = String(endMin % 60).padStart(2, '00');
        set('sumTime', `${state.time} – ${endH}:${endM}`);
    } else if (state.time) {
        set('sumTime', state.time);
    } else {
        set('sumTime', 'ยังไม่ได้เลือก', true);
    }
}

// ===== STEPS =====
function updateSteps() {
    const setStep = (id, status) => {
        const el = document.getElementById(id);
        el.classList.remove('active', 'done');
        if (status) el.classList.add(status);
    };
    setStep('step1', state.service ? 'done' : 'active');
    setStep('step2', state.service ? (state.stylist ? 'done' : 'active') : '');
    setStep('step3', state.stylist ? (state.date ? 'done' : 'active') : '');
    setStep('step4', state.date ? (state.time ? 'done' : 'active') : '');
}

// ===== CONFIRM =====
function checkConfirm() {
    const ready = state.service && state.stylist && state.date && state.time;
    document.getElementById('confirmBtn').disabled = !ready;
}

function confirmBooking() {
    if (!state.service || !state.stylist || !state.date || !state.time) return;
    const d = state.date;
    const dateStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    window.location.href = `confirm.php?service=${state.service.id}&stylist=${encodeURIComponent(state.stylist)}&date=${dateStr}&time=${state.time}`;
}

// ===== PROFILE DROPDOWN =====
const profileWrapper = document.getElementById('profileWrapper');
document.getElementById('profileBtn').addEventListener('click', e => {
    e.stopPropagation();
    profileWrapper.classList.toggle('open');
});
document.addEventListener('click', () => profileWrapper.classList.remove('open'));

document.addEventListener('DOMContentLoaded', function() {
    // ดึงค่ารหัสบริการจาก URL (เช่น ?service=S001)
    const urlParams = new URLSearchParams(window.location.search);
    const serviceCode = urlParams.get('service');

    if (serviceCode) {
        // รอให้หน้าเว็บโหลดข้อมูล Service เสร็จสักครู่ (กรณีดึงจาก DB)
        // แล้วค้นหา element ที่มี data-id ตรงกับรหัสที่ส่งมา
        setTimeout(() => {
            const serviceElement = document.querySelector(`.service-opt[data-id="${serviceCode}"]`);
            
            if (serviceElement) {
                // 3. สั่งคลิกเลือกบริการนั้น
                serviceElement.click(); 
                
                // 4. เลื่อนหน้าจอลงมาให้เห็นว่าเลือกแล้ว
                serviceElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 300); // รอ 300ms เพื่อความชัวร์
    }
});
</script>

</script>

</body>
</html>