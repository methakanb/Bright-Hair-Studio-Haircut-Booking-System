<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require 'db.php';
    
    $email    = trim($_POST['username']); // ใช้ช่อง username ส่ง email ได้เลย
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u && password_verify($password, $u['password'])) {
        $_SESSION['user']    = $u['email'];
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['name']    = $u['first_name'];
        header("Location: customer_home.php");
        exit();
    } else {
        $error = "Email หรือ Password ไม่ถูกต้อง";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Login - Bright Hair Studio</title>
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

    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 12px;
        margin: 10px 0;
        border: 1px solid #ccc;
        border-radius: 8px;
    }

    .remember {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 8px;
        font-size: 14px;
        margin: 10px 0;
    }

    .btn {
        width: 100%;
        padding: 12px;
        background: #ff8c00;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
        margin-top: 10px;
    }

    .btn:hover {
        background: #e67e00;
    }

    .forgot {
        margin-top: 10px;
        font-size: 13px;
        text-align: left;
    }

    .forgot a {
        color: #ff8c00;
        text-decoration: none;
    }

    .signup {
        margin-top: 15px;
        font-size: 14px;
    }

    .signup a {
        color: #ff8c00;
        font-weight: bold;
        text-decoration: none;
    }

    .error {
        color: red;
        font-size: 14px;
        margin-top: 10px;
    }
</style>
</head>

<body>

<div class="login-box">
    <img src="logo.png" alt="Logo" class="logo">

    <form method="POST">
        <input type="text" name="username" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>

        <div class="remember">
            <input type="checkbox" name="remember">
            <label>Remember me</label>
        </div>

        <button class="btn" type="submit">Login</button>

        <div class="forgot">
            <a href="forgot_password.php">Forgot password?</a>
        </div>
    </form>

    <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>

    <div class="signup">
        Don't have an account? <a href="customer_signup.php">Sign up</a>
    </div>
</div>

</body>
</html>