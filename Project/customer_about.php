<?php
session_start();

if(!isset($_SESSION['user'])){
    header("Location: login.php");
    exit();
}

require 'db.php';
$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['user'] ?? 'Guest';
$userEmail = '';

if ($userId) {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $userName  = $row['first_name'] . ' ' . $row['last_name'];
        $userEmail = $row['email'];
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About Us - Bright Hair Studio</title>

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
    --hero-bg: linear-gradient(135deg, #1a1a1a, #2a2a2a);
    --hero-fade: linear-gradient(to right, transparent 60%, #0d0d0d 100%);
    --dropdown-border-item: rgba(255,255,255,0.04);
    --footer-bottom-color: #444;
    --btn-dark-bg: #0d0d0d;
    --btn-dark-color: #f8f4ef;
    --btn-dark-hover: #222;
}

/* ===== LIGHT MODE ===== */
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
        --hero-bg: linear-gradient(135deg, #e8e8e8, #d0d0d0);
        --hero-fade: linear-gradient(to right, transparent 60%, #ffffff 100%);
        --dropdown-border-item: rgba(0,0,0,0.05);
        --footer-bottom-color: #bbb;
        --btn-dark-bg: #111111;
        --btn-dark-color: #ffffff;
        --btn-dark-hover: #333;
    }
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Jost', sans-serif;
    background: var(--black);
    color: var(--white);
    overflow-x: hidden;
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

/* ===== NAV TABS ===== */
.header-nav {
    display: flex;
    align-items: center;
    gap: 0;
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
    cursor: pointer;
    position: relative;
    white-space: nowrap;
}

.nav-tab i {
    font-size: 13px;
}

.nav-tab:hover,
.nav-tab.active {
    color: var(--white);
    border-bottom-color: var(--gold);
}

/* ===== HEADER RIGHT ===== */
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

.btn-book:hover {
    background: var(--gold-light);
}

/* ===== PROFILE BUTTON + DROPDOWN ===== */
.profile-wrapper {
    position: relative;
}

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

.btn-profile:hover {
    background: var(--gold);
    color: var(--black);
    border-color: var(--gold);
}

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

.profile-wrapper.open .profile-dropdown {
    display: block;
}

.dropdown-header {
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
}

.dropdown-header .name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--white);
    letter-spacing: 0.5px;
}

.dropdown-header .email {
    font-size: 11px;
    color: var(--muted);
    margin-top: 2px;
}

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

.dropdown-item i {
    width: 16px;
    color: var(--gold);
    font-size: 13px;
}

.dropdown-item:hover {
    background: var(--dark3);
    color: var(--white);
}

.dropdown-item.logout {
    color: #cc5555;
}

.dropdown-item.logout i {
    color: #cc5555;
}

/* ===== PAGE HERO (About) ===== */
.page-hero {
    background: var(--dark2);
    border-bottom: 1px solid var(--border);
    padding: 80px 40px 70px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 80px;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.page-hero::before {
    content: 'ABOUT';
    position: absolute;
    right: -20px;
    top: 50%;
    transform: translateY(-50%);
    font-family: 'Cormorant Garamond', serif;
    font-size: 180px;
    font-weight: 700;
    color: rgba(255,159,36,0.04);
    letter-spacing: -5px;
    pointer-events: none;
    user-select: none;
    line-height: 1;
}

.page-hero-content .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-hero-content .eyebrow::before {
    content: '';
    display: block;
    width: 40px;
    height: 1px;
    background: var(--gold);
}

.page-hero-content h1 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 68px;
    font-weight: 300;
    line-height: 1;
    letter-spacing: -1px;
    color: var(--white);
    margin-bottom: 28px;
}

.page-hero-content h1 em {
    font-style: italic;
    color: var(--gold);
}

.page-hero-content p {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.9;
    font-weight: 300;
    max-width: 420px;
}

.page-hero-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    align-self: start;
}

