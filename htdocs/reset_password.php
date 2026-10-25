<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($token !== '' && !rate_limit('reset:' . client_ip(), 120, 600)) rate_limited_page();
$tokenHash = hash('sha256', $token);

$error = '';
$success = false;

$stmt = db()->prepare(
    'SELECT pr.*, s.roll_no FROM password_resets pr
     JOIN students s ON s.id = pr.student_id
     WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$reset = $token !== '' ? $stmt->fetch() : null;

if (!$reset) {
    $error = 'This reset link is invalid, already used, or has expired. Ask the admin to send you a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password_confirm'] ?? '');
    if (strlen($p1) < 8) {
        $error = 'Choose a password of at least 8 characters.';
    } elseif ($p1 !== $p2) {
        $error = "Passwords don't match.";
    } else {
        $hash = password_hash($p1, PASSWORD_BCRYPT);
        db()->prepare('UPDATE students SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$hash, $reset['student_id']]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([$reset['id']]);
        // Invalidate any other outstanding links for this student.
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE student_id = ? AND used_at IS NULL')
            ->execute([$reset['student_id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Set your password</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header() ?>
<main class="wrap">
  <h1 style="margin-top:14px;">Set your password</h1>
  <?php if ($reset): ?>
    <p class="sub">For roll number <strong><?= esc($reset['roll_no']) ?></strong></p>
  <?php endif; ?>

  <div class="card">
    <?php if ($success): ?>
      <p class="ok">Your password has been set. You can sign in now.</p>
      <a class="btn" href="index.php">Go to sign in</a>
    <?php elseif ($reset): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="token" value="<?= esc($token) ?>">
        <?= csrf_field() ?>
        <label for="password">New password</label>
        <input id="password" name="password" type="password" minlength="8" required>
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" name="password_confirm" type="password" minlength="8" required>
        <button type="submit">Set password</button>
        <div class="err"><?= esc($error) ?></div>
      </form>
    <?php else: ?>
      <div class="err"><?= esc($error) ?></div>
      <a class="btn ghost" href="index.php">Back to sign in</a>
    <?php endif; ?>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
