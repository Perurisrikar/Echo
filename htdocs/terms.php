<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Terms of Use — Echo</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header('terms.php') ?>
<main class="wrap doc">
  <h1>Terms of Use</h1>
  <p class="sub">Last updated <?= esc(date('F Y')) ?>.</p>

  <div class="card">
    <h2>What this is</h2>
    <p>Echo is a personal, non-commercial project built by a student for their own
    class. It isn't a paid product and comes with no guarantees.</p>

    <h2>Attendance figures</h2>
    <p>Percentages and the class predictor are estimates pulled from a source file
    and simple maths — not an official record. Always check with your actual
    institution's records for anything that affects your standing.</p>

    <h2>Accounts</h2>
    <p>Accounts are only for students on the class roster. Keep your password to
    yourself; don't share your account or use someone else's.</p>

    <h2>Be reasonable</h2>
    <p>Don't upload anything abusive, harassing, or belonging to someone else as a
    profile photo or bio. The admin can remove content or accounts that break this.</p>

    <h2>No warranty</h2>
    <p>Provided as-is, with no guarantee it's always available, accurate, or
    error-free. It may change or shut down at any time.</p>

    <p><?= owner_credit_html() ?>. Questions — <a href="contact.php">get in touch</a>.</p>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
