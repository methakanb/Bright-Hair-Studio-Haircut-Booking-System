<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'move') {
        require_once 'db.php';
        $old_key = $_POST['old_key'] ?? '';
        $new_key = $_POST['new_key'] ?? '';

        if (!$old_key || !$new_key || $old_key === $new_key) {
            http_response_code(400); echo 'error'; exit;
        }

        // key format: dayOfWeek_hour_empId
        $old_parts = explode('_', $old_key);
        $new_parts = explode('_', $new_key);

        if (count($old_parts) < 3 || count($new_parts) < 3) {
            http_response_code(400); echo 'error'; exit;
        }

        $old_day  = (int)$old_parts[0];
        $old_hour = (int)$old_parts[1];
        $old_emp  = (int)$old_parts[2];

        $new_day  = (int)$new_parts[0];
        $new_hour = (int)$new_parts[1];
        $new_emp  = (int)$new_parts[2];

        $selectedDate = $_POST['date'] ?? date('Y-m-d');

        // Find booking ID by emp + hour + date
        $findStmt = $pdo->prepare("
            SELECT id, duration_min FROM bookings
            WHERE employee_id = ?
              AND DATE(booking_date) = ?
              AND TIME_FORMAT(start_time,'%H') = ?
              AND status != 'cancelled'
            LIMIT 1
        ");
        $findStmt->execute([$old_emp, $selectedDate, str_pad($old_hour, 2, '0', STR_PAD_LEFT)]);
        $booking = $findStmt->fetch();

        if (!$booking) {
            http_response_code(400); echo 'error'; exit;
        }

        $bookingId = $booking['id'];
        $hrs = max(1, ceil($booking['duration_min'] / 60));

        // Check if new slot exceeds closing time
        if ($new_hour + $hrs > 20) {
            http_response_code(400); echo 'error'; exit;
        }

        // Check for time-slot conflict across all duration hours
        $checkStmt = $pdo->prepare("
            SELECT TIME_FORMAT(start_time,'%H') as h, duration_min FROM bookings
            WHERE employee_id = ?
              AND DATE(booking_date) = ?
              AND status != 'cancelled'
              AND id != ?
        ");
        $checkStmt->execute([$new_emp, $selectedDate, $bookingId]);
        $conflict = false;
        $mStart = $new_hour * 60;
        $mEnd = $mStart + ($hrs * 60);
        foreach($checkStmt->fetchAll() as $r) {
            $bStart = ((int)$r['h']) * 60;
            $bEnd = $bStart + (int)$r['duration_min'];
            if (max($mStart, $bStart) < min($mEnd, $bEnd)) {
                $conflict = true;
                break;
            }
        }

        if ($conflict) {
            http_response_code(400); echo 'error'; exit;
        }

        // Update booking in DB
        $newTime = str_pad($new_hour, 2, '0', STR_PAD_LEFT) . ':00:00';
        $upStmt = $pdo->prepare("UPDATE bookings SET employee_id = ?, start_time = ? WHERE id = ?");
        $upStmt->execute([$new_emp, $newTime, $bookingId]);
        echo 'success';
        exit;
    }
    if ($_POST['action'] === 'add') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            require_once 'db.php';

            $clientName  = trim($_POST['client_name'] ?? '');
            $clientTier  = $_POST['client_tier'] ?? 'Member';
            $serviceCode = $_POST['service_code'] ?? '';
            $employeeId  = (int)($_POST['employee_id'] ?? 0);
            $hour        = (int)($_POST['hour'] ?? 10);
            $duration    = (int)($_POST['duration'] ?? 1);
            $status      = $_POST['status'] ?? 'confirmed';
            $notes       = trim($_POST['notes'] ?? '');
            $bookingDate = $_POST['date'] ?? date('Y-m-d');

            if (!$clientName || !$serviceCode || !$employeeId) {
                http_response_code(400);
                echo json_encode(['error' => 'ข้อมูลไม่ครบ กรุณากรอกให้ครบทุกช่อง']);
                exit;
            }

            // Split client name into first / last
            $nameParts = explode(' ', $clientName, 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? '';

            // Find existing user or create a new one
            $stmtUser = $pdo->prepare("SELECT id FROM users WHERE first_name = ? LIMIT 1");
            $stmtUser->execute([$firstName]);
            $userId = $stmtUser->fetchColumn();

            if (!$userId) {
                // สร้าง email ไม่ซ้ำสำหรับ user ที่เพิ่มจากหน้า admin
                $uniqueEmail = 'walkin_' . time() . '_' . mt_rand(1000,9999) . '@brighthair.local';

                // ตรวจ column ที่มีจริงใน users table แล้ว insert
                $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                $hasEmail = in_array('email', $cols);
                $hasTier  = in_array('member_tier', $cols);

                if ($hasEmail && $hasTier) {
                    $stmtIU = $pdo->prepare("INSERT INTO users (first_name, last_name, email, member_tier) VALUES (?, ?, ?, ?)");
                    $stmtIU->execute([$firstName, $lastName, $uniqueEmail, $clientTier]);
                } elseif ($hasEmail) {
                    $stmtIU = $pdo->prepare("INSERT INTO users (first_name, last_name, email) VALUES (?, ?, ?)");
                    $stmtIU->execute([$firstName, $lastName, $uniqueEmail]);
                } elseif ($hasTier) {
                    $stmtIU = $pdo->prepare("INSERT INTO users (first_name, last_name, member_tier) VALUES (?, ?, ?)");
                    $stmtIU->execute([$firstName, $lastName, $clientTier]);
                } else {
                    $stmtIU = $pdo->prepare("INSERT INTO users (first_name, last_name) VALUES (?, ?)");
                    $stmtIU->execute([$firstName, $lastName]);
                }
                $userId = $pdo->lastInsertId();
            }

            $serviceId = null;
            $promotionId = null;

            if (strpos($serviceCode, 'P_') === 0) {
                // It's a promotion
                $pId = str_replace('P_', '', $serviceCode);
                $stmtPromo = $pdo->prepare("SELECT id FROM promotions WHERE id = ? LIMIT 1");
                $stmtPromo->execute([$pId]);
                $promotionId = $stmtPromo->fetchColumn();
                if (!$promotionId) {
                    http_response_code(400); echo json_encode(['error' => 'ไม่พบโปรโมชั่นที่เลือก']); exit;
                }
            } else {
                // Find service_id from code
                $stmtSvc = $pdo->prepare("SELECT id FROM services WHERE code = ? LIMIT 1");
                $stmtSvc->execute([$serviceCode]);
                $serviceId = $stmtSvc->fetchColumn();
                if (!$serviceId) {
                    http_response_code(400); echo json_encode(['error' => 'ไม่พบบริการที่เลือก (code: ' . $serviceCode . ')']); exit;
                }
            }

            // Check for closing time
            if ($hour + $duration > 20) {
                http_response_code(409);
                echo json_encode(['error' => 'เวลาจองรวมถึงระยะเวลาบริการ เกินเวลาปิดร้าน (20:00)']);
                exit;
            }

            // Check for time-slot conflict across all duration hours
            $currentStart = $hour * 60;
            $currentEnd = $currentStart + ($duration * 60);
            
            $stmtChk = $pdo->prepare("
                SELECT TIME_FORMAT(start_time, '%H') as h, duration_min 
                FROM bookings 
                WHERE employee_id = ? AND booking_date = ? AND status != 'cancelled'
            ");
            $stmtChk->execute([$employeeId, $bookingDate]);
            $conflict = false;
            foreach ($stmtChk->fetchAll() as $row) {
                $bStart = ((int)$row['h']) * 60;
                $bEnd = $bStart + (int)$row['duration_min'];
                if (max($currentStart, $bStart) < min($currentEnd, $bEnd)) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                http_response_code(409);
                echo json_encode(['error' => 'คิวงานซ้อนทับกับคิวอื่นของช่างท่านนี้ (ชนระยะเวลา '.$duration.' ชม.)']);
                exit;
            }

            $startTime = sprintf('%02d:00:00', $hour);

            // Generate a unique booking code
            $bookingCode = 'ADM-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $durationMin = $duration * 60;

            // ตรวจ column ที่มีจริงใน bookings table
            $bCols = $pdo->query("SHOW COLUMNS FROM bookings")->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('duration_min', $bCols) && in_array('promotion_id', $bCols)) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO bookings (booking_code, user_id, service_id, promotion_id, employee_id,
                                          booking_date, start_time, duration_min, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $bookingCode, $userId, $serviceId, $promotionId, $employeeId,
                    $bookingDate, $startTime, $durationMin, $status, $notes
                ]);
            } elseif (in_array('duration_min', $bCols)) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO bookings (booking_code, user_id, service_id, employee_id,
                                          booking_date, start_time, duration_min, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $bookingCode, $userId, $serviceId, $employeeId,
                    $bookingDate, $startTime, $durationMin, $status, $notes
                ]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO bookings (booking_code, user_id, service_id, employee_id,
                                          booking_date, start_time, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $bookingCode, $userId, $serviceId, $employeeId,
                    $bookingDate, $startTime, $status, $notes
                ]);
            }

            echo json_encode(['success' => true, 'booking_code' => $bookingCode]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($_POST['action'] === 'update_status') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            require_once 'db.php';
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';
            
            if (!$bookingId || !$newStatus) {
                http_response_code(400); echo json_encode(['error' => 'ข้อมูลไม่ครบ']); exit;
            }
            
            $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $bookingId]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]); exit;
        }
    }

    if ($_POST['action'] === 'cancel') {
        // Admin cancels a booking (keeps the record, just changes status)
        header('Content-Type: application/json; charset=utf-8');
        try {
            require_once 'db.php';
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            if (!$bookingId) {
                http_response_code(400);
                echo json_encode(['error' => 'ไม่ระบุ Booking ID']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status != 'cancelled'");
            $stmt->execute([$bookingId]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($_POST['action'] === 'delete') {
        // Hard delete — permanently removes the booking record
        header('Content-Type: application/json; charset=utf-8');
        try {
            require_once 'db.php';
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            if (!$bookingId) {
                http_response_code(400);
                echo json_encode(['error' => 'ไม่ระบุ Booking ID']);
                exit;
            }
            // ลบ reviews ที่เกี่ยวข้องก่อน (foreign key constraint)
            $pdo->prepare("DELETE FROM reviews WHERE booking_id = ?")->execute([$bookingId]);
            $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
            $stmt->execute([$bookingId]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
            exit;
        }
    }
}

/**
 * Bright Hair Studio - Admin Dashboard
 */
date_default_timezone_set('Asia/Bangkok');

$currentDay = (int) date('w'); // 0=Sun, 1=Mon, ...
$currentHour = (int) date('G');
$year = date('Y');

// รับวันที่จาก GET parameter (format: Y-m-d) หรือใช้วันนี้
$todayDate = date('Y-m-d');
// ไม่อนุญาตให้เลือกวันในอดีต — min คือวันนี้ (เวลาไทย)
$minDate = $todayDate;
$maxDate = date('Y-m-t', strtotime('first day of next month'));

$selectedDate = isset($_GET['date']) ? $_GET['date'] : $todayDate;
$selectedTimestamp = strtotime($selectedDate);
if (!$selectedTimestamp || $selectedDate < $minDate) {
    $selectedDate = $todayDate;
    $selectedTimestamp = strtotime($selectedDate);
} elseif ($selectedDate > $maxDate) {
    $selectedDate = $maxDate;
    $selectedTimestamp = strtotime($selectedDate);
}

$selectedDay = (int) date('w', $selectedTimestamp);
$selectedDayOfMonth = (int) date('j', $selectedTimestamp);
$selectedMonth = (int) date('n', $selectedTimestamp);
$selectedYear = (int) date('Y', $selectedTimestamp);

// Thai month names
$thaiMonths = [
    1 => 'มกราคม',
    2 => 'กุมภาพันธ์',
    3 => 'มีนาคม',
    4 => 'เมษายน',
    5 => 'พฤษภาคม',
    6 => 'มิถุนายน',
    7 => 'กรกฎาคม',
    8 => 'สิงหาคม',
    9 => 'กันยายน',
    10 => 'ตุลาคม',
    11 => 'พฤศจิกายน',
    12 => 'ธันวาคม'
];

$thaiShortDays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

// สร้างข้อมูลปฏิทินสำหรับเดือนที่เลือก
$firstDayOfMonth = mktime(0, 0, 0, $selectedMonth, 1, $selectedYear);
$daysInMonth = (int) date('t', $firstDayOfMonth);
$startingDayOfWeek = (int) date('w', $firstDayOfMonth); // 0=Sun

// เดือนก่อนหน้าและถัดไป
$prevMonth = date('Y-m-d', mktime(0, 0, 0, $selectedMonth - 1, 1, $selectedYear));
$nextMonth = date('Y-m-d', mktime(0, 0, 0, $selectedMonth + 1, 1, $selectedYear));

$weekNumber = date('W', $selectedTimestamp);

// วันในสัปดาห์
$days = [
    1 => ['short' => 'จันทร์', 'full' => 'วันจันทร์', 'en' => 'Mon'],
    2 => ['short' => 'อังคาร', 'full' => 'วันอังคาร', 'en' => 'Tue'],
    3 => ['short' => 'พุธ', 'full' => 'วันพุธ', 'en' => 'Wed'],
    4 => ['short' => 'พฤหัส', 'full' => 'วันพฤหัสบดี', 'en' => 'Thu'],
    5 => ['short' => 'ศุกร์', 'full' => 'วันศุกร์', 'en' => 'Fri'],
    6 => ['short' => 'เสาร์', 'full' => 'วันเสาร์', 'en' => 'Sat'],
    0 => ['short' => 'อาทิตย์', 'full' => 'วันอาทิตย์', 'en' => 'Sun'],
];

require_once 'db.php';

// ── ดึงวันลา approved ของทุกช่าง ────────────────────
// สร้าง map: emp_id → [dates ที่ลา]
$empLeaveDates = []; // [ emp_id => ['2026-04-15', '2026-04-16', ...] ]
try {
    $leaveStmt = $pdo->prepare("
        SELECT employee_id, leave_date_start, leave_date_end
        FROM leave_requests
        WHERE status = 'approved'
          AND leave_date_end >= ?
    ");
    $leaveStmt->execute([$selectedDate]);
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $eid   = (int)$lr['employee_id'];
        $start = new DateTime($lr['leave_date_start']);
        $end   = new DateTime($lr['leave_date_end']);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        foreach ($period as $dt) {
            $empLeaveDates[$eid][] = $dt->format('Y-m-d');
        }
    }
} catch (Exception $e) {
    $empLeaveDates = [];
}
// ────────────────────────────────────────────────────

// ช่างทำผม (จากฐานข้อมูล)
$stmtTech = $pdo->prepare("SELECT id, name, role, skills FROM employees ORDER BY id");
$stmtTech->execute();
$technicians = [];
foreach($stmtTech->fetchAll(PDO::FETCH_ASSOC) as $emp) {
    $emp['nickname'] = $emp['name'];
    $technicians[] = [
        'id' => $emp['id'],
        'name' => $emp['nickname'],
        'initials' => mb_substr($emp['nickname'], 0, 1, 'UTF-8'),
        'class' => 't' . (($emp['id'] % 4) + 1),
        'role' => $emp['role'] ?: 'Stylist',
        'skills' => $emp['skills'] ?? 'ทุกรายการ'
    ];
}

// ประเภทบริการ (จากฐานข้อมูล)
$stmtSvc = $pdo->prepare("SELECT code, name, duration_min FROM services WHERE active=1");
$stmtSvc->execute();
$serviceTypes = [];
foreach($stmtSvc->fetchAll(PDO::FETCH_ASSOC) as $svc) {
    if (!isset($svc['code']) || !$svc['code']) continue;
    $c = 'var(--accent-1)';
    $serviceTypes[$svc['code']] = [
        'name' => $svc['name'],
        'duration' => (int)($svc['duration_min'] ?? 60),
        'icon' => '<span class="dot" style="background: '.$c.'; display:inline-block; width:10px; height:10px; border-radius:50%;"></span>',
        'is_promo' => false
    ];
}

// โปรโมชั่น
$stmtPromo = $pdo->prepare("SELECT id, name FROM promotions WHERE active=1");
$stmtPromo->execute();
foreach($stmtPromo->fetchAll(PDO::FETCH_ASSOC) as $promo) {
    $c = 'var(--accent-gradient)';
    $serviceTypes['P_' . $promo['id']] = [
        'name' => 'โปรโมชั่น: ' . $promo['name'],
        'duration' => 60,
        'icon' => '<i class="fas fa-star" style="font-size:10px; color:var(--accent-1); margin-right:4px;"></i>',
        'is_promo' => true
    ];
}

// ข้อมูลลูกค้าทั้งหมดสำหรับ Autocomplete
$stmtUsersList = $pdo->query("SELECT first_name, last_name, member_tier FROM users");
$allUsersData = [];
foreach($stmtUsersList->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    if ($fullName) {
        $allUsersData[] = [
            'name' => $fullName,
            'tier' => $u['member_tier'] ?? 'Member'
        ];
    }
}

// ข้อมูลจำลองคิวงาน (key = "day_hour_techId")
$bookings = [];

// สร้างข้อมูลคิวงานจากฐานข้อมูล

// โหลดคิวของวันที่เลือก
$stmt = $pdo->prepare("
    SELECT b.id, b.booking_code, b.user_id,
           u.first_name, u.last_name, u.member_tier,
           COALESCE(s.code, CONCAT('P_', p.id)) as service_code, 
           COALESCE(s.name, p.name) as service_name, 
           b.duration_min,
           e.id as emp_id, e.name as emp_name,
           TIME_FORMAT(b.start_time,'%H') as hour,
           b.status, b.notes
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN promotions p ON b.promotion_id = p.id
    LEFT JOIN employees e ON b.employee_id = e.id
    WHERE b.booking_date = ?
");
$stmt->execute([$selectedDate]);
$bookingRows = $stmt->fetchAll();

// สร้าง $mockData ในรูปแบบเดิม (day_hour_techId) จาก DB
$mockData = [];

// รวบรวม ID ช่างทั้งหมดเพื่อใช้สุ่มจัดสรร
$allTechIds = array_column($technicians, 'id');

// ติดตามว่า slot ไหนถูกจองแล้ว (hour_empId => true)
$occupiedSlots = [];

// ====== Pass 1: ประมวลผลคิวที่มีช่างกำหนดแล้ว ======
foreach ($bookingRows as $b) {
    if (!$b['emp_id']) continue;
    $hour = (int)$b['hour'];
    $hrs = max(1, ceil($b['duration_min'] / 60));
    // บันทึก slot ที่ถูกจอง (รวม multi-hour)
    for ($h = $hour; $h < $hour + $hrs; $h++) {
        $occupiedSlots[$h . '_' . $b['emp_id']] = true;
    }
    $key    = $selectedDay . '_' . $hour . '_' . $b['emp_id'];
    $subKey = ($b['status'] === 'cancelled') ? 'cancelled' : 'active';
    if (!isset($mockData[$key][$subKey])) {
        $mockData[$key][$subKey] = [
            'id'          => $b['id'],
            'user_id'     => $b['user_id'] ?? 0,
            'client'      => $b['first_name'] . ' ' . $b['last_name'],
            'client_tier' => $b['member_tier'] ?? 'Member',
            'service'     => $b['service_code'],
            'status'      => $b['status'] === 'upcoming' ? 'confirmed' : $b['status'],
            'duration'    => $hrs . ' ชม.',
            'note'        => $b['notes'] ?? '',
        ];
    }
}

// ====== Pass 2: จัดสรรช่างให้คิวที่ยังไม่มี employee_id (ใครก็ได้ที่ว่าง + ทำ service นั้นได้) ======
// สร้าง map skills ตาม employee id
$techSkillsMap = [];
foreach ($technicians as $t) {
    $techSkillsMap[$t['id']] = $t['skills'] ?? 'ทุกรายการ';
}

foreach ($bookingRows as $b) {
    if ($b['emp_id']) continue; // ข้ามถ้ามีช่างแล้ว
    if ($b['status'] === 'cancelled') continue; // ข้ามคิวยกเลิก

    $hour = (int)$b['hour'];
    $hrs  = max(1, ceil($b['duration_min'] / 60));
    $serviceName = $b['service_name'] ?? '';
    $isPromo = (strpos($b['service_code'] ?? '', 'P_') === 0); // โปรโมชันเริ่มด้วย P_

    // สุ่มลำดับช่าง
    $shuffled = $allTechIds;
    shuffle($shuffled);

    $assignedEmp = null;
    foreach ($shuffled as $tid) {
        // เช็ค skill: โปรโมชันต้องเป็นช่างที่ทำได้ "ทุกรายการ" เท่านั้น
        $empSkills = $techSkillsMap[$tid] ?? '';
        if ($isPromo) {
            $canDo = (mb_strpos($empSkills, 'ทุกรายการ') !== false);
        } else {
            $canDo = (mb_strpos($empSkills, 'ทุกรายการ') !== false) || (mb_strpos($empSkills, $serviceName) !== false);
        }
        if (!$canDo) continue;

        // เช็คทุก slot ที่ต้องใช้ (multi-hour)
        $slotFree = true;
        for ($h = $hour; $h < $hour + $hrs; $h++) {
            if (isset($occupiedSlots[$h . '_' . $tid])) {
                $slotFree = false;
                break;
            }
        }
        if ($slotFree) {
            $assignedEmp = $tid;
            break;
        }
    }

    if ($assignedEmp) {
        // อัปเดต DB ให้จำช่างที่จัดสรร
        $pdo->prepare("UPDATE bookings SET employee_id = ? WHERE id = ?")->execute([$assignedEmp, $b['id']]);

        // บันทึก slot ที่ถูกจอง
        for ($h = $hour; $h < $hour + $hrs; $h++) {
            $occupiedSlots[$h . '_' . $assignedEmp] = true;
        }

        $key = $selectedDay . '_' . $hour . '_' . $assignedEmp;
        $mockData[$key]['active'] = [
            'id'            => $b['id'],
            'user_id'       => $b['user_id'] ?? 0,
            'client'        => $b['first_name'] . ' ' . $b['last_name'],
            'client_tier'   => $b['member_tier'] ?? 'Member',
            'service'       => $b['service_code'],
            'status'        => $b['status'] === 'upcoming' ? 'confirmed' : $b['status'],
            'duration'      => $hrs . ' ชม.',
            'note'          => $b['notes'] ?? '',
            'auto_assigned' => true,
        ];
    }
}
// ใช้ข้อมูลจาก Database เท่านั้น (ไม่ fallback ไปใช้ session mock data)
// นับสถิติจาก DB โดยตรง (ถูกต้องเสมอ)
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) as total_cnt,
        SUM(CASE WHEN status IN ('upcoming','confirmed') THEN 1 ELSE 0 END) as confirmed_cnt,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as inprogress_cnt,
        SUM(CASE WHEN status = 'walkin' THEN 1 ELSE 0 END) as walkin_cnt,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_cnt
    FROM bookings
    WHERE booking_date = ? AND status != 'cancelled'
");
$stmtStats->execute([$selectedDate]);
$statsRow = $stmtStats->fetch();
$totalCount     = (int)($statsRow['total_cnt']     ?? 0);
$confirmedCount = (int)($statsRow['confirmed_cnt'] ?? 0);
$inProgressCount = (int)($statsRow['inprogress_cnt'] ?? 0);
$walkinCount    = (int)($statsRow['walkin_cnt']    ?? 0);
$completedCount = (int)($statsRow['completed_cnt'] ?? 0);

// Status labels
$statusLabels = [
    'confirmed'  => ['label' => 'รอคิว',   'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:var(--success);margin-right:4px;"></span>'],
    'upcoming'   => ['label' => 'รอคิว',   'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:var(--success);margin-right:4px;"></span>'],
    'walkin'     => ['label' => 'Walk-in',    'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:var(--info);margin-right:4px;"></span>'],
    'in_progress'=> ['label' => 'กำลังให้บริการ', 'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#2563eb;margin-right:4px;"></span>'],
    'completed'  => ['label' => 'เสร็จแล้ว', 'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#a855f7;margin-right:4px;"></span>'],
    'pending'    => ['label' => 'รอยืนยัน',  'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:var(--warning);margin-right:4px;"></span>'],
    'cancelled'  => ['label' => 'ยกเลิก',    'icon' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:var(--danger);margin-right:4px;"></span>'],
];

// ช่วงเวลา 08:00 - 20:00
$timeSlots = range(10, 19);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bright Hair Studio | Admin Dashboard</title>
    <meta name="description" content="ระบบจัดการคิวงานสำหรับ Bright Hair Studio - ดูตารางนัดหมายประจำวัน">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600&display=swap"
        rel="stylesheet">
    <style>
        /* ===== DARK MODE (default) ===== */
        :root {
            --bg-primary: #0d0d0d;
            --bg-secondary: #141414;
            --bg-card: #1c1c1c;
            --bg-glass: rgba(255, 159, 36, 0.08);
            --border-glass: rgba(255, 159, 36, 0.25);
            --accent-1: #ff9f24;
            --accent-2: #ffb84a;
            --accent-gradient: linear-gradient(135deg, #ff9f24 0%, #ffb84a 50%, #ff9f24 100%);
            --text-primary: #f8f4ef;
            --text-secondary: #e4e4e4;
            --text-muted: #888888;
            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.15);
            --warning: #ff9f24;
            --warning-bg: rgba(255, 159, 36, 0.15);
            --danger: #f87171;
            --danger-bg: rgba(248, 113, 113, 0.15);
            --info: #60a5fa;
            --info-bg: rgba(96, 165, 250, 0.15);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --sidebar-bg: #141414;
            --topbar-bg: rgba(20, 20, 20, 0.9);
            --logo-filter: none;
            --btn-text: #0d0d0d;
        }

        /* ===== LIGHT MODE ===== */
        @media (prefers-color-scheme: light) {
            :root {
                --bg-primary: #f5f5f5;
                --bg-secondary: #e4e4e4;
                --bg-card: #ffffff;
                --bg-glass: rgba(255, 159, 36, 0.04);
                --border-glass: rgba(255, 159, 36, 0.12);
                --accent-1: #ff9f24;
                --accent-2: #ffb54d;
                --accent-gradient: linear-gradient(135deg, #ff9f24 0%, #ffb54d 50%, #ff9f24 100%);
                --text-primary: #111111;
                --text-secondary: #333333;
                --text-muted: #777777;
                --success: #22c55e;
                --success-bg: rgba(34, 197, 94, 0.1);
                --warning: #ff9f24;
                --warning-bg: rgba(255, 159, 36, 0.1);
                --danger: #f87171;
                --danger-bg: rgba(248, 113, 113, 0.1);
                --info: #60a5fa;
                --info-bg: rgba(96, 165, 250, 0.1);
                --shadow-sm: 0 2px 8px rgba(255, 159, 36, 0.08);
                --shadow-md: 0 8px 32px rgba(255, 159, 36, 0.1);
                --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.1);
                --sidebar-bg: #ffffff;
                --topbar-bg: rgba(255, 255, 255, 0.9);
                --logo-filter: none;
                --btn-text: #ffffff;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Jost', 'Noto Sans Thai', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 20% 20%, rgba(255, 159, 36, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(255, 181, 77, 0.03) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(255, 159, 36, 0.02) 0%, transparent 70%);
            z-index: 0;
            pointer-events: none;
            animation: bgShift 20s ease-in-out infinite alternate;
        }

        @keyframes bgShift {
            0% {
                transform: translate(0, 0);
            }

            100% {
                transform: translate(-5%, -3%);
            }
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-glass);
            box-shadow: 2px 0 20px rgba(255, 159, 36, 0.05);
            z-index: 100;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
        }

        .logo-text .brand {
            font-family: 'Cormorant Garamond', serif;
            font-size: 18px;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 0.5px;
        }

        .logo-text .sub {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            padding: 12px 12px 8px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .nav-item:hover {
            background: rgba(255, 159, 36, 0.06);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 60%;
            background: var(--accent-gradient);
            border-radius: 0 4px 4px 0;
        }

        .nav-item .icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .nav-item .badge {
            margin-left: auto;
            background: var(--accent-gradient);
            color: var(--btn-text);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid rgba(255, 159, 36, 0.1);
        }

        .technician-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 159, 36, 0.04);
            border-radius: var(--radius-md);
            border: 1px solid rgba(255, 159, 36, 0.1);
        }

        .technician-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: var(--btn-text);
            flex-shrink: 0;
        }

        .technician-info .name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .technician-info .role {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            position: relative;

            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 16px 32px;
            background: var(--topbar-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-glass);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-toggle {
            display: none;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.15);
            color: var(--text-primary);
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 159, 36, 0.1);
            border-color: var(--accent-1);
        }

        .page-title h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text-primary);
        }

        .page-title p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-btn {
            position: relative;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.12);
            color: var(--text-secondary);
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .topbar-btn:hover {
            background: rgba(255, 159, 36, 0.12);
            color: var(--accent-1);
            border-color: rgba(255, 159, 36, 0.3);
        }

        .topbar-btn .dot {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid #fff;
        }

        /* ===== CONTENT AREA ===== */
        .content {
            padding: 28px 32px;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.1);
            border-radius: var(--radius-lg);
            padding: 22px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .stat-card.primary::before {
            background: var(--accent-gradient);
        }

        .stat-card.green::before {
            background: linear-gradient(90deg, #4ade80, #22c55e);
        }

        .stat-card.orange::before {
            background: linear-gradient(90deg, #f97316, #ea580c);
        }

        .stat-card.blue::before {
            background: linear-gradient(90deg, #60a5fa, #3b82f6);
        }

        .stat-card.purple::before {
            background: linear-gradient(90deg, #c084fc, #a855f7);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 159, 36, 0.2);
            box-shadow: var(--shadow-md);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-card.primary .stat-icon {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .stat-card.green .stat-icon {
            background: var(--success-bg);
            color: var(--success);
        }

        .stat-card.orange .stat-icon {
            background: rgba(249, 115, 22, 0.15);
            color: #f97316;
        }

        .stat-card.blue .stat-icon {
            background: var(--info-bg);
            color: var(--info);
        }

        .stat-card.purple .stat-icon {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
        }

        .stat-change {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .stat-change.up {
            background: rgba(255, 159, 36, 0.1);
            color: var(--accent-1);
        }

        .stat-change.down {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .stat-value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -1px;
            margin- color: var(--text-primary);
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ===== SCHEDULE SECTION ===== */
        .schedule-section {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.1);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .schedule-header {
            padding: 22px 28px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .schedule-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .schedule-title h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .schedule-title .icon {
            font-size: 22px;
        }

        /* ===== CALENDAR NAVIGATION ===== */
        .calendar-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            flex: 1;
        }

        .cal-arrow {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid rgba(255, 159, 36, 0.2);
            background: rgba(255, 159, 36, 0.06);
            color: var(--accent-1);
            font-size: 22px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            line-height: 1;
        }

        .cal-arrow:hover {
            background: var(--accent-1);
            color: var(--btn-text);
            border-color: var(--accent-1);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.3);
            transform: scale(1.05);
        }

        .cal-month-label {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            min-width: 160px;
            text-align: center;
        }

        /* ===== MINI CALENDAR ===== */
        .mini-calendar-wrapper {
            padding: 16px 28px;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            background: rgba(255, 159, 36, 0.02);
        }

        .mini-calendar {
            max-width: 420px;
            margin: 0 auto;
        }

        .cal-header-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-bottom: 8px;
        }

        .cal-header-cell {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 0;
        }

        .cal-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .cal-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .cal-cell.empty,
        .cal-cell.disabled {
            cursor: default;
        }

        .cal-cell.disabled {
            opacity: 0.25;
            text-decoration: line-through;
            color: var(--text-muted);
            pointer-events: none;
        }

        .cal-cell:not(.empty):not(.disabled):hover {
            background: rgba(255, 159, 36, 0.1);
            transform: scale(1.05);
        }

        .cal-cell.today {
            background: rgba(255, 159, 36, 0.08);
            font-weight: 700;
        }

        .cal-cell.today .cal-day-num {
            color: var(--accent-1);
        }

        .cal-cell.selected {
            background: var(--accent-1);
            color: var(--btn-text);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.3);
            transform: scale(1.05);
        }

        .cal-cell.selected .cal-day-num {
            color: var(--btn-text);
        }

        .cal-cell.selected .cal-dot {
            background: var(--bg-card);
        }

        .cal-day-num {
            font-size: 13px;
            line-height: 1;
        }

        .cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--accent-1);
            margin-top: 3px;
        }

        .schedule-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex: 1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--accent-gradient);
            color: var(--btn-text);
            box-shadow: 0 4px 12px rgba(255, 159, 36, 0.25);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(255, 159, 36, 0.4);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(255, 159, 36, 0.2);
        }

        .btn-outline:hover {
            background: rgba(255, 159, 36, 0.06);
            color: var(--accent-1);
            border-color: rgba(255, 159, 36, 0.3);
        }

        /* ===== SCHEDULE TABLE ===== */
        .schedule-table-wrapper {
            overflow-x: auto;
        }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .schedule-table thead th {
            padding: 14px 20px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid rgba(255, 159, 36, 0.08);
            background: rgba(255, 159, 36, 0.03);
            white-space: nowrap;
        }

        .schedule-table thead th:first-child {
            width: 100px;
            position: sticky;
            left: 0;
            z-index: 3;
            background: var(--bg-card);
        }

        .schedule-table tbody tr {
            transition: background 0.15s ease;
        }

        .schedule-table tbody tr:hover {
            background: rgba(255, 159, 36, 0.03);
        }

        .schedule-table tbody td {
            padding: 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: top;
            height: 72px;
        }

        .time-cell {
            padding: 14px 20px !important;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            border-right: 1px solid rgba(255, 159, 36, 0.08);
            white-space: nowrap;
            position: sticky;
            left: 0;
            z-index: 2;
            background: var(--bg-secondary);
        }

        .time-cell .period {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
            display: block;
            margin-top: 2px;
        }

        .time-cell.current-time {
            color: var(--accent-1);
        }

        .time-cell.current-time::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 3px;
            height: 70%;
            transform: translateY(-50%);
            background: var(--accent-1);
            border-radius: 0 4px 4px 0;
        }

        .slot-cell {
            padding: 4px !important;
            min-width: 180px;
            height: 80px;
            border-right: 1px solid rgba(255, 159, 36, 0.05);
            vertical-align: top;
        }

        /* ===== TIER BADGES ===== */
        .tier-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 8px;
            vertical-align: middle;
            line-height: 1;
        }

        .tier-member {
            background: rgba(255, 255, 255, 0.06);
            color: #999;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .tier-silver {
            background: rgba(192, 192, 192, 0.12);
            color: #c0c0c0;
            border: 1px solid rgba(192, 192, 192, 0.25);
        }

        .tier-gold {
            background: rgba(255, 184, 74, 0.12);
            color: #ffb84a;
            border: 1px solid rgba(255, 184, 74, 0.3);
        }

        .tier-platinum {
            background: rgba(229, 228, 226, 0.12);
            color: #e5e4e2;
            border: 1px solid rgba(229, 228, 226, 0.3);
        }

        /* ===== BOOKING CARDS ===== */
        .booking-card {





            box-sizing: border-box;

            border-radius: var(--radius-sm);
            padding: 10px 12px;

            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            border-left: 3px solid;
        }

        .booking-card::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            background: rgba(255, 159, 36, 0.05);
            transition: opacity 0.2s ease;
        }

        .booking-card:hover::before {
            opacity: 1;
        }

        .booking-card:hover {
            transform: scale(1.02);
            box-shadow: var(--shadow-sm);
        }

        .booking-card.haircut {
            background: rgba(255, 159, 36, 0.08);
            border-color: var(--accent-1);
        }

        .booking-card.color {
            background: rgba(168, 85, 247, 0.08);
            border-color: #a855f7;
        }

        .booking-card.perm {
            background: rgba(236, 72, 153, 0.08);
            border-color: #ec4899;
        }

        .booking-card.treatment {
            background: rgba(34, 197, 94, 0.08);
            border-color: #22c55e;
        }

        .booking-card.wash {
            background: rgba(96, 165, 250, 0.08);
            border-color: #60a5fa;
        }

        .booking-card .client-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .booking-card .service-name {
            color: var(--text-secondary);
            font-size: 11px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .booking-card .booking-time {
            color: var(--text-muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .slot-empty {





            box-sizing: border-box;

            width: 100%;

            min- display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px dashed rgba(255, 159, 36, 0.1);
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            opacity: 0;
        }

        .slot-cell:hover .slot-empty {





            box-sizing: border-box;

            width: 100%;

            min- display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px dashed rgba(255, 159, 36, 0.1);
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            opacity: 0;
        }

        /* ===== STATUS BADGES ===== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-badge.confirmed,
        .status-badge.upcoming {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-badge.walkin {
            background: var(--info-bg);
            color: var(--info);
        }

        .status-badge.completed {
            background: rgba(168, 85, 247, 0.12);
            color: #a855f7;
        }

        .status-badge.pending {
            background: var(--warning-bg);
            color: var(--warning);
        }

        /* Fallback for any unknown status */
        .status-badge:not(.confirmed):not(.upcoming):not(.walkin):not(.completed):not(.pending):not(.cancelled-badge) {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-muted);
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(8px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-xl);
            width: 480px;
            max-width: 90vw;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 24px 28px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 159, 36, 0.1);
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 159, 36, 0.06);
            border: 1px solid rgba(255, 159, 36, 0.12);
            color: var(--text-secondary);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: rgba(248, 113, 113, 0.3);
        }

        .modal-body {
            padding: 24px 28px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 11px 16px;
            background: rgba(255, 159, 36, 0.03);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus,
        .form-select:focus {
            border-color: var(--accent-1);
            box-shadow: 0 0 0 3px rgba(255, 159, 36, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238888a0' viewBox='0 0 16 16'%3E%3Cpath d='M1.5 5.5l6.5 6.5 6.5-6.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }

        .form-select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .modal-footer {
            padding: 16px 28px 24px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-lg {
            padding: 12px 28px;
            font-size: 14px;
        }

        /* ===== RESET BANNER ===== */
        .reset-banner {
            background: linear-gradient(135deg, rgba(255, 159, 36, 0.06), rgba(255, 181, 77, 0.04));
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-lg);
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .reset-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .reset-info .icon {
            font-size: 22px;
        }

        .reset-info .text strong {
            font-size: 14px;
            color: var(--accent-1);
        }

        .reset-info .text p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .btn-reset {
            background: rgba(248, 113, 113, 0.08);
            color: var(--danger);
            border: 1px solid rgba(248, 113, 113, 0.2);
            white-space: nowrap;
        }

        .btn-reset:hover {
            background: rgba(248, 113, 113, 0.15);
            border-color: rgba(248, 113, 113, 0.4);
        }

        /* ===== TOAST ===== */
        .toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 300;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: var(--bg-card);
            border: 1px solid rgba(255, 159, 36, 0.15);
            border-radius: var(--radius-md);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: var(--shadow-md);
            animation: toastIn 0.3s ease forwards;
            min-width: 280px;
        }

        .toast.removing {
            animation: toastOut 0.3s ease forwards;
        }

        @keyframes toastIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast .toast-icon {
            font-size: 20px;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast.info .toast-icon {
            color: var(--accent-1);
        }

        .toast .toast-message {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: flex;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px 16px;
            }

            .topbar {
                padding: 12px 16px;
            }

            .schedule-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .reset-banner {
                flex-direction: column;
                align-items: flex-start;
            }

            .mini-calendar-wrapper {
                padding: 12px 16px;
            }

            .mini-calendar {
                max-width: 100%;
            }
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 159, 36, 0.2);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 159, 36, 0.35);
        }

        /* ===== TECHNICIAN COLUMN HEADERS ===== */
        .tech-header {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tech-avatar-sm {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--btn-text);
            flex-shrink: 0;
        }

        .tech-avatar-sm.t1 {
            background: linear-gradient(135deg, #ff9f24, #ffb54d);
        }

        .tech-avatar-sm.t2 {
            background: linear-gradient(135deg, #a855f7, #c084fc);
        }

        .tech-avatar-sm.t3 {
            background: linear-gradient(135deg, #ec4899, #f472b6);
        }

        .tech-avatar-sm.t4 {
            background: linear-gradient(135deg, #60a5fa, #93c5fd);
        }

        .tech-name {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            text-transform: none;
            letter-spacing: 0;
        }

        /* ===== PULSE DOT FOR LIVE ===== */
        .pulse-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.5;
                transform: scale(0.8);
            }
        }

        /* Multi-hour spanning cells */
        .slot-cell.spanned {
            position: relative;
            padding: 0 !important;
            vertical-align: top;
        }

        .slot-cell.spanned .booking-card {
            position: absolute;
            top: 4px;
            left: 4px;
            right: 4px;
            bottom: 4px;
            margin: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        /* ===== CANCELLED BOOKING CARDS ===== */
        .booking-card.cancelled-card {
            background: rgba(100, 100, 120, 0.08);
            border-left-color: rgba(156, 163, 175, 0.5);
            display: none; /* hidden until filter is toggled */
            position: relative;
            cursor: pointer;
        }
        .booking-card.cancelled-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                -45deg,
                transparent, transparent 5px,
                rgba(156,163,175,0.06) 5px, rgba(156,163,175,0.06) 10px
            );
            border-radius: var(--radius-sm);
            pointer-events: none;
        }
        .status-badge.cancelled-badge {
            background: rgba(156, 163, 175, 0.15);
            color: #9ca3af;
        }

        /* ===== SPLIT SLOT (active + cancelled side-by-side) ===== */
        .slot-cell.has-both {
            padding: 4px 5px !important;
        }

        /* ── ช่วงวันลาของช่าง ── */
        .slot-cell.on-leave-cell {
            background: rgba(248, 113, 113, 0.08) !important;
            cursor: not-allowed !important;
            border-right: 1px solid rgba(248, 113, 113, 0.15);
            position: relative;
        }
        .slot-cell.on-leave-cell::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 6px,
                rgba(248, 113, 113, 0.06) 6px,
                rgba(248, 113, 113, 0.06) 12px
            );
            pointer-events: none;
        }
        .leave-label {
            font-size: 10px;
            color: rgba(248, 113, 113, 0.7);
            text-align: center;
            padding: 6px 2px;
            letter-spacing: 0.3px;
            opacity: 0.8;
        }
        .leave-conflict-badge {
            background: rgba(248, 113, 113, 0.15);
            border: 1px solid rgba(248, 113, 113, 0.4);
            border-radius: 4px;
            padding: 6px 8px;
            font-size: 10px;
            color: #f87171;
            line-height: 1.4;
            position: relative;
            z-index: 1;
        }
        .leave-conflict-badge .conflict-title {
            font-weight: 700;
            font-size: 9px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .leave-conflict-badge .conflict-client {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 11px;
        }
        .leave-conflict-badge .conflict-svc {
            color: var(--text-muted);
            font-size: 10px;
            margin-top: 1px;
        }
        .split-slot-inner {
            display: flex;
            flex-direction: row;
            gap: 3px;
            height: 100%;
            min-height: 60px;
        }
        .split-slot-inner .booking-card {
            flex: 1;
            min-width: 0;
            font-size: 10px;
            padding: 5px 6px;
            margin: 0;
        }
        .split-slot-inner .booking-card .client-name { font-size: 11px; }
        .split-slot-inner .booking-card .service-name { font-size: 9px; }

        /* ── Drag & Drop Visual Feedback ── */
        .drag-valid {
            background: rgba(74, 222, 128, 0.04) !important;
            border-right: 2px solid rgba(74, 222, 128, 0.2) !important;
        }
        .drag-invalid {
            background: rgba(248, 113, 113, 0.06) !important;
            border-right: 2px solid rgba(248, 113, 113, 0.15) !important;
            position: relative;
        }
        .drag-invalid::before {
            content: '✕';
            position: absolute;
            top: 4px;
            right: 6px;
            font-size: 10px;
            color: rgba(248, 113, 113, 0.5);
            z-index: 2;
            pointer-events: none;
        }
        .drag-hover-ok {
            background: rgba(74, 222, 128, 0.12) !important;
            border-right: 3px solid rgba(74, 222, 128, 0.5) !important;
            box-shadow: inset 0 0 12px rgba(74, 222, 128, 0.08);
        }
        .drag-hover-bad {
            background: rgba(248, 113, 113, 0.12) !important;
            border-right: 3px solid rgba(248, 113, 113, 0.5) !important;
            box-shadow: inset 0 0 12px rgba(248, 113, 113, 0.08);
            cursor: not-allowed !important;
        }

        /* Auto-assigned badge (ระบบสุ่มช่างให้) */
        .auto-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(96, 165, 250, 0.15);
            border: 1px solid rgba(96, 165, 250, 0.4);
            color: #60a5fa;
            font-size: 8px;
            margin-left: 4px;
            vertical-align: middle;
            cursor: help;
            flex-shrink: 0;
        }

        /* Show cancelled cards when body has show-cancelled class */
        body.show-cancelled .booking-card.cancelled-card {
            display: block;
        }
        /* In has-cancelled-only cells, hide slot-empty when filter is on */
        body.show-cancelled .has-cancelled-only .slot-empty {
            display: none !important;
        }
        /* Cancel filter active button style */
        #cancelledFilterBtn.active {
            border-color: rgba(248, 113, 113, 0.5) !important;
            color: var(--danger) !important;
            background: rgba(248, 113, 113, 0.06) !important;
        }

        .tech-select-btn:hover {
            background: rgba(255, 159, 36, 0.1) !important;
            border-color: var(--accent-1) !important;
            transform: translateY(-2px);
        }

        .schedule-table th {
            scroll-margin-top: 120px;
        }
    </style>

