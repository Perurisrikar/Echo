<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();
$msg = $_SESSION['customize_msg'] ?? null;
unset($_SESSION['customize_msg']);
$myThr = (int)round(student_threshold($student));
$presets = [
    'Matcha' => ['matcha', false],
    'Ember'  => ['ochre', true],
    'Brick'  => ['brick', true],
    'Ink'    => ['ink', false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Customize</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <a class="back" href="profile.php">‹ Me</a>
  <h1>Customize</h1>
  <p class="sub">Pick a colour and an optional glow for your profile photo. Friends will see it.</p>

  <?php if ($msg): ?><div class="banner"><?= esc($msg) ?></div><?php endif; ?>
  <div class="card center"><?= render_avatar($student, 'lg', true) ?></div>

  <div class="card">
    <h2>Quick looks</h2>
    <p class="hint hint-tight">One tap to pick a ready-made style. You can fine-tune below.</p>
    <div class="preset-row">
      <?php foreach ($presets as $name => [$accentKey, $glowOn]): ?>
        <form method="post" action="update_theme.php" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="accent" value="<?= esc($accentKey) ?>">
          <?php if ($glowOn): ?><input type="hidden" name="glow" value="1"><?php endif; ?>
          <button type="submit" class="preset"><span class="preset-dot" style="background:<?= esc(THEME_ACCENTS[$accentKey]) ?>"></span><?= esc($name) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <form method="post" action="update_theme.php">
      <?= csrf_field() ?>
      <label>Accent colour</label>
      <div class="swatches" id="swatches">
        <button type="button" class="swatch-btn none <?= !$student['theme_accent'] ? 'selected' : '' ?>" data-value="" title="None"></button>
        <?php foreach (THEME_ACCENTS as $key => $hex): ?>
          <button type="button" class="swatch-btn <?= $student['theme_accent'] === $key ? 'selected' : '' ?>"
                  style="background:<?= esc($hex) ?>" data-value="<?= esc($key) ?>" title="<?= esc(ucfirst($key)) ?>"></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="accent" id="accentInput" value="<?= esc((string)($student['theme_accent'] ?? '')) ?>">

      <div class="toggle-row">
        <label for="glowCheck">Glow border</label>
        <input type="checkbox" id="glowCheck" name="glow" value="1" <?= $student['theme_glow'] ? 'checked' : '' ?>>
      </div>
      <button type="submit">Save</button>
    </form>
  </div>

  <div class="card">
    <h2>Warning level</h2>
    <p class="hint hint-tight">Echo warns you when a subject falls below this. The official eligibility line stays at 75% — set it higher if you want a safety margin (60–95).</p>
    <form method="post" action="update_threshold.php">
      <?= csrf_field() ?>
      <label for="thr">My warning level (%)</label>
      <input id="thr" name="threshold" type="number" min="<?= THRESHOLD_MIN ?>" max="<?= THRESHOLD_MAX ?>" inputmode="numeric" value="<?= $myThr ?>">
      <button type="submit">Save</button>
    </form>
  </div>

  <p class="hint" style="text-align:center;"><a href="sessions.php" style="color:var(--ash);">Manage signed-in devices ›</a></p>
</div>
<?= render_tabbar('me', (int)$student['id']) ?>
<script src="assets/customize.js"></script>
<script src="assets/ui.js" defer></script>
</body>
</html>
