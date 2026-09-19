<?php
session_start();
if(!isset($_SESSION['user'])){
    header("Location: login.php");
    exit();
}

require_once 'db.php';
// Fetch promotions and their included services
$promotionsData = [];
try {
    $stmt = $pdo->query("SELECT * FROM promotions WHERE active = 1 ORDER BY id ASC");
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($promotions as $p) {
        $promoId = $p['id'];
        $stmtSvc = $pdo->prepare("SELECT s.name, s.code, s.duration_min FROM promotion_services ps JOIN services s ON ps.service_id = s.id WHERE ps.promotion_id = ?");
        $stmtSvc->execute([$promoId]);
        $svcs = $stmtSvc->fetchAll(PDO::FETCH_ASSOC);

        $totalDuration = 0;
        foreach($svcs as $s) {
            $totalDuration += $s['duration_min'];
        }

        $p['services'] = $svcs;
        $p['total_duration'] = $totalDuration;
        
        // Generate Thai Date String
        $thaiMonths = [1=>'ม.ค.', 2=>'ก.พ.', 3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.', 6=>'มิ.ย.', 7=>'ก.ค.', 8=>'ส.ค.', 9=>'ก.ย.', 10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.'];
        if (!empty($p['start_date']) && !empty($p['end_date'])) {
            $ts1 = strtotime($p['start_date']);
            $ts2 = strtotime($p['end_date']);
            $m1 = $thaiMonths[(int)date('n', $ts1)];
            $m2 = $thaiMonths[(int)date('n', $ts2)];
            $y = (int)date('Y', $ts2) + 543;
            if (date('Y-m', $ts1) === date('Y-m', $ts2)) {
                $p['duration_days'] = date('j', $ts1) . ' - ' . date('j', $ts2) . ' ' . $m1 . ' ' . $y;
            } else {
                $p['duration_days'] = date('j', $ts1) . ' ' . $m1 . ' - ' . date('j', $ts2) . ' ' . $m2 . ' ' . $y;
            }
        }

        $promotionsData[$p['id']] = $p;
    }
} catch (PDOException $e) {
    // Graceful fallback if tables don't exist yet
    $promotionsData = [];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Promotions - Bright Hair Studio</title>

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
    --logo-filter: none;
    --footer-bottom-color: #444;
    --dropdown-border-item: rgba(255,255,255,0.04);
    --card-bg: #1c1c1c;
    --input-bg: #1c1c1c;
    --day-bg: #242424;
    --day-hover: rgba(255,159,36,0.15);
    --day-past: #181818;
    --day-past-text: #333;
    --day-selected-text: #0d0d0d;
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
        --logo-filter: none;
        --footer-bottom-color: #bbb;
        --dropdown-border-item: rgba(0,0,0,0.05);
        --card-bg: #f5f5f5;
        --input-bg: #ffffff;
        --day-bg: #ebebeb;
        --day-hover: rgba(255,159,36,0.18);
        --day-past: #f0f0f0;
        --day-past-text: #ccc;
        --day-selected-text: #ffffff;
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
.nav-tab i { font-size: 13px; }
.nav-tab:hover, .nav-tab.active { color: var(--white); border-bottom-color: var(--gold); }
.header-right { display: flex; align-items: center; gap: 8px; }
.btn-book {
    background: var(--gold); color: var(--black); border: none;
    padding: 10px 24px; font-size: 11px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    text-decoration: none; transition: background 0.2s;
    display: flex; align-items: center; gap: 8px;
}
.btn-book:hover { background: var(--gold-light); }
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

/* ===== PAGE HERO ===== */
.page-hero {
    background: var(--dark);
    border-bottom: 1px solid var(--border);
    padding: 60px 40px 50px;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.page-hero::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at center top, rgba(255,159,36,0.08) 0%, transparent 70%);
    pointer-events: none;
}
.page-hero-eyebrow {
    font-size: 10px; letter-spacing: 4px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 16px;
    display: flex; align-items: center; justify-content: center; gap: 14px;
}
.page-hero-eyebrow::before, .page-hero-eyebrow::after {
    content: ''; display: block; width: 40px; height: 1px; background: var(--gold); opacity: 0.5;
}
.page-hero-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 56px; font-weight: 300; line-height: 1;
    color: var(--white); margin-bottom: 16px;
}
.page-hero-title em { font-style: italic; color: var(--gold); }
.page-hero-sub {
    font-size: 13px; color: var(--muted); letter-spacing: 0.5px; max-width: 480px; margin: 0 auto;
}

