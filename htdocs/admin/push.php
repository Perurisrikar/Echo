<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$cap = push_capability();
$result = '';
$resultOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    if (!$cap['ok']) {
        $result = 'This server cannot send notifications: ' . implode('; ', $cap['problems']) . '.';
        $resultOk = false;
    } elseif ($do === 'rekey') {
        save_setting('cron_key', bin2hex(random_bytes(16)));
        $result = 'New link created. Update the scheduled job with it; the old link no longer works.';
    } elseif ($do === 'test') {
        $roll = strtoupper(trim((string)($_POST['roll'] ?? '')));
        $st = db()->prepare('SELECT p.* FROM push_subs p JOIN students s ON s.id = p.student_id WHERE s.roll_no = ?');
        $st->execute([$roll]);
        $rows = $st->fetchAll();
        if (!$rows) { $result = 'No device is subscribed for ' . ($roll ?: 'that roll number') . '.'; $resultOk = false; }
        else {
            $r = push_send_rows($rows, push_payload('Test from Echo', 'If you can read this, notifications work on this device.', 'dashboard.php', 'test'), 600);
            $result = 'Sent to ' . $r['sent'] . ' device(s)' . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '') . ($r['removed'] ? ', ' . $r['removed'] . ' expired and removed' : '') . ($r['errors'] ? '. ' . implode(' | ', $r['errors']) : '.');
            $resultOk = $r['sent'] > 0;
        }
    } elseif ($do === 'daily') {
        $r = push_send_daily(!empty($_POST['force']));
        $result = 'Morning heads-up: ' . $r['sent'] . ' sent, ' . $r['skipped'] . ' skipped (no classes today or already sent)' . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '') . ($r['errors'] ? '. ' . implode(' | ', $r['errors']) : '.');
        $resultOk = $r['failed'] === 0;
    }
}

// Can this server reach a push service at all? Any HTTP answer (even 404/405) means yes.
$reach = null;
if ($cap['ok']) {
    $ch = curl_init('https://fcm.googleapis.com/');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    $reach = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch)];
    curl_close($ch);
}
$counts = push_counts();
$totalStudents = (int)db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
$cronUrl = $cap['ok'] ? rtrim(app_url(), '/') . '/cron_push.php?key=' . push_cron_key() : '';

admin_header('Notifications', 'push');
?>
  <h1>Notifications</h1>
  <p class="sub">Students can turn on notifications from their home page or the Me tab. They get announcements instantly and a morning heads-up with the day's classes.</p>

  <?php if ($result !== ''): ?><div class="banner <?= $resultOk ? '' : 'warn' ?>"><?= esc($result) ?></div><?php endif; ?>

  <div class="card" style="padding:0 20px;">
    <div class="chk-row"><span class="chk <?= $cap['ok'] ? 'ok' : 'bad' ?>"><?= $cap['ok'] ? '✓' : '✕' ?></span>
      <div class="mat-main"><div class="mat-title">This server can send notifications</div><div class="mat-meta"><?= $cap['ok'] ? 'openssl and curl are available.' : esc(implode('; ', $cap['problems'])) . '. Ask your host to enable it.' ?></div></div></div>
    <?php if ($reach): $ok = $reach['code'] > 0; ?>
    <div class="chk-row"><span class="chk <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✕' ?></span>
      <div class="mat-main"><div class="mat-title">Server can reach Google's notification service</div><div class="mat-meta"><?= $ok ? 'Connected (answered ' . (int)$reach['code'] . ').' : 'Could not connect' . ($reach['err'] ? ': ' . esc($reach['err']) : '') . '. Free hosts sometimes block outgoing connections; if so, notifications cannot be sent from here.' ?></div></div></div>
    <?php endif; ?>
    <div class="chk-row"><span class="chk <?= $counts['devices'] ? 'ok' : 'warn' ?>"><?= $counts['devices'] ? '✓' : '!' ?></span>
      <div class="mat-main"><div class="mat-title"><?= (int)$counts['students'] ?> of <?= $totalStudents ?> students have notifications on</div><div class="mat-meta"><?= (int)$counts['devices'] ?> device<?= $counts['devices'] === 1 ? '' : 's' ?> in total. Tell your class to open Echo and tap “Turn on notifications”.</div></div></div>
  </div>

  <?php if ($cap['ok']): ?>
  <div class="card">
    <h2>Morning heads-up schedule</h2>
    <p class="hint hint-tight">Echo can't run by itself on a free host, so a scheduled job has to open this link once each morning. In cron-job.org, make a job that opens it at 7:30 AM India time on weekdays (a Saturday works too if it's a working day). Anyone with no classes that day is skipped automatically, and nobody is sent it twice in a day.</p>
    <label for="cronUrl">Link for the scheduled job (keep it private)</label>
    <input id="cronUrl" type="text" readonly value="<?= esc($cronUrl) ?>" data-select-all>
    <form method="post" data-confirm="Create a new link? The scheduled job must be updated with it." style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="do" value="rekey"><button type="submit" class="small ghost">Create a new link</button></form>
  </div>

  <div class="two-col">
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="test">
      <h2>Send a test</h2>
      <p class="hint hint-tight">Sends a test notification to every device a student has turned on.</p>
      <label for="roll">Roll number</label><input id="roll" name="roll" placeholder="e.g. DEMO0001" required>
      <button type="submit" class="matcha">Send test</button>
    </form>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="daily">
      <h2>Send the morning heads-up now</h2>
      <p class="hint hint-tight">Same as the scheduled job, run by hand.</p>
      <label class="check"><input type="checkbox" name="force" value="1"><span>Send again even if already sent today</span></label>
      <button type="submit" class="matcha">Send now</button>
    </form>
  </div>
  <?php endif; ?>
<?php admin_footer(); ?>
