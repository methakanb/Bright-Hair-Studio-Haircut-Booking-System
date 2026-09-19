<?php
session_start();

// --- ส่วนการเชื่อมต่อ Database ---
require_once __DIR__ . '/env.php';
$host = DB_HOST; $user = DB_USER; $pass = DB_PASS; $db = DB_NAME;

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";
$error = "";
$show_form = false;

// 1. ตรวจสอบ Token จาก URL
if (isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = $_GET['email'];

    // เช็คว่า Token ตรงกับใน DB และยังไม่หมดอายุ (token_expiry > ปัจจุบัน)
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND reset_token = ?");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $show_form = true; // Token ถูกต้อง ให้แสดงฟอร์มเปลี่ยนรหัส
    } else {
        $error = "ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาทำรายการใหม่อีกครั้ง";
    }
    $stmt->close();
} else {
    $error = "ไม่พบข้อมูลที่จำเป็นในการรีเซ็ตรหัสผ่าน";
}

// 2. เมื่อมีการกดปุ่ม Update Password
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $email = $_POST['email'];
    $token = $_POST['token'];

    if (strlen($new_password) < 6) {
        $error = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
        $show_form = true;
    } elseif ($new_password !== $confirm_password) {
        $error = "รหัสผ่านไม่ตรงกัน";
        $show_form = true;
    } else {
        // Hash รหัสผ่านใหม่เพื่อความปลอดภัย
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // อัปเดตรหัสผ่าน และล้างค่า Token ทิ้ง (เพื่อไม่ให้ใช้ซ้ำได้อีก)
        $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE email = ?");
        $update_stmt->bind_param("ss", $hashed_password, $email);
        
        if ($update_stmt->execute()) {
            $message = "เปลี่ยนรหัสผ่านสำเร็จแล้ว! <br><a href='customer_login.php' style='color:#ff8c00;'>คลิกที่นี่เพื่อเข้าสู่ระบบ</a>";
            $show_form = false;
        } else {
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            $show_form = true;
        }
        $update_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Bright Hair Studio</title>
    <style>
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: #f4f4f4; height: 100vh; display: flex; justify-content: center; align-items: center; }
        .login-box { background: white; padding: 40px; border-radius: 20px; width: 350px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #eee; }
        .logo { width: 150px; margin-bottom: 20px; }
        .page-title { font-size: 18px; font-weight: 700; color: #222; margin-bottom: 20px; }
        input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; box-sizing: border-box; outline: none; }
        input[type="password"]:focus { border-color: #ff8c00; }
        .btn { width: 100%; padding: 12px; background: #ff8c00; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .error { color: #d9534f; font-size: 14px; margin-top: 15px; padding: 10px; background: #fff5f5; border-radius: 5px; }
        .success { color: #2f855a; font-size: 14px; margin-top: 15px; padding: 10px; background: #f0fff4; border-radius: 5px; }
    </style>
</head>
<body>

<div class="login-box">
    <img src="logo.png" alt="Logo" class="logo">
    <div class="page-title">Reset Your Password</div>

    <?php if ($show_form): ?>
        <form method="POST" action="">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
            
            <button class="btn" type="submit">Update Password</button>
        </form>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="success"><?php echo $message; ?></div>
    <?php endif; ?>

    <div style="margin-top: 20px; font-size: 14px;">
        <a href="customer_login.php" style="color: #888; text-decoration: none;">← Back to Login</a>
    </div>
</div>

</body>
</html>