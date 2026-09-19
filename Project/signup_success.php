<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up Success - Bright Hair Studio</title>
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

        .success-box {
            background: white;
            padding: 50px 40px;
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

        /* Checkmark circle */
        .check-circle {
            width: 80px;
            height: 80px;
            background: #fff4e6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px auto;
        }

        .check-circle svg {
            width: 40px;
            height: 40px;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            color: #222;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 14px;
            color: #777;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .btn {
            display: inline-block;
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
            text-decoration: none;
            box-sizing: border-box;
            transition: background 0.2s;
            letter-spacing: 0.5px;
        }

        .btn:hover {
            background: #e67e00;
        }

        /* countdown text */
        .auto-redirect {
            margin-top: 14px;
            font-size: 13px;
            color: #aaa;
        }

        .auto-redirect span {
            color: #ff8c00;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="success-box">
    <img src="logo.png" alt="Bright Hair Studio" class="logo">

    <!-- Checkmark icon -->
    <div class="check-circle">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="11" stroke="#ff8c00" stroke-width="2"/>
            <path d="M7 12.5l3.5 3.5 6.5-7" stroke="#ff8c00" stroke-width="2.2"
                  stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>

    <div class="title">ลงทะเบียนสำเร็จ!</div>
    <div class="subtitle">
        บัญชีของคุณถูกสร้างเรียบร้อยแล้ว<br>
        คุณสามารถเข้าสู่ระบบได้ทันที
    </div>

    <a href="customer_login.php" class="btn">กลับไปหน้า Login</a>

    <div class="auto-redirect">
        หน้าจะเปลี่ยนอัตโนมัติใน <span id="countdown">5</span> วินาที
    </div>
</div>

<script>
    let seconds = 5;
    const el = document.getElementById('countdown');
    const timer = setInterval(() => {
        seconds--;
        el.textContent = seconds;
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = 'customer_login.php';
        }
    }, 1000);
</script>

</body>
</html>
