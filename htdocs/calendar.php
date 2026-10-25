<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$prev = ['y' => $month === 1 ? $year - 1 : $year, 'm' => $month === 1 ? 12 : $month - 1];
$next = ['y' => $month === 12 ? $year + 1 : $year, 'm' => $month === 12 ? 1 : $month + 1];
$monthLabel = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('F Y');

// Upcoming holidays list, for the dropdown.
$today = new DateTimeImmutable('today');
$upcoming = [];
foreach (get_holidays() as $dateStr => $name) {
    $d = new DateTimeImmutable($dateStr);
    if ($d >= $today) $upcoming[$dateStr] = $name;
}
ksort($upcoming);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Calendar</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>
  <h1>Calendar</h1>

  <div class="card">
    <details>
      <summary style="cursor:pointer;font-weight:600;font-size:.85rem;">Upcoming holidays (<?= count($upcoming) ?>)</summary>
      <div style="margin-top:10px;">
        <?php if (!$upcoming): ?>
          <p class="hint" style="margin-top:0;">None on file yet for the coming months.</p>
        <?php else: foreach ($upcoming as $dateStr => $name): ?>
          <div class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);">
            <span><?= esc($name) ?></span>
            <span class="since mono"><?= esc((new DateTimeImmutable($dateStr))->format('d M Y')) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </details>
  </div>

  <div class="card">
    <div class="cal-nav">
      <a href="?y=<?= $prev['y'] ?>&m=<?= $prev['m'] ?>">‹</a>
      <span class="label"><?= esc($monthLabel) ?></span>
      <a href="?y=<?= $next['y'] ?>&m=<?= $next['m'] ?>">›</a>
    </div>
    <?= render_month_calendar($year, $month, false) ?>
    <?= render_calendar_legend() ?>
  </div>
</div>
<?= render_tabbar('calendar', (int)$student['id']) ?>
<script src="assets/ui.js" defer></script>
</body>
</html>
