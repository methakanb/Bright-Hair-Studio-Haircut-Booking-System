<?php
session_start();
if(!isset($_SESSION['user'])){
    header("Location: customer_login.php");
    exit();
}
require 'db.php';
$user_id = $_SESSION['user_id'] ?? 0;

// ── AJAX: บันทึกรีวิว ─────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    header('Content-Type: application/json; charset=utf-8');

    $booking_id  = (int)($_POST['booking_id']  ?? 0);
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $rating      = (int)($_POST['rating']      ?? 0);
    $comment     = trim($_POST['comment']      ?? '');

    if (!$booking_id || $rating < 1 || $rating > 5 || !$comment) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }

    // ตรวจ booking เป็นของ user นี้และ completed
    $chk = $pdo->prepare("SELECT id FROM bookings WHERE id=? AND user_id=? AND status IN ('completed','done')");
    $chk->execute([$booking_id, $user_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบการจองหรือยังไม่เสร็จสิ้น']);
        exit;
    }

    // ตรวจรีวิวซ้ำ
    $dup = $pdo->prepare("SELECT id FROM reviews WHERE booking_id=?");
    $dup->execute([$booking_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'คุณได้รีวิวการจองนี้ไปแล้ว']);
        exit;
    }

    // INSERT
    $ins = $pdo->prepare("INSERT INTO reviews (booking_id, rating, comment) VALUES (?, ?, ?)");
    $ins->execute([$booking_id, $rating, $comment]);

    if ($ins->rowCount() > 0) {
        // อัปเดต rating + review_count ในตาราง employees
        if ($employee_id > 0) {
            $pdo->prepare("
                UPDATE employees
                SET review_count = (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.employee_id=?),
                    rating        = (SELECT ROUND(AVG(r.rating),1) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.employee_id=?)
                WHERE id = ?
            ")->execute([$employee_id, $employee_id, $employee_id]);
        }
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

// Fetch all active services for the filter dropdown
$services = [];
$stmtServices = $pdo->query("SELECT id, name, price, duration_min as duration FROM services WHERE active = 1");
while ($s = $stmtServices->fetch()) {
    $services[$s['id']] = [
        'name' => $s['name'],
        'price' => (float)$s['price'],
        'duration' => (int)$s['duration']
    ];
}

// Fetch dynamic history with reviews
$stmtHistory = $pdo->prepare("
    SELECT b.id as raw_booking_id, b.booking_code, b.service_id, b.booking_date as date, 
           b.start_time, b.employee_id,
           e.name as stylist,
           r.rating, r.comment, s.price as backup_price, s.name as backup_name, s.duration_min as backup_duration
    FROM bookings b
    LEFT JOIN employees e ON b.employee_id = e.id
    LEFT JOIN reviews r ON b.id = r.booking_id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.user_id = ? AND b.status IN ('completed', 'done')
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$stmtHistory->execute([$user_id]);
$dbHistory = $stmtHistory->fetchAll();

$history = [];
foreach ($dbHistory as $row) {
    if (!isset($services[$row['service_id']])) {
        // Fallback if service is inactive but exists in history
        $services[$row['service_id']] = [
            'name' => $row['backup_name'] ?? 'ไม่มีชื่อบริการ',
            'price' => (float)$row['backup_price'],
            'duration' => (int)$row['backup_duration']
        ];
    }
    $history[] = [
        'booking_id'     => $row['booking_code'] ?: 'BK-'.$row['raw_booking_id'],
        'raw_booking_id' => $row['raw_booking_id'],
        'employee_id'    => $row['employee_id'],
        'service_id'     => $row['service_id'],
        'date'           => $row['date'],
        'start_time'     => substr($row['start_time'], 0, 5),
        'stylist'        => $row['stylist'] ?? 'ไม่ระบุช่าง',
        'review'         => $row['rating'] ? ['rating' => (int)$row['rating'], 'comment' => $row['comment']] : null
    ];
}

function calcEndTime($start, $dur) {
    return date('H:i', strtotime($start) + $dur * 60);
}
function formatDateThai($d) {
    $m = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    [$y,$mo,$dy] = explode('-',$d);
    return (int)$dy.' '.$m[(int)$mo].' '.((int)$y+543);
}

// Summary stats
$totalVisits  = count($history);
$totalSpend   = array_sum(array_map(fn($b) => $services[$b['service_id']]['price'], $history));
$totalReviews = count(array_filter($history, fn($b) => !empty($b['review'])));
$avgRating    = $totalReviews > 0
    ? array_sum(array_map(fn($b) => $b['review']['rating'] ?? 0, $history)) / $totalReviews
    : 0;

// Fav stylist: วัดจาก avg rating ที่ลูกค้าให้ช่างแต่ละคน, tiebreak ด้วยความถี่
$stylistStats = [];
foreach ($history as $b) {
    if (empty($b['stylist']) || $b['stylist'] === 'ไม่ระบุช่าง') continue;
    $name = $b['stylist'];
    if (!isset($stylistStats[$name])) {
        $stylistStats[$name] = ['count' => 0, 'rating_sum' => 0, 'rating_count' => 0];
    }
    $stylistStats[$name]['count']++;
    if (!empty($b['review']['rating'])) {
        $stylistStats[$name]['rating_sum']   += $b['review']['rating'];
        $stylistStats[$name]['rating_count'] += 1;
    }
}
// คำนวณ avg rating ของแต่ละช่าง
foreach ($stylistStats as &$st) {
    $st['avg_rating'] = $st['rating_count'] > 0
        ? $st['rating_sum'] / $st['rating_count']
        : 0;
}
unset($st);
// เรียง: avg_rating DESC, count DESC
uasort($stylistStats, function($a, $b) {
    if ($b['avg_rating'] !== $a['avg_rating']) return $b['avg_rating'] <=> $a['avg_rating'];
    return $b['count'] <=> $a['count'];
});
// compat: stylistCount ยังคงใช้สำหรับ dropdown
$stylistCount = array_map(fn($s) => $s['count'], $stylistStats);
$favStylist      = !empty($stylistStats) ? array_key_first($stylistStats) : '—';
$favStylistCount = !empty($stylistStats) ? $stylistStats[$favStylist]['count'] : 0;

// Filter via GET
$filterStylist = $_GET['stylist'] ?? '';
$filterService = $_GET['service'] ?? '';
$filterYear    = $_GET['year'] ?? '';

$filtered = $history;
if ($filterStylist) $filtered = array_filter($filtered, fn($b) => $b['stylist'] === $filterStylist);
if ($filterService) $filtered = array_filter($filtered, fn($b) => $b['service_id'] === $filterService);
if ($filterYear)    $filtered = array_filter($filtered, fn($b) => substr($b['date'],0,4) === $filterYear);

// Unique values for filter dropdowns
$stylists = array_unique(array_column($history, 'stylist'));
sort($stylists);
$years = array_unique(array_map(fn($b) => substr($b['date'],0,4), $history));
rsort($years);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking History - Bright Hair Studio</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --gold:#ff9f24; --gold-light:#ffb84a;
    --black:#0d0d0d; --dark:#141414; --dark2:#1c1c1c; --dark3:#242424;
    --white:#f8f4ef; --muted:#888;
    --border:rgba(255,159,36,0.25); --dbi:rgba(255,255,255,0.04);
    --logo-filter: none; --fbc:#444;
    --card:#1c1c1c; --card-border:rgba(255,159,36,0.2);
    --tag-done-bg:rgba(136,136,136,0.12); --tag-done-color:#888;
    --divider:rgba(255,255,255,0.06);
    --input-bg:#1c1c1c; --input-border:rgba(255,159,36,0.2);
    --stat-bg:#141414;
    --s-fill:#ff9f24; --s-empty:#242424;
}
@media (prefers-color-scheme:light){
    :root{
        --black:#fff; --dark:#f5f5f5; --dark2:#efefef; --dark3:#e4e4e4;
        --white:#111; --muted:#777; --border:rgba(255,159,36,0.3); --dbi:rgba(0,0,0,0.05);
        --logo-filter:none; --fbc:#bbb; --card:#f5f5f5; --card-border:rgba(255,159,36,0.25);
        --tag-done-bg:rgba(0,0,0,0.07); --tag-done-color:#777;
        --divider:rgba(0,0,0,0.07); --input-bg:#fff; --input-border:rgba(255,159,36,0.3);
        --stat-bg:#f0f0f0; --s-empty:#e0e0e0;
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

/* ===== PAGE ===== */
.page-content{flex:1;max-width:900px;width:100%;margin:0 auto;padding:56px 40px 80px;}

.page-eyebrow{font-size:10px;letter-spacing:4px;text-transform:uppercase;color:var(--gold);margin-bottom:12px;display:flex;align-items:center;gap:12px;}
.page-eyebrow::before{content:'';display:block;width:30px;height:1px;background:var(--gold);}
.page-title{font-family:'Cormorant Garamond',serif;font-size:44px;font-weight:300;color:var(--white);line-height:1;margin-bottom:12px;}
.page-title em{font-style:italic;color:var(--gold);}
.page-desc{font-size:13px;color:var(--muted);font-weight:300;margin-bottom:40px;}

/* ===== STATS BAND ===== */
.stats-band{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border);margin-bottom:32px;}
.stat-box{background:var(--stat-bg);padding:20px 22px;}
.stat-key{font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);margin-bottom:5px;}
.stat-val{font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;color:var(--white);line-height:1;}
.stat-val sup{font-size:13px;color:var(--muted);font-family:'Jost',sans-serif;}
.stat-sub{font-size:11px;color:var(--muted);font-weight:300;margin-top:3px;}

/* ===== FILTERS ===== */
.filter-bar{display:flex;gap:8px;align-items:center;margin-bottom:28px;flex-wrap:wrap;}
.filter-label{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-right:4px;}
.filter-select{background:var(--input-bg);border:1px solid var(--input-border);color:var(--white);font-family:'Jost',sans-serif;font-size:11px;letter-spacing:1px;padding:8px 12px;outline:none;cursor:pointer;transition:border-color .2s;-webkit-appearance:none;appearance:none;padding-right:28px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;}
.filter-select:focus{border-color:var(--gold);}
.filter-select option{background:var(--dark2);}
.btn-reset{background:transparent;color:var(--muted);border:1px solid var(--border);padding:8px 14px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;text-decoration:none;transition:all .2s;}
.btn-reset:hover{color:var(--white);border-color:var(--white);}

.filter-count{font-size:11px;color:var(--muted);font-weight:300;margin-left:auto;align-self:center;}

/* ===== SECTION LABEL ===== */
.section-label{font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--muted);margin-bottom:16px;display:flex;align-items:center;gap:12px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* ===== HISTORY LIST ===== */
.history-list{display:flex;flex-direction:column;gap:1px;background:var(--border);margin-bottom:40px;}

.history-card{background:var(--card);display:block;transition:background .2s;}
.history-card:hover{background:var(--dark3);}

.history-card-main{display:grid;grid-template-columns:auto 1fr auto;align-items:center;}

/* Date block */
.hc-date{padding:22px 24px;border-right:1px solid var(--border);text-align:center;min-width:80px;flex-shrink:0;}
.hc-date .day{font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;color:var(--white);line-height:1;}
.hc-date .month{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-top:3px;}
.hc-date .year{font-size:10px;color:var(--muted);margin-top:1px;}

/* Info */
.hc-info{padding:20px 24px;display:flex;flex-direction:column;gap:8px;}
.hc-service{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:500;color:var(--white);}
.hc-meta{display:flex;flex-wrap:wrap;gap:16px;}
.meta-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);font-weight:300;}
.meta-item i{color:var(--gold);font-size:11px;width:12px;}
.meta-item strong{color:var(--white);font-weight:500;}

