<?php
if (isset($_POST['role'])) {
    if ($_POST['role'] == 'customer') {
        header("Location: customer_login.php");
        exit();
    } elseif ($_POST['role'] == 'employee') {
        header("Location: employee_login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bright Hair Studio</title>

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
    --border-subtle: rgba(255,159,36,0.12);
    --logo-filter: none;
    --card-bg: #1c1c1c;
    --card-hover: #242424;
    --card-border: rgba(255,159,36,0.2);
    --icon-bg: #242424;
    --icon-color: #ff9f24;
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
        --border-subtle: rgba(255,159,36,0.15);
        --logo-filter: none;
        --card-bg: #f5f5f5;
        --card-hover: #ebebeb;
        --card-border: rgba(255,159,36,0.25);
        --icon-bg: #e8e8e8;
        --icon-color: #ff9f24;
    }
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    height: 100%;
}

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
    background: var(--black);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 40px;
    height: 70px;
    flex-shrink: 0;
}

.header-logo img {
    height: 36px;
    object-fit: contain;
    filter: var(--logo-filter);
}

/* ===== MAIN CONTENT ===== */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    position: relative;
    overflow: hidden;
}

/* subtle background pattern */
.main::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 60% 50% at 50% 50%, rgba(255,159,36,0.04) 0%, transparent 70%);
    pointer-events: none;
}

/* ===== WELCOME TEXT ===== */
.welcome-label {
    font-size: 10px;
    letter-spacing: 5px;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
}

.welcome-label::before,
.welcome-label::after {
    content: '';
    display: block;
    width: 40px;
    height: 1px;
    background: var(--gold);
    opacity: 0.5;
}

.welcome-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 56px;
    font-weight: 300;
    letter-spacing: -1px;
    line-height: 1;
    text-align: center;
    margin-bottom: 6px;
}

.welcome-title em {
    font-style: italic;
    color: var(--gold);
}

.welcome-sub {
    font-size: 11px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 60px;
}

/* ===== ROLE CARDS ===== */
.role-prompt {
    font-size: 10px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 28px;
    text-align: center;
}

.role-container {
    display: flex;
    gap: 24px;
    justify-content: center;
    flex-wrap: wrap;
}

.card {
    width: 220px;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 24px 36px;
    gap: 0;
    transition: background 0.2s, border-color 0.2s, transform 0.2s;
    position: relative;
    overflow: hidden;
    font-family: 'Jost', sans-serif;
}

.card::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--gold);
    transform: scaleX(0);
    transition: transform 0.25s ease;
}

.card:hover {
    background: var(--card-hover);
    border-color: var(--gold);
    transform: translateY(-4px);
}

.card:hover::before {
    transform: scaleX(1);
}

.card-icon {
    width: 68px;
    height: 68px;
    background: var(--icon-bg);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 22px;
    transition: background 0.2s, border-color 0.2s;
}

.card:hover .card-icon {
    background: var(--gold);
    border-color: var(--gold);
}

.card-icon i {
    font-size: 26px;
    color: var(--icon-color);
    transition: color 0.2s;
}

.card:hover .card-icon i {
    color: var(--black);
}

.card-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--white);
    margin-bottom: 8px;
}

.card-desc {
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 0.5px;
    font-weight: 300;
}

/* ===== FOOTER STRIP ===== */
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

.footer-strip p {
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 0.5px;
}

.footer-socials {
    display: flex;
    gap: 8px;
}

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

.footer-socials a:hover {
    border-color: var(--gold);
    color: var(--gold);
}

/* ===== MOBILE ===== */
@media (max-width: 600px) {
    .welcome-title { font-size: 38px; }
    .role-container { gap: 16px; }
    .card { width: 160px; padding: 32px 16px 28px; }
    .header { padding: 0 20px; }
    .footer-strip { padding: 16px 20px; }
    .main { padding: 40px 16px; }
}
</style>
</head>
<body>

<!-- ANNOUNCEMENT BAR -->
<div class="announcement-bar">
    ✦ &nbsp; Welcome to Bright Hair Studio &nbsp; ✦
</div>

<!-- HEADER -->
<div class="header">
    <div class="header-logo">
        <img src="logo-crop.png" alt="Bright Hair Studio">
    </div>
</div>

<!-- MAIN -->
<div class="main">
    <div class="welcome-label">Premium Hair Studio · Bangkok</div>

    <h1 class="welcome-title">BRIGHT <em>Hair</em> Studio</h1>
    <p class="welcome-sub">ร้านทำผมสไตล์พรีเมี่ยม โดยช่างมืออาชีพ</p>

    <div class="role-prompt">Please select your role to continue</div>

    <form method="POST">
        <div class="role-container">

            <button name="role" value="customer" class="card">
                <div class="card-icon">
                    <i class="fa fa-user"></i>
                </div>
                <div class="card-label">Customer</div>
                <div class="card-desc">จองนัดและติดตาม</div>
            </button>

            <button name="role" value="employee" class="card">
                <div class="card-icon">
                    <i class="fa fa-scissors"></i>
                </div>
                <div class="card-label">Employee</div>
                <div class="card-desc">จัดการระบบหลังบ้าน</div>
            </button>

        </div>
    </form>
</div>

<!-- FOOTER STRIP -->
<div class="footer-strip">
    <p>© <?php echo date('Y'); ?> Bright Hair Studio. All rights reserved.</p>
    <div class="footer-socials">
        <a href="https://www.facebook.com/BrightHairStudio/" target="_blank"><i class="fab fa-facebook-f"></i></a>
        <a href="#"><i class="fab fa-youtube"></i></a>
        <a href="https://www.instagram.com/bright.hair.studio/" target="_blank"><i class="fab fa-instagram"></i></a>
        <a href="https://page.line.me/679hncxi" target="_blank"><i class="fab fa-line"></i></a>
    </div>
</div>

</body>
</html>