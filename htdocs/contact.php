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
<title>Contact — Echo</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<?= render_public_header('contact.php') ?>
<main class="wrap doc">
  <h1>Contact</h1>
  <p class="sub">Wrong details, a bug, a privacy request — this reaches the admin directly.</p>

  <div class="card center">
    <a class="btn" href="<?= esc(admin_whatsapp_link(time_greeting() . ', I have a question about Echo. Could you please help me? Thank you.')) ?>" target="_blank" rel="noopener">
      Message on WhatsApp
    </a>
    <?php if (contact_email() !== ''): ?><p class="hint" style="margin-top:14px;">Or email <a href="mailto:<?= esc(contact_email()) ?>"><?= esc(contact_email()) ?></a></p><?php endif; ?>
  </div>
</main>
<?= render_public_footer() ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