/* Stars inline */
.hc-stars{display:flex;gap:3px;margin-top:2px;}
.hc-stars i{font-size:12px;}
.hc-stars .sf{color:var(--s-fill);}
.hc-stars .se{color:var(--s-empty);}
.hc-comment{font-size:12px;color:var(--muted);font-weight:300;font-style:italic;line-height:1.5;}
.hc-comment::before{content:'"';color:var(--gold);}
.hc-comment::after{content:'"';color:var(--gold);}

/* Right block */
.hc-right{padding:20px 24px;border-left:1px solid var(--border);display:flex;flex-direction:column;align-items:flex-end;gap:8px;min-width:130px;}
.hc-price{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:400;color:var(--white);}
.hc-price span{font-size:11px;font-family:'Jost',sans-serif;color:var(--muted);font-weight:300;}
.hc-id{font-size:10px;color:var(--muted);letter-spacing:1px;}
.status-tag{font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;padding:4px 10px;background:var(--tag-done-bg);color:var(--tag-done-color);border:1px solid var(--divider);}
.btn-review-tog{display:flex;align-items:center;gap:5px;background:transparent;color:var(--gold);border:1px solid rgba(255,159,36,.3);padding:6px 12px;font-size:9px;letter-spacing:1.5px;font-weight:700;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;transition:all .2s;white-space:nowrap;}
.btn-review-tog:hover{background:rgba(255,159,36,.1);border-color:var(--gold);}
.btn-review-tog.no-review{color:var(--muted);border-color:var(--border);pointer-events:none;}