.stat-box {
    background: var(--dark);
    padding: 36px 30px;
    text-align: center;
}

.stat-num {
    font-family: 'Cormorant Garamond', serif;
    font-size: 52px;
    font-weight: 300;
    color: var(--gold);
    line-height: 1;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 10px;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 500;
}

/* ===== MARQUEE BAND ===== */
.band {
    background: var(--gold);
    color: var(--black);
    padding: 14px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    overflow: hidden;
    position: relative;
}

.band-marquee {
    display: flex;
    gap: 48px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
    animation: marquee 18s linear infinite;
    white-space: nowrap;
}

@keyframes marquee {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

/* ===== STORY SECTION ===== */
.story-section {
    padding: 80px 40px;
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 80px;
    align-items: start;
    border-bottom: 1px solid var(--border);
}

.story-heading .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.story-heading .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.story-heading h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 300;
    line-height: 1.2;
    color: var(--white);
}

.story-heading h2 em {
    font-style: italic;
    color: var(--gold);
}

.story-body p {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.9;
    font-weight: 300;
    margin-bottom: 22px;
}

.story-body p:last-child {
    margin-bottom: 0;
}

.story-body strong {
    color: var(--white);
    font-weight: 500;
}

/* ===== MISSION BAND ===== */
.mission-band {
    background: var(--gold);
    padding: 70px 40px;
    text-align: center;
}

.mission-band .quote-mark {
    font-family: 'Cormorant Garamond', serif;
    font-size: 100px;
    line-height: 0.5;
    color: rgba(0,0,0,0.15);
    margin-bottom: 16px;
    display: block;
}

.mission-band blockquote {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 400;
    color: var(--black);
    font-style: italic;
    line-height: 1.3;
    max-width: 700px;
    margin: 0 auto 20px;
    letter-spacing: -0.5px;
}

.mission-band .mission-sub {
    font-size: 11px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: rgba(0,0,0,0.5);
    font-weight: 600;
}

/* ===== VALUES SECTION ===== */
.values-section {
    background: var(--dark2);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 80px 40px;
}

.values-header {
    margin-bottom: 56px;
}

.values-header .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.values-header .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.values-header h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 300;
    color: var(--white);
}

.values-header h2 em {
    font-style: italic;
    color: var(--gold);
}

.values-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
}

.value-card {
    background: var(--dark);
    padding: 44px 36px;
    transition: background 0.3s;
}

.value-card:hover {
    background: var(--dark3);
}

.value-num {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 300;
    color: var(--border);
    line-height: 1;
    margin-bottom: 20px;
}

.value-icon {
    color: var(--gold);
    font-size: 22px;
    margin-bottom: 20px;
}

.value-card h4 {
    font-size: 13px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 14px;
    font-weight: 600;
}

.value-card p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
    font-weight: 300;
}

/* ===== TIMELINE SECTION ===== */
.timeline-section {
    padding: 80px 40px;
    border-bottom: 1px solid var(--border);
}

.timeline-header {
    margin-bottom: 56px;
}

.timeline-header .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.timeline-header .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.timeline-header h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 300;
    color: var(--white);
}

.timeline-header h2 em {
    font-style: italic;
    color: var(--gold);
}

.timeline {
    position: relative;
    padding-left: 40px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 0;
    top: 8px;
    bottom: 8px;
    width: 1px;
    background: var(--border);
}

.timeline-item {
    position: relative;
    padding-bottom: 48px;
    padding-left: 44px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -5px;
    top: 8px;
    width: 10px;
    height: 10px;
    background: var(--gold);
    border-radius: 50%;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -1px;
    top: 12px;
    bottom: -48px;
    width: 1px;
    background: transparent;
}

.timeline-item:last-child::after {
    display: none;
}

.timeline-date {
    font-size: 10px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--gold);
    font-weight: 600;
    margin-bottom: 10px;
}

.timeline-item h4 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    font-weight: 500;
    color: var(--white);
    margin-bottom: 8px;
    letter-spacing: 0.3px;
}

