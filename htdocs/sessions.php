<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();
$id = (int)$student['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'revoke_others') {
        revoke_other_sessions($id);
        $_SESSION['sess_msg'] = 'Signed out of all your other devices.';
    } elseif ($do === 'revoke') {
        $hash = (string)($_POST['hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $hash) && $hash !== current_sid_hash()) {
            revoke_session($id, $hash);
            $_SESSION['sess_msg'] = 'That device was signed out.';
        }
    }
    header('Location: sessions.php');
    exit;
}
$msg = $_SESSION['sess_msg'] ?? null;
unset($_SESSION['sess_msg']);
$mine = current_sid_hash();
$sessions = list_sessions($id);
$trackedHere = !empty($_SESSION['tracked']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Devices</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <a class="back" href="customize.php">‹ Customize</a>
  <h1>Signed-in devices</h1>
  <p class="sub">You stay signed in for 7 days on each device. If you used a shared computer or lost a phone, sign it out here.</p>
  <?php if ($msg): ?><div class="banner"><?= esc($msg) ?></div><?php endif; ?>

  <div class="card" style="padding:0 20px;">
    <?php if (!$sessions): ?>
      <p class="hint" style="padding:18px 0;margin:0;">No devices recorded yet. Sign out and back in on this device and it will appear here.</p>
    <?php else: foreach ($sessions as $s): $isMe = hash_equals($mine, (string)$s['sid_hash']); ?>
      <div class="mat-row">
        <div class="mat-main">
          <div class="mat-title"><?= esc(describe_device((string)$s['ua'])) ?><?= $isMe ? ' · this device' : '' ?></div>
          <div class="mat-meta">Last active <?= esc(date('j M, g:i A', strtotime((string)$s['last_seen']))) ?> · <?= esc($s['ip']) ?></div>
        </div>
        <?php if (!$isMe): ?>
          <form method="post" class="mat-actions" style="margin:0;">
            <?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="hash" value="<?= esc((string)$s['sid_hash']) ?>">
            <button class="small ghost" type="submit">Sign out</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if (count($sessions) > 1): ?>
    <form method="post" data-confirm="Sign out of every other device?">
      <?= csrf_field() ?><input type="hidden" name="do" value="revoke_others">
      <button type="submit" class="ghost">Sign out everywhere else</button>
    </form>
  <?php endif; ?>
</div>
<?= render_tabbar('me', $id) ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
