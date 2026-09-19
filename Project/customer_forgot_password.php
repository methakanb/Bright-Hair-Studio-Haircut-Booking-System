<?php
session_start();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "กรุณากรอก Email";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบ Email ไม่ถูกต้อง";
    } else {
        // TODO: ตรวจสอบ email ในฐานข้อมูลและส่งลิงก์ reset password
        $message = "ส่งลิงก์รีเซ็ตรหัสผ่านไปที่ $email แล้ว กรุณาตรวจสอบอีเมลของคุณ";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password - Bright Hair Studio</title>
<style>
    body {
        margin: 0;
        font-family: 'Segoe UI', sans-serif;
        background: #ffffff;
        height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .login-box {
        background: white;
        padding: 40px;
        border-radius: 20px;
        width: 350px;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        border: 1px solid #eee;
    }

    .logo {
        width: 150px;
        margin-bottom: 20px;
    }

    .page-title {
        font-size: 18px;
        font-weight: 700;
        color: #222;
        margin-bottom: 8px;
    }

    .page-subtitle {
        font-size: 13px;
        color: #888;
        margin-bottom: 20px;
        line-height: 1.6;
    }

    input[type="email"] {
        width: 100%;
        padding: 12px;
        margin: 10px 0;
        border: 1px solid #ccc;
        border-radius: 8px;
        font-size: 14px;
        font-family: 'Segoe UI', sans-serif;
        color: #333;
        box-sizing: border-box;
        outline: none;
        transition: border-color 0.2s;
    }

    input[type="email"]:focus {
        border-color: #ff8c00;
    }

    input::placeholder {
        color: #aaa;
    }

    .btn {
        width: 100%;
        padding: 12px;
        background: #ff8c00;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-family: 'Segoe UI', sans-serif;
        font-weight: bold;
        cursor: pointer;
        margin-top: 10px;
        transition: background 0.2s;
        letter-spacing: 0.5px;
    }

    .btn:hover {
        background: #e67e00;
    }

    .back-login {
        margin-top: 15px;
        font-size: 14px;
        color: #555;
    }

    .back-login a {
        color: #ff8c00;
        font-weight: bold;
        text-decoration: none;
    }

    .back-login a:hover {
        text-decoration: underline;
    }

    .error {
        color: red;
        font-size: 14px;
        margin-top: 10px;
    }

    .success {
        color: #28a745;
        font-size: 14px;
        margin-top: 10px;
        line-height: 1.6;
    }
</style>
</head>

<body>

<div class="login-box">
    <img src="logo.png" alt="Logo" class="logo">

    <div class="page-title">Forgot Password?</div>
    <div class="page-subtitle">
        กรอก Email ที่ใช้สมัครสมาชิก<br>
        เราจะส่งลิงก์รีเซ็ตรหัสผ่านให้คุณ
    </div>

    <form method="POST" action="">
        <input type="email" name="email" placeholder="Email"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>

        <button class="btn" type="submit">Send Reset Link</button>
    </form>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="success"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="back-login">
        <a href="customer_login.php">← Back to Login</a>
    </div>
</div>

</body>
</html>
