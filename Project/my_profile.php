<?php
session_start();

if(!isset($_SESSION['user'])){
    header("Location: customer_login.php");
    exit();
}

require 'db.php';

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header("Location: customer_login.php");
    exit();
}

// ===== ดึงข้อมูล user =====
$stmt = $pdo->prepare("
    SELECT 
        first_name, 
        last_name, 
        email, 
        phone, 
        member_tier, 
        created_at
    FROM users 
    WHERE id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: customer_login.php");
    exit();
}

// ===== คำนวณยอดเงินรวมจาก bookings ที่ completed เท่านั้น =====
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(s.price), 0)
    FROM bookings b
    JOIN services s ON b.service_id = s.id
    WHERE b.user_id = ? AND b.status = 'completed'
");
$stmt->execute([$userId]);
$user['total_spend'] = (float) $stmt->fetchColumn();

// ===== คำนวณ points จาก total_spend หารด้วย 5 =====
$user['points'] = floor($user['total_spend'] / 5);

// ===== นับจำนวนครั้งใช้บริการที่ completed =====
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'completed'");
$stmt->execute([$userId]);
$user['total_visits'] = $stmt->fetchColumn();

// ===== แปลงวันที่ =====
$months_th = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
              'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

[$y,$m,$d] = explode('-', date('Y-m-d', strtotime($user['created_at'])));
$memberSince = (int)$d . ' ' . $months_th[(int)$m] . ' ' . ((int)$y + 543);

// ===== คำนวณ tier =====
$currentPoints = $user['points'];

if ($currentPoints < 2000) {
    $user['next_tier'] = 'Gold';
    $user['points_to_next'] = 2000 - $currentPoints;
} elseif ($currentPoints < 5000) {
    $user['next_tier'] = 'Platinum';
    $user['points_to_next'] = 5000 - $currentPoints;
} else {
    $user['next_tier'] = 'Max';
    $user['points_to_next'] = 0;
}

