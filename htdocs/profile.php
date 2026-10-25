<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();
$roll = $student['roll_no'];
$photoError = $_SESSION['photo_error'] ?? null;
$saved = $_SESSION['profile_saved'] ?? false;
unset($_SESSION['photo_error'], $_SESSION['profile_saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Me</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <h1>Me</h1>
  <p class="sub">Changes save instantly. No approval needed.</p>

  <div class="card center">
    <form method="post" action="upload_photo.php" enctype="multipart/form-data" id="photoForm">
      <?= csrf_field() ?>
      <?= render_avatar($student, 'lg', true) ?>
      <div class="upload-row">
        <label class="btn ghost inline" for="photoInput">Upload photo</label>
      </div>
      <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden">
    </form>
    <p class="hint hint-tight">JPG, PNG or WEBP. You'll be able to crop it next.</p>
    <div class="err" id="photoMsg"><?= $photoError ? esc((string)$photoError) : '' ?></div>
  </div>

  <div class="card hidden" id="cropCard">
    <h2>Crop your photo</h2>
    <p class="hint hint-tight">Drag to reposition. Use the slider to zoom.</p>
    <div id="cropStage"><img id="cropImg" alt=""></div>
    <input type="range" id="zoomRange" min="100" max="300" value="100">
    <div class="row row-split">
      <button type="button" class="ghost" id="cropCancel">Cancel</button>
      <button type="button" id="cropSave">Use this photo</button>
    </div>
  </div>

  <div class="card">
    <h2>Nickname &amp; bio</h2>
    <p class="hint hint-tight">Classmates see your nickname, never your real name.</p>
    <form method="post" action="update_profile.php">
      <?= csrf_field() ?>
      <label for="nickname">Nickname</label>
      <input id="nickname" name="nickname" maxlength="40" value="<?= esc((string)($student['nickname'] ?? '')) ?>" placeholder="e.g. Kiran">
      <label for="bio">Bio</label>
      <textarea id="bio" name="bio" maxlength="280" rows="3" placeholder="A line about you…"><?= esc((string)($student['bio'] ?? '')) ?></textarea>
      <button type="submit">Save</button>
      <?php if ($saved): ?><div class="ok">Saved.</div><?php endif; ?>
    </form>
  </div>

  <?= render_push_card($student) ?>

  <a class="card link-card" href="customize.php"><span>Customize your look</span><span class="chev">›</span></a>

  <div class="card">
    <h2>Your details</h2>
    <p class="hint hint-tight">Only you (and the admin) can see these.</p>
    <label>Name</label>
    <div class="since"><?= $student['name'] ? esc((string)$student['name']) : '<span class="muted">Not on file</span>' ?></div>
    <label>Department / Section</label>
    <div class="since"><?= esc(trim(($student['dept'] ?? '') . ' · ' . ($student['sec'] ?? ''), ' ·')) ?: '<span class="muted">Not on file</span>' ?></div>
    <label>Email</label>
    <div class="since"><?= $student['email'] ? esc((string)$student['email']) : '<span class="muted">Not on file</span>' ?></div>
    <label>Mobile</label>
    <div class="since"><?= $student['mobile'] ? esc((string)$student['mobile']) : '<span class="muted">Not on file</span>' ?></div>
    <p class="hint">These come from the roster the admin uploads. If anything is wrong,
      <a class="whatsapp" href="<?= esc(admin_whatsapp_link(time_greeting() . ", some of my details on Echo need correcting (Roll no {$roll}). Could you please help me fix them? Thank you.")) ?>" target="_blank" rel="noopener">click here to message the admin →</a>
    </p>
  </div>
  <?= render_report_link($student, 'Profile') ?>
</div>

<?= render_tabbar('me', (int)$student['id']) ?>
<script src="assets/profile.js"></script>
<script src="assets/push.js" defer></script>
<script src="assets/ui.js" defer></script>
</body>
</html>