/* ===== MAIN CONTENT ===== */
.main { flex: 1; max-width: 1100px; width: 100%; margin: 0 auto; padding: 60px 40px 80px; }

/* ===== PROMO GRID ===== */
.promo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
}

/* ===== PROMO CARD ===== */
.promo-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: transform 0.3s, box-shadow 0.3s;
    cursor: pointer;
    position: relative;
}
.promo-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 48px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,159,36,0.3);
}
.promo-card.expired {
    opacity: 0.5;
    cursor: default;
    pointer-events: none;
}

/* Badge */
.promo-badge {
    position: absolute;
    top: 14px; right: 14px;
    background: var(--gold);
    color: var(--black);
    font-size: 10px; font-weight: 700;
    letter-spacing: 1.5px; text-transform: uppercase;
    padding: 4px 10px;
    z-index: 2;
}
.promo-badge.bundle { background: #9b5de5; color: #fff; }
.promo-badge.limited { background: #e5413a; color: #fff; }
.promo-badge.new { background: #2ec4b6; color: #fff; }

/* Header strip */
.promo-card-header {
    padding: 28px 28px 20px;
    border-bottom: 1px solid var(--border);
    position: relative;
}
.promo-card-header::after {
    content: '';
    position: absolute; bottom: 0; left: 28px; right: 28px;
    height: 1px;
}
.promo-icon {
    width: 44px; height: 44px;
    background: rgba(255,159,36,0.1);
    border: 1px solid rgba(255,159,36,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: var(--gold);
    margin-bottom: 16px;
}
.promo-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 24px; font-weight: 600; color: var(--white);
    line-height: 1.2; margin-bottom: 8px;
}
.promo-tagline {
    font-size: 12px; color: var(--muted); letter-spacing: 0.5px;
    line-height: 1.5;
}

/* Services list */
.promo-card-body { padding: 20px 28px; }
.promo-services-label {
    font-size: 9px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 10px;
}
.promo-services-list {
    list-style: none;
    display: flex; flex-direction: column; gap: 6px;
    margin-bottom: 20px;
}
.promo-services-list li {
    font-size: 12px; color: var(--muted);
    display: flex; align-items: center; gap: 8px;
}
.promo-services-list li i {
    font-size: 10px; color: var(--gold); flex-shrink: 0;
}

/* Pricing */
.promo-pricing {
    display: flex; align-items: flex-end; gap: 12px;
    margin-bottom: 6px;
}
.promo-price-new {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px; font-weight: 600; color: var(--gold); line-height: 1;
}
.promo-price-old {
    font-size: 14px; color: var(--muted);
    text-decoration: line-through; margin-bottom: 4px;
}
.promo-saving {
    font-size: 10px; color: #2ec4b6; letter-spacing: 1px;
    text-transform: uppercase; font-weight: 600;
}

/* Duration */
.promo-meta {
    display: flex; gap: 16px; margin: 14px 0 20px;
    padding: 12px 0; border-top: 1px solid rgba(255,255,255,0.05);
}
.promo-meta-item {
    font-size: 11px; color: var(--muted);
    display: flex; align-items: center; gap: 6px;
}
.promo-meta-item i { color: var(--gold); font-size: 11px; }

/* Validity */
.promo-validity {
    font-size: 10px; color: var(--muted); letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 6px; margin-bottom: 20px;
}
.promo-validity i { color: var(--gold); }

/* CTA */
.promo-cta {
    display: flex;
    width: 100%;
    background: var(--gold);
    color: var(--black);
    border: none;
    padding: 14px 20px;
    font-size: 11px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer;
    font-family: 'Jost', sans-serif;
    align-items: center; justify-content: center; gap: 8px;
    transition: background 0.2s;
    text-decoration: none;
}
.promo-cta:hover { background: var(--gold-light); }

/* ===== MODAL OVERLAY ===== */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
    animation: fadeIn 0.2s ease;
}
.modal-overlay.open { display: flex; }

@keyframes fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

.modal {
    background: var(--dark);
    border: 1px solid var(--border);
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideUp 0.3s ease;
    position: relative;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to   { opacity: 1; transform: translateY(0); }
}

.modal-close {
    position: absolute; top: 16px; right: 16px;
    width: 36px; height: 36px;
    background: var(--dark3); border: 1px solid var(--border);
    color: var(--muted); font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all 0.2s; z-index: 10;
}
.modal-close:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }

.modal-header {
    padding: 36px 36px 24px;
    border-bottom: 1px solid var(--border);
}
.modal-eyebrow {
    font-size: 9px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px;
}
.modal-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 34px; font-weight: 600; color: var(--white); line-height: 1.1;
    margin-bottom: 8px;
}
.modal-tagline { font-size: 13px; color: var(--muted); }

