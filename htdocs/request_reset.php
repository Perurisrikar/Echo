<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

$sent = false;
$roll = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit('resetreq:' . client_ip(), 15, 3600)) rate_limited_page();
    csrf_verify();
    $roll = strtoupper(trim((string)($_POST['roll_no'] ?? '')));
    if (preg_match('/^[A-Z0-9]{4,20}$/', $roll)) {
        create_reset_request($roll);
        $sent = true;   // same answer whether or not the roll exists, so nobody can probe for valid ones
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Request a reset link</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?= render_public_header() ?>
<main class="wrap">
  <h1>Forgot your password?</h1>
  <p class="sub">Enter your roll number and we'll let your class rep and the admin know. They'll send you a link to set a new password.</p>
  <div class="card">
    <?php if ($sent): ?>
      <div class="banner">If that roll number is registered, your request has been sent. You'll get a link from your CR or the admin — it can take a little while.</div>
      <a class="btn ghost" href="<?= esc(admin_whatsapp_link(time_greeting() . ', I requested a password reset link on Echo (Roll no ' . $roll . '). Could you please send it when you get a chance? Thank you.')) ?>" target="_blank" rel="noopener">Message the admin too</a>
      <a class="btn ghost" href="index.php">Back to sign in</a>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label for="roll_no">Roll number</label>
        <input id="roll_no" name="roll_no" autocapitalize="characters" required maxlength="20">
        <button type="submit">Request link</button>
      </form>
      <a class="btn ghost" href="index.php">Back to sign in</a>
    <?php endif; ?>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
