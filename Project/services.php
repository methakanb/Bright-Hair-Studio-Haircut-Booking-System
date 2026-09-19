<?php
session_start();
require_once 'db.php';

try {
    $stmt = $pdo->query("SELECT * FROM services WHERE active=1 ORDER BY id ASC");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $services = [];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Services - Bright Hair Studio</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ===== ROOT VARS (dark default) ===== */
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
    --card-border: rgba(255,159,36,0.18);
    --card-hover: #222;
    --divider: rgba(255,255,255,0.06);
    --icon-bg: #242424;
    --service-banner-bg: #181818;
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
        --footer-bottom-color: #bbb;
        --card-bg: #f5f5f5;
        --card-border: rgba(255,159,36,0.22);
        --card-hover: #ebebeb;
        --divider: rgba(0,0,0,0.07);
        --icon-bg: #e8e8e8;
        --service-banner-bg: #ebebeb;
    }
}

* { margin: 0; padding: 0; box-sizing: border-box; }

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

/* ===== HERO BAND ===== */
.hero-band {
    background: var(--dark2);
    border-bottom: 1px solid var(--border);
    padding: 64px 40px 56px;
    position: relative;
    overflow: hidden;
}
.hero-band::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 70% 80% at 80% 50%, rgba(255,159,36,0.05) 0%, transparent 70%);
    pointer-events: none;
}
.hero-band-inner {
    max-width: 1100px; margin: 0 auto;
    display: flex; align-items: center; gap: 60px;
}
.hero-band-content { flex: 1; min-width: 0; }
.hero-band-image {
    flex: 0 0 420px;
    height: 340px;
    position: relative;
    overflow: hidden;
}
.hero-band-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
    display: block;
    filter: brightness(0.92) contrast(1.05);
}
.hero-band-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to left, transparent 60%, var(--dark2) 100%);
    pointer-events: none;
}
.hero-eyebrow {
    font-size: 10px; letter-spacing: 5px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
}
.hero-eyebrow::before { content: ''; display: block; width: 30px; height: 1px; background: var(--gold); }
.hero-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 56px; font-weight: 300; line-height: 1;
    margin-bottom: 20px; color: var(--white);
}
.hero-title em { font-style: italic; color: var(--gold); }
.hero-desc {
    font-size: 13px; color: var(--muted); line-height: 1.8;
    font-weight: 300; max-width: 520px; margin-bottom: 32px;
}
.btn-primary {
    display: inline-flex; align-items: center; gap: 10px;
    background: var(--gold); color: var(--black);
    padding: 14px 32px; font-size: 11px; letter-spacing: 2.5px;
    font-weight: 700; text-transform: uppercase; text-decoration: none;
    transition: background 0.2s; border: none; cursor: pointer; font-family: 'Jost', sans-serif;
}
.btn-primary:hover { background: var(--gold-light); }

/* ===== MARQUEE BAND ===== */
.band { background: var(--gold); padding: 13px 0; overflow: hidden; }
.band-marquee {
    display: flex; gap: 48px; font-size: 11px; font-weight: 700;
    letter-spacing: 3px; text-transform: uppercase; color: var(--black);
    animation: marquee 24s linear infinite; white-space: nowrap;
}
@keyframes marquee {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

/* ===== SERVICES SECTION ===== */
.services-section {
    max-width: 1100px; margin: 0 auto; padding: 72px 40px 80px;
}
.section-eyebrow {
    font-size: 10px; letter-spacing: 4px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px;
    display: flex; align-items: center; gap: 12px;
}
.section-eyebrow::before { content: ''; display: block; width: 30px; height: 1px; background: var(--gold); }
.section-heading {
    font-family: 'Cormorant Garamond', serif;
    font-size: 40px; font-weight: 300; color: var(--white); margin-bottom: 12px;
}
.section-heading em { font-style: italic; color: var(--gold); }
.section-sub {
    font-size: 13px; color: var(--muted); font-weight: 300; margin-bottom: 48px;
}

/* ===== SERVICE GRID ===== */
.service-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    background: var(--border);
}
.service-card {
    background: var(--card-bg);
    display: flex; flex-direction: column;
    transition: background 0.2s;
    position: relative;
}
.service-card:hover { background: var(--card-hover); }

