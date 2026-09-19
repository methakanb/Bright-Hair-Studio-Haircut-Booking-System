<?php
session_start();

if(!isset($_SESSION['user'])){
    header("Location: customer_login.php");
    exit();
}

require 'db.php';
$user_id = $_SESSION['user_id'] ?? 0;

// ── AJAX: บันทึกรีวิว ────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    header('Content-Type: application/json; charset=utf-8');

    $booking_id  = (int)($_POST['booking_id']  ?? 0);
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $rating      = (int)($_POST['rating']      ?? 0);
    $comment     = trim($_POST['comment']      ?? '');

    // validate
    if (!$booking_id || !$rating || $rating < 1 || $rating > 5 || !$comment) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }

    // ตรวจว่า booking นี้เป็นของ user คนนี้จริงและ status = completed
    $chk = $pdo->prepare("SELECT id FROM bookings WHERE id=? AND user_id=? AND status IN ('completed','done')");
    $chk->execute([$booking_id, $user_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบการจองหรือยังไม่เสร็จสิ้น']);
        exit;
    }

    // ตรวจว่าเคยรีวิว booking นี้แล้วหรือยัง
    $dup = $pdo->prepare("SELECT id FROM reviews WHERE booking_id=?");
    $dup->execute([$booking_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'คุณได้รีวิวการจองนี้ไปแล้ว']);
        exit;
    }

    // INSERT รีวิว
    $ins = $pdo->prepare("INSERT INTO reviews (booking_id, rating, comment) VALUES (?, ?, ?)");
    $ins->execute([$booking_id, $rating, $comment]);

    if ($ins->rowCount() > 0) {
        // อัปเดต rating เฉลี่ยและ review_count ในตาราง employees
        $pdo->prepare("
            UPDATE employees
            SET review_count = (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.employee_id=?),
                rating        = (SELECT ROUND(AVG(r.rating),1) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.employee_id=?)
            WHERE id = ?
        ")->execute([$employee_id, $employee_id, $employee_id]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึก']);
    }
    exit;
}

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

