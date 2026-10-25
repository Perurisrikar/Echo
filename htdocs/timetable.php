<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();
$roll = (string)$student['roll_no'];
$displayName = $student['nickname'] ?: ($student['name'] ?: $roll);

$section = section_of_student($student);
$att = $section ? get_attendance($roll) : null;
$subjectByCode = [];
foreach ((array)($att['subjects'] ?? []) as $sub) $subjectByCode[$sub['code']] = $sub;
$tt = $section ? get_timetable($section) : [];
$fac = $section ? get_faculty($section) : [];
$thr = student_threshold($student);

$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
$todayDt = ist_now();
$todayDate = $todayDt->format('Y-m-d');
$todayDow = (int)$todayDt->format('N');

// Today's classes — or, if there are none (weekend/holiday), the next day that has some.
$showDate = null; $showLabel = '';
if ($tt) {
    for ($i = 0; $i < 10; $i++) {
        $d = (clone $todayDt)->modify("+{$i} day")->format('Y-m-d');
        if (periods_on_date($tt, $d)) {
            $showDate = $d;
            $showLabel = $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : date('l', strtotime($d)));
            break;
        }
    }
}
$showDow = $showDate ? (int)date('N', strtotime($showDate)) : 0;
$todayHoliday = get_holidays()[$todayDate] ?? null;

$maxPeriod = 0;
foreach ($tt as $periods) if ($periods) $maxPeriod = max($maxPeriod, max(array_keys($periods)));
$maxPeriod = max($maxPeriod, 1);

$chat = function (string $code) use ($fac, $student, $section, $subjectByCode): ?string {
    return faculty_chat_link($fac[$code] ?? null, $student, (string)$section, (string)($subjectByCode[$code]['label'] ?? $code));
};
$usedCodes = [];
foreach ($tt as $periods) foreach ($periods as $c) $usedCodes[$c] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Timetable</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <h1>Timetable</h1>
  <p class="sub"><?= $section ? esc($section) . ' · Monday to Friday' : 'Your class isn\'t set up yet.' ?></p>

  <?php if (!$tt): ?>
    <div class="card"><p class="hint" style="margin:0;">Your timetable hasn't been added yet. Ask the admin to add it — once it's there you'll see today's classes here, the weekly grid, and quick ways to contact each teacher.</p></div>
  <?php else: ?>

    <div class="card">
      <h2><?= esc($showLabel) ?>'s classes<?= $showDate && $showLabel !== 'Today' ? ' · ' . esc(date('j M', strtotime($showDate))) : '' ?></h2>
      <?php if ($showLabel !== 'Today'): ?>
        <p class="hint hint-tight"><?= $todayDow >= 6 ? 'No classes on weekends.' : ($todayHoliday ? 'Holiday today: ' . esc($todayHoliday) . '.' : 'No classes today.') ?> Here's what's next.</p>
      <?php endif; ?>
      <div class="today-list">
        <?php foreach ($tt[$showDow] as $period => $code): $sub = $subjectByCode[$code] ?? null; $link = $chat($code); ?>
          <div class="today-row">
            <span class="today-p">P<?= (int)$period ?></span>
            <span class="today-name"><?= esc($sub['label'] ?? $code) ?>
              <?php if (!empty($fac[$code]['name'])): ?><span class="muted" style="display:block;font-size:.74rem;"><?= esc($fac[$code]['name']) ?></span><?php endif; ?>
            </span>
            <?php if ($sub && $sub['percentage'] !== null): $w = (float)$sub['percentage'] < $thr; ?>
              <span class="subject-pct <?= $w ? 'warn' : '' ?>"><?= number_format((float)$sub['percentage'], 0) ?>%</span>
            <?php endif; ?>
            <?php if ($link): ?><a data-wa-live class="fac-link" style="margin:0;" href="<?= esc($link) ?>" target="_blank" rel="noopener">Contact ↗</a><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>This week</h2>
      <p class="hint hint-tight">Tap a subject to message its teacher on WhatsApp.</p>
      <div class="tt-scroll">
        <table class="tt-view">
          <thead><tr><th></th><?php for ($p = 1; $p <= $maxPeriod; $p++): ?><th>P<?= $p ?></th><?php endfor; ?></tr></thead>
          <tbody>
          <?php foreach ($days as $d => $label): ?>
            <tr class="<?= $d === $todayDow ? 'is-today' : '' ?>">
              <th><?= $label ?></th>
              <?php for ($p = 1; $p <= $maxPeriod; $p++): $code = $tt[$d][$p] ?? ''; $link = $code !== '' ? $chat($code) : null; ?>
                <td>
                  <?php if ($code === ''): ?><span class="muted">—</span>
                  <?php elseif ($link): ?><a data-wa-live href="<?= esc($link) ?>" target="_blank" rel="noopener" title="Contact <?= esc($fac[$code]['name'] ?? 'teacher') ?>"><?= esc(strtoupper($code)) ?></a>
                  <?php else: ?><span><?= esc(strtoupper($code)) ?></span><?php endif; ?>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="padding:0 20px;">
      <h2 style="padding-top:18px;">Subjects &amp; teachers</h2>
      <?php foreach (array_keys($usedCodes) as $code): $sub = $subjectByCode[$code] ?? null; $link = $chat($code); $f = $fac[$code] ?? null; ?>
        <div class="mat-row">
          <span class="mat-type"><?= esc(strtoupper($code)) ?></span>
          <div class="mat-main">
            <div class="mat-title"><?= esc($sub['label'] ?? $code) ?></div>
            <div class="mat-meta"><?= $f && $f['name'] !== '' ? esc($f['name']) : 'Teacher not added yet' ?></div>
          </div>
          <?php if ($link): ?><a data-wa-live class="btn small-btn ghost" style="margin:0;width:auto;" href="<?= esc($link) ?>" target="_blank" rel="noopener">Contact</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?= render_predictor_disclaimer() ?>
  <?php endif; ?>
  <?= render_report_link($student, 'Timetable') ?>
</div>
<?= render_tabbar('timetable', (int)$student['id']) ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
