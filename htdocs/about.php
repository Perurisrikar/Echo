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
<title>About — Echo</title>
<meta name="description" content="Echo is a small, student-run attendance tracker built for classmates.">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header('about.php') ?>
<main class="wrap doc">
  <h1>About Echo</h1>
  <p class="sub">A small tool, built for one class, by one of your classmates.</p>

  <div class="card">
    <p>Echo started as a simple attendance tracker and grew from there: a way to see
    your attendance percentage, project it forward before you skip a class, and check
    the term's holidays without hunting for a notice board.</p>
    <p>It's run by a student, for their own class — not by any college, university, or
    company. Nobody outside your class can sign up: accounts only exist for the roll
    numbers on the class roster, and only the admin (also a student) can issue the
    link that lets someone set a password.</p>
    <p><?= owner_credit_html() ?>.</p>
    <p>See <a href="privacy.php">Privacy Policy</a> for exactly what's stored and who
    can see it, or <a href="contact.php">get in touch</a> if anything looks wrong.</p>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