/* ===== REVIEW EXPAND ===== */
.review-expand{display:none;border-top:1px solid var(--border);background:var(--dark);padding:20px 24px;animation:slideDown .18s ease;}
@keyframes slideDown{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
.review-expand.open{display:block;}

.re-label{font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);margin-bottom:14px;display:flex;align-items:center;gap:10px;}
.re-label::after{content:'';flex:1;height:1px;background:var(--border);}

.re-stars{display:flex;gap:5px;margin-bottom:10px;}
.re-stars i{font-size:18px;}
.re-stars .sf{color:var(--gold);}
.re-stars .se{color:var(--dark3);}
.re-comment{font-size:13px;color:var(--muted);font-weight:300;font-style:italic;line-height:1.7;}
.re-comment::before{content:'"';color:var(--gold);margin-right:2px;}
.re-comment::after{content:'"';color:var(--gold);margin-left:2px;}
.re-meta{font-size:10px;color:var(--muted);letter-spacing:.5px;margin-top:8px;}

/* no-review form */
.re-form .star-row{display:flex;gap:7px;margin-bottom:12px;}
.re-form .star{font-size:22px;cursor:pointer;color:var(--dark3);transition:color .12s;-webkit-user-select:none;user-select:none;}
.re-form .star.lit{color:var(--gold);}
.re-form textarea{width:100%;background:var(--dark2);border:1px solid var(--input-border);color:var(--white);font-family:'Jost',sans-serif;font-size:13px;font-weight:300;padding:11px 14px;resize:none;outline:none;transition:border-color .2s;margin-bottom:12px;line-height:1.6;}
.re-form textarea:focus{border-color:var(--gold);}
.re-form textarea::placeholder{color:var(--muted);}
.re-form-actions{display:flex;gap:10px;}
.btn-submit-re{background:var(--gold);color:var(--black);border:none;padding:9px 22px;font-size:10px;letter-spacing:2px;font-weight:700;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;transition:background .2s;display:flex;align-items:center;gap:7px;}
.btn-submit-re:hover{background:var(--gold-light);}
.btn-submit-re:disabled{background:var(--dark3);color:var(--muted);cursor:not-allowed;}
.btn-cancel-re{background:transparent;color:var(--muted);border:1px solid var(--border);padding:9px 16px;font-size:10px;letter-spacing:1.5px;font-weight:600;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;transition:all .2s;}
.btn-cancel-re:hover{color:var(--white);border-color:var(--white);}