.modal-body { padding: 28px 36px; }

/* Services in modal */
.modal-services-label {
    font-size: 9px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px;
}
.modal-services-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 8px; margin-bottom: 24px;
}
.modal-service-item {
    background: var(--dark2);
    border: 1px solid var(--border);
    padding: 10px 14px;
    font-size: 12px; color: var(--white);
    display: flex; align-items: center; gap: 8px;
}
.modal-service-item i { color: var(--gold); font-size: 11px; }

/* Pricing block */
.modal-pricing-block {
    background: var(--dark2);
    border: 1px solid var(--border);
    padding: 20px; display: flex;
    align-items: center; justify-content: space-between;
    margin-bottom: 24px;
}
.modal-price-new {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px; font-weight: 600; color: var(--gold); line-height: 1;
}
.modal-price-new span { font-size: 18px; }
.modal-price-info { text-align: right; }
.modal-price-old { font-size: 14px; color: var(--muted); text-decoration: line-through; }
.modal-saving { font-size: 12px; color: #2ec4b6; font-weight: 600; margin-top: 4px; }
.modal-duration { font-size: 12px; color: var(--muted); margin-top: 4px; }

/* ===== DATE & TIME PICKER ===== */
.picker-section {
    margin-bottom: 24px;
}
.picker-label {
    font-size: 9px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
}
.picker-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Calendar */
.calendar-wrap { border: 1px solid var(--border); }
.cal-nav {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px; background: var(--dark3);
    border-bottom: 1px solid var(--border);
}
.cal-month {
    font-family: 'Cormorant Garamond', serif;
    font-size: 18px; font-weight: 500; color: var(--white);
}
.cal-arrow {
    width: 30px; height: 30px;
    background: transparent; border: 1px solid var(--border);
    color: var(--muted); cursor: pointer; font-size: 11px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.cal-arrow:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }
.cal-arrow.hidden { visibility: hidden; }

.cal-weekdays {
    display: grid; grid-template-columns: repeat(7, 1fr);
    background: var(--dark2);
    border-bottom: 1px solid var(--border);
}
.cal-weekday {
    padding: 8px 4px;
    text-align: center; font-size: 10px; letter-spacing: 1px;
    text-transform: uppercase; color: var(--muted);
}
.cal-grid {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 1px; background: var(--border); padding: 1px;
}
.cal-day-cell, .cal-empty {
    background: var(--dark2);
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
    font-size: 12px; cursor: pointer; transition: all 0.15s;
    position: relative;
}
.cal-empty { background: var(--dark); cursor: default; }
.cal-day-cell:hover { background: var(--day-hover); }
.cal-day-cell.past { color: var(--day-past-text); cursor: default; pointer-events: none; }
.cal-day-cell.today { color: var(--gold); font-weight: 600; }
.cal-day-cell.out-of-range { color: var(--day-past-text); cursor: default; pointer-events: none; opacity: 0.4; }
.cal-day-cell.selected {
    background: var(--gold) !important;
    color: var(--day-selected-text) !important;
    font-weight: 700;
}

/* Time slots */
.time-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
}
.time-slot {
    padding: 10px 4px;
    text-align: center; font-size: 12px; letter-spacing: 0.5px;
    background: var(--dark2); border: 1px solid var(--border);
    cursor: pointer; transition: all 0.15s; color: var(--white);
    line-height: 1.4;
}
.time-slot:hover { background: var(--day-hover); border-color: var(--gold); }
.time-slot.selected { background: var(--gold); color: var(--black); border-color: var(--gold); font-weight: 700; }
.time-slot.disabled { color: var(--day-past-text); cursor: default; opacity: 0.4; pointer-events: none; }
.time-slot.no-space { color: var(--day-past-text); cursor: default; opacity: 0.3; pointer-events: none; }
.time-slot.booked {
    background: rgba(204,85,85,0.08);
    border-color: rgba(204,85,85,0.35);
    color: rgba(204,85,85,0.55);
    cursor: not-allowed;
    pointer-events: none;
    font-style: italic;
}

/* ===== VALIDITY NOTE ===== */
.validity-note {
    font-size: 11px; color: var(--muted);
    display: flex; align-items: flex-start; gap: 8px;
    background: var(--dark2); border: 1px solid var(--border);
    padding: 12px 16px; margin-bottom: 24px;
}
.validity-note i { color: var(--gold); margin-top: 1px; flex-shrink: 0; }

