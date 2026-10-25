<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

// This page only works once: as soon as one admin account exists, it locks itself.
$adminCount = (int)db()->query('SELECT COUNT(*) AS c FROM admins')->fetch()['c'];

$error = '';
$done = false;

if ($adminCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password_confirm'] ?? '');
    if ($username === '' || strlen($username) < 3) {
        $error = 'Choose a username of at least 3 characters.';
    } elseif (strlen($p1) < 10) {
        $error = 'Choose an admin password of at least 10 characters.';
    } elseif ($p1 !== $p2) {
        $error = "Passwords don't match.";
    } else {
        $hash = password_hash($p1, PASSWORD_BCRYPT);
        db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — First-time setup</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header() ?>
<main class="wrap">
  <h1 style="margin-top:14px;">First-time setup</h1>
  <div class="card">
    <?php if ($adminCount > 0): ?>
      <p class="err" style="margin-top:0;">Setup has already been completed. Delete this file (setup.php) from the server — it should not stay online.</p>
      <a class="btn ghost" href="admin/login.php">Go to admin sign in</a>
    <?php elseif ($done): ?>
      <p class="ok">Admin account created.</p>
      <p class="hint" style="margin-top:0;">Now delete <strong>setup.php</strong> from your server using File Manager or FTP — leaving it online would let anyone create an admin account if this one were ever removed.</p>
      <a class="btn" href="admin/login.php">Go to admin sign in</a>
    <?php else: ?>
      <p class="sub" style="margin-top:0;">Create the one admin account for this site. Do this once, right after deploying.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label for="username">Admin username</label>
        <input id="username" name="username" required>
        <label for="password">Admin password</label>
        <input id="password" name="password" type="password" minlength="10" required>
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" name="password_confirm" type="password" minlength="10" required>
        <button type="submit">Create admin account</button>
        <div class="err"><?= esc($error) ?></div>
      </form>
    <?php endif; ?>
  </div>
  <p class="hint">Remember: delete this file after use. It is safe to leave the code in your project folder off-server for future re-deployments, but it must not remain reachable on the live site.</p>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