/* ===== SERVICE BANNER (replaces image) ===== */
.service-banner {
    background: var(--service-banner-bg);
    border-bottom: 1px solid var(--border);
    padding: 28px 24px 22px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    position: relative;
    overflow: hidden;
    min-height: 130px;
}

/* decorative gold arc */
.service-banner::after {
    content: '';
    position: absolute;
    right: -30px; bottom: -30px;
    width: 110px; height: 110px;
    border-radius: 50%;
    border: 1px solid rgba(255,159,36,0.12);
    pointer-events: none;
}
.service-banner::before {
    content: '';
    position: absolute;
    right: -10px; bottom: -10px;
    width: 70px; height: 70px;
    border-radius: 50%;
    border: 1px solid rgba(255,159,36,0.08);
    pointer-events: none;
}

.banner-id {
    font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--gold); font-weight: 600;
    background: rgba(255,159,36,0.08);
    border: 1px solid rgba(255,159,36,0.2);
    padding: 3px 10px;
    display: inline-block;
}

.banner-icon {
    width: 44px; height: 44px;
    background: rgba(255,159,36,0.1);
    border: 1px solid rgba(255,159,36,0.25);
    display: flex; align-items: center; justify-content: center;
    color: var(--gold); font-size: 18px;
    flex-shrink: 0;
}

.banner-title-th {
    font-family: 'Cormorant Garamond', serif;
    font-size: 26px; font-weight: 500;
    color: var(--white); line-height: 1.15;
}

.banner-title-en {
    font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted);
}

/* Card body */
.service-card-body {
    padding: 20px 22px 24px;
    flex: 1; display: flex; flex-direction: column;
}
.service-desc {
    font-size: 12px; color: var(--muted); line-height: 1.7;
    font-weight: 300; margin-bottom: 18px; flex: 1;
}
.service-meta {
    display: flex; align-items: center; gap: 16px; margin-bottom: 18px;
}
.meta-badge {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; color: var(--muted); font-weight: 300;
}
.meta-badge i { color: var(--gold); font-size: 11px; }

/* Price row */
.price-row-single {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 14px;
    background: rgba(255,159,36,0.05);
    border: 1px solid rgba(255,159,36,0.18);
    margin-bottom: 18px;
}
.price-row-single .price-label {
    font-size: 10px; letter-spacing: 2px; text-transform: uppercase;
    color: var(--muted);
}
.price-row-single .price-value {
    font-family: 'Cormorant Garamond', serif;
    font-size: 24px; font-weight: 500; color: var(--gold); line-height: 1;
}
.price-row-single .price-unit {
    font-size: 11px; color: var(--muted); font-weight: 300; margin-left: 3px;
}

/* Book button */
.btn-book-service {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    background: transparent; color: var(--gold); border: 1px solid var(--gold);
    padding: 11px 20px; font-size: 10px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; text-decoration: none; cursor: pointer;
    font-family: 'Jost', sans-serif; transition: all 0.2s; margin-top: auto;
}
.btn-book-service:hover { background: var(--gold); color: var(--black); }

/* ===== CTA BAND ===== */
.cta-band {
    background: var(--gold); padding: 50px 40px;
    display: flex; justify-content: space-between; align-items: center; gap: 40px;
}
.cta-band-text h3 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px; font-weight: 400; color: var(--black); line-height: 1.2;
}
.cta-band-text p { font-size: 13px; color: rgba(0,0,0,0.55); margin-top: 8px; }
.btn-dark {
    background: var(--black); color: var(--white); border: none;
    padding: 15px 36px; font-size: 11px; letter-spacing: 2.5px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    text-decoration: none; white-space: nowrap; transition: opacity 0.2s;
}
.btn-dark:hover { opacity: 0.85; }

