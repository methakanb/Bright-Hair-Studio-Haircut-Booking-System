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
<title>Customer Home - Bright Hair Studio</title>

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

/* ===== HERO ===== */
.hero {
    display: grid;
    grid-template-columns: 1fr 1fr;
    min-height: 520px;
}

.hero-image {
    background: var(--hero-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 13px;
    letter-spacing: 2px;
    position: relative;
    overflow: hidden;
    min-height: 520px;
}

.hero-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--hero-fade);
}

.hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hero-content {
    background: var(--black);
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 60px 60px 60px 50px;
    position: relative;
}

.hero-label {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.hero-label::before {
    content: '';
    display: block;
    width: 40px;
    height: 1px;
    background: var(--gold);
}

.hero-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 72px;
    font-weight: 300;
    line-height: 1;
    letter-spacing: -1px;
    color: var(--white);
    margin-bottom: 8px;
}

.hero-title em {
    font-style: italic;
    color: var(--gold);
}

.hero-subtitle {
    font-size: 13px;
    color: var(--muted);
    letter-spacing: 1px;
    line-height: 1.8;
    margin-bottom: 40px;
    max-width: 300px;
}

.hero-actions {
    display: flex;
    gap: 16px;
    align-items: center;
}

.btn-primary {
    background: var(--gold);
    color: var(--black);
    border: none;
    padding: 14px 32px;
    font-size: 11px;
    letter-spacing: 2.5px;
    font-weight: 700;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'Jost', sans-serif;
    text-decoration: none;
    transition: background 0.2s;
}

.btn-primary:hover { background: var(--gold-light); }

.btn-secondary {
    color: var(--white);
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    text-decoration: none;
    border-bottom: 1px solid var(--border);
    padding-bottom: 2px;
    transition: border-color 0.2s, color 0.2s;
}

.btn-secondary:hover {
    color: var(--gold);
    border-color: var(--gold);
}

/* ===== FULL-WIDTH BAND ===== */
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

/* ===== SECTION HEADING ===== */
.section-band {
    padding: 60px 40px 40px;
}

.section-band .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.section-band .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.section-band h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 40px;
    font-weight: 400;
    color: var(--white);
    letter-spacing: -0.5px;
}

/* ===== PROMOTIONS CAROUSEL ===== */
.promo-carousel-wrapper {
    margin: 0 40px 60px;
    position: relative;
    overflow: hidden;
}

.promo-carousel-track {
    display: flex;
    transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    gap: 1px;
    background: var(--border);
}

.promo-card {
    background: var(--dark2);
    flex: 0 0 calc(50% - 0.5px);
    min-width: calc(50% - 0.5px);
    aspect-ratio: 1 / 1;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    transition: background 0.3s;
}

.promo-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.4s ease;
}

.promo-card:hover img {
    transform: scale(1.03);
}

/* Carousel dots */
.promo-carousel-dots {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 18px;
}

.promo-dot {
    width: 24px;
    height: 3px;
    background: var(--border);
    cursor: pointer;
    transition: background 0.3s, width 0.3s;
}

.promo-dot.active {
    background: var(--gold);
    width: 36px;
}

/* Carousel nav arrows */
.promo-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    width: 40px;
    height: 40px;
    background: rgba(0,0,0,0.5);
    border: 1px solid var(--border);
    color: var(--white);
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s, border-color 0.2s;
}

.promo-arrow:hover {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
}

.promo-arrow.prev { left: 12px; }
.promo-arrow.next { right: 12px; }

/* ===== DARK BAND (WHY US) ===== */
.why-band {
    background: var(--dark2);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 70px 40px;
}

.why-band-inner {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 60px;
    align-items: start;
}

.why-heading .eyebrow {
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.why-heading .eyebrow::before {
    content: '';
    display: block;
    width: 30px;
    height: 1px;
    background: var(--gold);
}

.why-heading h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 300;
    line-height: 1.2;
    color: var(--white);
}

.why-heading h2 em {
    font-style: italic;
    color: var(--gold);
}

.why-items {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.why-item {
    display: flex;
    align-items: flex-start;
    gap: 24px;
    padding: 28px 0;
    border-bottom: 1px solid var(--border);
}

.why-item:first-child {
    padding-top: 0;
}

.why-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.why-num {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 300;
    color: var(--border);
    line-height: 1;
    min-width: 40px;
}

.why-icon {
    color: var(--gold);
    font-size: 20px;
    min-width: 24px;
    margin-top: 4px;
}

.why-text h4 {
    font-size: 14px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 8px;
}

.why-text p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    font-weight: 300;
}

/* ===== GOLD DIVIDER BAND ===== */
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
    .hero { grid-template-columns: 1fr; }
    .hero-image { min-height: 260px; }
    .hero-content { padding: 40px 24px; }
    .hero-title { font-size: 48px; }
    .promo-carousel-wrapper { margin: 0 0 40px; }
    .why-band-inner { grid-template-columns: 1fr; gap: 32px; }
    .footer-grid { grid-template-columns: 1fr 1fr; }
    .gold-band { flex-direction: column; text-align: center; padding: 40px 24px; }
    .section-band, .why-band { padding-left: 24px; padding-right: 24px; }
    .band { padding: 14px 24px; }
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
        <a href="#" class="nav-tab active">
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
        <a href="customer_about.php" class="nav-tab">
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

