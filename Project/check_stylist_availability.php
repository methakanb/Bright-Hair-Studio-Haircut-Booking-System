<?php
require 'db.php'; // ไฟล์เชื่อมต่อฐานข้อมูลของคุณ
$date = $_GET['date'];

// ดึงรายชื่อช่างทั้งหมดที่ "ไม่ได้ลา" ในวันที่ระบุ และสถานะปกติเป็น "online"
$sql = "SELECT id, name, nickname, avatar FROM employees 
        WHERE status = 'online' 
        AND id NOT IN (
            SELECT employee_id FROM stylist_leaves WHERE leave_date = ?
        )";

$stmt = $pdo->prepare($sql);
$stmt->execute([$date]);
$stylists = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($stylists);