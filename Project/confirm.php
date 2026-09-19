<?php
session_start();
if(!isset($_SESSION['user'])){
    header("Location: customer_login.php");
    exit();
}

require 'db.php'; // เรียกใช้ DB ตั้งแต่ต้นเพื่อความสะดวก

// รับค่าจาก booking_calendar.php หรือ promotions.php
$date       = $_GET['date']     ?? '';
$service    = $_GET['service']  ?? '';
$stylist    = $_GET['stylist']  ?? '';
$time       = $_GET['time']     ?? '';
$price      = $_GET['price']    ?? '';        // ส่งมาจากโปรโมชั่น (optional)
$duration   = $_GET['duration'] ?? '';        // ส่งมาจากโปรโมชั่น (optional)
$source     = $_GET['source']   ?? 'normal';  // "promo" หรือ "normal"

// --- ส่วนที่เพิ่ม: แปลง Service Code เป็นชื่อบริการเพื่อการแสดงผล ---
$displayServiceName = $service; 
if ($service != "" && $source !== 'promo') {
    $stmtSvc = $pdo->prepare("SELECT name FROM services WHERE code = ?");
    $stmtSvc->execute([$service]);
    $rowSvc = $stmtSvc->fetch();
    if ($rowSvc) {
        $displayServiceName = $rowSvc['name'];
    }
}

// แปลงวันที่เป็นภาษาไทย
$thaiDate = "";
if ($date != "") {
    $timestamp = strtotime($date);
    $days   = ["อาทิตย์","จันทร์","อังคาร","พุธ","พฤหัส","ศุกร์","เสาร์"];
    $months = ["","มกราคม","กุมภาพันธ์","มีนาคม","เมษายน","พฤษภาคม","มิถุนายน",
               "กรกฎาคม","สิงหาคม","กันยายน","ตุลาคม","พฤศจิกายน","ธันวาคม"];
    $day  = $days[date("w", $timestamp)];
    $d    = date("j",  $timestamp);
    $m    = $months[date("n", $timestamp)];
    $y    = date("Y",  $timestamp) + 543;
    $thaiDate = "วัน$day ที่ $d $m $y";
}

// คำนวณ end time ถ้ามี duration
$endTime = '';
if ($time && $duration) {
    $parts     = explode(':', $time);
    $startMin  = (int)$parts[0] * 60 + (int)$parts[1];
    $endMin    = $startMin + (int)$duration;
    $endTime   = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);
}

