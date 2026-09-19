<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit();
}

require 'db.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$booking_code = $_POST['booking_code'] ?? '';
$new_date     = $_POST['booking_date'] ?? '';
$new_time     = $_POST['start_time'] ?? '';   // format: HH:MM  e.g. "10:00"

if (empty($booking_code) || empty($new_date) || empty($new_time)) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit();
}

// Validate date format YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode(['success' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']);
    exit();
}

// ห้ามจองวันในอดีต
if ($new_date < date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเลือกวันในอดีตได้']);
    exit();
}

// Validate time: must be exactly HH:00 and between 10:00 – 19:00
$allowed_times = ['10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00'];
if (!in_array($new_time, $allowed_times, true)) {
    echo json_encode(['success' => false, 'message' => 'เวลาที่เลือกไม่ถูกต้อง (10:00 – 19:00 เท่านั้น)']);
    exit();
}

// ตรวจสอบว่า booking นี้เป็นของ user คนนี้จริง และ status = upcoming
$stmt = $pdo->prepare("
    SELECT id, status FROM bookings
    WHERE booking_code = ? AND user_id = ?
");
$stmt->execute([$booking_code, $user_id]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบการจองนี้']);
    exit();
}

if ($booking['status'] !== 'upcoming') {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถแก้ไขได้ (สถานะไม่ใช่ upcoming)']);
    exit();
}

// อัปเดตวัน เวลา และเปลี่ยน status -> pending (รอแอดมินยืนยัน)
$update = $pdo->prepare("
    UPDATE bookings
    SET booking_date = ?,
        start_time   = ?,
        status       = 'pending'
    WHERE id = ?
");
$update->execute([$new_date, $new_time . ':00', $booking['id']]);

echo json_encode([
    'success'      => true,
    'message'      => 'ส่งคำขอแก้ไขเรียบร้อยแล้ว — รอแอดมินยืนยัน',
    'booking_date' => $new_date,
    'start_time'   => $new_time,
    'status'       => 'pending',
]);