/* ===== CONFIRM BTN ===== */
.modal-confirm-btn {
    width: 100%; background: var(--gold); color: var(--black);
    border: none; padding: 16px 20px;
    font-size: 12px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; cursor: pointer;
    font-family: 'Jost', sans-serif;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    transition: background 0.2s;
}
.modal-confirm-btn:hover { background: var(--gold-light); }
.modal-confirm-btn:disabled {
    background: var(--dark3); color: var(--muted); cursor: not-allowed;
}

/* ===== FOOTER ===== */
.footer {
    background: var(--dark);
    border-top: 1px solid var(--border);
    padding: 60px 40px 0;
}
.footer-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.5fr;
    gap: 40px;
    max-width: 1100px;
    margin: 0 auto;
    padding-bottom: 48px;
}
.footer-brand img { height: 28px; margin-bottom: 16px; }
.footer-brand p { font-size: 12px; color: var(--muted); line-height: 1.7; margin-bottom: 20px; }
.footer-socials { display: flex; gap: 10px; }
.footer-socials a {
    width: 34px; height: 34px; background: var(--dark2);
    border: 1px solid var(--border); color: var(--muted); font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all 0.2s;
}
.footer-socials a:hover { background: var(--gold); color: var(--black); border-color: var(--gold); }
.footer-col h4 {
    font-size: 10px; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 18px;
}
.footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 10px; }
.footer-col ul li a {
    font-size: 13px; color: var(--muted); text-decoration: none; transition: color 0.2s;
}
.footer-col ul li a:hover { color: var(--white); }
.footer-contact-item {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 12px; color: var(--muted); margin-bottom: 12px; line-height: 1.5;
}
.footer-contact-item i { color: var(--gold); margin-top: 2px; flex-shrink: 0; width: 14px; }
.footer-contact-item strong { display: block; color: var(--white); font-weight: 500; }
.footer-bottom {
    border-top: 1px solid var(--footer-bottom-color);
    padding: 18px 0;
    max-width: 1100px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px; color: var(--muted);
}
.footer-links { display: flex; gap: 24px; }
.footer-links a { color: var(--muted); text-decoration: none; transition: color 0.2s; font-size: 11px; }
.footer-links a:hover { color: var(--white); }

/* ===== SUCCESS STATE ===== */
.success-banner {
    display: none;
    background: rgba(46, 196, 182, 0.1);
    border: 1px solid rgba(46, 196, 182, 0.4);
    padding: 16px 20px;
    margin-bottom: 24px;
    font-size: 13px;
    color: #2ec4b6;
    align-items: center;
    gap: 10px;
}
.success-banner.show { display: flex; }
</style>

</head>
<body>

<!-- ANNOUNCEMENT BAR -->
<div class="announcement-bar">✦ &nbsp; New Season Collection — Book Your Appointment Today &nbsp; ✦</div>

<!-- HEADER -->
<div class="header">
    <div class="header-logo">
        <a href="customer_home.php"><img src="logo-crop.png" alt="Bright Hair Studio"></a>
    </div>

    <nav class="header-nav">
        <a href="customer_home.php" class="nav-tab">
            <i class="fa fa-house"></i> Home
        </a>
        <a href="services.php" class="nav-tab">
            <i class="fa fa-scissors"></i> Services
        </a>
        <a href="promotions.php" class="nav-tab active">
            <i class="fa fa-star"></i> Promotions
        </a>
        <a href="customer_gallery.php" class="nav-tab">
            <i class="fa fa-images"></i> Gallery
        </a>
        <a href="customer_about.php" class="nav-tab">
            <i class="fa fa-circle-info"></i> About
        </a>
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
                    <div class="name"><?php echo htmlspecialchars($_SESSION['user'] ?? 'Guest'); ?></div>
                    <div class="email">member@brighthairstudio.com</div>
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

<!-- PAGE HERO -->
<div class="page-hero">
    <div class="page-hero-eyebrow">Exclusive Offers</div>
    <h1 class="page-hero-title">Special <em>Promotions</em></h1>
    <p class="page-hero-sub">บริการพรีเมี่ยมในราคาพิเศษ · เลือกวันที่และเวลาที่สะดวก · จองง่ายผ่านออนไลน์</p>
</div>

