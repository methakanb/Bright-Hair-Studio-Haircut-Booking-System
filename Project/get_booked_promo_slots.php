<?php
/**
 * get_booked_promo_slots.php
 * คืนช่วงเวลาที่ถูกจองโปรโมชั่นในวันที่กำหนด
 *
 * GET params:
 *   promo  - ชื่อโปรโมชั่น (ตรงกับ notes ใน bookings ที่ขึ้นต้นด้วย "PROMO: ")
 *   date   - YYYY-MM-DD
 *
 * Response JSON:
 *   { "booked": [ { "start_min": 660, "end_min": 825 }, ... ] }
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$promo = trim($_GET['promo'] ?? '');
$date  = trim($_GET['date']  ?? '');

if (!$promo || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['booked' => []]);
    exit();
}

require 'db.php';

$stmt = $pdo->prepare("
    SELECT b.start_time, b.duration_min
    FROM bookings b
    WHERE b.notes = ?
      AND b.booking_date = ?
      AND b.status NOT IN ('completed', 'cancelled')
");
$stmt->execute(['PROMO: ' . $promo, $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$booked = [];
foreach ($rows as $row) {
    [$hh, $mm] = explode(':', $row['start_time']);
    $startMin  = (int)$hh * 60 + (int)$mm;
    $endMin    = $startMin + (int)$row['duration_min'];
    $booked[]  = ['start_min' => $startMin, 'end_min' => $endMin];
}

echo json_encode(['booked' => $booked]);