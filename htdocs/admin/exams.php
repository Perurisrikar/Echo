<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['do'] ?? '') === 'delete') {
        delete_exam_period((int)($_POST['id'] ?? 0));
        $msg = ['ok', 'Removed.'];
    } else {
        $ok = add_exam_period((string)($_POST['label'] ?? ''), (string)($_POST['start'] ?? ''), (string)($_POST['end'] ?? ''));
        $msg = $ok ? ['ok', 'Exam period added.'] : ['err', 'Enter a name and a valid start and end date (end not before start).'];
    }
}
$periods = list_exam_periods();
$active = active_exam_period();
?>
<?php admin_header('Exam periods', 'exams'); ?>
  <h1>Exam periods</h1>
  <p class="sub">From 14 days before an exam period starts until it ends, students see an “Exam eligibility” card on Home (eligible, or which subjects are under 75%), and the predictor tucks into a collapsed section.</p>
  <?php if ($msg): ?><div class="banner <?= $msg[0] === 'ok' ? '' : 'warn' ?>"><?= esc($msg[1]) ?></div><?php endif; ?>
  <?php if ($active): ?><div class="banner">Showing now: <strong><?= esc($active['label']) ?></strong> (<?= esc($active['start_date']) ?> → <?= esc($active['end_date']) ?>)</div><?php endif; ?>

  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <label for="label">Name</label><input id="label" name="label" maxlength="80" required placeholder="e.g. Internals 1">
      <div class="map-grid">
        <div><label for="start">Starts</label><input id="start" name="start" type="date" required></div>
        <div><label for="end">Ends</label><input id="end" name="end" type="date" required></div>
      </div>
      <button type="submit" class="matcha">Add exam period</button>
    </form>
  </div>

  <div class="card" style="padding:0 20px;">
    <?php if (!$periods): ?><p class="hint" style="padding:18px 0;margin:0;">None yet.</p><?php else: foreach ($periods as $p): ?>
      <div class="mat-row">
        <div class="mat-main"><div class="mat-title"><?= esc($p['label']) ?></div><div class="mat-meta"><?= esc($p['start_date']) ?> → <?= esc($p['end_date']) ?></div></div>
        <form method="post" class="mat-actions" data-confirm="Remove this exam period?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="small ghost" type="submit">Remove</button></form>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php admin_footer([]); ?>
