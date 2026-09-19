<?php
session_start();

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name       = trim($_POST['first_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');

    if (empty($email) || empty($password) || empty($confirm_password) || empty($first_name) || empty($last_name)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบ Email ไม่ถูกต้อง";
    } elseif ($password !== $confirm_password) {
        $error = "Password และ Confirm Password ไม่ตรงกัน";
    } elseif (strlen($password) < 6) {
        $error = "Password ต้องมีอย่างน้อย 6 ตัวอักษร";
    } else {
        require 'db.php';
        
        // เช็ค email ซ้ำ
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = "Email นี้ถูกใช้งานแล้ว";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users 
                (first_name, last_name, email, phone, password, member_tier, points, total_spend, created_at) 
                VALUES (?,?,?,?,?,?,?, ?, NOW())
            ");

            $stmt->execute([
                $first_name,
                $last_name,
                $email,
                $phone,
                $hash,
                'Bronze',   // 👈 ใส่ตรงนี้
                0,          // points
                0           // total_spend
            ]);
            header("Location: customer_login.php?registered=1");
            exit();
        } // ปิด else ของการเช็ค email ซ้ำ
    } // ปิด else ของการตรวจสอบเงื่อนไขข้อมูล (บรรทัดที่ 20)
} // ปิด if ($_SERVER["REQUEST_METHOD"] == "POST") (บรรทัดที่ 6)

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up - Bright Hair Studio</title>
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px 0;
            box-sizing: border-box;
        }

        .signup-box {
            background: white;
            padding: 40px;
            border-radius: 20px;
            width: 350px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid #eee;
        }

        .logo {
            width: 150px;
            margin-bottom: 30px;
        }

        .section-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 600;
            color: #222;
            margin: 18px 0 10px 0;
            text-align: left;
        }

        .section-label::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 18px;
            background: #ff8c00;
            border-radius: 2px;
            flex-shrink: 0;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"] {
            width: 100%;
            padding: 12px 14px;
            margin: 6px 0;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Segoe UI', sans-serif;
            color: #333;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="tel"]:focus {
            border-color: #ff8c00;
        }

        input::placeholder {
            color: #aaa;
        }

        .row-2 {
            display: flex;
            gap: 8px;
        }

        .row-2 input {
            flex: 1;
            min-width: 0;
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
            margin-top: 20px;
            transition: background 0.2s;
            letter-spacing: 0.5px;
        }

        .btn:hover {
            background: #e67e00;
        }

        .error {
            color: red;
            font-size: 14px;
            margin-top: 12px;
        }

        .login-link {
            margin-top: 16px;
            font-size: 14px;
            color: #555;
        }

        .login-link a {
            color: #ff8c00;
            font-weight: bold;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="signup-box">
    <img src="logo.png" alt="Bright Hair Studio" class="logo">

    <form method="POST" action="">

        <div class="section-label">Login Info</div>
        <input type="email" name="email" placeholder="Email"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>

        <div class="section-label">Personal Info</div>
        <div class="row-2">
            <input type="text" name="first_name" placeholder="First Name"
                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
            <input type="text" name="last_name" placeholder="Last Name"
                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
        </div>
        <input type="tel" name="phone" placeholder="Phone Number"
               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">

        <button class="btn" type="submit">Sign up</button>
    </form>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="login-link">
        Already have an account? <a href="customer_login.php">Login</a>
    </div>
</div>

</body>
</html>