$total = $currentPoints + $user['points_to_next'];
$progressPct = $total > 0 ? round(($currentPoints / $total) * 100) : 100;

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - Bright Hair Studio</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --gold:#ff9f24; --gold-light:#ffb84a;
    --black:#0d0d0d; --dark:#141414; --dark2:#1c1c1c; --dark3:#242424;
    --white:#f8f4ef; --muted:#888;
    --border:rgba(255,159,36,0.25); --dbi:rgba(255,255,255,0.04);
    --logo-filter: none; --fbc:#444;
    --card:#1c1c1c; --divider:rgba(255,255,255,0.06); --track:#242424;
}
@media (prefers-color-scheme:light){
    :root{
        --black:#fff; --dark:#f5f5f5; --dark2:#efefef; --dark3:#e4e4e4;
        --white:#111; --muted:#777; --border:rgba(255,159,36,0.3); --dbi:rgba(0,0,0,0.05);
        --logo-filter:none; --fbc:#bbb; --card:#f5f5f5;
        --divider:rgba(0,0,0,0.07); --track:#e0e0e0;
    }
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Jost',sans-serif;background:var(--black);color:var(--white);overflow-x:hidden;min-height:100vh;display:flex;flex-direction:column;}

.announcement-bar{background:var(--gold);color:var(--black);text-align:center;padding:8px 20px;font-size:11px;letter-spacing:2px;font-weight:600;text-transform:uppercase;}

.header{position:sticky;top:0;z-index:200;background:var(--black);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 40px;height:70px;}
.header-logo img{height:36px;object-fit:contain;filter:var(--logo-filter);}
.header-nav{display:flex;align-items:center;height:100%;}
.nav-tab{display:flex;align-items:center;gap:7px;height:100%;padding:0 22px;color:var(--muted);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;font-weight:500;text-decoration:none;border-bottom:2px solid transparent;transition:all .2s;white-space:nowrap;}
.nav-tab:hover,.nav-tab.active{color:var(--white);border-bottom-color:var(--gold);}
.header-right{display:flex;align-items:center;gap:8px;}
.btn-book{background:var(--gold);color:var(--black);border:none;padding:10px 24px;font-size:11px;letter-spacing:2px;font-weight:700;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;text-decoration:none;transition:background .2s;display:flex;align-items:center;gap:8px;}
.btn-book:hover{background:var(--gold-light);}
.profile-wrapper{position:relative;}
.btn-profile{width:40px;height:40px;background:var(--dark3);border:1px solid var(--border);color:var(--gold);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.btn-profile:hover{background:var(--gold);color:var(--black);border-color:var(--gold);}
.profile-dropdown{position:absolute;top:calc(100% + 12px);right:0;width:240px;background:var(--dark2);border:1px solid var(--border);z-index:300;display:none;animation:fadeDown .2s ease;}
@keyframes fadeDown{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}
.profile-wrapper.open .profile-dropdown{display:block;}
.dropdown-header{padding:18px 20px 14px;border-bottom:1px solid var(--border);}
.dropdown-header .name{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--white);letter-spacing:.5px;}
.dropdown-header .email{font-size:11px;color:var(--muted);margin-top:2px;}
.dropdown-item{display:flex;align-items:center;gap:12px;padding:13px 20px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:var(--muted);text-decoration:none;transition:all .2s;border-bottom:1px solid var(--dbi);}
.dropdown-item i{width:16px;color:var(--gold);font-size:13px;}
.dropdown-item:hover{background:var(--dark3);color:var(--white);}
.dropdown-item.active-link{color:var(--gold);}
.dropdown-item.active-link i{color:var(--gold);}
.dropdown-item.logout{color:#cc5555;}
.dropdown-item.logout i{color:#cc5555;}

/* PAGE */
.page-wrap{flex:1;max-width:860px;width:100%;margin:0 auto;padding:56px 40px 80px;}
.page-eyebrow{font-size:10px;letter-spacing:4px;text-transform:uppercase;color:var(--gold);margin-bottom:12px;display:flex;align-items:center;gap:12px;}
.page-eyebrow::before{content:'';display:block;width:30px;height:1px;background:var(--gold);}
.page-title{font-family:'Cormorant Garamond',serif;font-size:44px;font-weight:300;color:var(--white);line-height:1;margin-bottom:36px;}
.page-title em{font-style:italic;color:var(--gold);}

/* HERO */
.profile-hero{display:grid;grid-template-columns:auto 1fr;gap:28px;align-items:center;background:var(--card);border:1px solid var(--border);padding:28px 32px;margin-bottom:1px;}
.avatar-block{width:68px;height:68px;background:var(--dark3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--gold);flex-shrink:0;}
.hero-info{display:flex;flex-direction:column;gap:5px;}
.hero-name{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:400;color:var(--white);line-height:1;}
.hero-email{font-size:12px;color:var(--muted);font-weight:300;}
.tier-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,159,36,.1);border:1px solid rgba(255,159,36,.3);padding:4px 12px;font-size:9px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);width:fit-content;}
.hero-since{font-size:11px;color:var(--muted);font-weight:300;display:flex;align-items:center;gap:6px;}
.hero-since i{color:var(--gold);font-size:10px;}

/* STATS */
.stats-band{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);margin-bottom:24px;}
.stat-box{background:var(--dark);padding:22px 26px;display:flex;flex-direction:column;gap:3px;}
.stat-key{font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);}
.stat-val{font-family:'Cormorant Garamond',serif;font-size:36px;font-weight:300;color:var(--white);line-height:1;}
.stat-val sup{font-size:14px;color:var(--muted);font-family:'Jost',sans-serif;margin-left:1px;}
.stat-sub{font-size:11px;color:var(--muted);font-weight:300;}

