<?php
/**
 * get_booked_slots.php
 * คืนค่า array ของช่วงเวลาที่ช่างถูกจองแล้ว (status = upcoming / pending)
 *
 * GET params:
 *   stylist_id  — employee_id หรือ "ANY" (ถ้า ANY ให้คืน [] เสมอ)
 *   date        — YYYY-MM-DD
 *
 * Response JSON:
 * {
 *   "booked": [
 *     { "start": "10:00", "end": "11:30", "start_min": 600, "end_min": 690 },
 *     ...
 *   ]
 * }
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$stylist_id = trim($_GET['stylist_id'] ?? '');
$date       = trim($_GET['date'] ?? '');

// Validate
if (!$stylist_id || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['booked' => []]);
    exit();
}

// ถ้าเลือก "ใครก็ได้" ไม่ต้อง block เวลาใด ๆ ฝั่ง client
if ($stylist_id === 'ANY') {
    echo json_encode(['booked' => []]);
    exit();
}

require 'db.php';

/*
 * ดึงการจองของช่างคนนี้ในวันที่กำหนด
 * ที่ status ยังไม่ใช่ completed หรือ cancelled
 */
$stmt = $pdo->prepare("
    SELECT
        b.start_time,
        s.duration_min
    FROM bookings b
    JOIN services s ON b.service_id = s.id
    JOIN employees e ON b.employee_id = e.id
    WHERE e.id = ?
      AND b.booking_date = ?
      AND b.status NOT IN ('completed', 'cancelled')
");
$stmt->execute([$stylist_id, $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$booked = [];
foreach ($rows as $row) {
    // start_time อาจเป็น "HH:MM:SS" หรือ "HH:MM"
    [$hh, $mm] = explode(':', $row['start_time']);
    $startMin  = (int)$hh * 60 + (int)$mm;
    $endMin    = $startMin + (int)$row['duration_min'];

    $fmtEnd    = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);

    $booked[] = [
        'start'     => sprintf('%02d:%02d', (int)$hh, (int)$mm),
        'end'       => $fmtEnd,
        'start_min' => $startMin,
        'end_min'   => $endMin,
    ];
}

echo json_encode(['booked' => $booked]);