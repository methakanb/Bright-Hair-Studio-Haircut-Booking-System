<?php
session_start();
// ตั้งค่าเขตเวลาให้ตรงกับไทยเพื่อใช้เช็คเวลาหมดอายุของ Token
date_default_timezone_set('Asia/Bangkok');

// 1. นำเข้าไฟล์ PHPMailer แบบ Manual (ใช้ไฟล์ดิบ)
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. เชื่อมต่อฐานข้อมูล s58m7
require_once __DIR__ . '/env.php';
$host = DB_HOST; $user = DB_USER; $pass = DB_PASS; $db = DB_NAME;

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "กรุณากรอก Email";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบ Email ไม่ถูกต้อง";
    } else {
        // 3. ดึงข้อมูลจากตาราง users เพื่อตรวจสอบอีเมล
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // 4. สร้าง Token และกำหนดวันหมดอายุ 3 นาที
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+3 minutes'));

            // 5. บันทึก Token ลงในฐานข้อมูล
            $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, token_expiry = ? WHERE email = ?");
            $update_stmt->bind_param("sss", $token, $expiry, $email);
            
            if ($update_stmt->execute()) {
                // 6. ตั้งค่าและส่งอีเมลผ่าน Gmail SMTP
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = bright_env('SMTP_HOST', 'smtp.gmail.com');
                    $mail->SMTPAuth   = true;
                    $mail->Username = bright_env('SMTP_USER', '');
                    $mail->Password = bright_env('SMTP_PASS', '');
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom(bright_env('SMTP_FROM', bright_env('SMTP_USER', '')), 'Bright Hair Studio');
                    $mail->addAddress($email);

                    // Configure APP_URL in the environment when deploying (e.g. https://example.com/source-code).
                    // Do not hard-code the university server URL in public source code.
                    $base_url = rtrim(bright_env('APP_URL', 'http://localhost/brighthair/source-code'), '/');
                    $reset_link = $base_url . '/reset_password.php?' . http_build_query([
                        'token' => $token,
                        'email' => $email,
                    ], '', '&', PHP_QUERY_RFC3986);

                    $mail->isHTML(true);
                    $mail->Subject = 'รีเซ็ตรหัสผ่าน - Bright Hair Studio';
                    $mail->Body    = "<h3>เรียนคุณลูกค้า,</h3>
                                     <p>คุณได้รับอีเมลนี้เนื่องจากมีการขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณที่ Bright Hair Studio</p>
                                     <p>กรุณาคลิกลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ (ลิงก์นี้จะหมดอายุใน 3 นาที):</p>
                                     <p><a href='$reset_link' style='background-color: #ff8c00; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>คลิกที่นี่เพื่อตั้งรหัสผ่านใหม่</a></p>
                                     <p>หากคุณไม่ได้ทำรายการนี้ โปรดเพิกเฉยต่ออีเมลฉบับนี้</p>";

                    $mail->send();
                    $message = "ระบบได้ส่งลิงก์รีเซ็ตรหัสผ่านไปที่อีเมลของคุณแล้ว กรุณาตรวจสอบในกล่องจดหมาย (Inbox) หรือ Junk mail";
                } catch (Exception $e) {
                    $error = "ไม่สามารถส่งอีเมลได้: SMTP Error - กรุณาตรวจสอบ App Password หรือการตั้งค่า SMTP"; //
                }
            }
        } else {
            // เพื่อความปลอดภัย ใช้ข้อความเดียวกันแม้จะไม่พบเมลในฐานข้อมูล
            $message = "หากอีเมลนี้มีอยู่ในระบบ ลิงก์รีเซ็ตจะถูกส่งไปให้ในไม่ช้า";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password - Bright Hair Studio</title>
<style>
    /* CSS อ้างอิงจากไฟล์ที่คุณส่งมาเพื่อความสวยงามคงเดิม */
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background: #ffffff; height: 100vh; display: flex; justify-content: center; align-items: center; }
    .login-box { background: white; padding: 40px; border-radius: 20px; width: 350px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #eee; }
    .logo { width: 150px; margin-bottom: 20px; }
    .page-title { font-size: 18px; font-weight: 700; color: #222; margin-bottom: 8px; }
    .page-subtitle { font-size: 13px; color: #888; margin-bottom: 20px; line-height: 1.6; }
    input[type="email"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; box-sizing: border-box; outline: none; transition: border-color 0.2s; }
    input[type="email"]:focus { border-color: #ff8c00; }
    .btn { width: 100%; padding: 12px; background: #ff8c00; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: bold; cursor: pointer; margin-top: 10px; transition: background 0.2s; }
    .btn:hover { background: #e67e00; }
    .error { color: #ff4d4d; font-size: 14px; margin-top: 10px; background: #fff5f5; padding: 10px; border-radius: 5px; }
    .success { color: #28a745; font-size: 14px; margin-top: 10px; line-height: 1.6; background: #f6fff9; padding: 10px; border-radius: 5px; }
    .back-login { margin-top: 15px; font-size: 14px; }
    .back-login a { color: #ff8c00; font-weight: bold; text-decoration: none; }
</style>
</head>
<body>

<div class="login-box">
    <img src="logo.png" alt="Logo" class="logo">
    <div class="page-title">Forgot Password?</div>
    <div class="page-subtitle">กรอก Email ที่ใช้สมัครสมาชิก<br>เราจะส่งลิงก์รีเซ็ตรหัสผ่านให้คุณ</div>

    <form method="POST" action="">
        <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        <button class="btn" type="submit">Send Reset Link</button>
    </form>

    <?php if (!empty($error)): ?> <div class="error"><?php echo $error; ?></div> <?php endif; ?>
    <?php if (!empty($message)): ?> <div class="success"><?php echo $message; ?></div> <?php endif; ?>

    <div class="back-login"><a href="customer_login.php">← Back to Login</a></div>
</div>

</body>
</html>