<?php
@ob_end_clean();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);

require 'db.php';

$lastId = intval($_GET['lastId'] ?? 0);

if ($lastId === 0) {
    $row = $pdo->query("SELECT MAX(id) as m FROM bookings")->fetch();
    $lastId = $row['m'] ?? 0;
    echo "event: init\ndata: " . json_encode(['lastId' => $lastId]) . "\n\n";
    flush();
}

while (true) {
    if (connection_aborted()) break;

    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_code,
               CONCAT(u.first_name,' ',u.last_name) as client_name,
               s.name as service_name, e.name as stylist,
               b.booking_date, TIME_FORMAT(b.start_time,'%H:%i') as start_time,
               b.created_at
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.id
        JOIN services s ON b.service_id = s.id
        LEFT JOIN employees e ON b.employee_id = e.id
        WHERE b.id > ?
        ORDER BY b.id ASC
    ");
    $stmt->execute([$lastId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        echo "event: newBooking\n";
        echo "data: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        $lastId = $row['id'];
    }

    sleep(5); // polling ทุก 5 วินาที ลด load บน DB
}