// ===== บันทึก booking เมื่อกด Confirm =====
$bookingResult = null;
$bookingCode   = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id     = $_SESSION['user_id'] ?? null;
    $postDate    = $_POST['date']     ?? '';
    $postService = $_POST['service']  ?? '';
    $postStylist = $_POST['stylist']  ?? '';
    $postTime    = $_POST['time']     ?? '';
    $postSource  = $_POST['source']   ?? 'normal';
    $postDuration= $_POST['duration'] ?? null;
    $postPrice   = $_POST['price']    ?? null;

    $svcId  = null;
    $empId  = null;
    $durMin = $postDuration ? (int)$postDuration : 60;

    if ($postSource === 'promo') {
        $durMin = (int)$postDuration;
    } else {
        $svc = $pdo->prepare("SELECT * FROM services WHERE code = ?");
        $svc->execute([$postService]);
        $svcRow = $svc->fetch();
        $svcId  = $svcRow['id'] ?? null;
        $durMin = $svcRow['duration_min'] ?? 60;

        if ($postStylist) {
            $emp = $pdo->prepare("SELECT id FROM employees WHERE name = ?");
            $emp->execute([$postStylist]);
            $empRow = $emp->fetch();
            $empId  = $empRow['id'] ?? null;
        }
    }

    $code = 'BK-' . strtoupper(uniqid());

    $stmt = $pdo->prepare("
        INSERT INTO bookings
            (booking_code, user_id, service_id, employee_id, booking_date,
             start_time, duration_min, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', ?)
    ");
    $notes = ($postSource === 'promo')
        ? 'PROMO:' . $postService . ($postPrice ? '|PRICE:' . (int)$postPrice : '') . '|DUR:' . $durMin
        : null;
    $stmt->execute([
        $code,
        $user_id,
        $svcId,
        $empId,
        $postDate,
        $postTime . ':00',
        $durMin,
        $notes,
    ]);

    $bookingCode   = $code;
    $bookingResult = 'success';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm Booking - Bright Hair Studio</title>
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
}
@media (prefers-color-scheme: light) {
    :root {
        --black: #ffffff; --dark: #f5f5f5; --dark2: #efefef; --dark3: #e4e4e4;
        --white: #111111; --muted: #777; --border: rgba(255,159,36,0.3);
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Jost', sans-serif;
    background: var(--black); color: var(--white);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.box {
    background: var(--dark);
    border: 1px solid var(--border);
    width: 100%; max-width: 480px;
    padding: 40px 36px 36px;
}
.box-eyebrow {
    font-size: 9px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px;
    display: flex; align-items: center; gap: 10px;
}
.box-eyebrow::before { content: ''; display: block; width: 24px; height: 1px; background: var(--gold); }
.box-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px; font-weight: 300; color: var(--white); line-height: 1; margin-bottom: 28px;
}
.box-title em { font-style: italic; color: var(--gold); }
.promo-source-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(158,90,229,0.12); border: 1px solid rgba(158,90,229,0.35);
    color: #c084fc; font-size: 10px; letter-spacing: 1.5px;
    text-transform: uppercase; padding: 4px 12px; margin-bottom: 20px;
}
.detail-rows { display: flex; flex-direction: column; gap: 0; margin-bottom: 28px; }
.detail-row {
    display: flex; align-items: flex-start;
    padding: 14px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
}
.detail-row:last-child { border-bottom: none; }
.detail-key {
    font-size: 9px; letter-spacing: 2px; text-transform: uppercase;
    color: var(--gold); width: 80px; flex-shrink: 0; padding-top: 2px;
}
.detail-val { font-size: 14px; color: var(--white); font-weight: 400; flex: 1; }
.detail-val.muted { color: var(--muted); font-style: italic; font-size: 12px; }
.price-block {
    background: var(--dark2); border: 1px solid var(--border);
    padding: 16px 20px; margin-bottom: 28px;
    display: flex; align-items: center; justify-content: space-between;
}
.price-label { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
.price-val {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px; color: var(--gold); line-height: 1;
}
.price-val span { font-family: 'Jost', sans-serif; font-size: 14px; color: var(--muted); }
.btn-confirm {
    width: 100%; background: var(--gold); color: var(--black); border: none;
    padding: 16px; font-size: 11px; letter-spacing: 2.5px; font-weight: 700;
    text-transform: uppercase; cursor: pointer; font-family: 'Jost', sans-serif;
    transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px;
    margin-bottom: 12px;
}
.btn-confirm:hover { background: var(--gold-light); }
.btn-back {
    display: block; text-align: center;
    font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted); text-decoration: none;
    padding: 10px; transition: color 0.2s;
}
.btn-back:hover { color: var(--white); }
.success-box { text-align: center; padding: 20px 0; }
.success-icon {
    width: 64px; height: 64px;
    background: rgba(46,196,182,0.1); border: 1px solid rgba(46,196,182,0.4);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #2ec4b6; margin: 0 auto 20px;
}
.success-code { font-size: 11px; letter-spacing: 2px; color: var(--muted); margin-top: 6px; }
.success-code strong { color: var(--gold); font-size: 14px; }
.btn-home {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--gold); color: var(--black);
    padding: 13px 28px; font-size: 11px; letter-spacing: 2px; font-weight: 700;
    text-transform: uppercase; text-decoration: none;
    transition: background 0.2s; margin-top: 24px;
}
.btn-home:hover { background: var(--gold-light); }
</style>
</head>
<body>
<div class="box">
<?php if ($bookingResult === 'success'): ?>
    <div class="success-box">
        <div class="success-icon"><i class="fa fa-circle-check"></i></div>
        <div class="box-eyebrow" style="justify-content:center;">Booking Confirmed</div>
        <div class="box-title" style="text-align:center;margin-bottom:12px;">จอง<em>สำเร็จ</em></div>
        <div class="success-code">รหัสการจอง: <strong><?= htmlspecialchars($bookingCode) ?></strong></div>
        <a href="customer_home.php" class="btn-home"><i class="fa fa-house"></i> กลับหน้าหลัก</a>
    </div>
<?php else: ?>
    <div class="box-eyebrow">
        <?= $source === 'promo' ? 'Promotion Booking' : 'Appointment' ?>
    </div>
    <div class="box-title">ยืนยัน<em>การจอง</em></div>

    <?php if ($source === 'promo'): ?>
    <div class="promo-source-badge"><i class="fa fa-star"></i> โปรโมชั่นพิเศษ</div>
    <?php endif; ?>

    <div class="detail-rows">
        <div class="detail-row">
            <div class="detail-key">บริการ</div>
            <div class="detail-val"><?= htmlspecialchars($displayServiceName) ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-key">ช่าง</div>
            <div class="detail-val"><?= $stylist ? htmlspecialchars($stylist) : '<span class="muted">ทางร้านจะจัดให้</span>' ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-key">วันที่</div>
            <div class="detail-val"><?= $thaiDate ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-key">เวลา</div>
            <div class="detail-val">
                <?= htmlspecialchars($time) ?>
                <?php if ($endTime): ?> – <?= $endTime ?><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($price): ?>
    <div class="price-block">
        <div class="price-label">ราคา</div>
        <div class="price-val"><?= number_format((int)$price) ?> <span>บาท</span></div>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="date"     value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="service"  value="<?= htmlspecialchars($service) ?>">
        <input type="hidden" name="stylist"  value="<?= htmlspecialchars($stylist) ?>">
        <input type="hidden" name="time"     value="<?= htmlspecialchars($time) ?>">
        <input type="hidden" name="source"   value="<?= htmlspecialchars($source) ?>">
        <input type="hidden" name="duration" value="<?= htmlspecialchars($duration) ?>">
        <input type="hidden" name="price"    value="<?= htmlspecialchars($price) ?>">
        <button class="btn-confirm" type="submit">
            <i class="fa fa-calendar-check"></i> ยืนยันการจอง
        </button>
    </form>
    <a class="btn-back" href="<?= $source === 'promo' ? 'promotions.php' : 'booking_calendar.php' ?>">← กลับไปแก้ไข</a>
<?php endif; ?>
</div>
</body>
</html>