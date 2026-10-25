<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

if (current_student()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit('login:' . client_ip(), 300, 600)) rate_limited_page();
    csrf_verify();
    $roll = strtoupper(trim((string)($_POST['roll_no'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');

    $student = get_attendance($roll) ? ensure_student_account($roll) : null;

    if (!$student) {
        $error = 'No student found with that roll number.';
    } elseif (is_locked($student)) {
        $error = 'Too many failed attempts. Try again in a few minutes.';
    } elseif (empty($student['password_hash'])) {
        $error = 'Your account has no password set yet. Ask the admin to send you a reset link.';
    } elseif (!password_verify($pass, $student['password_hash'])) {
        register_failed_attempt('students', (int)$student['id'], (int)$student['failed_attempts']);
        $error = 'Incorrect password.';
    } else {
        clear_failed_attempts('students', (int)$student['id']);
        session_regenerate_id(true);
        $_SESSION['student_id'] = $student['id'];
        register_session((int)$student['id']);
        header('Location: dashboard.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Sign in</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header() ?>
<main class="wrap">
  <p class="tagline">Sign in with your roll number.</p>
  <div class="card">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label for="roll_no">Roll number</label>
      <input id="roll_no" name="roll_no" autocapitalize="characters" required
             value="<?= isset($_POST['roll_no']) ? esc(strtoupper($_POST['roll_no'])) : '' ?>">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>
      <button type="submit">Sign in</button>
      <div class="err"><?= esc($error) ?></div>
    </form>
  </div>
  <p class="hint">Forgotten your password? <a href="request_reset.php">Request a reset link</a> — your class rep or the admin will send it to you.</p>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