.timeline-item p {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.8;
    font-weight: 300;
    max-width: 560px;
}

/* ===== PARTNER SECTION ===== */
.partner-section {
    background: var(--dark2);
    border-bottom: 1px solid var(--border);
    padding: 70px 40px;
    text-align: center;
}

.partner-section .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.partner-section .eyebrow::before,
.partner-section .eyebrow::after {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.partner-section h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 300;
    color: var(--white);
    margin-bottom: 14px;
}

.partner-section p {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.8;
    max-width: 500px;
    margin: 0 auto 48px;
    font-weight: 300;
}

.partner-badge {
    display: inline-flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border);
    padding: 20px 36px;
    background: var(--dark);
}

.partner-badge i {
    color: var(--gold);
    font-size: 22px;
}

.partner-badge span {
    font-size: 12px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--white);
    font-weight: 500;
}

.partner-badge .badge-sub {
    display: block;
    font-size: 10px;
    letter-spacing: 1.5px;
    color: var(--muted);
    text-transform: uppercase;
    margin-top: 4px;
}

/* ===== INFO SECTION ===== */
.info-section {
    padding: 80px 40px;
    border-bottom: 1px solid var(--border);
}

.info-section .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.info-section .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.info-section h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 300;
    color: var(--white);
    margin-bottom: 48px;
}

.info-section h2 em {
    font-style: italic;
    color: var(--gold);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
}

.info-card {
    background: var(--dark);
    padding: 36px 32px;
    display: flex;
    gap: 22px;
    align-items: flex-start;
    transition: background 0.3s;
}

.info-card:hover {
    background: var(--dark3);
}

.info-card-icon {
    color: var(--gold);
    font-size: 20px;
    min-width: 24px;
    margin-top: 2px;
    flex-shrink: 0;
}

.info-card h4 {
    font-size: 12px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 8px;
    font-weight: 600;
}

.info-card p {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.7;
    font-weight: 300;
}

/* ===== GOLD BAND CTA ===== */
.gold-band {
    background: var(--gold);
    padding: 50px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 40px;
}

.gold-band-text h3 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 400;
    color: var(--black);
    line-height: 1.2;
}

.gold-band-text p {
    font-size: 13px;
    color: rgba(0,0,0,0.6);
    margin-top: 8px;
    letter-spacing: 0.5px;
}

.btn-dark {
    background: var(--btn-dark-bg);
    color: var(--btn-dark-color);
    border: none;
    padding: 15px 36px;
    font-size: 11px;
    letter-spacing: 2.5px;
    font-weight: 700;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'Jost', sans-serif;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.2s;
}

.btn-dark:hover { background: var(--btn-dark-hover); }

/* ===== FOOTER ===== */
.footer {
    background: var(--black);
    border-top: 1px solid var(--border);
    padding: 60px 40px 30px;
}

.footer-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 40px;
    padding-bottom: 40px;
    border-bottom: 1px solid var(--border);
}

.footer-brand img {
    height: 30px;
    filter: var(--logo-filter);
    margin-bottom: 18px;
}

.footer-brand p {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.8;
    margin-bottom: 20px;
    font-weight: 300;
}

.footer-socials {
    display: flex;
    gap: 8px;
}

.footer-socials a {
    width: 34px;
    height: 34px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 13px;
    text-decoration: none;
    transition: all 0.2s;
}

.footer-socials a:hover {
    border-color: var(--gold);
    color: var(--gold);
}

.footer-col h4 {
    font-size: 10px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 20px;
    font-weight: 600;
}

.footer-col ul {
    list-style: none;
}

.footer-col ul li {
    margin-bottom: 12px;
}