/* POINTS CARD */
.points-card{background:var(--card);border:1px solid var(--border);padding:26px 30px;margin-bottom:24px;}
.points-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;}
.points-label{font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);margin-bottom:5px;}
.points-val{font-family:'Cormorant Garamond',serif;font-size:38px;font-weight:300;color:var(--white);line-height:1;}
.points-val span{font-size:13px;font-family:'Jost',sans-serif;color:var(--muted);font-weight:300;}
.next-label{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:5px;text-align:right;}
.next-val{font-size:13px;color:var(--white);font-weight:500;text-align:right;}
.progress-track{height:2px;background:var(--track);margin-bottom:10px;}
.progress-fill{height:100%;background:var(--gold);}
.progress-note{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);font-weight:300;}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);margin-bottom:24px;}
.info-cell{background:var(--card);padding:18px 22px;}
.info-key{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:5px;}
.info-val{font-size:14px;color:var(--white);}

/* ACTIONS */
.actions-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);}
.action-item{background:var(--card);padding:18px 22px;display:flex;align-items:center;gap:14px;text-decoration:none;transition:background .2s;}
.action-item:hover{background:var(--dark3);}
.action-icon{width:34px;height:34px;background:rgba(255,159,36,.1);border:1px solid rgba(255,159,36,.2);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:13px;flex-shrink:0;}
.action-title{font-size:12px;font-weight:500;color:var(--white);letter-spacing:.3px;}
.action-sub{font-size:10px;color:var(--muted);font-weight:300;margin-top:2px;}

