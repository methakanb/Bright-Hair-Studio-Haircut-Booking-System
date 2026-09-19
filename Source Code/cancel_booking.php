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

if (empty($booking_code)) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสการจอง']);
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
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถยกเลิกได้ (สถานะไม่ใช่ upcoming)']);
    exit();
}

// อัปเดต status เป็น cancelled
$update = $pdo->prepare("
    UPDATE bookings SET status = 'cancelled' WHERE id = ?
");
$update->execute([$booking['id']]);

echo json_encode(['success' => true, 'message' => 'ยกเลิกการจองเรียบร้อยแล้ว']);