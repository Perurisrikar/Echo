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
<title>Privacy Policy — Echo</title>
<meta name="description" content="What Echo stores, who can see it, and how to have it removed.">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header('privacy.php') ?>
<main class="wrap doc">
  <h1>Privacy Policy</h1>
  <p class="sub">Last updated <?= esc(date('F Y')) ?>.</p>

  <div class="card">
    <h2>What's stored</h2>
    <p>From the class roster: your roll number, name, department, section, email, and
    mobile number. From your attendance records: classes held and your attendance
    percentage. From you, if you choose to add them: a nickname, a short bio, and a
    profile photo. If you turn on notifications, your browser gives us a private address for your device so we can send them; you can turn them off any time on the Me tab, and doing so deletes it.</p>

    <h2>Who can see what</h2>
    <ul>
      <li><strong>You:</strong> everything on your own account.</li>
      <li><strong>Your class rep (CR):</strong> your roll number, name, attendance and mobile number, for your own class only —
        so they can remind classmates who are short on attendance and send set-password links.</li>
      <li><strong>The admin:</strong> everything, because the admin runs the site.</li>
      <li><strong>Other students:</strong> nothing about you. Nicknames and photos are only shown to you.</li>
      <li><strong>Nobody outside the class:</strong> the site has no public pages that
        expose student data — everything above requires signing in.</li>
    </ul>
    <p>Study material you or your CR upload is visible only to students of that class and the admin,
    and every upload, link and deletion is recorded in a log the admin can read.</p>

    <h2>What it's used for</h2>
    <p>Showing you your own attendance, predicting how skipping classes would change it, and sharing class timetables and study material. Your
    email and mobile aren't used to send anything — they exist so the admin can reach
    you if needed, and so you can double-check they're correct.</p>

    <h2>What it's not used for</h2>
    <p>No ads, no analytics or tracking scripts, and your data is never sold or shared
    with anyone outside this site.</p>

    <h2>Passwords</h2>
    <p>Stored as one-way cryptographic hashes — nobody, including the admin, can see
    or recover your actual password. Only the admin can send you a link to set a new
    one, and that link works once and expires.</p>

    <h2>Who runs this site</h2>
    <p><?= owner_credit_html() ?>.</p>

    <h2>Removal</h2>
    <p>To have your account or photo removed, <a href="contact.php">contact the
    admin</a> — this can usually be done immediately.</p>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