/* ===== EMPTY ===== */
.empty-state{background:var(--card);border:1px solid var(--card-border);padding:48px 32px;text-align:center;margin-bottom:40px;}
.empty-state i{font-size:32px;color:var(--border);margin-bottom:16px;}
.empty-state p{font-size:13px;color:var(--muted);font-weight:300;}

/* ===== CTA ===== */
.cta-book{display:inline-flex;align-items:center;gap:10px;background:var(--gold);color:var(--black);padding:14px 32px;font-size:11px;letter-spacing:2.5px;font-weight:700;text-transform:uppercase;text-decoration:none;transition:background .2s;}
.cta-book:hover{background:var(--gold-light);}

/* ===== FOOTER ===== */
.footer{background:var(--dark);border-top:1px solid var(--border);padding:20px 40px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.footer p{font-size:11px;color:var(--fbc);}
.footer-links{display:flex;gap:20px;}
.footer-links a{font-size:11px;color:var(--fbc);text-decoration:none;transition:color .2s;}
.footer-links a:hover{color:var(--gold);}

@media(max-width:700px){
    .header{padding:0 16px;} .header-nav{display:none;}
    .page-content{padding:36px 16px 60px;}
    .stats-band{grid-template-columns:1fr 1fr;}
    .history-card-main{grid-template-columns:1fr;}
    .hc-date{border-right:none;border-bottom:1px solid var(--border);padding:16px 20px;text-align:left;display:flex;align-items:baseline;gap:10px;}
    .hc-date .day{font-size:26px;}
    .hc-right{border-left:none;border-top:1px solid var(--border);flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:space-between;}
    .filter-bar{gap:6px;}
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
                    <div class="name"><?= htmlspecialchars($fullName ?: 'Guest') ?></div>
                    <div class="email"><?= htmlspecialchars($email) ?></div>
                </div>
                <a href="my_profile.php" class="dropdown-item"><i class="fa fa-user-circle"></i> My Profile</a>
                <a href="customer_my_booking.php" class="dropdown-item"><i class="fa fa-calendar-check"></i> My Bookings</a>
                <a href="booking_history.php" class="dropdown-item active-link"><i class="fa fa-clock-rotate-left"></i> Booking History</a>
                <a href="my_rewards.php" class="dropdown-item"><i class="fa fa-gift"></i> Rewards & Points</a>
                <a href="logout.php" class="dropdown-item logout"><i class="fa fa-arrow-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<div class="page-content">

    <div class="page-eyebrow">Account</div>
    <h1 class="page-title">Booking <em>History</em></h1>
    <p class="page-desc">ประวัติการใช้บริการทั้งหมดของคุณ</p>

    <!-- STATS -->
    <div class="stats-band">
        <div class="stat-box">
            <div class="stat-key">Total Visits</div>
            <div class="stat-val"><?= $totalVisits ?></div>
            <div class="stat-sub">ครั้งรวมทั้งหมด</div>
        </div>
        <div class="stat-box">
            <div class="stat-key">Total Spend</div>
            <div class="stat-val"><?= number_format($totalSpend) ?><sup>฿</sup></div>
            <div class="stat-sub">ยอดสะสม</div>
        </div>
        <div class="stat-box">
            <div class="stat-key">Avg. Rating</div>
            <div class="stat-val"><?= $totalReviews > 0 ? number_format($avgRating,1) : '—' ?></div>
            <div class="stat-sub">จาก <?= $totalReviews ?> รีวิว</div>
        </div>
        <div class="stat-box">
            <div class="stat-key">Fav. Stylist</div>
            <div class="stat-val" style="font-size:22px;"><?= htmlspecialchars($favStylist) ?></div>
            <div class="stat-sub"><?= $favStylistCount ?> ครั้ง</div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="">
        <div class="filter-bar">
            <span class="filter-label">Filter</span>

            <select class="filter-select" name="stylist" onchange="this.form.submit()">
                <option value="">ช่างทุกคน</option>
                <?php foreach ($stylists as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStylist===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>

            <select class="filter-select" name="service" onchange="this.form.submit()">
                <option value="">ทุกบริการ</option>
                <?php foreach ($services as $sid => $svc): ?>
                    <option value="<?= $sid ?>" <?= $filterService===$sid?'selected':'' ?>><?= $svc['name'] ?></option>
                <?php endforeach; ?>
            </select>

            <select class="filter-select" name="year" onchange="this.form.submit()">
                <option value="">ทุกปี</option>
                <?php foreach ($years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $filterYear===$yr?'selected':'' ?>><?= (int)$yr+543 ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($filterStylist || $filterService || $filterYear): ?>
                <a href="booking_history.php" class="btn-reset">
                    <i class="fa fa-xmark"></i> รีเซ็ต
                </a>
            <?php endif; ?>

            <span class="filter-count"><?= count($filtered) ?> รายการ</span>
        </div>
    </form>

    <!-- HISTORY LIST -->
    <div class="section-label">ประวัติการนัดหมาย</div>

    <?php if (empty($filtered)): ?>
    <div class="empty-state">
        <i class="fa fa-clock-rotate-left"></i>
        <p>ไม่พบรายการที่ตรงกับเงื่อนไข</p>
    </div>
    <?php else: ?>
    <div class="history-list">
        <?php foreach ($filtered as $b):
            $svc       = $services[$b['service_id']];
            $endTime   = calcEndTime($b['start_time'], $svc['duration']);
            $dp        = explode('-', $b['date']);
            $ms        = ['','JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            $hasReview = !empty($b['review']);
            $bid       = $b['booking_id'];
        ?>
        <div class="history-card">
            <div class="history-card-main">

                <!-- Date -->
                <div class="hc-date">
                    <div class="day"><?= (int)$dp[2] ?></div>
                    <div class="month"><?= $ms[(int)$dp[1]] ?></div>
                    <div class="year"><?= (int)$dp[0]+543 ?></div>
                </div>

                <!-- Info -->
                <div class="hc-info">
                    <div class="hc-service"><?= $svc['name'] ?></div>
                    <div class="hc-meta">
                        <div class="meta-item"><i class="fa fa-clock"></i> <strong><?= $b['start_time'] ?> – <?= $endTime ?></strong> <span>(<?= $svc['duration'] ?> นาที)</span></div>
                        <div class="meta-item"><i class="fa fa-user-scissors"></i> ช่าง <strong><?= htmlspecialchars($b['stylist']) ?></strong></div>
                    </div>
                    <?php if ($hasReview): ?>
                    <div class="hc-stars">
                        <?php for ($i=1;$i<=5;$i++): ?><i class="fa fa-star <?= $i<=$b['review']['rating']?'sf':'se' ?>"></i><?php endfor; ?>
                    </div>
                    <div class="hc-comment"><?= htmlspecialchars($b['review']['comment']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Right -->
                <div class="hc-right">
                    <div class="hc-price"><?= number_format($svc['price']) ?> <span>฿</span></div>
                    <div class="status-tag">Completed</div>
                    <?php if ($hasReview): ?>
                        <button class="btn-review-tog" onclick="toggleRE('<?= $bid ?>')">
                            <i class="fa fa-star"></i> รีวิว
                        </button>
                    <?php else: ?>
                        <button class="btn-review-tog" onclick="toggleRE('<?= $bid ?>')">
                            <i class="fa fa-pen-to-square"></i> เขียนรีวิว
                        </button>
                    <?php endif; ?>
                    <div class="hc-id"><?= $bid ?></div>
                </div>

            </div>

            <!-- REVIEW EXPAND -->
            <div class="review-expand" id="re-<?= $bid ?>"
                 data-db-id="<?= (int)$b['raw_booking_id'] ?>"
                 data-employee-id="<?= (int)$b['employee_id'] ?>">
                <?php if ($hasReview): ?>
                    <div class="re-label">รีวิวของคุณ</div>
                    <div class="re-stars">
                        <?php for ($i=1;$i<=5;$i++): ?><i class="fa fa-star <?= $i<=$b['review']['rating']?'sf':'se' ?>"></i><?php endfor; ?>
                    </div>
                    <div class="re-comment"><?= htmlspecialchars($b['review']['comment']) ?></div>
                    <div class="re-meta">ช่าง <?= htmlspecialchars($b['stylist']) ?> · <?= formatDateThai($b['date']) ?></div>

                <?php else: ?>
                    <div class="re-label">เขียนรีวิว — ช่าง <?= htmlspecialchars($b['stylist']) ?></div>
                    <div class="re-form">
                        <div class="star-row" id="sr-<?= $bid ?>" data-rating="0">
                            <?php for ($i=1;$i<=5;$i++): ?>
                            <span class="star" data-val="<?= $i ?>"
                                  onmouseover="hov('<?= $bid ?>',<?= $i ?>)"
                                  onmouseout="rst('<?= $bid ?>')"
                                  onclick="setR('<?= $bid ?>',<?= $i ?>)">★</span>
                            <?php endfor; ?>
                        </div>
                        <textarea id="cm-<?= $bid ?>" rows="3"
                            placeholder="แชร์ประสบการณ์การบริการของคุณ..."></textarea>
                        <div class="re-form-actions">
                            <button class="btn-submit-re" id="sbtn-<?= $bid ?>"
                                    onclick="submitRE('<?= $bid ?>')" disabled>
                                <i class="fa fa-paper-plane"></i> ส่งรีวิว
                            </button>
                            <button class="btn-cancel-re" onclick="toggleRE('<?= $bid ?>')">ยกเลิก</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="booking_calendar.php" class="cta-book">
        <i class="fa fa-calendar-plus"></i> จองนัดใหม่
    </a>

</div>

<footer class="footer">
    <p>© <?= date('Y') ?> Bright Hair Studio. All rights reserved.</p>
    <div class="footer-links">
        <a href="#">Terms & Conditions</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Service Policy</a>
    </div>
</footer>

<script>
// Profile dropdown
const pw = document.getElementById('profileWrapper');
document.getElementById('profileBtn').addEventListener('click', e => { e.stopPropagation(); pw.classList.toggle('open'); });
document.addEventListener('click', () => pw.classList.remove('open'));

// Review expand toggle
function toggleRE(bid) {
    document.getElementById('re-' + bid).classList.toggle('open');
}

// Star interactions
function hov(bid, val) {
    document.querySelectorAll(`#sr-${bid} .star`).forEach((s,i) => s.classList.toggle('lit', i < val));
}
function rst(bid) {
    const r = parseInt(document.getElementById('sr-' + bid).dataset.rating);
    document.querySelectorAll(`#sr-${bid} .star`).forEach((s,i) => s.classList.toggle('lit', i < r));
}
function setR(bid, val) {
    document.getElementById('sr-' + bid).dataset.rating = val;
    rst(bid);
    chk(bid);
}

// Wire textarea input
document.querySelectorAll('.re-form textarea').forEach(ta => {
    ta.addEventListener('input', () => chk(ta.id.replace('cm-','')));
});

function chk(bid) {
    const r = parseInt(document.getElementById('sr-' + bid)?.dataset.rating ?? 0);
    const c = document.getElementById('cm-' + bid)?.value.trim();
    const btn = document.getElementById('sbtn-' + bid);
    if (btn) btn.disabled = !(r > 0 && c?.length > 0);
}

async function submitRE(bid) {
    const r = parseInt(document.getElementById('sr-' + bid).dataset.rating);
    const c = document.getElementById('cm-' + bid).value.trim();
    if (!r || !c) return;

    const panel      = document.getElementById('re-' + bid);
    const bookingDbId  = panel.dataset.dbId;
    const employeeId   = panel.dataset.employeeId;

    const btn = document.getElementById('sbtn-' + bid);
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> กำลังส่ง...';

    try {
        const fd = new FormData();
        fd.append('action',      'submit_review');
        fd.append('booking_id',  bookingDbId);
        fd.append('employee_id', employeeId);
        fd.append('rating',      r);
        fd.append('comment',     c);

        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const starsHtml = [1,2,3,4,5].map(i =>
                `<i class="fa fa-star ${i<=r?'sf':'se'}"></i>`
            ).join('');

            panel.innerHTML = `
                <div class="re-label">รีวิวของคุณ</div>
                <div class="re-stars">${starsHtml}</div>
                <div class="re-comment">${esc(c)}</div>
                <div class="re-meta">เพิ่งส่งรีวิว</div>`;

            // อัปเดตปุ่มในการ์ด
            const card = panel.closest('.history-card');
            const togBtn = card.querySelector('.btn-review-tog');
            if (togBtn) togBtn.innerHTML = '<i class="fa fa-star"></i> รีวิว';

            // เพิ่มดาวใน hc-info
            const infoEl = card.querySelector('.hc-info');
            if (infoEl && !infoEl.querySelector('.hc-stars')) {
                const smallStars = [1,2,3,4,5].map(i =>
                    `<i class="fa fa-star ${i<=r?'sf':'se'}" style="font-size:12px;"></i>`
                ).join('');
                const starsDiv = document.createElement('div');
                starsDiv.className = 'hc-stars';
                starsDiv.innerHTML = smallStars;
                const commentDiv = document.createElement('div');
                commentDiv.className = 'hc-comment';
                commentDiv.textContent = c;
                infoEl.appendChild(starsDiv);
                infoEl.appendChild(commentDiv);
            }
        } else {
            alert(data.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> ส่งรีวิว';
        }
    } catch(e) {
        alert('ไม่สามารถเชื่อมต่อได้');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> ส่งรีวิว';
    }
}

function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>