<!-- HERO -->
<section class="hero">
    <div class="hero-image">
        <span><img src="posemodel2.png" style="height:100%; width:100%; object-fit:cover;"></span>
    </div>
    <div class="hero-content">
        <div class="hero-label">Premium Hair Studio · Bangkok</div>
        <h1 class="hero-title">BRIGHT<br><em>Hair</em><br>Studio</h1>
        <p class="hero-subtitle">ร้านทำผมสไตล์พรีเมี่ยม โดยช่างมืออาชีพที่พร้อมดูแลทุกรายละเอียด</p>
        <div class="hero-actions">
            <a href="booking_calendar.php" class="btn-primary">Book Appointment</a>
            <a href="services.php" class="btn-secondary">Our Services</a>
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

<!-- PROMOTIONS -->
<section class="section-band">
    <div class="eyebrow">Exclusive Offers</div>
    <h2>โปรโมชั่น</h2>
</section>

<div class="promo-carousel-wrapper">
    <button class="promo-arrow prev" id="promoPrev"><i class="fa fa-chevron-left"></i></button>
    <button class="promo-arrow next" id="promoNext"><i class="fa fa-chevron-right"></i></button>

    <div class="promo-carousel-track" id="promoTrack">
        <div class="promo-card">
            <img src="promo1.png" alt="Hair Cut Special - บุคลากรจุฬา">
        </div>
        <div class="promo-card">
            <img src="promo2.png" alt="พี่บัณฑิตจบใหม่ ทุกสถาบัน">
        </div>
        <div class="promo-card">
            <img src="promo3.png" alt="Special Discount for Student">
        </div>
    </div>

    <div class="promo-carousel-dots" id="promoDots">
        <div class="promo-dot active" data-index="0"></div>
        <div class="promo-dot" data-index="1"></div>
    </div>
</div>

<!-- WHY CHOOSE US -->
<section class="why-band">
    <div class="why-band-inner">
        <div class="why-heading">
            <div class="eyebrow">Our Standards</div>
            <h2>Why Choose<br><em>Us?</em></h2>
        </div>
        <div class="why-items">
            <div class="why-item">
                <div class="why-num">01</div>
                <div class="why-icon"><i class="fa fa-scissors"></i></div>
                <div class="why-text">
                    <h4>ช่างมืออาชีพ</h4>
                    <p>ทีมช่างผ่านการฝึกอบรมและมีประสบการณ์ พร้อมดูแลทุกรายละเอียดเพื่อผลลัพธ์ที่ดีที่สุด</p>
                </div>
            </div>
            <div class="why-item">
                <div class="why-num">02</div>
                <div class="why-icon"><i class="fa fa-star"></i></div>
                <div class="why-text">
                    <h4>บริการระดับพรีเมี่ยม</h4>
                    <p>สินค้าและอุปกรณ์คุณภาพสูง จากแบรนด์ชั้นนำระดับสากล เพื่อผลลัพธ์ที่ยั่งยืน</p>
                </div>
            </div>
            <div class="why-item">
                <div class="why-num">03</div>
                <div class="why-icon"><i class="fa fa-calendar-check"></i></div>
                <div class="why-text">
                    <h4>จองนัดได้ง่าย</h4>
                    <p>ระบบจองออนไลน์สะดวก รวดเร็ว ไม่ต้องรอคิวนาน จัดการตารางได้ทุกที่ทุกเวลา</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA GOLD BAND -->
<div class="gold-band">
    <div class="gold-band-text">
        <h3>Ready for a New Look?</h3>
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
                <li><a href="#">ติดต่อเรา</a></li>
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

// Active nav tab
document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
    });
});

// ===== PROMO CAROUSEL =====
(function() {
    const track   = document.getElementById('promoTrack');
    const dots    = document.querySelectorAll('.promo-dot');
    const prevBtn = document.getElementById('promoPrev');
    const nextBtn = document.getElementById('promoNext');
    const cards   = track.querySelectorAll('.promo-card');
    const total   = cards.length;          // 3 cards
    const visible = 2;                     // show 2 at a time
    const maxStep = total - visible;       // = 1  (step 0 or 1)
    let current   = 0;
    let timer;

    function goTo(index) {
        current = ((index % (maxStep + 1)) + (maxStep + 1)) % (maxStep + 1);
        // Each card is 50% wide; move by 50% per step
        track.style.transform = `translateX(calc(-${current * 50}% - ${current}px))`;
        dots.forEach((d, i) => d.classList.toggle('active', i === current));
    }

    function next() { goTo(current + 1); }
    function prev() { goTo(current - 1); }

    function startAuto() {
        clearInterval(timer);
        timer = setInterval(next, 3500);
    }

    prevBtn.addEventListener('click', () => { prev(); startAuto(); });
    nextBtn.addEventListener('click', () => { next(); startAuto(); });
    dots.forEach(d => d.addEventListener('click', () => {
        goTo(parseInt(d.dataset.index));
        startAuto();
    }));

    startAuto();
})();
</script>

</body>
</html>