/* FOOTER */
.footer{background:var(--black);border-top:1px solid var(--border);padding:20px 40px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.footer p{font-size:11px;color:var(--fbc);}
.footer-links{display:flex;gap:20px;}
.footer-links a{font-size:11px;color:var(--fbc);text-decoration:none;transition:color .2s;}
.footer-links a:hover{color:var(--gold);}

@media(max-width:768px){
    .header{padding:0 16px;} .header-nav{display:none;}
    .page-wrap{padding:36px 20px 60px;}
    .profile-hero{grid-template-columns:1fr;gap:18px;}
    .stats-band{grid-template-columns:1fr 1fr;}
    .info-grid{grid-template-columns:1fr;}
    .actions-row{grid-template-columns:1fr;}
    .page-title{font-size:34px;}
    .footer{padding:16px 20px;}
}
</style>
</head>
<body>

<div class="announcement-bar">✦ &nbsp; New Season Collection — Book Your Appointment Today &nbsp; ✦</div>

<div class="header">
    <div class="header-logo"><img src="logo-crop.png" alt="Bright Hair Studio"></div>
    <nav class="header-nav">
        <a href="customer_home.php" class="nav-tab"><i class="fa fa-house"></i> Home</a>
        <a href="services.php" class="nav-tab"><i class="fa fa-scissors"></i> Services</a>
        <a href="promotions.php" class="nav-tab"><i class="fa fa-star"></i> Promotions</a>
        <a href="customer_gallery.php" class="nav-tab"><i class="fa fa-images"></i> Gallery</a>
        <a href="customer_about.php" class="nav-tab"><i class="fa fa-circle-info"></i> About</a>
    </nav>
    <div class="header-right">
        <a href="booking_calendar.php" class="btn-book"><i class="fa fa-calendar-check"></i> Book Now</a>
        <div class="profile-wrapper" id="profileWrapper">
            <button class="btn-profile" id="profileBtn"><i class="fa fa-user"></i></button>
            <div class="profile-dropdown">
                <div class="dropdown-header">
                    <div class="name"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></div>
                    <div class="email"><?= htmlspecialchars($user['email']) ?></div>
                </div>
                <a href="my_profile.php" class="dropdown-item active-link"><i class="fa fa-user-circle"></i> My Profile</a>
                <a href="customer_my_booking.php" class="dropdown-item"><i class="fa fa-calendar-check"></i> My Bookings</a>
                <a href="booking_history.php" class="dropdown-item"><i class="fa fa-clock-rotate-left"></i> Booking History</a>
                <a href="my_rewards.php" class="dropdown-item"><i class="fa fa-gift"></i> Rewards & Points</a>
                <a href="logout.php" class="dropdown-item logout"><i class="fa fa-arrow-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<div class="page-wrap">

    <div class="page-eyebrow">Account</div>
    <h1 class="page-title">My <em>Profile</em></h1>

    <div class="profile-hero">
        <div class="avatar-block"><i class="fa fa-user"></i></div>
        <div class="hero-info">
            <div class="hero-name"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></div>
            <div class="hero-email"><?= htmlspecialchars($user['email']) ?></div>
            <div class="tier-badge"><i class="fa fa-crown"></i> Member <?= $user['member_tier'] ?></div>
            <div class="hero-since"><i class="fa fa-calendar"></i> สมาชิกตั้งแต่ <?= $memberSince ?></div>
        </div>
    </div>

    <div class="stats-band">
        <div class="stat-box">
            <div class="stat-key">Points</div>
            <div class="stat-val"><?= number_format($user['points']) ?></div>
            <div class="stat-sub">คะแนนสะสม</div>
        </div>
        <div class="stat-box">
            <div class="stat-key">Visits</div>
            <div class="stat-val"><?= $user['total_visits'] ?></div>
            <div class="stat-sub">ครั้งที่เข้าใช้บริการ</div>
        </div>
        <div class="stat-box">
            <div class="stat-key">Total Spend</div>
            <div class="stat-val"><?= number_format($user['total_spend']) ?><sup>฿</sup></div>
            <div class="stat-sub">ยอดใช้จ่ายรวม</div>
        </div>
    </div>

    <div class="points-card">
        <div class="points-header">
            <div>
                <div class="points-label">Points Balance</div>
                <div class="points-val"><?= number_format($user['points']) ?> <span>pts</span></div>
            </div>
            <div>
                <div class="next-label">Next Tier</div>
                <div class="next-val"><?= $user['next_tier'] ?></div>
            </div>
        </div>
        <div class="progress-track">
            <div class="progress-fill" style="width:<?= $progressPct ?>%;"></div>
        </div>
        <div class="progress-note">
            <span><?= $user['member_tier'] ?></span>
            <span>อีก <?= number_format($user['points_to_next']) ?> pts ถึง <?= $user['next_tier'] ?></span>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-cell">
            <div class="info-key">ชื่อ-นามสกุล</div>
            <div class="info-val"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-key">เบอร์โทรศัพท์</div>
            <div class="info-val"><?= htmlspecialchars($user['phone']) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-key">Email</div>
            <div class="info-val"><?= htmlspecialchars($user['email']) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-key">ระดับสมาชิก</div>
            <div class="info-val"><?= $user['member_tier'] ?> </div>
        </div>
    </div>

    <div class="actions-row">
        <a href="customer_my_booking.php" class="action-item">
            <div class="action-icon"><i class="fa fa-calendar-check"></i></div>
            <div><div class="action-title">My Bookings</div><div class="action-sub">ดูการนัดหมาย</div></div>
        </a>
        <a href="booking_calendar.php" class="action-item">
            <div class="action-icon"><i class="fa fa-calendar-plus"></i></div>
            <div><div class="action-title">Book Appointment</div><div class="action-sub">จองนัดใหม่</div></div>
        </a>
        <a href="logout.php" class="action-item">
            <div class="action-icon" style="background:rgba(204,85,85,.1);border-color:rgba(204,85,85,.2);"><i class="fa fa-arrow-right-from-bracket" style="color:#cc5555;"></i></div>
            <div><div class="action-title">Logout</div><div class="action-sub">ออกจากระบบ</div></div>
        </a>
    </div>

</div>

<footer class="footer">
    <p>© <?php echo date('Y'); ?> Bright Hair Studio. All rights reserved.</p>
    <div class="footer-links">
        <a href="#">Terms & Conditions</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Service Policy</a>
    </div>
</footer>

<script>
const pw = document.getElementById('profileWrapper');
document.getElementById('profileBtn').addEventListener('click', e => { e.stopPropagation(); pw.classList.toggle('open'); });
document.addEventListener('click', () => pw.classList.remove('open'));
</script>
</body>
</html>