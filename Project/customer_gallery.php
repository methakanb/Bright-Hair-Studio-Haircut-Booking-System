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
<title>Gallery - Bright Hair Studio</title>

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
        --dropdown-border-item: rgba(0,0,0,0.05);
        --footer-bottom-color: #bbb;
        --btn-dark-bg: #111111;
        --btn-dark-color: #ffffff;
        --btn-dark-hover: #333;
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
    flex-shrink: 0;
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
    flex-shrink: 0;
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
    white-space: nowrap;
}

.nav-tab i { font-size: 13px; }

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

.btn-book:hover { background: var(--gold-light); }

/* ===== PROFILE BUTTON + DROPDOWN ===== */
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

.profile-wrapper.open .profile-dropdown { display: block; }

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

.dropdown-item i { width: 16px; color: var(--gold); font-size: 13px; }
.dropdown-item:hover { background: rgba(255,159,36,0.06); color: var(--white); }
.dropdown-item.logout { color: #f87171; }
.dropdown-item.logout i { color: #f87171; }
.dropdown-item.active-page { background: rgba(255,159,36,0.08); color: var(--gold); }

/* ===== MAIN CONTENT ===== */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.main::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 60% 50% at 50% 50%, rgba(255,159,36,0.05) 0%, transparent 70%);
    pointer-events: none;
}

/* ===== COMING SOON CONTENT ===== */
.coming-soon-icon {
    font-size: 64px;
    margin-bottom: 28px;
    display: block;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-10px); }
}

.coming-label {
    font-size: 10px;
    letter-spacing: 5px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
}

.coming-label::before,
.coming-label::after {
    content: '';
    display: block;
    width: 40px;
    height: 1px;
    background: var(--gold);
    opacity: 0.5;
}

.coming-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 52px;
    font-weight: 300;
    line-height: 1.1;
    margin-bottom: 10px;
}

.coming-title em {
    font-style: italic;
    color: var(--gold);
}

.coming-sub {
    font-size: 13px;
    color: var(--muted);
    letter-spacing: 0.5px;
    max-width: 420px;
    line-height: 1.7;
    margin-bottom: 40px;
}

.coming-features {
    display: flex;
    gap: 20px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 44px;
}

.feature-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--muted);
}

.feature-pill i { color: var(--gold); font-size: 14px; }

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 32px;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--white);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2px;
    text-transform: uppercase;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-back:hover {
    border-color: var(--gold);
    color: var(--gold);
}

/* ===== FOOTER ===== */
.footer {
    background: var(--dark);
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}

.footer-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr 1fr 1.4fr;
    gap: 40px;
    padding: 60px 80px 40px;
}

.footer-brand img {
    height: 32px;
    margin-bottom: 14px;
    display: block;
    filter: var(--logo-filter);
}

.footer-brand p {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.8;
    margin-bottom: 20px;
}

.footer-socials { display: flex; gap: 8px; }

.footer-socials a {
    width: 32px;
    height: 32px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 12px;
    text-decoration: none;
    transition: all 0.2s;
}

.footer-socials a:hover { border-color: var(--gold); color: var(--gold); }

.footer-col h4 {
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 18px;
    font-weight: 600;
}

.footer-col ul { list-style: none; }

.footer-col ul li { margin-bottom: 10px; }

.footer-col ul li a {
    font-size: 13px;
    color: var(--muted);
    text-decoration: none;
    transition: color 0.2s;
}

.footer-col ul li a:hover { color: var(--gold); }

.footer-contact-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.6;
}

.footer-contact-item i { color: var(--gold); margin-top: 2px; width: 14px; }

.footer-contact-item strong {
    display: block;
    color: var(--white);
    font-size: 10px;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.footer-bottom {
    border-top: 1px solid var(--border);
    padding: 18px 80px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.footer-bottom p { font-size: 11px; color: var(--muted); }

.footer-links { display: flex; gap: 20px; }

.footer-links a {
    font-size: 11px;
    color: var(--muted);
    text-decoration: none;
    transition: color 0.2s;
}

.footer-links a:hover { color: var(--gold); }

@media (max-width: 900px) {
    .footer-grid { grid-template-columns: 1fr 1fr; padding: 40px 30px 30px; }
    .footer-bottom { padding: 16px 30px; }
}

@media (max-width: 600px) {
    .header { padding: 0 16px; }
    .header-nav { display: none; }
    .coming-title { font-size: 36px; }
    .coming-features { gap: 10px; }
    .footer-grid { grid-template-columns: 1fr; padding: 30px 20px; }
    .footer-bottom { padding: 14px 20px; flex-direction: column; text-align: center; }
    .footer-links { justify-content: center; }
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
        <a href="customer_home.php" class="nav-tab">
            <i class="fa fa-house"></i> Home
        </a>
        <a href="services.php" class="nav-tab">
            <i class="fa fa-scissors"></i> Services
        </a>
        <a href="promotions.php" class="nav-tab">
            <i class="fa fa-star"></i> Promotions
        </a>
        <a href="customer_gallery.php" class="nav-tab active">
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

<!-- MAIN -->
<div class="main">
    <span class="coming-soon-icon">📸</span>

    <div class="coming-label">Gallery</div>

    <h1 class="coming-title">Coming <em>Soon</em></h1>

    <p class="coming-sub">
        เรากำลังรวบรวมผลงานสวยๆ ของพวกเราไว้ให้คุณชม<br>
        คอยติดตามเร็วๆ นี้
    </p>

    <div class="coming-features">
        <div class="feature-pill"><i class="fa fa-scissors"></i> Haircut Looks</div>
        <div class="feature-pill"><i class="fa fa-palette"></i> Color Works</div>
        <div class="feature-pill"><i class="fa fa-wand-magic-sparkles"></i> Transformations</div>
        <div class="feature-pill"><i class="fa fa-camera"></i> Behind The Scenes</div>
    </div>

    <a href="customer_home.php" class="btn-back">
        <i class="fa fa-arrow-left"></i> Back to Home
    </a>
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
                <li><a href="customer_gallery.php">แกลเลอรี่</a></li>
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
