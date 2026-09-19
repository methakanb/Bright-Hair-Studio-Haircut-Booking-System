<?php
// ===================================================
// Bright Hair Studio — Employee Login
// Stylist ID is the username; configure a separate password hash in .env
// ===================================================
session_start();

// ถ้า login แล้วให้ redirect ทันที
if (isset($_SESSION['employee_id'])) {
    header('Location: stylist-dashboard.php');
    exit;
}

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/env.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // --- admin login (ไม่เปลี่ยน) ---
    if (hash_equals((string) bright_env('ADMIN_USER', ''), $username) && bright_env('ADMIN_PASSWORD_HASH', '') !== '' && password_verify($password, bright_env('ADMIN_PASSWORD_HASH', ''))) {
        $_SESSION['user'] = 'admin';
        header('Location: admin_home.php');
        exit;
    }

    // --- stylist login: username = id, password = id ---
    if (ctype_digit($username) && bright_env('STYLIST_PASSWORD_HASH', '') !== '' && password_verify($password, bright_env('STYLIST_PASSWORD_HASH', ''))) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');

        if (!$conn->connect_error) {
            $stmt = $conn->prepare("SELECT id, name, role FROM employees WHERE id = ? LIMIT 1");
            $emp_id_try = (int)$username;
            $stmt->bind_param('i', $emp_id_try);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($row) {
                $_SESSION['employee_id']   = $row['id'];
                $_SESSION['employee_name'] = $row['name'];
                $_SESSION['employee_role'] = $row['role'];
                header('Location: stylist-dashboard.php');
                exit;
            } else {
                $error = 'ไม่พบรหัสช่างในระบบ';
            }
        } else {
            $error = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ';
        }
    } else {
        $error = 'Username หรือ Password ไม่ถูกต้อง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Bright Hair Studio — เข้าสู่ระบบช่าง</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet"/>
<style>
:root {
  --amber:      #ff9f24;
  --amber-deep: #e8860c;
  --amber-pale: #fff8ed;
  --ink:        #1c1a17;
  --stone:      #7a756d;
  --mist:       #b8b3ab;
  --rule:       #e8e4de;
  --paper:      #fdfcf9;
  --white:      #ffffff;
  --sage:       #4a7c6f;
  --rust:       #c0392b;
  --rust-pale:  #fdf0ee;
  --r:          10px;
  --ease:       .2s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

body {
  font-family: 'Sarabun', sans-serif;
  background: var(--paper);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.login-wrap {
  width: 100%;
  max-width: 400px;
}

.login-card {
  background: var(--white);
  border: 1px solid var(--rule);
  border-radius: 16px;
  padding: 40px 36px 36px;
  box-shadow: 0 12px 40px rgba(0,0,0,.08);
  animation: fadeUp .3s ease;
}

@keyframes fadeUp {
  from { opacity:0; transform:translateY(12px); }
  to   { opacity:1; transform:translateY(0); }
}

.logo-wrap {
  text-align: center;
  margin-bottom: 28px;
}

.logo-wrap img {
  height: 52px;
  width: auto;
  object-fit: contain;
}

.brand-name {
  font-family: 'Playfair Display', serif;
  font-size: 18px;
  color: var(--ink);
  margin-top: 8px;
  letter-spacing: -.2px;
}

.brand-sub {
  font-size: 10px;
  color: var(--mist);
  letter-spacing: 1.2px;
  text-transform: uppercase;
  margin-top: 2px;
}

.login-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--stone);
  text-align: center;
  margin-bottom: 24px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--rule);
}

.field-wrap {
  margin-bottom: 14px;
}

.field-label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .8px;
  text-transform: uppercase;
  color: var(--stone);
  margin-bottom: 6px;
}

.field-input {
  width: 100%;
  padding: 11px 14px;
  border: 1px solid var(--rule);
  border-radius: var(--r);
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  color: var(--ink);
  background: var(--paper);
  outline: none;
  transition: var(--ease);
}

.field-input:focus {
  border-color: var(--amber);
  background: var(--white);
  box-shadow: 0 0 0 3px rgba(255,159,36,.12);
}

.field-hint {
  font-size: 11px;
  color: var(--mist);
  margin-top: 5px;
}

.btn-login {
  width: 100%;
  padding: 12px;
  background: var(--amber);
  color: var(--white);
  border: none;
  border-radius: var(--r);
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  margin-top: 8px;
  transition: var(--ease);
  letter-spacing: .3px;
}

.btn-login:hover {
  background: var(--amber-deep);
  box-shadow: 0 4px 12px rgba(255,159,36,.3);
}

.error-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--rust-pale);
  border: 1px solid #f5c4c0;
  border-radius: 8px;
  padding: 10px 14px;
  margin-top: 14px;
  font-size: 13px;
  color: var(--rust);
  font-weight: 500;
}

.divider {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 20px 0 16px;
  color: var(--mist);
  font-size: 11px;
}
.divider::before, .divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--rule);
}

.info-box {
  background: var(--amber-pale);
  border: 1px solid rgba(255,159,36,.2);
  border-radius: 8px;
  padding: 12px 14px;
  font-size: 12.5px;
  color: var(--stone);
  line-height: 1.6;
}
.info-box strong {
  color: var(--amber-deep);
}
</style>
</head>
<body>

<div class="login-wrap">
  <div class="login-card">

    <div class="logo-wrap">
      <img src="logo-crop.png" alt="Bright Hair Studio"/>
      <div class="brand-sub">- Staff Portal -</div>
    </div>


    <form method="POST" autocomplete="off">
      <div class="field-wrap">
        <label class="field-label" for="username">STAFF ID</label>
        <input
          type="text"
          id="username"
          name="username"
          class="field-input"
          placeholder="ID"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          required
          autofocus
        />
      </div>

      <div class="field-wrap">
        <label class="field-label" for="password">password</label>
        <input
          type="password"
          id="password"
          name="password"
          class="field-input"
          placeholder="••••••"
          required
        />
      </div>

      <button type="submit" class="btn-login">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:6px"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        เข้าสู่ระบบ
      </button>

      <?php if ($error): ?>
      <div class="error-box">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
    </form>



  </div>
</div>

</body>
</html>