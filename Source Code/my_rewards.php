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
<title>Rewards & Points - Bright Hair Studio</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
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
}

.header-nav {
    display: flex;
    align-items: center;
    gap: 4px;
}

.nav-tab {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: color 0.2s, border-color 0.2s;
}

.nav-tab:hover, .nav-tab.active {
    color: var(--white);
    border-bottom-color: var(--gold);
}

.header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-book {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--gold);
    color: #000;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 10px 22px;
    text-decoration: none;
    transition: background 0.2s;
}

.btn-book:hover { background: var(--gold-light); }

/* ===== PROFILE DROPDOWN ===== */
.profile-wrapper { position: relative; }

.btn-profile {
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--white);
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.btn-profile:hover { border-color: var(--gold); color: var(--gold); }

.profile-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 220px;
    background: var(--dark2);
    border: 1px solid var(--border);
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    z-index: 300;
}

.profile-wrapper.open .profile-dropdown { display: block; }

.dropdown-header {
    padding: 16px;
    border-bottom: 1px solid var(--border);
}

.dropdown-header .name {
    font-size: 13px;
    font-weight: 600;
    color: var(--white);
    margin-bottom: 3px;
}

.dropdown-header .email {
    font-size: 11px;
    color: var(--muted);
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.5px;
    color: var(--white);
    text-decoration: none;
    transition: background 0.15s;
    border-bottom: 1px solid var(--dropdown-border-item);
}

.dropdown-item i { width: 14px; color: var(--gold); }
.dropdown-item:hover { background: rgba(255,159,36,0.06); }
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

/* ===== COMING SOON CARD ===== */
.coming-soon-icon {
    font-size: 64px;
    margin-bottom: 28px;
    display: block;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
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
.footer-strip {
    background: var(--dark);
    border-top: 1px solid var(--border);
    padding: 18px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    flex-shrink: 0;
}

.footer-strip p { font-size: 11px; color: var(--muted); }

.footer-socials { display: flex; gap: 8px; }

.footer-socials a {
    width: 30px;
    height: 30px;
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

@media (max-width: 600px) {
    .header { padding: 0 16px; }
    .header-nav { display: none; }
    .coming-title { font-size: 36px; }
    .coming-features { gap: 10px; }
    .footer-strip { padding: 16px 20px; }
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
                <a href="my_rewards.php" class="dropdown-item active-page">
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
    <span class="coming-soon-icon">🎁</span>

    <div class="coming-label">Rewards & Points</div>

    <h1 class="coming-title">Coming <em>Soon</em></h1>

    <p class="coming-sub">
        เรากำลังพัฒนาระบบสะสมแต้มและรางวัลสำหรับสมาชิก<br>
        คอยติดตามเร็วๆ นี้
    </p>

    <div class="coming-features">
        <div class="feature-pill"><i class="fa fa-star"></i> Loyalty Points</div>
        <div class="feature-pill"><i class="fa fa-gift"></i> Exclusive Rewards</div>
        <div class="feature-pill"><i class="fa fa-crown"></i> Member Tiers</div>
        <div class="feature-pill"><i class="fa fa-tag"></i> Special Offers</div>
    </div>

    <a href="customer_home.php" class="btn-back">
        <i class="fa fa-arrow-left"></i> Back to Home
    </a>
</div>

<!-- FOOTER -->
<div class="footer-strip">
    <p>© <?php echo date('Y'); ?> Bright Hair Studio. All rights reserved.</p>
    <div class="footer-socials">
        <a href="https://www.facebook.com/BrightHairStudio/" target="_blank"><i class="fab fa-facebook-f"></i></a>
        <a href="#"><i class="fab fa-youtube"></i></a>
        <a href="https://www.instagram.com/bright.hair.studio/" target="_blank"><i class="fab fa-instagram"></i></a>
        <a href="https://page.line.me/679hncxi" target="_blank"><i class="fab fa-line"></i></a>
    </div>
</div>

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