$stmt = $pdo->prepare("
    SELECT b.*, b.booking_date as date, TIME_FORMAT(b.start_time,'%H:%i') as start_time,
           s.name as service_name, s.price, s.duration_min as duration,
           s.code as service_id,
           e.name as stylist,
           r.rating, r.comment
    FROM bookings b
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN employees e ON b.employee_id = e.id
    LEFT JOIN reviews r ON r.booking_id = b.id
    WHERE b.user_id = ?
    ORDER BY b.booking_date ASC, b.start_time ASC
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll();

// แปลงให้ตรงกับโครงสร้างเดิม
$bookings = array_map(function($r) {
    // ตรวจว่าเป็นการจองโปรโมชั่นหรือไม่ (service_id = NULL, notes เริ่มด้วย PROMO:)
    $isPromo    = is_null($r['service_id']) && isset($r['notes']) && strpos($r['notes'], 'PROMO:') === 0;
    $promoTitle = null;
    $promoPrice = null;
    $promoDur   = $r['duration_min'] ?? 60;
    if ($isPromo) {
        // format: PROMO:<title>|PRICE:<price>|DUR:<min>
        $parts = explode('|', $r['notes']);
        $promoTitle = trim(substr($parts[0], 6)); // ตัด "PROMO:" ออก
        foreach ($parts as $p) {
            if (strpos($p, 'PRICE:') === 0) $promoPrice = (int)substr($p, 6);
            if (strpos($p, 'DUR:')   === 0) $promoDur   = (int)substr($p, 4);
        }
        // รองรับ format เก่า "PROMO: <title>" (มีช่องว่าง)
        if (!$promoTitle) $promoTitle = trim(substr($r['notes'], 6));
    }

    return [
        'booking_id'  => $r['booking_code'],
        'db_id'       => $r['id'],           // bookings.id จริง (ใช้บันทึกรีวิว)
        'employee_id' => $r['employee_id'],  // employees.id (ใช้ระบุช่าง)
        'service_id'  => $r['service_id'],
        'date'        => $r['date'],
        'start_time'  => $r['start_time'],
        'duration'    => $r['duration'] ?? $promoDur ?? 60,
        'stylist'     => $r['stylist'] ?? '-',
        'status'      => $r['status'],
        'review'      => $r['rating'] ? ['rating' => $r['rating'], 'comment' => $r['comment']] : null,
        'is_promo'    => $isPromo,
        'promo_title' => $promoTitle,
        'price'       => $isPromo ? $promoPrice : ($r['price'] ?? null),
    ];
}, $rows);

// services array ต้องมีเพื่อ lookup — โหลดจาก DB
$svcRows = $pdo->query("SELECT * FROM services")->fetchAll();
$services = [];
foreach ($svcRows as $s) {
    $services[$s['code']] = [
        'name'     => $s['name'],
        'price'    => $s['price'],
        'duration' => $s['duration_min'],
    ];
}

// Helper: calculate end time
function calcEndTime($start, $durationMin) {
    $t = strtotime($start);
    $t += $durationMin * 60;
    return date('H:i', $t);
}

// Helper: format date Thai
function formatDateThai($dateStr) {
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
               'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    [$y,$m,$d] = explode('-', $dateStr);
    return (int)$d . ' ' . $months[(int)$m] . ' ' . ((int)$y + 543);
}

// Separate upcoming vs history
$upcoming  = array_filter($bookings, fn($b) => in_array($b['status'], ['upcoming','pending','in_progress']));
$completed = array_filter($bookings, fn($b) => in_array($b['status'], ['completed','done']));

// upcoming: เรียงจากวันที่ใกล้ปัจจุบันที่สุดก่อน (ASC)
usort($upcoming, fn($a, $b) =>
    strcmp($a['date'] . ' ' . $a['start_time'], $b['date'] . ' ' . $b['start_time'])
);

// history: เรียงจากล่าสุดก่อน (DESC)
usort($completed, fn($a, $b) =>
    strcmp($b['date'] . ' ' . $b['start_time'], $a['date'] . ' ' . $a['start_time'])
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Bookings - Bright Hair Studio</title>

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
    --btn-dark-bg: #0d0d0d;
    --btn-dark-color: #f8f4ef;
    --card-bg: #1c1c1c;
    --card-border: rgba(255,159,36,0.2);
    --tag-upcoming-bg: rgba(255,159,36,0.12);
    --tag-upcoming-color: #ff9f24;
    --tag-done-bg: rgba(136,136,136,0.12);
    --tag-done-color: #888;
    --divider: rgba(255,255,255,0.06);
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
        --btn-dark-bg: #111111;
        --btn-dark-color: #ffffff;
        --card-bg: #f5f5f5;
        --card-border: rgba(255,159,36,0.25);
        --tag-upcoming-bg: rgba(255,159,36,0.15);
        --tag-upcoming-color: #e07800;
        --tag-done-bg: rgba(0,0,0,0.07);
        --tag-done-color: #777;
        --divider: rgba(0,0,0,0.07);
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

.header-logo img {
    height: 36px;
    object-fit: contain;
    filter: var(--logo-filter);
}

.header-nav {
    display: flex;
    align-items: center;
    height: 100%;
}

.nav-tab {
    display: flex;
    align-items: center;
    gap: 7px;
    height: 100%;
    padding: 0 22px;
    color: var(--muted);
    font-size: 11px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    font-weight: 500;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
    white-space: nowrap;
}

.nav-tab:hover, .nav-tab.active {
    color: var(--white);
    border-bottom-color: var(--gold);
}

.header-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-book {
    background: var(--gold);
    color: var(--black);
    border: none;
    padding: 10px 24px;
    font-size: 11px;
    letter-spacing: 2px;
    font-weight: 700;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'Jost', sans-serif;
    text-decoration: none;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn-book:hover { background: var(--gold-light); }

/* Profile dropdown */
.profile-wrapper { position: relative; }

.btn-profile {
    width: 40px;
    height: 40px;
    background: var(--dark3);
    border: 1px solid var(--border);
    color: var(--gold);
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.btn-profile:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }

.profile-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 240px;
    background: var(--dark2);
    border: 1px solid var(--border);
    z-index: 300;
    display: none;
    animation: fadeDown 0.2s ease;
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
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 20px;
    font-size: 12px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.2s;
    border-bottom: 1px solid var(--dropdown-border-item);
}
.dropdown-item i { width: 16px; color: var(--gold); font-size: 13px; }
.dropdown-item:hover { background: var(--dark3); color: var(--white); }
.dropdown-item.active-link { color: var(--gold); }
.dropdown-item.active-link i { color: var(--gold); }
.dropdown-item.logout { color: #cc5555; }
.dropdown-item.logout i { color: #cc5555; }

/* ===== PAGE CONTENT ===== */
.page-content {
    flex: 1;
    max-width: 900px;
    width: 100%;
    margin: 0 auto;
    padding: 56px 40px 80px;
}

/* PAGE HEADER */
.page-header {
    margin-bottom: 48px;
}

.page-eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.page-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 44px;
    font-weight: 300;
    color: var(--white);
    line-height: 1;
    margin-bottom: 12px;
}

.page-title em { font-style: italic; color: var(--gold); }

.page-desc {
    font-size: 13px;
    color: var(--muted);
    font-weight: 300;
    letter-spacing: 0.3px;
}

/* ===== SECTION LABEL ===== */
.section-label {
    font-size: 10px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ===== BOOKING CARD ===== */
.booking-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: var(--border);
    margin-bottom: 48px;
}

.booking-card {
    background: var(--card-bg);
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0;
    transition: background 0.2s;
}

.booking-card:hover { background: var(--dark3); }

/* Date block */
.booking-date-block {
    padding: 28px 28px;
    border-right: 1px solid var(--border);
    text-align: center;
    min-width: 90px;
}

.booking-date-block .day {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 300;
    color: var(--white);
    line-height: 1;
}

.booking-date-block .month {
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--gold);
    margin-top: 4px;
}

.booking-date-block .year {
    font-size: 10px;
    color: var(--muted);
    margin-top: 2px;
}

/* Info block */
.booking-info {
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.booking-service {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    font-weight: 500;
    color: var(--white);
    letter-spacing: 0.3px;
}

.booking-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: var(--muted);
    font-weight: 300;
    letter-spacing: 0.3px;
}

.meta-item i {
    color: var(--gold);
    font-size: 12px;
    width: 13px;
}

.meta-item strong {
    color: var(--white);
    font-weight: 500;
}

/* Right block */
.booking-right {
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 12px;
    border-left: 1px solid var(--border);
    min-width: 140px;
}

.booking-price {
    font-family: 'Cormorant Garamond', serif;
    font-size: 26px;
    font-weight: 400;
    color: var(--white);
}

.booking-price span {
    font-size: 12px;
    font-family: 'Jost', sans-serif;
    color: var(--muted);
    font-weight: 300;
}

.status-tag {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding: 5px 12px;
}

.status-upcoming {
    background: var(--tag-upcoming-bg);
    color: var(--tag-upcoming-color);
    border: 1px solid rgba(255,159,36,0.3);
}

.status-completed {
    background: var(--tag-done-bg);
    color: var(--tag-done-color);
    border: 1px solid var(--divider);
}

.status-pending {
    background: rgba(100,160,255,0.12);
    color: #6aa0ff;
    border: 1px solid rgba(100,160,255,0.3);
}

.booking-id {
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1px;
    font-weight: 300;
}

/* ===== EMPTY STATE ===== */
.empty-state {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    padding: 48px 32px;
    text-align: center;
    margin-bottom: 48px;
}

.empty-state i {
    font-size: 32px;
    color: var(--border);
    margin-bottom: 16px;
}

.empty-state p {
    font-size: 13px;
    color: var(--muted);
    font-weight: 300;
    letter-spacing: 0.5px;
}

/* CTA */
.cta-book {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: var(--gold);
    color: var(--black);
    padding: 14px 32px;
    font-size: 11px;
    letter-spacing: 2.5px;
    font-weight: 700;
    text-transform: uppercase;
    text-decoration: none;
    transition: background 0.2s;
    margin-top: 24px;
}
.cta-book:hover { background: var(--gold-light); }

/* ===== FOOTER ===== */
.footer {
    background: var(--dark);
    border-top: 1px solid var(--border);
    padding: 20px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.footer p { font-size: 11px; color: var(--footer-bottom-color); letter-spacing: 0.5px; }
.footer-links { display: flex; gap: 20px; }
.footer-links a { font-size: 11px; color: var(--footer-bottom-color); text-decoration: none; transition: color 0.2s; }
.footer-links a:hover { color: var(--gold); }

/* Mobile */
@media (max-width: 700px) {
    .header { padding: 0 16px; }
    .header-nav { display: none; }
    .page-content { padding: 36px 16px 60px; }
    .booking-card { grid-template-columns: 1fr; }
    .booking-date-block { border-right: none; border-bottom: 1px solid var(--border); padding: 20px 20px 16px; text-align: left; display: flex; align-items: baseline; gap: 10px; }
    .booking-date-block .day { font-size: 28px; }
    .booking-right { border-left: none; border-top: 1px solid var(--border); flex-direction: row; align-items: center; justify-content: space-between; }
    .footer { padding: 16px 20px; }
    .page-title { font-size: 32px; }
}

/* ===== REVIEW PANEL ===== */
.review-panel {
    display: none;
    border-top: 1px solid var(--border);
    background: var(--dark);
    padding: 22px 28px 26px;
    animation: slideDown 0.2s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.review-panel.open { display: block; }

.review-panel-label {
    font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px;
}
.review-panel-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

.star-row { display: flex; gap: 8px; margin-bottom: 14px; }
.star {
    font-size: 24px; cursor: pointer; color: var(--dark3);
    transition: color 0.12s; line-height: 1;
    -webkit-user-select: none; user-select: none;
}
.star.lit { color: var(--gold); }

.review-textarea {
    width: 100%; background: var(--dark2);
    border: 1px solid rgba(255,159,36,0.2); color: var(--white);
    font-family: 'Jost', sans-serif; font-size: 13px; font-weight: 300;
    padding: 12px 14px; resize: none; outline: none;
    transition: border-color 0.2s; margin-bottom: 14px; line-height: 1.6;
}
.review-textarea:focus { border-color: var(--gold); }
.review-textarea::placeholder { color: var(--muted); }

.review-actions { display: flex; align-items: center; gap: 10px; }

.btn-submit-review {
    background: var(--gold); color: var(--black); border: none;
    padding: 10px 24px; font-size: 10px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: background 0.2s; display: flex; align-items: center; gap: 7px;
}
.btn-submit-review:hover { background: var(--gold-light); }
.btn-submit-review:disabled {
    background: var(--dark3); color: var(--muted); cursor: not-allowed;
}
.btn-cancel-review {
    background: transparent; color: var(--muted); border: 1px solid var(--border);
    padding: 10px 18px; font-size: 10px; letter-spacing: 2px; font-weight: 600;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: all 0.2s;
}
.btn-cancel-review:hover { color: var(--white); border-color: var(--white); }

/* already-reviewed display */
.review-display { display: flex; flex-direction: column; gap: 10px; }
.stars-display { display: flex; gap: 4px; }
.stars-display i { font-size: 15px; }
.stars-display .s-fill { color: var(--gold); }
.stars-display .s-empty { color: var(--dark3); }
.review-comment {
    font-size: 13px; color: var(--muted); font-weight: 300;
    font-style: italic; line-height: 1.7;
}
.review-comment::before { content: '"'; color: var(--gold); margin-right: 2px; }
.review-comment::after  { content: '"'; color: var(--gold); margin-left: 2px; }
.review-meta { font-size: 10px; color: var(--muted); letter-spacing: 1px; }

/* review button inside booking-right */
.btn-review {
    display: flex; align-items: center; gap: 6px;
    background: transparent; color: var(--gold);
    border: 1px solid rgba(255,159,36,0.35); padding: 7px 14px;
    font-size: 10px; letter-spacing: 1.5px; font-weight: 600; text-transform: uppercase;
    cursor: pointer; font-family: 'Jost', sans-serif; transition: all 0.2s; white-space: nowrap;
}
.btn-review:hover { background: rgba(255,159,36,0.1); border-color: var(--gold); }
.btn-review.done { color: var(--gold); border-color: rgba(255,159,36,0.5); }
.btn-review.done:hover { background: rgba(255,159,36,0.1); border-color: var(--gold); }

/* ===== ACTION BUTTONS (Cancel / Edit) ===== */
.booking-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.btn-cancel-booking {
    display: flex; align-items: center; gap: 6px;
    background: transparent; color: #cc5555;
    border: 1px solid rgba(204,85,85,0.4); padding: 7px 14px;
    font-size: 10px; letter-spacing: 1.5px; font-weight: 600; text-transform: uppercase;
    cursor: pointer; font-family: 'Jost', sans-serif; transition: all 0.2s; white-space: nowrap;
}
.btn-cancel-booking:hover { background: rgba(204,85,85,0.1); border-color: #cc5555; }


/* ===== MODAL ===== */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}
.modal-overlay.open { display: flex; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal-box {
    background: var(--dark2);
    border: 1px solid var(--border);
    width: 100%;
    max-width: 440px;
    padding: 36px 32px 32px;
    animation: slideUp 0.25s ease;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.modal-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 26px; font-weight: 400;
    color: var(--white); margin-bottom: 6px;
}
.modal-title em { font-style: italic; color: var(--gold); }

.modal-subtitle {
    font-size: 12px; color: var(--muted);
    font-weight: 300; margin-bottom: 28px;
    letter-spacing: 0.3px;
}

.modal-field { margin-bottom: 18px; }

.modal-label {
    display: block;
    font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 8px;
}

.modal-input {
    width: 100%;
    background: var(--dark3);
    border: 1px solid rgba(255,159,36,0.2);
    color: var(--white);
    font-family: 'Jost', sans-serif; font-size: 14px; font-weight: 300;
    padding: 11px 14px; outline: none;
    transition: border-color 0.2s;
}
.modal-input:focus { border-color: var(--gold); }

/* Select dropdown styling */
.modal-select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23ff9f24' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
    cursor: pointer;
}
.modal-select option {
    background: var(--dark2);
    color: var(--white);
}

/* Pending note */
.modal-pending-note {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,159,36,0.08);
    border: 1px solid rgba(255,159,36,0.25);
    color: var(--gold);
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    margin-top: 4px;
}
.modal-pending-note i { font-size: 12px; flex-shrink: 0; }

.modal-actions {
    display: flex; gap: 10px; margin-top: 28px;
}

.btn-modal-confirm {
    flex: 1;
    background: var(--gold); color: var(--black); border: none;
    padding: 12px 20px; font-size: 10px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: background 0.2s;
}
.btn-modal-confirm:hover { background: var(--gold-light); }

.btn-modal-cancel {
    background: transparent; color: var(--muted); border: 1px solid var(--border);
    padding: 12px 20px; font-size: 10px; letter-spacing: 2px; font-weight: 600;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: all 0.2s;
}
.btn-modal-cancel:hover { color: var(--white); border-color: var(--white); }

/* Confirm (cancel booking) modal */
.modal-warn-icon {
    font-size: 32px; color: #cc5555; margin-bottom: 16px;
}
.modal-booking-code-display {
    background: var(--dark3); border: 1px solid var(--border);
    padding: 10px 14px; font-size: 12px; color: var(--gold);
    letter-spacing: 1px; margin-bottom: 10px;
}

/* Toast notification */
.toast {
    position: fixed; bottom: 32px; left: 50%; transform: translateX(-50%);
    background: var(--dark2); border: 1px solid var(--border);
    color: var(--white); padding: 14px 28px;
    font-size: 12px; letter-spacing: 1px;
    z-index: 2000; opacity: 0; pointer-events: none;
    transition: opacity 0.3s ease;
    display: flex; align-items: center; gap: 10px;
    white-space: nowrap;
}
.toast.show { opacity: 1; pointer-events: auto; }
.toast.toast-success { border-color: var(--gold); }
.toast.toast-error   { border-color: #cc5555; color: #cc5555; }
.toast i { font-size: 14px; }

@media (max-width: 700px) {
    .modal-box { margin: 16px; padding: 28px 20px 24px; }
    .booking-actions { flex-direction: column; width: 100%; }
    .btn-cancel-booking { justify-content: center; }
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
        <a href="booking_calendar.php" class="btn-book">
            <i class="fa fa-calendar-check"></i> Book Now
        </a>
        <div class="profile-wrapper" id="profileWrapper">
            <button class="btn-profile" id="profileBtn">
                <i class="fa fa-user"></i>
            </button>
            <div class="profile-dropdown">
                <div class="dropdown-header">
                    <div class="name"><?= htmlspecialchars($fullName ?: 'Guest') ?></div>
                    <div class="email"><?= htmlspecialchars($email) ?></div>
                </div>
                <a href="my_profile.php" class="dropdown-item"><i class="fa fa-user-circle"></i> My Profile</a>
                <a href="customer_my_booking.php" class="dropdown-item active-link"><i class="fa fa-calendar-check"></i> My Bookings</a>
                <a href="booking_history.php" class="dropdown-item"><i class="fa fa-clock-rotate-left"></i> Booking History</a>
                <a href="my_rewards.php" class="dropdown-item"><i class="fa fa-gift"></i> Rewards & Points</a>
                <a href="logout.php" class="dropdown-item logout"><i class="fa fa-arrow-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- PAGE CONTENT -->
<div class="page-content">

    <div class="page-header">
        <div class="page-eyebrow">Account</div>
        <h1 class="page-title">My <em>Bookings</em></h1>
        <p class="page-desc">ดูรายการนัดหมายของคุณทั้งหมด — ที่กำลังจะมาถึงและที่ผ่านมาแล้ว</p>
    </div>

    <!-- UPCOMING -->
    <div class="section-label">กำลังจะมาถึง</div>

    <?php if (empty($upcoming)): ?>
    <div class="empty-state">
        <i class="fa fa-calendar-xmark"></i>
        <p>ไม่มีการนัดหมายที่กำลังจะมาถึง</p>
    </div>
    <?php else: ?>
    <div class="booking-list">
        <?php foreach ($upcoming as $b):
            $isPromo    = $b['is_promo'] ?? false;
            $svc        = (!$isPromo && isset($b['service_id'])) ? ($services[$b['service_id']] ?? null) : null;
            $svcName    = $isPromo ? $b['promo_title'] : ($svc['name'] ?? '-');
            $svcDuration= $isPromo ? ($b['duration'] ?? 60) : ($svc['duration'] ?? 60);
            $svcPrice   = $isPromo ? ($b['price'] ?? null) : ($svc['price'] ?? null);
            $endTime    = calcEndTime($b['start_time'], $svcDuration);
            $dateParts  = explode('-', $b['date']);
            $months_short = ['','JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            $bid = $b['booking_id'];
        ?>
        <div class="booking-card" id="card-<?= $bid ?>">
            <div class="booking-date-block">
                <div class="day"><?= (int)$dateParts[2] ?></div>
                <div class="month"><?= $months_short[(int)$dateParts[1]] ?></div>
                <div class="year"><?= (int)$dateParts[0] + 543 ?></div>
            </div>

            <div class="booking-info">
                <div class="booking-service">
                    <?= htmlspecialchars($svcName) ?>
                    <?php if ($isPromo): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(158,90,229,0.15);border:1px solid rgba(158,90,229,0.35);color:#c084fc;font-size:9px;letter-spacing:1.2px;text-transform:uppercase;padding:2px 8px;margin-left:8px;vertical-align:middle;">
                            <i class="fa fa-star" style="font-size:8px;"></i> โปร
                        </span>
                    <?php endif; ?>
                </div>
                <div class="booking-meta">
                    <div class="meta-item">
                        <i class="fa fa-clock"></i>
                        <strong class="time-display-<?= $bid ?>"><?= $b['start_time'] ?> – <?= $endTime ?></strong>
                        <span>(<?= $svcDuration ?> นาที)</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa fa-user-scissors"></i>
                        <?php if ($isPromo): ?>
                            <span style="color:var(--muted);font-style:italic;">ทางร้านจะจัดให้</span>
                        <?php else: ?>
                            ช่าง <strong><?= htmlspecialchars($b['stylist']) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isPromo): ?>
                    <div class="meta-item">
                        <i class="fa fa-tag"></i>
                        <?= htmlspecialchars($b['service_id'] ?? '') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="booking-right">
                <?php if ($svcPrice !== null): ?>
                <div class="booking-price"><?= number_format($svcPrice) ?> <span>บาท</span></div>
                <?php endif; ?>
                <div class="status-tag status-upcoming">✦ Upcoming</div>
                <div class="booking-id"><?= $bid ?></div>
                <div class="booking-actions">
                    <button class="btn-cancel-booking"
                            onclick="openCancelModal('<?= $bid ?>')">
                        <i class="fa fa-xmark"></i> ยกเลิก
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- COMPLETED -->
    <div class="section-label">ประวัติการนัดหมาย</div>

    <?php if (empty($completed)): ?>
    <div class="empty-state">
        <i class="fa fa-clock-rotate-left"></i>
        <p>ยังไม่มีประวัติการนัดหมาย</p>
    </div>
    <?php else: ?>
    <div class="booking-list">
        <?php foreach ($completed as $b):
            $isPromo    = $b['is_promo'] ?? false;
            $svc        = (!$isPromo && isset($b['service_id'])) ? ($services[$b['service_id']] ?? null) : null;
            $svcName    = $isPromo ? $b['promo_title'] : ($svc['name'] ?? '-');
            $svcDuration= $isPromo ? ($b['duration'] ?? 60) : ($svc['duration'] ?? 60);
            $svcPrice   = $isPromo ? ($b['price'] ?? null) : ($svc['price'] ?? null);
            $endTime    = calcEndTime($b['start_time'], $svcDuration);
            $dateParts  = explode('-', $b['date']);
            $months_short = ['','JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            $hasReview  = !empty($b['review']);
            $bid        = $b['booking_id'];
        ?>
        <div class="booking-card" style="display:block;">
            <div style="display:grid;grid-template-columns:auto 1fr auto;">

                <div class="booking-date-block">
                    <div class="day"><?= (int)$dateParts[2] ?></div>
                    <div class="month"><?= $months_short[(int)$dateParts[1]] ?></div>
                    <div class="year"><?= (int)$dateParts[0] + 543 ?></div>
                </div>

                <div class="booking-info">
                    <div class="booking-service">
                        <?= htmlspecialchars($svcName) ?>
                        <?php if ($isPromo): ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(158,90,229,0.15);border:1px solid rgba(158,90,229,0.35);color:#c084fc;font-size:9px;letter-spacing:1.2px;text-transform:uppercase;padding:2px 8px;margin-left:8px;vertical-align:middle;">
                                <i class="fa fa-star" style="font-size:8px;"></i> โปร
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="booking-meta">
                        <div class="meta-item">
                            <i class="fa fa-clock"></i>
                            <strong><?= $b['start_time'] ?> – <?= $endTime ?></strong>
                            <span>(<?= $svcDuration ?> นาที)</span>
                        </div>
                        <div class="meta-item">
                            <i class="fa fa-user-scissors"></i>
                            <?php if ($isPromo): ?>
                                <span style="color:var(--muted);font-style:italic;">ทางร้านจัดให้</span>
                            <?php else: ?>
                                ช่าง <strong><?= htmlspecialchars($b['stylist']) ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isPromo): ?>
                        <div class="meta-item">
                            <i class="fa fa-tag"></i>
                            <?= htmlspecialchars($b['service_id'] ?? '') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="booking-right">
                    <?php if ($svcPrice !== null): ?>
                    <div class="booking-price"><?= number_format($svcPrice) ?> <span>บาท</span></div>
                    <?php endif; ?>
                    <div class="status-tag status-completed">Completed</div>
                    <?php if ($hasReview): ?>
                        <button class="btn-review done" onclick="toggleReview('<?= $bid ?>')">
                            <i class="fa fa-star"></i> ดูรีวิว
                        </button>
                    <?php else: ?>
                        <button class="btn-review" onclick="toggleReview('<?= $bid ?>')">
                            <i class="fa fa-pen-to-square"></i> เขียนรีวิว
                        </button>
                    <?php endif; ?>
                    <div class="booking-id"><?= $bid ?></div>
                </div>

            </div>

            <!-- REVIEW PANEL -->
            <div class="review-panel" id="review-<?= $bid ?>"
                 data-db-id="<?= (int)$b['db_id'] ?>"
                 data-employee-id="<?= (int)$b['employee_id'] ?>">
                <?php if ($hasReview): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                        <div class="review-panel-label" style="margin-bottom:0;">รีวิวของคุณ</div>
                        <button onclick="toggleReview('<?= $bid ?>')" style="background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:16px;line-height:1;padding:0 2px;transition:color .2s;" title="ปิด"><i class="fa fa-xmark"></i></button>
                    </div>
                    <div class="review-display">
                        <div class="stars-display">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fa fa-star <?= $i <= $b['review']['rating'] ? 's-fill' : 's-empty' ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="review-comment"><?= htmlspecialchars($b['review']['comment']) ?></div>
                        <div class="review-meta">ช่าง <?= htmlspecialchars($b['stylist']) ?> · <?= formatDateThai($b['date']) ?></div>
                    </div>

                <?php else: ?>
                    <div class="review-panel-label">รีวิวการบริการ — ช่าง <?= htmlspecialchars($b['stylist']) ?></div>

                    <div class="star-row" id="stars-<?= $bid ?>" data-rating="0">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="star" data-val="<?= $i ?>"
                              onmouseover="hoverStars('<?= $bid ?>',<?= $i ?>)"
                              onmouseout="resetStars('<?= $bid ?>')"
                              onclick="setRating('<?= $bid ?>',<?= $i ?>)">★</span>
                        <?php endfor; ?>
                    </div>

                    <textarea class="review-textarea" id="comment-<?= $bid ?>" rows="3"
                        placeholder="แชร์ประสบการณ์ของคุณ..."></textarea>

                    <div class="review-actions">
                        <button class="btn-submit-review" id="submitBtn-<?= $bid ?>"
                                onclick="submitReview('<?= $bid ?>')" disabled>
                            <i class="fa fa-paper-plane"></i> ส่งรีวิว
                        </button>
                        <button class="btn-cancel-review" onclick="toggleReview('<?= $bid ?>')">
                            ยกเลิก
                        </button>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- CTA -->
    <a href="booking_calendar.php" class="cta-book">
        <i class="fa fa-calendar-plus"></i> จองนัดใหม่
    </a>

</div>

<!-- MODALS & TOAST -->
<!-- ===== MODAL: ยกเลิกการจอง ===== -->
<div class="modal-overlay" id="modalCancel">
    <div class="modal-box">
        <div class="modal-warn-icon"><i class="fa fa-circle-exclamation"></i></div>
        <div class="modal-title">ยืนยัน<em>ยกเลิก</em></div>
        <div class="modal-subtitle">การกระทำนี้ไม่สามารถย้อนกลับได้ คุณแน่ใจหรือไม่?</div>
        <div class="modal-booking-code-display" id="cancelCodeDisplay"></div>
        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeModal('modalCancel')">ย้อนกลับ</button>
            <button class="btn-modal-confirm" style="background:#cc5555;color:#fff;" id="confirmCancelBtn" onclick="doCancel()">
                <i class="fa fa-xmark"></i> ยืนยันยกเลิก
            </button>
        </div>
    </div>
</div>



<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- FOOTER -->
<footer class="footer">
    <p>© 2023 Bright Hair Studio. All rights reserved.</p>
    <div class="footer-links">
        <a href="#">Terms & Conditions</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Service Policy</a>
    </div>
</footer>

<script>
// Profile dropdown
const profileWrapper = document.getElementById('profileWrapper');
const profileBtn = document.getElementById('profileBtn');
profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    profileWrapper.classList.toggle('open');
});
document.addEventListener('click', () => profileWrapper.classList.remove('open'));

// ===== REVIEW FUNCTIONS =====
function toggleReview(bid) {
    document.getElementById('review-' + bid).classList.toggle('open');
}

function hoverStars(bid, val) {
    document.querySelectorAll(`#stars-${bid} .star`).forEach((s, i) => {
        s.classList.toggle('lit', i < val);
    });
}

function resetStars(bid) {
    const saved = parseInt(document.getElementById('stars-' + bid).dataset.rating);
    document.querySelectorAll(`#stars-${bid} .star`).forEach((s, i) => {
        s.classList.toggle('lit', i < saved);
    });
}

function setRating(bid, val) {
    document.getElementById('stars-' + bid).dataset.rating = val;
    resetStars(bid);
    checkReady(bid);
}

function checkReady(bid) {
    const rating  = parseInt(document.getElementById('stars-' + bid).dataset.rating);
    const comment = document.getElementById('comment-' + bid).value.trim();
    document.getElementById('submitBtn-' + bid).disabled = !(rating > 0 && comment.length > 0);
}

document.querySelectorAll('.review-textarea').forEach(ta => {
    ta.addEventListener('input', () => checkReady(ta.id.replace('comment-', '')));
});

async function submitReview(bid) {
    const rating  = parseInt(document.getElementById('stars-' + bid).dataset.rating);
    const comment = document.getElementById('comment-' + bid).value.trim();
    if (!rating || !comment) return;

    const panel      = document.getElementById('review-' + bid);
    const bookingDbId  = panel.dataset.dbId;
    const employeeId   = panel.dataset.employeeId;

    const submitBtn = document.getElementById('submitBtn-' + bid);
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> กำลังส่ง...';

    try {
        const fd = new FormData();
        fd.append('action',      'submit_review');
        fd.append('booking_id',  bookingDbId);
        fd.append('employee_id', employeeId);
        fd.append('rating',      rating);
        fd.append('comment',     comment);

        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const starsHtml = [1,2,3,4,5].map(i =>
                `<i class="fa fa-star ${i <= rating ? 's-fill' : 's-empty'}"></i>`
            ).join('');

            panel.innerHTML = `
                <div class="review-panel-label">รีวิวของคุณ</div>
                <div class="review-display">
                    <div class="stars-display">${starsHtml}</div>
                    <div class="review-comment">${escHtml(comment)}</div>
                    <div class="review-meta">เพิ่งส่งรีวิว</div>
                </div>`;

            const card = panel.closest('.booking-card');
            const btn  = card.querySelector('.btn-review');
            if (btn) {
                btn.innerHTML = '<i class="fa fa-star"></i> ดูรีวิว';
                btn.classList.add('done');
            }
            showToast('ส่งรีวิวเรียบร้อยแล้ว ขอบคุณ!');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa fa-paper-plane"></i> ส่งรีวิว';
        }
    } catch (e) {
        showToast('ไม่สามารถเชื่อมต่อได้', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa fa-paper-plane"></i> ส่งรีวิว';
    }
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

</body>
</html>
<script>
// ===== MODAL HELPERS =====
let currentCancelCode = null;

function openCancelModal(code) {
    currentCancelCode = code;
    document.getElementById('cancelCodeDisplay').textContent = code;
    document.getElementById('modalCancel').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
    }
});

// ===== TOAST =====
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.className = 'toast toast-' + type + ' show';
    t.innerHTML = type === 'success'
        ? '<i class="fa fa-circle-check"></i> ' + msg
        : '<i class="fa fa-circle-xmark"></i> ' + msg;
    setTimeout(() => t.classList.remove('show'), 3200);
}

// ===== CANCEL BOOKING =====
function doCancel() {
    if (!currentCancelCode) return;
    const btn = document.getElementById('confirmCancelBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> กำลังยกเลิก...';

    const fd = new FormData();
    fd.append('booking_code', currentCancelCode);

    fetch('cancel_booking.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal('modalCancel');
                showToast('ยกเลิกการจอง ' + currentCancelCode + ' เรียบร้อยแล้ว');
                // Remove the card from DOM
                const card = document.getElementById('card-' + currentCancelCode);
                if (card) {
                    card.style.transition = 'opacity 0.4s, transform 0.4s';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(-20px)';
                    setTimeout(() => card.remove(), 420);
                }
            } else {
                showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
            }
        })
        .catch(() => showToast('ไม่สามารถเชื่อมต่อได้', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-xmark"></i> ยืนยันยกเลิก';
        });
}

</script>