.footer-col ul li a {
    color: var(--muted);
    text-decoration: none;
    font-size: 12px;
    font-weight: 300;
    transition: color 0.2s;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.footer-col ul li a::before {
    content: '—';
    color: var(--gold);
    font-size: 10px;
}

.footer-col ul li a:hover { color: var(--gold); }

.footer-contact-item {
    display: flex;
    gap: 12px;
    margin-bottom: 14px;
}

.footer-contact-item i {
    color: var(--gold);
    font-size: 13px;
    margin-top: 2px;
    flex-shrink: 0;
    width: 14px;
}

.footer-contact-item span {
    font-size: 11px;
    color: var(--muted);
    line-height: 1.6;
    font-weight: 300;
}

.footer-contact-item strong {
    display: block;
    color: #ccc;
    font-size: 11px;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
    font-weight: 500;
}

.footer-bottom {
    padding-top: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.footer-bottom p {
    font-size: 11px;
    color: var(--footer-bottom-color);
    letter-spacing: 0.5px;
}

.footer-links {
    display: flex;
    gap: 24px;
}

.footer-links a {
    font-size: 11px;
    color: var(--footer-bottom-color);
    text-decoration: none;
    transition: color 0.2s;
    letter-spacing: 0.5px;
}

.footer-links a:hover { color: var(--gold); }

/* Mobile */
@media (max-width: 768px) {
    .header { padding: 0 16px; }
    .header-nav { display: none; }

    .page-hero {
        grid-template-columns: 1fr;
        padding: 50px 24px 40px;
        gap: 40px;
    }
    .page-hero::before { display: none; }
    .page-hero-content h1 { font-size: 48px; }
    .page-hero-stats { grid-template-columns: 1fr 1fr; }

    .story-section {
        grid-template-columns: 1fr;
        gap: 32px;
        padding: 50px 24px;
    }

    .values-grid {
        grid-template-columns: 1fr;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .timeline-section,
    .partner-section,
    .info-section {
        padding: 50px 24px;
    }

    .mission-band {
        padding: 50px 24px;
    }

    .mission-band blockquote {
        font-size: 28px;
    }

    .gold-band {
        flex-direction: column;
        text-align: center;
        padding: 40px 24px;
    }

    .footer-grid { grid-template-columns: 1fr 1fr; }
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

    <!-- NAV TABS -->
    <nav class="header-nav">
        <a href="customer_home.php" class="nav-tab">
            <i class="fa fa-house"></i> Home
        </a>
        <a href="services.php" class="nav-tab">
            <i class="fa fa-scissors"></i> Services
        </a>
        <a href="promotions.php" class="nav-tab">
            <i class="fa fa-star"></i> Promotions
        </a>
        <a href="customer_gallery.php" class="nav-tab">
            <i class="fa fa-images"></i> Gallery
        </a>
        <a href="customer_about.php" class="nav-tab active">
            <i class="fa fa-circle-info"></i> About
        </a>
    </nav>

    <div class="header-right">
        <a href="booking_calendar.php" class="btn-book">
            <i class="fa fa-calendar-check"></i> Book Now
        </a>

        <!-- PROFILE BUTTON -->
        <div class="profile-wrapper" id="profileWrapper">
            <button class="btn-profile" id="profileBtn">
                <i class="fa fa-user"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="name"><?php echo htmlspecialchars($userName); ?></div>
                    <div class="email"><?php echo htmlspecialchars($userEmail); ?></div>
                </div>
                <a href="my_profile.php" class="dropdown-item">
                    <i class="fa fa-user-circle"></i> My Profile
                </a>
                <a href="customer_my_booking.php" class="dropdown-item">
                    <i class="fa fa-calendar-check"></i> My Bookings
                </a>
                <a href="booking_history.php" class="dropdown-item">
                    <i class="fa fa-clock-rotate-left"></i> Booking History
                </a>
                <a href="my_rewards.php" class="dropdown-item">
                    <i class="fa fa-gift"></i> Rewards & Points
                </a>
                <a href="logout.php" class="dropdown-item logout">
                    <i class="fa fa-arrow-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<!-- PAGE HERO -->
<section class="page-hero">
    <div class="page-hero-content">
        <div class="eyebrow">Our Story · Since 2022</div>
        <h1>About<br><em>Bright</em><br>Hair Studio</h1>
        <p>ร้านทำผมพรีเมี่ยมใจกลางสามย่าน ที่เชื่อว่าทรงผมที่ดีที่สุดคือทรงที่สะท้อนตัวตนของคุณ — ไม่ใช่ทรงที่เหมือนใคร</p>
    </div>

    <div class="page-hero-stats">
        <div class="stat-box">
            <div class="stat-num">3+</div>
            <div class="stat-label">ปีแห่งประสบการณ์</div>
        </div>
        <div class="stat-box">
            <div class="stat-num">30+</div>
            <div class="stat-label">ที่นั่งบริการ</div>
        </div>
        <div class="stat-box">
            <div class="stat-num">11–50</div>
            <div class="stat-label">ทีมงานมืออาชีพ</div>
        </div>
        <div class="stat-box">
            <div class="stat-num">5</div>
            <div class="stat-label">บริการครบวงจร</div>
        </div>
    </div>
</section>

<!-- MARQUEE BAND -->
<div class="band">
    <div class="band-marquee">
        <span>✦ HAIRCUT</span>
        <span>✦ COLORING</span>
        <span>✦ TREATMENT</span>
        <span>✦ PERM</span>
        <span>✦ STRAIGHTENING</span>
        <span>✦ LUXURY STYLING</span>
        <span>✦ HAIRCUT</span>
        <span>✦ COLORING</span>
        <span>✦ TREATMENT</span>
        <span>✦ PERM</span>
        <span>✦ STRAIGHTENING</span>
        <span>✦ LUXURY STYLING</span>
    </div>
</div>

<!-- STORY SECTION -->
<section class="story-section">
    <div class="story-heading">
        <div class="eyebrow">Our Story</div>
        <h2>จุดเริ่มต้นของ<br><em>Bright</em></h2>
    </div>
    <div class="story-body">
        <p>
            <strong>Bright Hair Studio</strong> ก่อตั้งขึ้นในปี พ.ศ. 2565 (ค.ศ. 2022) ณ ซอยจุฬาลงกรณ์ 50 ย่านสามย่าน กรุงเทพฯ โดยมีวิสัยทัศน์ที่ชัดเจนตั้งแต่วันแรก — ร้านทำผมที่ไม่ได้มีไว้แค่ตัดผม แต่มีไว้เพื่อช่วยให้คุณค้นพบ "ลุค" ที่เป็นตัวคุณอย่างแท้จริง
        </p>
        <p>
            ด้วยแนวคิด <strong>"ความเป็นคุณสำคัญที่สุด"</strong> ร้านของเรามุ่งสร้างประสบการณ์ที่ลูกค้าทุกคนออกไปพร้อมทรงผมที่ดูดี เหมาะกับรูปหน้า บุคลิก และสไตล์ส่วนตัว ไม่ใช่แค่ทรงที่กำลังเป็นกระแส
        </p>
        <p>
            ในปี 2566 เราได้รับการรับรองให้เป็น <strong>Selective Partner Salon</strong> ของแบรนด์ NIGAO ยืนยันมาตรฐานการใช้ผลิตภัณฑ์คุณภาพสูงในทุกบริการ และในโอกาสครบรอบ 3 ปี ต้นปี 2569 เราได้ขยายพื้นที่ร้านสู่ Bright Hair Studio ขนาดใหม่ รองรับลูกค้าได้มากกว่า 30 ที่นั่ง พร้อมยกระดับมาตรฐานการบริการในทุกมิติ
        </p>
        <p>
            ปัจจุบัน Bright Hair Studio ดูแลโดยทีมงาน 11–50 คน ที่ผ่านการอบรมอย่างมืออาชีพ พร้อมให้บริการทุกวัน ตั้งแต่ 10:00 – 19:30 น. บรรยากาศเป็นกันเอง เดินทางสะดวกด้วย MRT สามย่าน
        </p>
    </div>
</section>

<!-- MISSION QUOTE BAND -->
<div class="mission-band">
    <span class="quote-mark">"</span>
    <blockquote>ความเป็นคุณสำคัญที่สุด</blockquote>
    <div class="mission-sub">Being You is What's Essential — พันธกิจหลักของเรา</div>
</div>

<!-- VALUES SECTION -->
<section class="values-section">
    <div class="values-header">
        <div class="eyebrow">What We Stand For</div>
        <h2>คุณค่าที่เรา<em>ยึดมั่น</em></h2>
    </div>
    <div class="values-grid">
        <div class="value-card">
            <div class="value-num">01</div>
            <div class="value-icon"><i class="fa fa-scissors"></i></div>
            <h4>ช่างมืออาชีพ</h4>
            <p>ทีมช่างทุกคนผ่านการฝึกอบรมเทคนิคขั้นสูงทั้งงานตัดและทำสี พร้อมให้คำแนะนำเชิงสร้างสรรค์ที่เหมาะกับรูปหน้าและบุคลิกของคุณโดยเฉพาะ</p>
        </div>
        <div class="value-card">
            <div class="value-num">02</div>
            <div class="value-icon"><i class="fa fa-flask"></i></div>
            <h4>ผลิตภัณฑ์คุณภาพสูง</h4>
            <p>เราใช้เฉพาะผลิตภัณฑ์แบรนด์ชั้นนำ อาทิ NIGAO เพื่อผลลัพธ์ที่เงางามและยั่งยืน ทุก treatment ได้รับการคัดสรรให้เหมาะกับสภาพผมของคุณ</p>
        </div>
        <div class="value-card">
            <div class="value-num">03</div>
            <div class="value-icon"><i class="fa fa-heart"></i></div>
            <h4>บริการด้วยใจ</h4>
            <p>บรรยากาศอบอุ่น เป็นกันเอง ไม่ว่าคุณจะมาคนเดียวหรือมาเป็นกลุ่ม เรายินดีต้อนรับทุกเพศทุกวัย พร้อมโปรพิเศษสำหรับนักศึกษา</p>
        </div>
    </div>
</section>

<!-- TIMELINE SECTION -->
<section class="timeline-section">
    <div class="timeline-header">
        <div class="eyebrow">Our Journey</div>
        <h2>เส้นทางของ<br><em>Bright Hair Studio</em></h2>
    </div>

    <div class="timeline">
        <div class="timeline-item">
            <div class="timeline-date">พฤษภาคม 2565 · 2022</div>
            <h4>ก่อตั้ง Bright Hair Studio</h4>
            <p>เปิดร้านครั้งแรก ณ ซอยจุฬาลงกรณ์ 50 สามย่าน กรุงเทพฯ พร้อมพันธกิจ "ความเป็นคุณสำคัญที่สุด" และจดทะเบียนบริษัทอย่างเป็นทางการด้วยทุนจดทะเบียน 2.3 ล้านบาท</p>
        </div>
        <div class="timeline-item">
            <div class="timeline-date">เมษายน 2567 · 2024</div>
            <h4>Selective Partner Salon — NIGAO</h4>
            <p>ได้รับการรับรองเป็นพันธมิตรอย่างเป็นทางการกับแบรนด์ NIGAO ผลิตภัณฑ์ดูแลผมคุณภาพสูง ยืนยันมาตรฐานการบริการในระดับสากล</p>
        </div>
        <div class="timeline-item">
            <div class="timeline-date">มกราคม 2569 · 2026</div>
            <h4>Grand Opening — ครบรอบ 3 ปี</h4>
            <p>ขยายพื้นที่ร้านสู่ Bright Hair Studio ขนาดใหม่ รองรับลูกค้าได้มากกว่า 30 ที่นั่ง พร้อมยกระดับมาตรฐานการบริการและประสบการณ์ลูกค้าในทุกมิติ</p>
        </div>
    </div>
</section>

<!-- PARTNER SECTION -->
<section class="partner-section">
    <div class="eyebrow">Official Partnership</div>
    <h2>พันธมิตรที่เราไว้วางใจ</h2>
    <p>เราร่วมมือกับแบรนด์ผลิตภัณฑ์ดูแลผมชั้นนำ เพื่อให้มั่นใจว่าผลลัพธ์ที่คุณได้รับนั้นดีที่สุดทุกครั้ง</p>
    <div class="partner-badge">
        <i class="fa fa-award"></i>
        <span>
            NIGAO Selective Partner Salon
            <span class="badge-sub">Official Certified Partner · Since 2024</span>
        </span>
    </div>
</section>

<!-- VISIT INFO SECTION -->
<section class="info-section">
    <div class="eyebrow">Find Us</div>
    <h2>ข้อมูลการ<em>ติดต่อ</em></h2>

    <div class="info-grid">
        <div class="info-card">
            <div class="info-card-icon"><i class="fa fa-map-marker-alt"></i></div>
            <div>
                <h4>ที่อยู่</h4>
                <p>186 ซอยจุฬาลงกรณ์ 50<br>แขวงวังใหม่ เขตปทุมวัน<br>กรุงเทพฯ 10330<br><br>ใกล้ MRT สามย่าน · มีที่จอดรถ</p>
            </div>
        </div>
        <div class="info-card">
            <div class="info-card-icon"><i class="fa fa-clock"></i></div>
            <div>
                <h4>เวลาทำการ</h4>
                <p>เปิดทุกวัน<br>10:00 – 19:30 น.<br><br>รับลูกค้าทุกวันไม่มีวันหยุด<br>แนะนำจองล่วงหน้าเพื่อสิทธิ์ก่อน</p>
            </div>
        </div>
        <div class="info-card">
            <div class="info-card-icon"><i class="fa fa-phone"></i></div>
            <div>
                <h4>โทรศัพท์</h4>
                <p>092-964-5991<br><br>โทรหาเราเพื่อสอบถามบริการ<br>หรือขอนัดหมายล่วงหน้า</p>
            </div>
        </div>
        <div class="info-card">
            <div class="info-card-icon"><i class="fab fa-line"></i></div>
            <div>
                <h4>LINE Official</h4>
                <p>@brighthairstudio<br><br>ติดต่อผ่าน LINE ได้ตลอดเวลา<br>สำหรับสอบถามโปรโมชั่นและนัดหมาย</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA GOLD BAND -->
<div class="gold-band">
    <div class="gold-band-text">
        <h3>พร้อมสำหรับลุคใหม่แล้วหรือยัง?</h3>
        <p>จองนัดหมายของคุณวันนี้ — ช่างผมพรีเมี่ยมพร้อมดูแลคุณ</p>
    </div>
    <a href="booking_calendar.php" class="btn-dark">Book Now →</a>
</div>

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
                <li><a href="customer_about.php">เกี่ยวกับเรา</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Contact Us</h4>
            <div class="footer-contact-item">
                <i class="fab fa-line"></i>
                <span><strong>LINE</strong>@brighthairstudio</span>
            </div>
            <div class="footer-contact-item">
                <i class="fa fa-phone"></i>
                <span><strong>Phone</strong>092-964-5991</span>
            </div>
            <div class="footer-contact-item">
                <i class="fa fa-map-marker-alt"></i>
                <span><strong>Address</strong>186 Soi Chulalongkorn 50, Wang Mai, Pathum Wan, Bangkok 10330</span>
            </div>
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

<script>
// Profile dropdown toggle
const profileWrapper = document.getElementById('profileWrapper');
const profileBtn = document.getElementById('profileBtn');

profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    profileWrapper.classList.toggle('open');
});

document.addEventListener('click', () => {
    profileWrapper.classList.remove('open');
});
</script>

</body>
</html>