/* ===== FOOTER ===== */
.footer { background: var(--black); border-top: 1px solid var(--border); padding: 60px 40px 30px; }
.footer-grid {
    display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 40px; padding-bottom: 40px; border-bottom: 1px solid var(--border);
}
.footer-brand img { height: 30px; filter: var(--logo-filter); margin-bottom: 18px; }
.footer-brand p { font-size: 12px; color: var(--muted); line-height: 1.8; margin-bottom: 20px; font-weight: 300; }
.footer-socials { display: flex; gap: 8px; }
.footer-socials a {
    width: 34px; height: 34px; border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    color: var(--muted); font-size: 13px; text-decoration: none; transition: all 0.2s;
}
.footer-socials a:hover { border-color: var(--gold); color: var(--gold); }
.footer-col h4 {
    font-size: 10px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--white); margin-bottom: 20px; font-weight: 600;
}
.footer-col ul { list-style: none; }
.footer-col ul li { margin-bottom: 12px; }
.footer-col ul li a {
    color: var(--muted); text-decoration: none; font-size: 12px;
    font-weight: 300; transition: color 0.2s; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 8px;
}
.footer-col ul li a::before { content: '—'; color: var(--gold); font-size: 10px; }
.footer-col ul li a:hover { color: var(--gold); }
.footer-contact-item { display: flex; gap: 12px; margin-bottom: 14px; }
.footer-contact-item i { color: var(--gold); font-size: 13px; margin-top: 2px; flex-shrink: 0; width: 14px; }
.footer-contact-item span { font-size: 11px; color: var(--muted); line-height: 1.6; font-weight: 300; }
.footer-contact-item strong { display: block; color: var(--white); font-size: 11px; margin-bottom: 2px; font-weight: 500; }
.footer-bottom {
    padding-top: 24px;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
}
.footer-bottom p { font-size: 11px; color: var(--footer-bottom-color); letter-spacing: 0.5px; }
.footer-links { display: flex; gap: 24px; }
.footer-links a { font-size: 11px; color: var(--footer-bottom-color); text-decoration: none; transition: color 0.2s; }
.footer-links a:hover { color: var(--gold); }

