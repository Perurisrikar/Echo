<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
$admin = require_admin();

$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$prev = ['y' => $month === 1 ? $year - 1 : $year, 'm' => $month === 1 ? 12 : $month - 1];
$next = ['y' => $month === 12 ? $year + 1 : $year, 'm' => $month === 12 ? 1 : $month + 1];
$monthLabel = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('F Y');
$saturdays = weekend_dates_in_month($year, $month, 6);
$workingNow = get_working_days();
$done = (string)($_GET['done'] ?? '');

admin_header('Holiday calendar', 'calendar');
?>
  <h1>Holiday calendar</h1>
  <p class="sub">Holidays are red. Weekends are shaded, and you can turn any Saturday (or Sunday) into a working day. Students see a read-only version.</p>

  <?php if (preg_match('/^work(\d+)$/', $done, $mm)): ?><div class="banner"><?= (int)$mm[1] ?> day<?= (int)$mm[1] === 1 ? '' : 's' ?> marked as working.</div>
  <?php elseif (preg_match('/^off(\d+)$/', $done, $mm)): ?><div class="banner"><?= (int)$mm[1] ?> day<?= (int)$mm[1] === 1 ? '' : 's' ?> set back to day off.</div>
  <?php elseif ($done === 'nothing'): ?><div class="banner warn">Tick at least one Saturday first.</div><?php endif; ?>

  <div class="cal-layout">
    <div class="card">
      <div class="cal-nav">
        <a href="?y=<?= $prev['y'] ?>&m=<?= $prev['m'] ?>" aria-label="Previous month">‹</a>
        <span class="label"><?= esc($monthLabel) ?></span>
        <a href="?y=<?= $next['y'] ?>&m=<?= $next['m'] ?>" aria-label="Next month">›</a>
      </div>
      <?= render_month_calendar($year, $month, true) ?>
      <?= render_calendar_legend() ?>
    </div>

    <form method="post" action="holiday_toggle.php" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_weekend">
      <input type="hidden" name="y" value="<?= $year ?>"><input type="hidden" name="m" value="<?= $month ?>">
      <h2>Saturdays in <?= esc($monthLabel) ?></h2>
      <p class="hint hint-tight">Saturdays are days off by default. Tick the ones that are working days this month (for example only the 2nd and 4th), choose which weekday's timetable they follow, then apply.</p>
      <div class="scope-list">
        <?php foreach ($saturdays as $sd): $dt = new DateTimeImmutable($sd); $isWork = isset($workingNow[$sd]); $isHol = holiday_name_for($dt) !== null; ?>
          <label class="check"><input type="checkbox" name="dates[]" value="<?= esc($sd) ?>" <?= $isWork ? 'checked' : '' ?> <?= $isHol ? 'disabled' : '' ?>>
            <span><?= esc($dt->format('D j M')) ?><?= $isWork ? ' · working' : '' ?><?= $isHol ? ' · holiday' : '' ?></span></label>
        <?php endforeach; ?>
      </div>
      <label for="follow">Working Saturdays follow</label>
      <select id="follow" name="follow">
        <option value="0">No timetable (attendance counts, no class list)</option>
        <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'] as $n => $label): ?>
          <option value="<?= $n ?>">The <?= $label ?> timetable</option>
        <?php endforeach; ?>
      </select>
      <label class="check" style="margin-top:12px;"><input type="checkbox" name="notify" value="1" checked><span>Tell all students (shows once on their home page)</span></label>
      <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:12px;">
        <button type="submit" name="mode" value="working" class="matcha">Make ticked Saturdays working</button>
        <button type="submit" name="mode" value="off" class="ghost">Make ticked Saturdays days off</button>
      </div>
    </form>
  </div>
<?php admin_footer(); ?>