</head>

<body>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="admin_home.php" class="logo" style="justify-content: flex-start; gap: 10px; padding: 0 4px;">
                <div class="logo-icon">
                    <img src="logo.png" alt="Bright Hair Studio">
                </div>
                <div style="display:flex; flex-direction:column; line-height:1.2;">
                    <span
                        style="font-family:'Cormorant Garamond',serif; font-size:14px; font-weight:700; color:var(--text-primary); letter-spacing:0.3px;">Bright
                        Hair Studio</span>
                    <span
                        style="font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:var(--accent-1); margin-top:2px;">ADMIN</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="admin_home.php" class="nav-item active">
                <span class="icon"><i class="fas fa-chart-bar" style="color: var(--accent-1);"></i></span>
                <span>ตารางคิว</span>
                <span class="badge"><?= $todayBookings ?></span>
            </a>
            <a href="admin_customers.php" class="nav-item">
                <span class="icon"><i class="fas fa-users" style="color: var(--accent-1);"></i></span>
                <span>ลูกค้า</span>
            </a>
            <a href="admin_services.php" class="nav-item">
                <span class="icon"><i class="fas fa-cut" style="color: var(--accent-1);"></i></span>
                <span>บริการ</span>
            </a>
            <a href="admin_employees.php" class="nav-item">
                <span class="icon"><i class="fas fa-user-tie" style="color: var(--accent-1);"></i></span>
                <span>ช่าง</span>
            </a>
            <a href="admin_reports.php" class="nav-item">
                <span class="icon"><i class="fas fa-chart-line" style="color: var(--accent-1);"></i></span>
                <span>รายงาน</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="technician-card">
                <div class="technician-avatar">A</div>
                <div class="technician-info">
                    <div class="name">Admin</div>
                    <div class="role">ผู้ดูแลระบบ</div>
                </div>
            </div>
            <a href="logout.php"
                style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; padding: 10px; border-radius: 8px; color: var(--danger); background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.2); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s ease;"
                onmouseover="this.style.background='rgba(248, 113, 113, 0.2)'"
                onmouseout="this.style.background='rgba(248, 113, 113, 0.1)'">
                <i class="fas fa-sign-out-alt"></i>
                <span>ออกจากระบบ</span>
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- TOP BAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">☰</button>
                <div class="page-title">
                    <h1><i class="fas fa-calendar-alt"
                            style="color: var(--accent-1); margin-right: 8px;"></i>ตารางคิวงานประจำวัน</h1>
                    <p><?= $days[$selectedDay]['full'] ?> — <?= date('d/m/Y', $selectedTimestamp) ?></p>
                </div>
            </div>
            <div class="topbar-right"></div>
        </header>

        <div class="content">

            <!-- STATS CARDS -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-value"><?= $totalCount ?></div>
                    <div class="stat-label">รายการทั้งหมด</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-check-circle" style="font-size: 16px;"></i></div>
                    </div>
                    <div class="stat-value"><?= $confirmedCount ?></div>
                    <div class="stat-label">รอคิว</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-cut" style="font-size: 16px;"></i></div>
                    </div>
                    <div class="stat-value"><?= $inProgressCount ?></div>
                    <div class="stat-label">กำลังทำ</div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-walking" style="font-size: 16px;"></i></div>
                    </div>
                    <div class="stat-value"><?= $walkinCount ?></div>
                    <div class="stat-label">Walk-in</div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="fas fa-scissors" style="font-size: 16px;"></i></div>
                    </div>
                    <div class="stat-value"><?= $completedCount ?></div>
                    <div class="stat-label">เสร็จแล้ว</div>
                </div>
            </div>

            <!-- SCHEDULE TABLE -->
            <div class="schedule-section">
                <div class="schedule-header">
                    <div class="schedule-title">
                        <h2>ตารางเวลา</h2>

                    </div>

                    <!-- CALENDAR NAVIGATION -->
                    <div class="calendar-nav">
                        <?php
                        $todayMonthStr   = date('Y-m'); // เดือนปัจจุบัน (เวลาไทย)
                        $selectedMonthStr = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
                        $prevMonthStr = date('Y-m', strtotime($prevMonth));
                        // ปุ่มย้อนหลัง: disable ถ้าอยู่เดือนปัจจุบันอยู่แล้ว (ไม่มีประโยชน์เพราะวันก่อนวันนี้กดไม่ได้)
                        if ($selectedMonthStr > $todayMonthStr): ?>
                            <button class="cal-arrow" onclick="navigateMonth('prev')" title="เดือนก่อนหน้า">‹</button>
                        <?php else: ?>
                            <button class="cal-arrow" disabled style="opacity: 0.3; cursor: not-allowed;" title="ไม่สามารถย้อนกลับได้">‹</button>
                        <?php endif; ?>

                        <span class="cal-month-label"><?= $thaiMonths[$selectedMonth] ?>
                            <?= $selectedYear + 543 ?></span>

                        <?php
                        $maxMonthStr = date('Y-m', strtotime('+1 month'));
                        $nextMonthStr = date('Y-m', strtotime($nextMonth));
                        if ($nextMonthStr <= $maxMonthStr): ?>
                            <button class="cal-arrow" onclick="navigateMonth('next')" title="เดือนถัดไป">›</button>
                        <?php else: ?>
                            <button class="cal-arrow" disabled style="opacity: 0.3; cursor: not-allowed;">›</button>
                        <?php endif; ?>
                    </div>

                    <div class="schedule-actions">
                        <button class="btn btn-outline" id="cancelledFilterBtn"
                            style="margin-right: 8px; border-color: rgba(156,163,175,0.3); color: var(--text-muted);"
                            onclick="toggleCancelledFilter()">
                            <i class="fas fa-eye-slash"></i> แสดงคิวยกเลิก
                        </button>
                        <button class="btn btn-outline"
                            style="margin-right: 8px; border-color: rgba(255, 159, 36, 0.4); color: var(--text-primary);"
                            onclick="document.getElementById('techFilterModal').classList.add('active')"><i
                                class="fas fa-filter"></i> เลือกช่างเปรียบเทียบ</button>
                        <button class="btn btn-primary" onclick="openAddModal()">➕ เพิ่มคิว</button>
                    </div>
                </div>

                <!-- MINI CALENDAR -->
                <div class="mini-calendar-wrapper">
                    <div class="mini-calendar">
                        <div class="cal-header-row">
                            <?php foreach ($thaiShortDays as $dayLabel): ?>
                                <div class="cal-header-cell"><?= $dayLabel ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="cal-body">
                            <?php
                            $stmtMonth = $pdo->prepare("SELECT DAY(booking_date) as d, COUNT(*) as c FROM bookings WHERE MONTH(booking_date)=? AND YEAR(booking_date)=? AND status IN ('upcoming','confirmed','walkin') AND employee_id IS NOT NULL GROUP BY DAY(booking_date)");
                            $stmtMonth->execute([$selectedMonth, $selectedYear]);
                            $monthBookings = [];
                            foreach($stmtMonth->fetchAll() as $r) {
                                $monthBookings[(int)$r['d']] = (int)$r['c'];
                            }

                            // Empty cells before first day
                            for ($i = 0; $i < $startingDayOfWeek; $i++):
                                ?>
                                <div class="cal-cell empty"></div>
                            <?php endfor; ?>

                            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                                $dateStr = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $day);
                                $isSelected = ($day === $selectedDayOfMonth && $selectedDate === $dateStr);
                                $isToday = ($dateStr === date('Y-m-d'));

                                $dayBookingCount = $monthBookings[$day] ?? 0;

                                $cellClasses = 'cal-cell';
                                if ($isSelected)
                                    $cellClasses .= ' selected';
                                if ($isToday)
                                    $cellClasses .= ' today';

                                $minDate = $todayDate; // วันนี้ (เวลาไทย) — ห้ามเลือกวันก่อนหน้า
                                $isDisabled = ($dateStr < $minDate || $dateStr > $maxDate);
                                if ($isDisabled) {
                                    $cellClasses .= ' disabled';
                                    $onClick = "";
                                } else {
                                    $onClick = "onclick=\"window.location.href='?date=" . $dateStr . "'\"";
                                }
                                ?>
                                <div class="<?= $cellClasses ?>" <?= $onClick ?>>
                                    <span class="cal-day-num"><?= $day ?></span>
                                    <?php if ($dayBookingCount > 0 && !$isDisabled): ?>
                                        <span class="cal-dot"></span>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>



                <div class="schedule-table-wrapper" id="scheduleWrapper">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>เวลา</th>
                                <?php foreach ($technicians as $tech): ?>
                                    <th id="tech-<?= $tech['id'] ?>" class="tech-col tech-col-<?= $tech['id'] ?>">
                                        <div class="tech-header">
                                            <div class="tech-avatar-sm <?= $tech['class'] ?>"><?= $tech['initials'] ?></div>
                                            <span class="tech-name"><?= $tech['name'] ?></span>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Track how many rows to skip per technician (for rowspan)
                            $skipCells = [];
                            foreach ($technicians as $tc) {
                                $skipCells[$tc['id']] = 0;
                            }

                            foreach ($timeSlots as $hour):
                                $isCurrentHour = ($hour === $currentHour && $selectedDate === date('Y-m-d'));
                                $timeLabel = sprintf('%02d:00', $hour);
                                $nextHour = sprintf('%02d:00', $hour + 1);
                                ?>
                                <tr>
                                    <td class="time-cell <?= $isCurrentHour ? 'current-time' : '' ?>">
                                        <?= $timeLabel ?>
                                        <span class="period"><?= $nextHour ?></span>
                                    </td>
                                    <?php foreach ($technicians as $tech):
                                        // If this cell is covered by a rowspan from above, skip it
                                        if ($skipCells[$tech['id']] > 0) {
                                            $skipCells[$tech['id']]--;
                                            continue;
                                        }

                                        $key          = $selectedDay . '_' . $hour . '_' . $tech['id'];
                                        $hasActive    = isset($mockData[$key]['active']);
                                        $hasCancelled = isset($mockData[$key]['cancelled']);
                                        $hasBoth      = $hasActive && $hasCancelled;
                                        $cancelledOnly = !$hasActive && $hasCancelled;

                                        // ── ตรวจว่าช่างลาในวันที่เลือกหรือไม่ ──
                                        $techIsOnLeave = isset($empLeaveDates[(int)$tech['id']])
                                            && in_array($selectedDate, $empLeaveDates[(int)$tech['id']]);

                                        // Rowspan driven by active booking only
                                        $rowspan = 1;
                                        if ($hasActive) {
                                            preg_match('/^(\d+)/', $mockData[$key]['active']['duration'], $dm);
                                            $hrs = isset($dm[1]) ? (int) $dm[1] : 1;
                                            if ($hrs > 1) {
                                                $rowspan = $hrs;
                                                $skipCells[$tech['id']] = $hrs - 1;
                                            }
                                        }

                                        $tdClass = 'slot-cell tech-col tech-col-' . $tech['id'];
                                        if ($rowspan > 1)    $tdClass .= ' spanned';
                                        if ($hasBoth)        $tdClass .= ' has-both';
                                        if ($cancelledOnly)  $tdClass .= ' has-cancelled-only';
                                        if ($techIsOnLeave)  $tdClass .= ' on-leave-cell';
                                        ?>
                                        <td class="<?= $tdClass ?>"
                                            data-tech-id="<?= $tech['id'] ?>"
                                            data-tech-skills="<?= htmlspecialchars($tech['skills']) ?>"
                                            <?= $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : '' ?>
                                            <?php if (!$techIsOnLeave): ?>
                                            ondragover="handleDragOver(event, '<?= $key ?>')" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event, '<?= $key ?>')"
                                            <?php endif; ?>>

                                            <?php if ($techIsOnLeave): ?>
                                                <?php if ($hasActive):
                                                    // มีลูกค้าจองอยู่ในวันที่ลา → แสดง warning
                                                    $bConflict = $mockData[$key]['active'];
                                                    $stConflict = isset($serviceTypes[$bConflict['service']]) ? $serviceTypes[$bConflict['service']] : ['name' => $bConflict['service']];
                                                ?>
                                                <div class="leave-conflict-badge">
                                                    <div class="conflict-title">
                                                        <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                        ช่างลาแต่มีการจอง!
                                                    </div>
                                                    <div class="conflict-client"><?= htmlspecialchars($bConflict['client']) ?></div>
                                                    <div class="conflict-svc"><?= htmlspecialchars($stConflict['name']) ?> (<?= $bConflict['duration'] ?>)</div>
                                                </div>
                                                <?php else: ?>
                                                <div class="leave-label">ลาหยุด</div>
                                                <?php endif; ?>

                                            <?php else: // ไม่ใช่วันลา — แสดงปกติ ?>

                                            <?php if ($hasBoth): ?><div class="split-slot-inner"><?php endif; ?>

                                            <?php if ($hasActive):
                                                $b  = $mockData[$key]['active'];
                                                $st = isset($serviceTypes[$b['service']]) ? $serviceTypes[$b['service']] : ['name' => $b['service'], 'icon' => ''];
                                                $sl = isset($statusLabels[$b['status']]) ? $statusLabels[$b['status']] : ['label' => ucfirst($b['status']), 'icon' => ''];
                                                $note = isset($b['note']) ? htmlspecialchars(str_replace("'", "\\'", $b['note'])) : '';
                                                $tier = isset($b['client_tier']) ? $b['client_tier'] : 'Member';
                                            ?>
                                                <div class="booking-card <?= $b['service'] ?>" draggable="true"
                                                    data-service-code="<?= htmlspecialchars($b['service']) ?>"
                                                    ondragstart="handleDragStart(event, '<?= $key ?>', '<?= htmlspecialchars($b['service']) ?>')"
                                                    onclick="viewBooking('<?= $key ?>', '<?= htmlspecialchars($b['client']) ?>', '<?= $tier ?>', '<?= $st['name'] ?>', '<?= $tech['name'] ?>', '<?= $timeLabel ?>', '<?= $b['duration'] ?>', '<?= $sl['label'] ?>', '<?= $note ?>', <?= $b['id'] ?>, '<?= $b['status'] ?>', <?= (int)($b['user_id'] ?? 0) ?>)">
                                                    <div class="client-name">
                                                        <?= htmlspecialchars($b['client']) ?>
                                                        <span class="tier-badge tier-<?= strtolower($tier) ?>"><?= htmlspecialchars($tier) ?></span>
                                                        <?php if (!empty($b['auto_assigned'])): ?>
                                                        <span class="auto-badge" title="ระบบสุ่มช่างให้อัตโนมัติ"><i class="fas fa-shuffle"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="service-name"><?= $st['name'] ?> (<?= $b['duration'] ?>)</div>
                                                    <div class="booking-time">
                                                        <span class="status-badge <?= $b['status'] ?>"><?= $sl['icon'] ?><?= $sl['label'] ?></span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($hasCancelled):
                                                $bc   = $mockData[$key]['cancelled'];
                                                $stc  = isset($serviceTypes[$bc['service']]) ? $serviceTypes[$bc['service']] : ['name' => $bc['service'], 'icon' => ''];
                                                $notec = isset($bc['note']) ? htmlspecialchars(str_replace("'", "\\'", $bc['note'])) : '';
                                                $tierc = isset($bc['client_tier']) ? $bc['client_tier'] : 'Member';
                                            ?>
                                                <div class="booking-card cancelled-card"
                                                    onclick="viewBooking('', '<?= htmlspecialchars($bc['client']) ?>', '<?= $tierc ?>', '<?= $stc['name'] ?>', '<?= $tech['name'] ?>', '<?= $timeLabel ?>', '<?= $bc['duration'] ?>', 'ยกเลิก', '<?= $notec ?>', <?= $bc['id'] ?>, 'cancelled', <?= (int)($bc['user_id'] ?? 0) ?>)">
                                                    <div class="client-name" style="text-decoration:line-through;opacity:0.65;">
                                                        <?= htmlspecialchars($bc['client']) ?>
                                                    </div>
                                                    <div class="service-name" style="opacity:0.55;"><?= $stc['name'] ?></div>
                                                    <div class="booking-time">
                                                        <span class="status-badge cancelled-badge"><i class="fas fa-ban" style="font-size:9px;margin-right:2px;"></i>ยกเลิก</span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($hasBoth): ?></div><?php endif; ?>

                                            <?php if (!$hasActive): ?>
                                                <div class="slot-empty"
                                                    onclick="openAddModalAt('<?= $timeLabel ?>', '<?= $tech['name'] ?>', <?= $tech['id'] ?>)">
                                                    ＋</div>
                                            <?php endif; ?>

                                            <?php endif; // end else (non-leave) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- ===== ADD BOOKING MODAL ===== -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h3>➕ เพิ่มคิวงาน</h3>
                <button class="modal-close" onclick="closeModal('addModal')">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">ชื่อลูกค้า</label>
                    <input type="text" class="form-input" id="clientName" placeholder="เช่น คุณสมชาย" autocomplete="off">
                    <div id="clientAutocomplete" style="display:none; position:absolute; top:calc(100% - 14px); left:0; right:0; background:var(--bg-card); border:1px solid var(--border-glass); border-radius:var(--radius-sm); z-index:1000; max-height:150px; overflow-y:auto; box-shadow:var(--shadow-md);"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">ระดับสมาชิก (Tier)</label>
                    <select class="form-select" id="clientTier">
                        <option value="Member">Member</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">บริการ</label>
                        <select class="form-select" id="serviceType" onchange="updateDurationAndTechs()">
                            <?php foreach ($serviceTypes as $key => $svc): ?>
                                <option value="<?= $key ?>"><?= isset($svc['is_promo']) && $svc['is_promo'] ? '★ ' : '● ' ?> <?= htmlspecialchars($svc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ช่าง</label>
                        <select class="form-select" id="techSelect">
                            <?php foreach ($technicians as $tech): ?>
                                <option value="<?= $tech['id'] ?>"><?= $tech['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">เวลา</label>
                        <select class="form-select" id="timeSelect" onchange="updateDurationAndTechs()">
                            <?php foreach ($timeSlots as $hour): ?>
                                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ระยะเวลา <span id="durationDateLabel" style="font-size:11px;color:var(--text-muted);font-weight:normal;margin-left:8px;"></span></label>
                        <input type="text" class="form-input" id="durationDisplay" readonly style="background:rgba(255,159,36,0.05); cursor:not-allowed; color:var(--text-secondary); border-color:transparent;">
                        <input type="hidden" id="durationSelect" value="1">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" id="statusSelect">
                        <option value="walkin" selected>🚶 Walk-in</option>
                        <option value="confirmed">🟢 รอคิว</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                    <input type="text" class="form-input" id="notes" placeholder="เช่น อยากได้ทรง Layer สั้น">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-lg" onclick="closeModal('addModal')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" onclick="addBooking()">💾 บันทึกคิว</button>
            </div>
        </div>
    </div>

    <!-- ===== VIEW BOOKING MODAL ===== -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h3>📋 รายละเอียดคิว</h3>
                <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-lg" onclick="closeModal('viewModal')">ปิด</button>
            </div>
        </div>
    </div>

    <!-- ===== TECH FILTER MODAL ===== -->
    <div class="modal-overlay" id="techFilterModal">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <h3><i class="fas fa-filter" style="color:var(--accent-1); margin-right:8px;"></i>
                    เลือกดูคิวช่างเปรียบเทียบ</h3>
                <button class="modal-close" onclick="closeModal('techFilterModal')">✕</button>
            </div>
            <div class="modal-body">
                <div
                    style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <span>เลือกช่างที่คุณต้องการให้แสดงบนตาราง (สามารถเลือกหลายคนได้)</span>
                    <button class="btn btn-outline" style="padding: 4px 8px; font-size: 11px;"
                        onclick="toggleAllTechs()">เลือก/ยกเลิกทั้งหมด</button>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto;">
                    <?php foreach ($technicians as $tech): ?>
                        <label
                            style="display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; border: 1px solid var(--border-glass); cursor: pointer; background: var(--bg-glass); transition: all 0.2s ease;"
                            onmouseover="this.style.background='rgba(255,159,36,0.1)'"
                            onmouseout="this.style.background='var(--bg-glass)'">
                            <input type="checkbox" class="tech-filter-chk" value="<?= $tech['id'] ?>" checked
                                style="width: 18px; height: 18px; accent-color: var(--accent-1);">
                            <div class="tech-avatar-sm <?= $tech['class'] ?>"
                                style="width: 28px; height: 28px; font-size: 11px;"><?= $tech['initials'] ?></div>
                            <span style="font-weight: 500; font-size: 14px;"><?= $tech['name'] ?></span>
                            <span
                                style="margin-left: auto; font-size: 11px; color: var(--text-muted); font-weight: 600;"><?= $tech['role'] ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; gap: 12px; margin-top: 20px;">
                <button class="btn btn-outline btn-lg" style="flex: 1;"
                    onclick="closeModal('techFilterModal')">ยกเลิก</button>
                <button class="btn btn-primary btn-lg" style="flex: 1;" onclick="applyTechFilter()">✔️ นำไปใช้</button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST CONTAINER ===== -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // Data from PHP for Add Queue features
        const appData = {
            users: <?= json_encode($allUsersData) ?>,
            technicians: <?= json_encode($technicians) ?>,
            serviceTypes: <?= json_encode($serviceTypes) ?>,
            occupiedSlots: <?= json_encode($occupiedSlots) ?>,
            leaveDates: <?= json_encode($empLeaveDates) ?>,
            selectedDate: '<?= $selectedDate ?>',
            selectedDateFormatted: '<?= date('d/m/Y', $selectedTimestamp) ?>'
        };

        document.addEventListener('DOMContentLoaded', () => {
            initClientAutocomplete();
            // Initialize duration and techs when opening modal, but also set defaults now
            setTimeout(updateDurationAndTechs, 100);
        });

        function initClientAutocomplete() {
            const input = document.getElementById('clientName');
            const drop = document.getElementById('clientAutocomplete');
            const tierSelect = document.getElementById('clientTier');

            input.addEventListener('input', function() {
                const val = this.value.trim().toLowerCase();
                drop.innerHTML = '';
                if (!val) {
                    drop.style.display = 'none';
                    tierSelect.disabled = false;
                    return;
                }

                const matches = appData.users.filter(u => u.name.toLowerCase().includes(val)).slice(0, 5);
                if (matches.length > 0) {
                    drop.style.display = 'block';
                    matches.forEach(m => {
                        const item = document.createElement('div');
                        item.style.padding = '10px 12px';
                        item.style.cursor = 'pointer';
                        item.style.borderBottom = '1px solid var(--border-glass)';
                        item.style.fontSize = '14px';
                        item.innerHTML = `<b>${m.name}</b> <span style="font-size:11px; color:var(--text-muted); float:right;">ระดับ: ${m.tier}</span>`;
                        
                        item.onmouseover = () => item.style.background = 'var(--bg-glass)';
                        item.onmouseout = () => item.style.background = 'transparent';
                        
                        item.onclick = () => {
                            input.value = m.name;
                            drop.style.display = 'none';
                            
                            let tierSet = false;
                            for (let i=0; i<tierSelect.options.length; i++) {
                                if (tierSelect.options[i].value.toLowerCase() === m.tier.toLowerCase()) {
                                    tierSelect.selectedIndex = i;
                                    tierSet = true;
                                    break;
                                }
                            }
                            if(!tierSet) tierSelect.value = 'Member';
                            tierSelect.disabled = true; // Lock the tier dropdown
                        };
                        drop.appendChild(item);
                    });
                } else {
                    drop.style.display = 'none';
                    tierSelect.disabled = false;
                }
            });

            document.addEventListener('click', function (e) {
                if (e.target !== input && e.target !== drop) {
                    drop.style.display = 'none';
                }
            });
            
            input.addEventListener('keydown', (e) => {
                if(e.key === 'Backspace' || e.key === 'Delete') {
                    tierSelect.disabled = false;
                }
            });
        }

        function updateDurationAndTechs() {
            const svcCode = document.getElementById('serviceType').value;
            const hour = parseInt(document.getElementById('timeSelect').value);
            const techSelect = document.getElementById('techSelect');
            
            let durHrs = 1;
            let svcName = '';
            if (svcCode && appData.serviceTypes[svcCode]) {
                const durMin = appData.serviceTypes[svcCode].duration || 60;
                durHrs = Math.max(1, Math.ceil(durMin / 60));
                document.getElementById('durationSelect').value = durHrs;
                document.getElementById('durationDisplay').value = durHrs + ' ชม.';
                document.getElementById('durationDateLabel').textContent = '(วันที่ ' + appData.selectedDateFormatted + ')';
                svcName = appData.serviceTypes[svcCode].name.replace('โปรโมชั่น: ', '').trim();
            }

            const isPromo = svcCode.startsWith('P_');
            const currentTech = techSelect.value;
            techSelect.innerHTML = ''; 
            
            let hasFoundCurrent = false;

            appData.technicians.forEach(tech => {
                const skills = tech.skills || 'ทุกรายการ';
                let canDo = false;
                if (isPromo) {
                    canDo = skills.includes('ทุกรายการ');
                } else {
                    canDo = skills.includes('ทุกรายการ') || skills.includes(svcName);
                }

                if (!canDo) return;

                let onLeave = false;
                // appData.leaveDates uses tech.id as key, values are arrays of dates
                if (appData.leaveDates[tech.id] && appData.leaveDates[tech.id].includes(appData.selectedDate)) {
                    onLeave = true;
                }

                let exceedsClosing = (hour + durHrs > 20);

                let occupied = false;
                if (!exceedsClosing) {
                    for (let h = hour; h < hour + durHrs; h++) {
                        const key = h + '_' + tech.id;
                        if (appData.occupiedSlots[key]) {
                            occupied = true;
                            break;
                        }
                    }
                }

                const opt = document.createElement('option');
                opt.value = tech.id;
                
                if (onLeave) {
                    opt.textContent = `${tech.name} (ลา)`;
                    opt.disabled = true;
                } else if (exceedsClosing) {
                    opt.textContent = `${tech.name} (เกินเวลาปิดร้าน)`;
                    opt.disabled = true;
                } else if (occupied) {
                    opt.textContent = `${tech.name} (คิวซ้อน/ไม่ว่าง)`;
                    opt.disabled = true;
                } else {
                    opt.textContent = tech.name;
                    if (tech.id == currentTech) {
                        opt.selected = true;
                        hasFoundCurrent = true;
                    }
                }
                
                techSelect.appendChild(opt);
            });
            
            if (!hasFoundCurrent && techSelect.options.length > 0) {
                for (let i=0; i<techSelect.options.length; i++) {
                    if (!techSelect.options[i].disabled) {
                        techSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        // ===== SIDEBAR TOGGLE =====
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('menuToggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')
                && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });

        // ===== CALENDAR NAVIGATION =====
        function navigateMonth(direction) {
            const url = new URL(window.location);
            if (direction === 'prev') {
                url.searchParams.set('date', '<?= $prevMonth ?>');
            } else {
                url.searchParams.set('date', '<?= $nextMonth ?>');
            }
            window.location.href = url.toString();
        }

        // ===== MODAL =====
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function openAddModalAt(time, techName, techId) {
            const hour = parseInt(time.split(':')[0]);
            document.getElementById('timeSelect').value = hour;
            document.getElementById('statusSelect').value = 'walkin';
            updateDurationAndTechs();
            setTimeout(() => {
                const ts = document.getElementById('techSelect');
                let found = false;
                for (let i=0; i<ts.options.length; i++) {
                    if (ts.options[i].value == techId && !ts.options[i].disabled) {
                        ts.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
            }, 50);
            openAddModal();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // ===== VIEW BOOKING =====
        function viewBooking(key, client, tier, service, tech, time, duration, status, note = '', bookingId = 0, rawStatus = '', userId = 0) {
            const body = document.getElementById('viewModalBody');

            let noteHtml = '';
            if (note) {
                noteHtml = `
                    <div style="padding:14px;background:rgba(245,158,11,0.05);border-radius:var(--radius-sm);border:1px solid rgba(245,158,11,0.2);">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">📝 หมายเหตุประจำคิว</div>
                        <div style="font-weight:600;color:var(--warning,#f59e0b);font-size:13px;">${note}</div>
                    </div>`;
            }

            let statusHtml = `<div style="font-weight:600;">${status}</div>`;
            if (rawStatus) {
                statusHtml = `
                <select class="form-select" style="font-weight:600; font-size:14px; padding:4px 8px; border:1px solid var(--border-glass); background:var(--bg-card); color:var(--text-primary); border-radius:4px; max-width:200px; cursor:pointer;" onchange="updateBookingStatus(${bookingId}, this.value)">
                    <option value="pending" ${rawStatus==='pending'?'selected':''}>🟡 รอยืนยัน</option>
                    <option value="confirmed" ${rawStatus==='confirmed' || rawStatus==='upcoming'?'selected':''}>🟢 รอคิว</option>
                    <option value="walkin" ${rawStatus==='walkin'?'selected':''}>🚶 Walk-in</option>
                    <option value="in_progress" ${rawStatus==='in_progress'?'selected':''}>🔵 กำลังให้บริการ</option>
                    <option value="completed" ${rawStatus==='completed'?'selected':''}>🟣 เสร็จแล้ว</option>
                    <option value="cancelled" ${rawStatus==='cancelled'?'selected':''}>🔴 ยกเลิก</option>
                </select>`;
            }

            const custHref = userId ? `admin_customers.php?user_id=${userId}` : 'admin_customers.php';
            body.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:18px;">
            <a href="${custHref}" style="text-decoration:none;color:inherit;">
                <div style="display:flex;align-items:center;gap:12px;padding:16px;background:rgba(255,159,36,0.03);border-radius:var(--radius-md);border:1px solid rgba(255,159,36,0.15);transition:all 0.2s;cursor:pointer;" onmouseover="this.style.background='rgba(255,159,36,0.08)';this.style.borderColor='var(--accent-1)';" onmouseout="this.style.background='rgba(255,159,36,0.03)';this.style.borderColor='rgba(255,159,36,0.15)';">
                    <div style="width:48px;height:48px;background:var(--accent-gradient);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;">👤</div>
                    <div style="flex:1;">
                        <div style="font-size:16px;font-weight:700;">${client} <span class="tier-badge tier-${tier.toLowerCase()}">${tier}</span></div>
                        <div style="font-size:12px;color:var(--text-muted);">ดูประวัติลูกค้า →</div>
                    </div>
                    <div style="color:var(--accent-1);font-size:14px;"><i class="fas fa-chevron-right"></i></div>
                </div>
            </a>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="padding:14px;background:var(--bg-glass);border-radius:var(--radius-sm);border:1px solid var(--border-glass);">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">💇 บริการ</div>
                    <div style="font-weight:600;">${service}</div>
                </div>
                <div style="padding:14px;background:var(--bg-glass);border-radius:var(--radius-sm);border:1px solid var(--border-glass);">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">👤 ช่าง</div>
                    <div style="font-weight:600;">${tech}</div>
                </div>
                <div style="padding:14px;background:var(--bg-glass);border-radius:var(--radius-sm);border:1px solid var(--border-glass);">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">เวลา</div>
                    <div style="font-weight:600;">${time}</div>
                </div>
                <div style="padding:14px;background:var(--bg-glass);border-radius:var(--radius-sm);border:1px solid var(--border-glass);">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">⏱️ ระยะเวลา</div>
                    <div style="font-weight:600;">${duration}</div>
                </div>
            </div>
            <div style="padding:14px;background:var(--bg-glass);border-radius:var(--radius-sm);border:1px solid var(--border-glass);">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">📌 สถานะ (อัปเดตอัตโนมัติ)</div>
                ${statusHtml}
            </div>
            ${noteHtml}
        </div>
        <div style="margin-top:18px;border-top:1px solid var(--border-glass);padding-top:16px;">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.8px;">⚙️ จัดการคิว (Admin)</div>
            <div style="display:flex;gap:10px;">
                <button class="btn btn-outline" style="flex:1;color:var(--warning);border-color:rgba(255,159,36,0.4);" onclick="cancelBooking(${bookingId})">
                    <i class="fas fa-ban"></i> ยกเลิกคิว
                </button>
                <button class="btn btn-outline" style="flex:1;color:var(--danger);border-color:rgba(248,113,113,0.4);" onclick="deleteBooking(${bookingId})">
                    <i class="fas fa-trash-alt"></i> ลบออกจากระบบ
                </button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
                <i class="fas fa-info-circle" style="color:var(--info);"></i>
                <b>ยกเลิกคิว</b> = เก็บประวัติไว้ | <b>ลบออกจากระบบ</b> = ลบถาวร ไม่สามารถกู้คืนได้
            </div>
        </div>
    `;
            document.getElementById('viewModal').classList.add('active');
        }

        // ===== ADD BOOKING (DB) =====
        function addBooking() {
            const name = document.getElementById('clientName').value.trim();
            const tier = document.getElementById('clientTier').value;
            const serviceCode = document.getElementById('serviceType').value;
            const employeeId = document.getElementById('techSelect').value;
            const hour = document.getElementById('timeSelect').value;
            const duration = document.getElementById('durationSelect').value;
            const status = document.getElementById('statusSelect').value;
            const notes = document.getElementById('notes').value.trim();
            const date = '<?= $selectedDate ?>';

            if (!name) {
                showToast('กรุณากรอกชื่อลูกค้า', 'error');
                return;
            }

            const body = 'action=add'
                + '&client_name='  + encodeURIComponent(name)
                + '&client_tier='  + encodeURIComponent(tier)
                + '&service_code=' + encodeURIComponent(serviceCode)
                + '&employee_id='  + encodeURIComponent(employeeId)
                + '&hour='         + encodeURIComponent(hour)
                + '&duration='     + encodeURIComponent(duration)
                + '&status='       + encodeURIComponent(status)
                + '&notes='        + encodeURIComponent(notes)
                + '&date='         + encodeURIComponent(date);

            fetch('admin_home.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(res => res.text())
            .then(text => {
                let data;
                try { data = JSON.parse(text); } catch(e) {
                    console.error('Server response:', text);
                    showToast('Server Error: ' + text.substring(0, 200), 'error');
                    return;
                }
                if (data.success) {
                    showToast(`เพิ่มคิวสำหรับ "${name}" สำเร็จ! (${data.booking_code}) — หน้าจะรีเฟรชใน 12 วินาที`, 'success');
                    closeModal('addModal');
                    document.getElementById('clientName').value = '';
                    document.getElementById('notes').value = '';
                    setTimeout(() => window.location.reload(), 12000); // ทิ้งระยะ 12 วินาที ป้องกัน loop
                } else {
                    showToast(data.error || 'เกิดข้อผิดพลาดในการเพิ่มคิว', 'error');
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message, 'error');
            });
        }

        // ===== TOAST =====
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = { success: '<i class="fas fa-check-circle" style="color:var(--success);"></i>', error: '<i class="fas fa-times-circle" style="color:var(--error);"></i>', info: '<i class="fas fa-info-circle" style="color:var(--info);"></i>' };

            toast.innerHTML = `
        <span class="toast-icon">${icons[type] || ''}</span>
        <span class="toast-message">${message}</span>
    `;

            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('removing');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // ===== KEYBOARD SHORTCUTS =====
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
            }
            // Ctrl+N = new booking
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openAddModal();
            }
        });

        // ===== TECH FILTER LOGIC =====
        function applyTechFilter() {
            const checkboxes = document.querySelectorAll('.tech-filter-chk');
            checkboxes.forEach(chk => {
                const cols = document.querySelectorAll('.tech-col-' + chk.value);
                cols.forEach(col => {
                    col.style.display = chk.checked ? '' : 'none';
                });
            });
            closeModal('techFilterModal');
            showToast('ปรับปรุงการแสดงผลช่างเรียบร้อยแล้ว', 'success');
        }

        // ===== DRAG AND DROP, UPDATE, CANCEL & DELETE BOOKING (DB) =====
        function updateBookingStatus(bookingId, newStatus) {
            if (!bookingId || !newStatus) return;
            fetch('admin_home.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_status&booking_id=' + encodeURIComponent(bookingId) + '&status=' + encodeURIComponent(newStatus)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('อัปเดตสถานะคิวสำเร็จ — หน้าจะรีเฟรชใน 12 วินาที', 'success');
                    setTimeout(() => window.location.reload(), 12000);
                } else {
                    showToast(data.error || 'เกิดข้อผิดพลาดในการอัปเดตสถานะ', 'error');
                }
            })
            .catch(err => showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message, 'error'));
        }

        function cancelBooking(bookingId) {
            if (!bookingId) { showToast('ไม่พบ ID ของคิวนี้', 'error'); return; }
            if (!confirm('ยืนยันการยกเลิกคิวงานนี้?\n(ข้อมูลจะถูกเก็บไว้ในประวัติ)')) return;

            fetch('admin_home.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cancel&booking_id=' + encodeURIComponent(bookingId)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeModal('viewModal');
                    showToast('ยกเลิกคิวงานเรียบร้อย — หน้าจะรีเฟรชใน 12 วินาที', 'success');
                    setTimeout(() => window.location.reload(), 12000); // ทิ้งระยะ 12 วินาที
                } else {
                    showToast(data.error || 'เกิดข้อผิดพลาดในการยกเลิก', 'error');
                }
            })
            .catch(err => showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message, 'error'));
        }

        function deleteBooking(bookingId) {
            if (!bookingId) { showToast('ไม่พบ ID ของคิวนี้', 'error'); return; }
            if (!confirm('⚠️ ลบถาวร!\nคุณต้องการลบคิวงานนี้ออกจากระบบโดยสมบูรณ์ใช่หรือไม่?\n(ไม่สามารถกู้คืนได้)')) return;

            fetch('admin_home.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&booking_id=' + encodeURIComponent(bookingId)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeModal('viewModal');
                    showToast('ลบคิวงานสำเร็จ — หน้าจะรีเฟรชใน 12 วินาที', 'success');
                    setTimeout(() => window.location.reload(), 12000); // ทิ้งระยะ 12 วินาที
                } else {
                    showToast(data.error || 'เกิดข้อผิดพลาดในการลบ', 'error');
                }
            })
            .catch(err => showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message, 'error'));
        }

        // ===== SERVICE NAME MAP (code -> name) =====
        const serviceNameMap = <?= json_encode(array_map(function($s) { return $s['name']; }, $serviceTypes), JSON_UNESCAPED_UNICODE) ?>;
        const techSkillsMap = {};
        document.querySelectorAll('td[data-tech-id]').forEach(td => {
            techSkillsMap[td.dataset.techId] = td.dataset.techSkills || '';
        });

        let draggingServiceCode = null;
        let draggingServiceName = null;

        function canTechDoService(techId, serviceCode) {
            const skills = techSkillsMap[techId] || '';
            // ถ้าทำได้ทุกรายการ = ผ่านเสมอ
            if (skills.indexOf('ทุกรายการ') !== -1) return true;
            // โปรโมชัน (P_) ต้องทำได้ทุกรายการเท่านั้น
            if (serviceCode && serviceCode.startsWith('P_')) return false;
            // เช็คชื่อ service ตรงกับ skills
            const svcName = serviceNameMap[serviceCode] || '';
            if (svcName && skills.indexOf(svcName) !== -1) return true;
            return false;
        }

        function handleDragStart(e, key, serviceCode) {
            e.dataTransfer.setData('text/plain', key);
            e.dataTransfer.effectAllowed = 'move';
            draggingServiceCode = serviceCode;
            draggingServiceName = serviceNameMap[serviceCode] || serviceCode;

            // ไฮไลท์ทุกช่องเลยว่าลากไปได้/ไม่ได้
            setTimeout(() => {
                document.querySelectorAll('td.slot-cell:not(.on-leave-cell)').forEach(td => {
                    const tid = td.dataset.techId;
                    if (!tid) return;
                    if (canTechDoService(tid, serviceCode)) {
                        td.classList.add('drag-valid');
                    } else {
                        td.classList.add('drag-invalid');
                    }
                });
            }, 0);
        }

        // Clear highlights when drag ends
        document.addEventListener('dragend', () => {
            draggingServiceCode = null;
            draggingServiceName = null;
            document.querySelectorAll('.drag-valid, .drag-invalid, .drag-hover-ok, .drag-hover-bad').forEach(el => {
                el.classList.remove('drag-valid', 'drag-invalid', 'drag-hover-ok', 'drag-hover-bad');
            });
            // หยุด auto-scroll
            if (dragScrollRAF) { cancelAnimationFrame(dragScrollRAF); dragScrollRAF = null; }
        });

        // ===== AUTO-SCROLL ขณะลาก =====
        let dragScrollRAF = null;
        const EDGE_SIZE = 80; // px จากขอบที่จะเริ่ม scroll
        const SCROLL_SPEED = 8; // px ต่อ frame

        document.addEventListener('drag', (e) => {
            if (!e.clientX && !e.clientY) return; // browser ส่ง 0,0 ตอนจบ drag
            const wrapper = document.getElementById('scheduleWrapper');
            if (!wrapper) return;

            const rect = wrapper.getBoundingClientRect();
            let scrollX = 0, scrollY = 0;

            // ขอบซ้าย-ขวา (horizontal scroll)
            if (e.clientX < rect.left + EDGE_SIZE && e.clientX > rect.left) {
                scrollX = -SCROLL_SPEED * (1 - (e.clientX - rect.left) / EDGE_SIZE);
            } else if (e.clientX > rect.right - EDGE_SIZE && e.clientX < rect.right) {
                scrollX = SCROLL_SPEED * (1 - (rect.right - e.clientX) / EDGE_SIZE);
            }

            // ขอบบน-ล่าง (vertical scroll)
            if (e.clientY < rect.top + EDGE_SIZE && e.clientY > rect.top) {
                scrollY = -SCROLL_SPEED * (1 - (e.clientY - rect.top) / EDGE_SIZE);
            } else if (e.clientY > rect.bottom - EDGE_SIZE && e.clientY < rect.bottom) {
                scrollY = SCROLL_SPEED * (1 - (rect.bottom - e.clientY) / EDGE_SIZE);
            }

            if (scrollX !== 0 || scrollY !== 0) {
                if (!dragScrollRAF) {
                    const doScroll = () => {
                        wrapper.scrollLeft += scrollX;
                        wrapper.scrollTop += scrollY;
                        dragScrollRAF = requestAnimationFrame(doScroll);
                    };
                    dragScrollRAF = requestAnimationFrame(doScroll);
                }
            } else {
                if (dragScrollRAF) { cancelAnimationFrame(dragScrollRAF); dragScrollRAF = null; }
            }
        });

        function handleDragOver(e, targetKey) {
            e.preventDefault();
            const td = e.target.closest('td.slot-cell');
            if (!td) return;
            const tid = td.dataset.techId;
            if (draggingServiceCode && tid && !canTechDoService(tid, draggingServiceCode)) {
                e.dataTransfer.dropEffect = 'none';
                td.classList.add('drag-hover-bad');
                td.classList.remove('drag-hover-ok');
            } else {
                e.dataTransfer.dropEffect = 'move';
                td.classList.add('drag-hover-ok');
                td.classList.remove('drag-hover-bad');
            }
        }

        function handleDragLeave(e) {
            const td = e.target.closest('td.slot-cell');
            if (td) {
                td.classList.remove('drag-hover-ok', 'drag-hover-bad');
            }
        }

        function handleDrop(e, newKey) {
            e.preventDefault();
            const oldKey = e.dataTransfer.getData('text/plain');
            if (!oldKey || oldKey === newKey) return;

            // เช็ค skill ก่อน drop
            const td = e.target.closest('td.slot-cell');
            const tid = td ? td.dataset.techId : null;
            if (draggingServiceCode && tid && !canTechDoService(tid, draggingServiceCode)) {
                showToast('❌ ช่างคนนี้ไม่สามารถทำบริการ "' + draggingServiceName + '" ได้', 'error');
                return;
            }

            if (e.target.closest('.booking-card:not(.cancelled-card)')) {
                showToast('ช่องเวลานี้มีคิวงานอยู่แล้ว ไม่สามารถย้ายไปซ้อนได้', 'error');
                return;
            }

            fetch('admin_home.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=move&old_key=' + encodeURIComponent(oldKey) + '&new_key=' + encodeURIComponent(newKey) + '&date=<?= $selectedDate ?>'
            }).then(res => res.text()).then(text => {
                if (text === 'success') {
                    const draggedCard = document.querySelector(`[ondragstart*="'${oldKey}'"]`);
                    const newTd = e.target.closest('td.slot-cell');
                    if (draggedCard && newTd) {
                        newTd.appendChild(draggedCard);
                    }
                    showToast('✅ ย้ายคิวงานเรียบร้อย', 'success');
                    setTimeout(() => window.location.reload(), 10000);
                } else {
                    showToast('ไม่สามารถย้ายคิวได้ ช่องเวลานี้อาจมีคิวงานอยู่แล้ว', 'error');
                }
            });
        }

        function toggleAllTechs() {
            const checkboxes = document.querySelectorAll('.tech-filter-chk');
            const allChecked = Array.from(checkboxes).every(chk => chk.checked);
            checkboxes.forEach(chk => chk.checked = !allChecked);
        }

        // ===== CANCELLED QUEUE FILTER TOGGLE =====
        let showCancelled = false;
        function toggleCancelledFilter() {
            showCancelled = !showCancelled;
            document.body.classList.toggle('show-cancelled', showCancelled);
            const btn = document.getElementById('cancelledFilterBtn');
            if (showCancelled) {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fas fa-eye"></i> ซ่อนคิวยกเลิก';
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> แสดงคิวยกเลิก';
            }
        }


    </script>
    <script>
        // ===== REAL-TIME SSE =====
        let lastBookingId = 0;

        function connectSSE() {
            const es = new EventSource('events.php?lastId=' + lastBookingId);

            es.addEventListener('init', e => {
                const d = JSON.parse(e.data);
                lastBookingId = d.lastId;
            });

            es.addEventListener('newBooking', e => {
                const b = JSON.parse(e.data);
                lastBookingId = b.id;

                showToast(`📅 จองใหม่! ${b.client_name} — ${b.service_name} (${b.start_time})`, 'success');

                // Refresh ตารางเมื่อวันที่ตรงกับที่แสดงอยู่
                const today = new Date().toISOString().split('T')[0];
                if (b.booking_date === today || b.booking_date === '<?= $selectedDate ?>') {
                    setTimeout(() => window.location.reload(), 10000); // ทิ้งระยะ 10 วินาที ตามข้อกำหนด
                }
            });

            es.onerror = () => {
                es.close();
                setTimeout(connectSSE, 3000); // reconnect อัตโนมัติ
            };
        }

        connectSSE();
    </script>
</body>

</html>

</html>