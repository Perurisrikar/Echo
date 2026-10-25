<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();

if (current_admin()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit('adminlogin:' . client_ip(), 15, 600)) rate_limited_page();
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin) {
        log_admin_login(null, $username, false);
        $error = 'Incorrect username or password.';
    } elseif (is_locked($admin)) {
        $error = 'Too many failed attempts. Try again in a few minutes.';
    } elseif (!password_verify($pass, $admin['password_hash'])) {
        register_failed_attempt('admins', (int)$admin['id'], (int)$admin['failed_attempts']);
        log_admin_login((int)$admin['id'], $username, false);
        $error = 'Incorrect username or password.';
    } else {
        clear_failed_attempts('admins', (int)$admin['id']);
        log_admin_login((int)$admin['id'], $username, true);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        header('Location: dashboard.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Admin sign in</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="brand"><?= brand_mark('../') ?>Echo</div>
  <h1 style="margin-top:14px;">Admin sign in</h1>
  <p class="sub">Manage student accounts and password resets.</p>
  <div class="card">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label for="username">Username</label>
      <input id="username" name="username" required autofocus>
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>
      <button type="submit">Sign in</button>
      <div class="err"><?= esc($error) ?></div>
    </form>
  </div>
</div>
<script src="../assets/ui.js" defer></script>
</body>
</html>
