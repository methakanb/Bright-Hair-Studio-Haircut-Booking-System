<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $date = $_POST['date'];
    $service = $_POST['service'];
    $stylist = $_POST['stylist'];
    $time = $_POST['time'];

    // ส่งไปหน้าสรุป
    header("Location: confirm.php?date=$date&service=$service&stylist=$stylist&time=$time");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Booking - Bright Hair Studio</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    body {
        margin: 0;
        font-family: 'Segoe UI', sans-serif;
        background: #f5f5f5;
    }

    .container {
        padding: 20px;
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-top: 15px;
        font-weight: bold;
    }

    input, select {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border-radius: 8px;
        border: 1px solid #ccc;
    }

    .btn {
        width: 100%;
        margin-top: 20px;
        padding: 12px;
        background: #ff8c00;
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        cursor: pointer;
    }

    .btn:hover {
        background: #e67e00;
    }
</style>
</head>

<body>

<div class="container">
    <h2>จองคิว</h2>

    <form method="POST">

        <!-- วันที่ -->
        <label>เลือกวัน:</label>
        <input type="date" name="date" required>

        <!-- บริการ -->
        <label>เลือกบริการ:</label>
        <select name="service" required>
            <option value="">-- เลือกบริการ --</option>
            <option>ตัดผม</option>
            <option>ทำสี</option>
            <option>ไฮไลท์</option>
            <option>texture</option>
        </select>

        <!-- ช่าง -->
        <label>เลือกช่าง:</label>
        <select name="stylist" required>
            <option value="">-- เลือกช่าง --</option>
            <option>ช่างเลบรอน</option>
            <option>ช่างกันต์</option>
        </select>

        <!-- เวลา -->
        <label>เลือกเวลา:</label>
        <select name="time" required>
            <option value="">-- เลือกเวลา --</option>
            <option>10:00</option>
            <option>11:00</option>
            <option>13:00</option>
            <option>15:00</option>
            <option>17:00</option>
        </select>

        <button class="btn" type="submit">ยืนยันการจอง</button>

    </form>
</div>

</body>
</html>