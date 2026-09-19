<?php
require_once __DIR__ . '/env.php';
$host = DB_HOST; $dbname = DB_NAME; $user = DB_USER; $pass = DB_PASS;

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Global Dashboard Badge: Count actual bookings for real-world Today
    $stmtGlobal = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE() AND status != 'cancelled'");
    $stmtGlobal->execute();
    $todayBookings = $stmtGlobal->fetchColumn();
} catch (PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}