<!-- MAIN CONTENT -->
<main class="main">
    <div class="promo-grid" id="promoGrid">
        <?php foreach ($promotionsData as $pid => $promo): ?>
        <?php 
            $badgeColorClass = '';
            $badgeType = strtolower($promo['type']);
            if($badgeType == 'bundle') $badgeColorClass = 'bundle';
            else if($badgeType == 'limited') $badgeColorClass = 'limited';
            else if($badgeType == 'new') $badgeColorClass = 'new';
            else $badgeColorClass = '';
            
            $saving = $promo['price_old'] - $promo['price_new'];
            $pct = $promo['price_old'] > 0 ? round(($saving / $promo['price_old']) * 100) : 0;
        ?>
        <div class="promo-card" onclick="openModal('promo<?= $promo['id'] ?>')">
            <?php if($promo['type'] !== 'normal'): ?>
            <span class="promo-badge <?= $badgeColorClass ?>"><?= htmlspecialchars(ucfirst($promo['type'])) ?></span>
            <?php endif; ?>
            <div class="promo-card-header">
                <div class="promo-icon"><i class="fa <?= htmlspecialchars($promo['icon']) ?>"></i></div>
                <div class="promo-name"><?= nl2br(htmlspecialchars($promo['name'])) ?></div>
                <div class="promo-tagline"><?= htmlspecialchars($promo['description']) ?></div>
            </div>
            <div class="promo-card-body">
                <div class="promo-services-label">บริการที่รวม</div>
                <ul class="promo-services-list">
                    <?php foreach ($promo['services'] as $psvc): ?>
                    <li><i class="fa fa-check"></i> <?= htmlspecialchars($psvc['name']) ?> (<?= htmlspecialchars($psvc['code']) ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <div class="promo-pricing">
                    <div class="promo-price-new"><?= number_format($promo['price_new']) ?></div>
                    <div class="promo-price-old"><?= number_format($promo['price_old']) ?> บาท</div>
                </div>
                <div class="promo-saving">ประหยัด <?= number_format($saving) ?> บาท · ลด <?= $pct ?>%</div>
                <div class="promo-meta">
                    <div class="promo-meta-item"><i class="fa fa-clock"></i> <?= $promo['total_duration'] ?> นาที</div>
                    <div class="promo-meta-item"><i class="fa fa-calendar-days"></i> <?= htmlspecialchars($promo['duration_days']) ?></div>
                </div>
                <div class="promo-cta" style="pointer-events:none;">
                    <i class="fa fa-calendar-check"></i> เลือกวันและเวลา
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <img src="logo-crop.png" alt="Bright Hair Studio">
            <p>ร้านทำผมสไตล์พรีเมี่ยม<br>โดยช่างมืออาชีพ · Bangkok</p>
            <div class="footer-socials">
                <a href="https://www.facebook.com/BrightHairStudio/" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
                <a href="https://www.instagram.com/bright.hair.studio/" target="_blank"><i class="fab fa-instagram"></i></a>
                <a href="https://page.line.me/679hncxi" target="_blank"><i class="fab fa-line"></i></a>
            </div>
        </div>
        <div class="footer-col">
            <h4>Our Services</h4>
            <ul>
                <li><a href="#">ตัดผม</a></li>
                <li><a href="#">ทำสีผม</a></li>
                <li><a href="#">ดัดผม</a></li>
                <li><a href="#">ยืดผม</a></li>
                <li><a href="#">ทรีทเมนต์</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="booking_calendar.php">จองนัด</a></li>
                <li><a href="promotions.php">โปรโมชั่น</a></li>
                <li><a href="#">แกลเลอรี่</a></li>
                <li><a href="#">ติดต่อเรา</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Contact Us</h4>
            <div class="footer-contact-item"><i class="fab fa-line"></i><span><strong>LINE</strong>@brighthairstudio</span></div>
            <div class="footer-contact-item"><i class="fa fa-phone"></i><span><strong>Phone</strong>092-964-5991</span></div>
            <div class="footer-contact-item"><i class="fa fa-map-marker-alt"></i><span><strong>Address</strong>186 Soi Chulalongkorn 50, Wang Mai, Pathum Wan, Bangkok 10330</span></div>
        </div>
    </div>
    <div class="footer-bottom">
        <p>© 2023 Bright Hair Studio. All rights reserved.</p>
        <div class="footer-links">
            <a href="#">Terms & Conditions</a>
            <a href="#">Privacy Policy</a>
            <a href="#">Service Policy</a>
        </div>
    </div>
</footer>

<!-- ===== MODAL ===== -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnBg(event)">
    <div class="modal" id="modal">
        <button class="modal-close" onclick="closeModal()"><i class="fa fa-xmark"></i></button>

        <div class="modal-header">
            <div class="modal-eyebrow" id="mEyebrow">Exclusive Promotion</div>
            <div class="modal-title" id="mTitle">—</div>
            <div class="modal-tagline" id="mTagline">—</div>
        </div>

        <div class="modal-body">

            <!-- Services -->
            <div class="modal-services-label">บริการที่รวมในโปรโมชั่น</div>
            <div class="modal-services-grid" id="mServicesGrid"></div>

            <!-- Pricing -->
            <div class="modal-pricing-block">
                <div class="modal-price-new"><span id="mPriceNew">—</span> <span style="font-size:18px;">บาท</span></div>
                <div class="modal-price-info">
                    <div class="modal-price-old" id="mPriceOld">— บาท</div>
                    <div class="modal-saving" id="mSaving">—</div>
                    <div class="modal-duration" id="mDuration"><i class="fa fa-clock" style="color:var(--gold)"></i> — นาที</div>
                </div>
            </div>

            <!-- Validity note -->
            <div class="validity-note">
                <i class="fa fa-circle-info"></i>
                <span id="mValidityNote">โปรโมชั่นนี้ใช้ได้ในวันที่ที่กำหนดเท่านั้น</span>
            </div>

            <!-- Date Picker -->
            <div class="picker-section">
                <div class="picker-label">เลือกวันที่</div>
                <div class="calendar-wrap">
                    <div class="cal-nav">
                        <button class="cal-arrow" id="calPrev" onclick="prevMonth()"><i class="fa fa-chevron-left"></i></button>
                        <div class="cal-month" id="calMonthYear">—</div>
                        <button class="cal-arrow" id="calNext" onclick="nextMonth()"><i class="fa fa-chevron-right"></i></button>
                    </div>
                    <div class="cal-weekdays">
                        <div class="cal-weekday">อา</div>
                        <div class="cal-weekday">จ</div>
                        <div class="cal-weekday">อ</div>
                        <div class="cal-weekday">พ</div>
                        <div class="cal-weekday">พฤ</div>
                        <div class="cal-weekday">ศ</div>
                        <div class="cal-weekday">ส</div>
                    </div>
                    <div class="cal-grid" id="calGrid"></div>
                </div>
            </div>

            <!-- Time Picker -->
            <div class="picker-section">
                <div class="picker-label">เลือกเวลา</div>
                <div class="time-grid" id="timeGrid"></div>
            </div>

            <!-- Confirm -->
            <button class="modal-confirm-btn" id="modalConfirmBtn" disabled onclick="confirmBooking()">
                <i class="fa fa-calendar-check"></i> ยืนยันการจอง
            </button>

        </div>
    </div>
</div>

<script>
// ===== PROMOTIONS DATA =====
<?php
    $jsPromos = [];
    foreach ($promotionsData as $pid => $p) {
        $saving = $p['price_old'] - $p['price_new'];
        $pct = $p['price_old'] > 0 ? round(($saving / $p['price_old']) * 100) : 0;
        
        $savingTxt = 'ประหยัด ' . number_format($saving) . ' บาท · ลด ' . $pct . '%';
        $servicesTxt = array_map(function($s) { return $s['name'] . ' (' . $s['code'] . ')'; }, $p['services']);
        
        $jsPromos['promo'.$p['id']] = [
            'id' => $p['id'],
            'title' => $p['name'],
            'tagline' => $p['description'],
            'badge' => ucfirst($p['type']),
            'services' => $servicesTxt,
            'priceNew' => (float)$p['price_new'],
            'priceOld' => (float)$p['price_old'],
            'saving' => $savingTxt,
            'durationMin' => $p['total_duration'],
            'slots' => ceil($p['total_duration'] / 30),
            'validText' => 'โปรโมชั่นนี้ใช้ได้ตั้งแต่ ' . $p['duration_days'] . ' เท่านั้น'
        ];
    }
?>
const PROMOS = <?= json_encode($jsPromos, JSON_UNESCAPED_UNICODE) ?>;
for (const p in PROMOS) {
    // defaults to current month
    let d = new Date();
    PROMOS[p].startDate = new Date(d.getFullYear(), d.getMonth(), 1);
    PROMOS[p].endDate = new Date(d.getFullYear(), d.getMonth() + 1, 0);
}

// Time slots เริ่ม 11:00 และบวกช่วงเวลาบริการ (durationMin) ไปเรื่อยๆ
// จะสร้างใหม่ใน renderTimes() ตาม durationMin ของโปรโมชั่น
const SHOP_OPEN_MINUTES  = 11 * 60;      // 11:00
const SHOP_CLOSE_MINUTES = 20 * 60;      // 20:00 (ห้ามเกิน 2 ทุ่ม)

const MONTHS_TH = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const MONTHS_EN = ['January','February','March','April','May','June','July','August','September','October','November','December'];

let currentPromo = null;
let calYear, calMonth;
let selectedDate = null;
let selectedTime = null;

// cache: key = "promo_title|YYYY-MM-DD" => [{start_min, end_min}, ...]
const bookedPromoCache = {};

// ===== OPEN / CLOSE MODAL =====
function openModal(promoId) {
    currentPromo = PROMOS[promoId];
    selectedDate = null;
    selectedTime = null;

    // Fill header
    document.getElementById('mTitle').textContent    = currentPromo.title;
    document.getElementById('mTagline').textContent  = currentPromo.tagline;
    document.getElementById('mEyebrow').textContent  = currentPromo.badge + ' Promotion';
    document.getElementById('mPriceNew').textContent = currentPromo.priceNew.toLocaleString();
    document.getElementById('mPriceOld').textContent = currentPromo.priceOld.toLocaleString() + ' บาท';
    document.getElementById('mSaving').textContent   = currentPromo.saving;
    document.getElementById('mDuration').innerHTML   = `<i class="fa fa-clock" style="color:var(--gold)"></i> ${currentPromo.durationMin} นาที`;
    document.getElementById('mValidityNote').textContent = currentPromo.validText;

    // Fill services
    const grid = document.getElementById('mServicesGrid');
    grid.innerHTML = '';
    currentPromo.services.forEach(s => {
        const div = document.createElement('div');
        div.className = 'modal-service-item';
        div.innerHTML = `<i class="fa fa-scissors"></i> ${s}`;
        grid.appendChild(div);
    });

    // Init calendar to promo start month
    calYear  = currentPromo.startDate.getFullYear();
    calMonth = currentPromo.startDate.getMonth();
    renderCal();
    renderTimes([]);

    document.getElementById('modalConfirmBtn').disabled = true;
    document.getElementById('modalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

function closeModalOnBg(e) {
    if (e.target === document.getElementById('modalOverlay')) closeModal();
}

// ===== CALENDAR =====
function renderCal() {
    const today = new Date();
    today.setHours(0,0,0,0);

    const firstDay    = new Date(calYear, calMonth, 1).getDay();
    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    document.getElementById('calMonthYear').textContent = MONTHS_EN[calMonth] + ' ' + calYear;

    // Prev/next arrows
    const prevMonthDate = new Date(calYear, calMonth - 1, 1);
    const nextMonthDate = new Date(calYear, calMonth + 1, 1);
    const promoStart = new Date(currentPromo.startDate); promoStart.setHours(0,0,0,0);
    const promoEnd   = new Date(currentPromo.endDate);   promoEnd.setHours(23,59,59,0);

    document.getElementById('calPrev').classList.toggle('hidden',
        prevMonthDate.getFullYear() < promoStart.getFullYear() ||
        (prevMonthDate.getFullYear() === promoStart.getFullYear() && prevMonthDate.getMonth() < promoStart.getMonth())
    );
    document.getElementById('calNext').classList.toggle('hidden',
        nextMonthDate.getFullYear() > promoEnd.getFullYear() ||
        (nextMonthDate.getFullYear() === promoEnd.getFullYear() && nextMonthDate.getMonth() > promoEnd.getMonth())
    );

    // Empty cells
    for (let i = 0; i < firstDay; i++) {
        const e = document.createElement('div'); e.className = 'cal-empty'; grid.appendChild(e);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(calYear, calMonth, d);
        date.setHours(0,0,0,0);
        const div = document.createElement('div');
        div.className = 'cal-day-cell';
        div.textContent = d;

        const isPast       = date < today;
        const outOfRange   = date < promoStart || date > promoEnd;
        const isSelected   = selectedDate && date.toDateString() === selectedDate.toDateString();
        const isToday      = date.toDateString() === today.toDateString();

        if (isPast || outOfRange) {
            div.classList.add(isPast ? 'past' : 'out-of-range');
        } else {
            if (isToday) div.classList.add('today');
            if (isSelected) div.classList.add('selected');
            div.onclick = () => selectDate(date, div);
        }
        grid.appendChild(div);
    }
}

function selectDate(date, el) {
    document.querySelectorAll('.cal-day-cell').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    selectedDate = date;
    selectedTime = null;
    fetchBookedPromoAndRender();
    checkConfirm();
}

function prevMonth() {
    calMonth--;
    if (calMonth < 0) { calMonth = 11; calYear--; }
    renderCal();
}
function nextMonth() {
    calMonth++;
    if (calMonth > 11) { calMonth = 0; calYear++; }
    renderCal();
}

// ===== TIME SLOTS =====
/**
 * สร้าง time slots โดยเริ่ม 11:00 แล้วบวก durationMin ไปเรื่อยๆ
 * จนกว่า end time จะเกิน 20:00
 */
function buildSlots(durationMin) {
    const slots = [];
    let cur = SHOP_OPEN_MINUTES;
    while (true) {
        const end = cur + durationMin;
        if (end > SHOP_CLOSE_MINUTES) break;
        const hh = String(Math.floor(cur / 60)).padStart(2, '0');
        const mm = String(cur % 60).padStart(2, '0');
        slots.push({ time: `${hh}:${mm}`, minutes: cur, endMinutes: end });
        cur = end;
    }
    return slots;
}

async function fetchBookedPromoAndRender() {
    if (!selectedDate || !currentPromo) {
        renderTimes([]);
        return;
    }

    const yyyy    = selectedDate.getFullYear();
    const mm      = String(selectedDate.getMonth() + 1).padStart(2, '0');
    const dd      = String(selectedDate.getDate()).padStart(2, '0');
    const dateStr = `${yyyy}-${mm}-${dd}`;
    const cacheKey = `${encodeURIComponent(currentPromo.title)}|${dateStr}`;

    if (bookedPromoCache[cacheKey] !== undefined) {
        renderTimes(bookedPromoCache[cacheKey]);
        return;
    }

    // แสดง loading
    const grid = document.getElementById('timeGrid');
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--gold);padding:16px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> กำลังโหลด...</div>';

    try {
        const res  = await fetch(`get_booked_promo_slots.php?promo=${encodeURIComponent(currentPromo.title)}&date=${dateStr}`);
        const data = await res.json();
        bookedPromoCache[cacheKey] = data.booked || [];
        renderTimes(bookedPromoCache[cacheKey]);
    } catch (e) {
        console.warn('fetch promo slots failed:', e);
        renderTimes([]);
    }
}

function renderTimes(bookedRanges = []) {
    const grid = document.getElementById('timeGrid');
    grid.innerHTML = '';

    if (!currentPromo) return;

    const slots = buildSlots(currentPromo.durationMin);

    if (!selectedDate) {
        slots.forEach(slot => {
            const div = document.createElement('div');
            div.className = 'time-slot disabled';
            div.textContent = slot.time;
            grid.appendChild(div);
        });
        return;
    }

    slots.forEach(slot => {
        const div = document.createElement('div');
        div.className = 'time-slot';

        // แสดงช่วงเวลา เช่น "11:00 – 13:45"
        const endH = String(Math.floor(slot.endMinutes / 60)).padStart(2, '0');
        const endM = String(slot.endMinutes % 60).padStart(2, '00');
        div.innerHTML = `<strong>${slot.time}</strong><br><span style="font-size:9px;opacity:0.6;">ถึง ${endH}:${endM}</span>`;

        // ตรวจ overlap กับ booking ที่มีอยู่
        const isBooked = bookedRanges.some(b =>
            slot.minutes < b.end_min && slot.endMinutes > b.start_min
        );

        if (isBooked) {
            div.classList.add('booked');
        } else {
            if (selectedTime === slot.time) div.classList.add('selected');
            div.onclick = () => selectTime(slot.time, div);
        }

        grid.appendChild(div);
    });

    // ถ้าไม่มี slot เลย
    if (slots.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--muted);padding:16px;font-size:12px;">ไม่มีช่วงเวลาว่างสำหรับบริการนี้</div>';
    }
}

function selectTime(t, el) {
    document.querySelectorAll('.time-slot').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    selectedTime = t;
    checkConfirm();
}

// ===== CONFIRM =====
function checkConfirm() {
    document.getElementById('modalConfirmBtn').disabled = !(selectedDate && selectedTime);
}

function confirmBooking() {
    if (!selectedDate || !selectedTime || !currentPromo) return;
    const d = selectedDate;
    const dateStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const promoName  = encodeURIComponent(currentPromo.title);
    const price      = currentPromo.priceNew;
    const durationMin = currentPromo.durationMin;
    // ส่งไป confirm.php — ใช้ service=promo_name, stylist=ไม่มี, source=promo
    window.location.href = `confirm.php?service=${promoName}&stylist=&date=${dateStr}&time=${selectedTime}&price=${price}&duration=${durationMin}&source=promo`;
}

// ===== PROFILE DROPDOWN =====
const profileWrapper = document.getElementById('profileWrapper');
document.getElementById('profileBtn').addEventListener('click', e => {
    e.stopPropagation();
    profileWrapper.classList.toggle('open');
});
document.addEventListener('click', () => profileWrapper.classList.remove('open'));
</script>

</body>
</html>