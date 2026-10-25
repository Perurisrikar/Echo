<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$me = require_cr();

$dept = trim((string)($me['dept'] ?? ''));
$sec = trim((string)($me['sec'] ?? ''));
$className = trim($dept . ' ' . $sec);
$classmates = $className !== '' ? students_by_class($dept, $sec, 'roll_no', 'asc') : [];

$flashLink = $_SESSION['flash_link'] ?? null;
$flashRoll = $_SESSION['flash_roll'] ?? null;
$flashMobile = $_SESSION['flash_mobile'] ?? null;
unset($_SESSION['flash_link'], $_SESSION['flash_roll'], $_SESSION['flash_mobile']);
$flashWhatsapp = $flashLink ? whatsapp_link_for($flashMobile, reset_link_message($flashLink)) : null;
$myAnnouncements = list_announcements_by((int)$me['id']);
$resetReqs = $className !== '' ? open_reset_requests($dept, $sec) : [];
$shortage = $className !== '' ? class_shortage_list($dept, $sec) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Class Representative</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <a class="back" href="dashboard.php">‹ Home</a>
  <h1>Your class</h1>
  <p class="sub"><?= $className !== '' ? esc($className) : 'No class on file' ?> · you can send a password
     reset/set-up link, and post an announcement, to your own class only. That's everything a CR can do here.</p>

  <div class="card">
    <h2>Post an announcement</h2>
    <p class="hint hint-tight">Shown once to each of your classmates next time they open the site, until they dismiss it.</p>
    <form method="post" action="cr_announcement.php">
      <?= csrf_field() ?>
      <textarea name="message" rows="3" maxlength="500" required placeholder="e.g. Bring your ID cards tomorrow"></textarea>
      <button type="submit">Post to your class</button>
    </form>
    <?php if ($myAnnouncements): ?>
      <div style="margin-top:14px;">
        <?php foreach ($myAnnouncements as $a): ?>
          <div class="person" style="align-items:flex-start;">
            <span class="person-text" style="max-width:none;">
              <span class="person-sub"><?= esc(date('d M, g:i A', strtotime((string)$a['created_at']))) ?></span>
              <span class="person-name" style="white-space:normal;font-weight:500;"><?= nl2br(esc((string)$a['message'])) ?></span>
            </span>
            <form method="post" action="cr_announcement.php" style="margin:0;">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="small ghost" type="submit">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($resetReqs): ?>
    <div class="card">
      <h2>Password reset requests (<?= count($resetReqs) ?>)</h2>
      <p class="hint hint-tight">These classmates asked for a link. Tap to create it, then send it.</p>
      <?php foreach ($resetReqs as $rq): ?>
        <div class="person">
          <span class="person-text"><span class="person-name"><?= esc($rq['nickname'] ?: ($rq['name'] ?: $rq['roll_no'])) ?></span>
            <span class="person-sub mono"><?= esc($rq['roll_no']) ?> · <?= esc(date('j M, g:i A', strtotime((string)$rq['created_at']))) ?></span></span>
          <form method="post" action="cr_reset_link.php" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$rq['student_id'] ?>">
            <button class="small matcha" type="submit">Create link</button></form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Below 75% in a subject</h2>
    <?php if (!$shortage): ?>
      <p class="hint hint-tight">Nobody in your class is under 75% in any subject right now.</p>
    <?php else: ?>
      <p class="hint hint-tight"><?= count($shortage) ?> classmate<?= count($shortage) === 1 ? '' : 's' ?> — tap to send a friendly reminder on WhatsApp.</p>
      <?php foreach ($shortage as $row): $st = $row['student'];
        $subs = implode(', ', array_map(fn($x) => $x['label'] . ' ' . number_format($x['pct'], 0) . '%', $row['subjects']));
        $wa = whatsapp_link_for($st['mobile'] ?? null, time_greeting() . ", " . student_real_name($st) . ". This is a gentle reminder from your class representative: your attendance is currently below 75% in " . $subs . ". Kindly check Echo and try not to miss further classes. Thank you.");
      ?>
        <div class="person" style="align-items:flex-start;">
          <?= render_avatar($st, 'sm', false) ?>
          <span class="person-text" style="max-width:none;">
            <span class="person-name"><?= esc($st['nickname'] ?: $st['roll_no']) ?> <span class="muted mono" style="font-weight:400;">· <?= esc($st['roll_no']) ?></span></span>
            <span class="person-sub" style="white-space:normal;"><?= esc($subs) ?></span>
          </span>
          <?php if ($wa): ?><a class="btn small-btn ghost" style="margin:0;width:auto;" href="<?= esc($wa) ?>" target="_blank" rel="noopener">Remind</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($shortage): ?><p class="hint" style="margin:-6px 2px 14px;"><a href="condonation.php">Open printable shortage list ›</a></p><?php endif; ?>

  <?php if ($flashLink): ?>
    <div class="banner">
      Reset link generated for <strong><?= esc($flashRoll) ?></strong> — send it to them yourself
      (shown once, expires in 24 hours):
      <div class="linkbox" id="flashLinkBox"><?= esc($flashLink) ?></div>
      <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button class="small ghost" type="button" id="copyLinkBtn">Copy link</button>
        <?php if ($flashWhatsapp): ?>
          <a class="small matcha" style="text-decoration:none;display:inline-block;padding:7px 12px;border-radius:9px;font-size:.78rem;font-weight:600;"
             href="<?= esc($flashWhatsapp) ?>" target="_blank" rel="noopener">Send via WhatsApp</a>
        <?php else: ?>
          <span class="hint" style="margin:0;">No mobile number on file — copy and send manually.</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" style="padding:0;">
    <?php if (!$classmates): ?>
      <p class="hint" style="padding:20px;margin:0;">No classmates found — your own dept/section may not be on file yet.</p>
    <?php else: foreach ($classmates as $s): ?>
      <div class="person">
        <?= render_avatar($s, 'sm', false) ?>
        <span class="person-text">
          <span class="person-name"><?= esc($s['nickname'] ?: $s['roll_no']) ?></span>
          <span class="person-sub mono"><?= esc($s['roll_no']) ?></span>
        </span>
        <?php if ((int)$s['id'] === (int)$me['id']): ?>
          <span class="muted" style="font-size:.76rem;">You</span>
        <?php else: ?>
          <form method="post" action="cr_reset_link.php" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
            <button class="small ghost" type="submit"><?= $s['password_hash'] ? 'Reset link' : 'Set-password link' ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?= render_tabbar('home', (int)$me['id']) ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
