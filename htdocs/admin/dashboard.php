<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
$admin = require_admin();

$classes = list_classes();
$totalStudents = array_sum(array_column($classes, 'count'));
$totalBelow = array_sum(array_column($classes, 'below75'));
$totalNoPass = array_sum(array_column($classes, 'nopass'));
$resetReqs = open_reset_requests();
$fresh = data_freshness();
$lastTs = $fresh['last_run'] ? strtotime(trim(str_replace('IST', '', (string)$fresh['last_run']))) : false;
$logins = recent_admin_logins(8);
$runs = recent_scrape_runs(14);
maybe_auto_backup();

/** Link to the student list for one class. */
$classUrl = function (array $c): string {
    if ($c['section'] !== '') return 'students.php?section=' . rawurlencode($c['section']);
    return 'students.php';
};

admin_header('Overview', 'overview');
?>
  <h1>Overview</h1>
  <p class="sub">Signed in as <strong><?= esc((string)$admin['username']) ?></strong>.
    <?= $lastTs ? 'Last sync ' . esc(date('D, j M · g:i A', $lastTs)) . ' IST.' : 'No sync recorded yet.' ?></p>

  <?php if (empty($classes)): ?>
    <div class="banner warn">No attendance data could be read. Check that the GitHub Action has run at least once and that the <code>data</code> folder contains the section files.</div>
  <?php elseif ($fresh['failed_all']): ?>
    <div class="banner warn">⚠ The last sync couldn't fetch: <strong><?= esc(implode(', ', $fresh['failed_all'])) ?></strong>. Those sections are showing older data. Check the GitHub Action log.</div>
  <?php endif; ?>

  <?= admin_problems_html(admin_problems($classes, $resetReqs, $fresh)) ?>

  <div class="stat-tiles">
    <a class="tile" href="students.php"><b><?= (int)$totalStudents ?></b><span>students in <?= count($classes) ?> classes</span></a>
    <a class="tile<?= $totalBelow ? ' alert' : '' ?>" href="students.php?status=low"><b><?= (int)$totalBelow ?></b><span>under 75% overall</span></a>
    <a class="tile<?= $totalNoPass ? ' amber' : '' ?>" href="students.php?status=nopass"><b><?= (int)$totalNoPass ?></b><span>haven't set a password</span></a>
    <a class="tile<?= $resetReqs ? ' amber' : '' ?>" href="students.php?status=reset"><b><?= count($resetReqs) ?></b><span>reset requests waiting</span></a>
  </div>

  <?php if ($resetReqs): ?>
  <div class="card">
    <h2>Password reset requests</h2>
    <?php foreach ($resetReqs as $rq): ?>
      <div class="person">
        <span class="person-text"><span class="person-name"><?= esc((string)($rq['name'] ?: ($rq['nickname'] ?: $rq['roll_no']))) ?></span>
          <span class="person-sub mono"><?= esc((string)$rq['roll_no']) ?> · <?= esc(trim(($rq['dept'] ?? '') . ' ' . ($rq['sec'] ?? ''))) ?> · <?= esc(date('j M, g:i A', strtotime((string)$rq['created_at']))) ?></span></span>
        <form method="post" action="reset_link.php" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$rq['student_id'] ?>"><input type="hidden" name="back" value="students.php?status=reset">
          <button class="small matcha" type="submit">Create link</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?= admin_flash_link_html() ?>

  <h2 class="section-h">Classes</h2>
  <div class="class-grid">
    <?php foreach ($classes as $c): ?>
      <a class="class-card" href="<?= esc($classUrl($c)) ?>">
        <div class="cc-name"><?= esc($c['section'] ?: ($c['sec'] ?: '(no section set)')) ?></div>
        <div class="cc-sub"><?= esc(trim(($c['dept'] ? $c['dept'] : '') . ($c['year'] ? ' · Year ' . $c['year'] : ''))) ?></div>
        <div class="cc-meta"><span><?= (int)$c['count'] ?> student<?= $c['count'] === 1 ? '' : 's' ?></span>
          <?php if ($c['below75']): ?><span class="count alert"><?= (int)$c['below75'] ?> under 75%</span><?php endif; ?></div>
        <?php if ($c['crs']): ?><div class="cc-sub">CR: <?= esc(implode(', ', $c['crs'])) ?></div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="two-col">
    <?php if ($runs): ?>
    <div class="card">
      <h2 id="sync">Sync history</h2>
      <p class="hint hint-tight">Last <?= count($runs) ?> runs. Green = every section updated, amber = some failed.</p>
      <div class="run-grid">
        <?php foreach ($runs as $r): $ok = !$r['failed']; $t = strtotime(trim(str_replace('IST', '', (string)$r['run_at']))); ?>
          <div class="run <?= $ok ? 'ok' : 'bad' ?>" title="<?= esc($ok ? 'All sections updated' : 'Failed: ' . implode(', ', $r['failed'])) ?>">
            <span><?= $t ? esc(date('j M', $t)) : '' ?></span><b><?= $t ? esc(date('g:i A', $t)) : '' ?></b>
            <small><?= (int)$r['succeeded'] ?>/<?= (int)$r['total'] ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($logins): ?>
    <div class="card">
      <h2>Recent admin sign-ins</h2>
      <p class="hint hint-tight">If you see one you don't recognise, change your admin password.</p>
      <?php foreach ($logins as $l): ?>
        <div class="login-row <?= $l['ok'] ? '' : 'bad' ?>">
          <span><?= $l['ok'] ? '✓' : '✕' ?> <?= esc((string)$l['username']) ?></span>
          <span class="muted"><?= esc(date('j M, g:i A', strtotime((string)$l['at']))) ?> · <?= esc((string)$l['ip']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php admin_footer(['../assets/admin.js']); ?>