/* ===== MOBILE ===== */
@media (max-width: 900px) {
    .service-grid { grid-template-columns: repeat(2, 1fr); }
    .footer-grid { grid-template-columns: 1fr 1fr; }
    .cta-band { flex-direction: column; text-align: center; }
}
@media (max-width: 600px) {
    .header { padding: 0 16px; }
    .header-nav { display: none; }
    .hero-band { padding: 40px 20px; }
    .hero-band-image { display: none; }
    .services-section { padding: 48px 20px 60px; }
    .service-grid { grid-template-columns: 1fr; }
    .footer { padding: 40px 20px 24px; }
    .footer-grid { grid-template-columns: 1fr; }
    .cta-band { padding: 40px 20px; }
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
        <a href="services.php" class="nav-tab active"><i class="fa fa-scissors"></i> Services</a>
        <a href="promotions.php" class="nav-tab"><i class="fa fa-star"></i> Promotions</a>
        <a href="#" class="nav-tab"><i class="fa fa-images"></i> Gallery</a>
        <a href="customer_about.php" class="nav-tab"><i class="fa fa-circle-info"></i> About</a>
    </nav>
    <div class="header-right">
        <a href="booking_calendar.php" class="btn-book">
            <i class="fa fa-calendar-check"></i> Book Now
        </a>
        <div class="profile-wrapper" id="profileWrapper">
            <button class="btn-profile" id="profileBtn"><i class="fa fa-user"></i></button>
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

<!-- HERO BAND -->
<div class="hero-band">
    <div class="hero-band-inner">
        <div class="hero-band-content">
            <div class="hero-eyebrow">What We Offer</div>
            <h1 class="hero-title">Our<br><em>Services</em></h1>
            <p class="hero-desc">
                บริการทำผมครบวงจรโดยช่างมืออาชีพ ตั้งแต่ตัดผม ทำสี ดัด ยืด
                ไปจนถึงทรีทเมนต์บำรุงผมด้วยผลิตภัณฑ์คุณภาพสูงระดับสากล
                เพื่อผลลัพธ์ที่ดีที่สุดสำหรับคุณ
            </p>
            <a href="booking_calendar.php" class="btn-primary">
                <i class="fa fa-calendar-check"></i> Book Appointment
            </a>
        </div>
        <div class="hero-band-image">
            <img src="cuthair1.png" alt="Bright Hair Studio - Professional Hair Service">
        </div>
    </div>
</div>

<!-- MARQUEE BAND -->
<div class="band">
    <div class="band-marquee">
        <span>✦ HAIRCUT</span><span>✦ COLORING</span><span>✦ HIGHLIGHT</span>
        <span>✦ BLEACH</span><span>✦ DIGITAL PERM</span><span>✦ COLD PERM</span>
        <span>✦ STRAIGHTENING</span><span>✦ KERATIN</span><span>✦ WASH &amp; BLOWDRY</span>
        <span>✦ STYLING</span>
        <span>✦ HAIRCUT</span><span>✦ COLORING</span><span>✦ HIGHLIGHT</span>
        <span>✦ BLEACH</span><span>✦ DIGITAL PERM</span><span>✦ COLD PERM</span>
        <span>✦ STRAIGHTENING</span><span>✦ KERATIN</span><span>✦ WASH &amp; BLOWDRY</span>
        <span>✦ STYLING</span>
    </div>
</div>

<!-- SERVICES GRID -->
<div class="services-section">
    <div class="section-eyebrow">All Services</div>
    <h2 class="section-heading">บริการของ<em>เรา</em></h2>
    <p class="section-sub">บริการทำผมครบทุกรูปแบบ — กดจองได้เลย ช่างพร้อมดูแลคุณ</p>

    <div class="service-grid">
        <?php foreach ($services as $svc): ?>
        <div class="service-card">

            <!-- Service Banner (replaces image) -->
            <div class="service-banner">
                <span class="banner-id"><?= htmlspecialchars($svc['code']) ?></span>
                <div class="banner-icon"><i class="fa <?= htmlspecialchars($svc['icon']) ?>"></i></div>
                <div>
                    <div class="banner-title-th"><?= $svc['name'] ?></div>
                    <div class="banner-title-en"><?= $svc['name_en'] ?></div>
                </div>
            </div>

            <div class="service-card-body">
                <p class="service-desc"><?= htmlspecialchars($svc['description']) ?></p>

                <div class="service-meta">
                    <div class="meta-badge">
                        <i class="fa fa-clock"></i>
                        <?= $svc['duration_min'] ?> นาที
                    </div>
                </div>

                <!-- Single price -->
                <div class="price-row-single">
                    <span class="price-label">ราคา</span>
                    <span>
                        <span class="price-value"><?= number_format($svc['price']) ?></span>
                        <span class="price-unit">฿</span>
                    </span>
                </div>

                <a href="booking_calendar.php?service=<?= htmlspecialchars($svc['code']) ?>" class="btn-book-service">
                    <i class="fa fa-calendar-check"></i> จองบริการนี้
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- CTA BAND -->
<div class="cta-band">
    <div class="cta-band-text">
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
                <?php foreach ($services as $s): ?>
                <li><a href="booking_calendar.php?service=<?= htmlspecialchars($s['code']) ?>"><?= $s['name'] ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="customer_home.php">หน้าหลัก</a></li>
                <li><a href="#">โปรโมชั่น</a></li>
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
        <p>© <?php echo date('Y'); ?> Bright Hair Studio. All rights reserved.</p>
        <div class="footer-links">
            <a href="#">Terms & Conditions</a>
            <a href="#">Privacy Policy</a>
            <a href="#">Service Policy</a>
        </div>
    </div>
</footer>

<script>
const profileWrapper = document.getElementById('profileWrapper');
const profileBtn     = document.getElementById('profileBtn');
profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    profileWrapper.classList.toggle('open');
});
document.addEventListener('click', () => profileWrapper.classList.remove('open'));
</script>

</body>
</html>