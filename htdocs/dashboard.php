<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();
$roll = $student['roll_no'];
$attendance = get_attendance($roll);
$displayName = $student['nickname'] ?: ($student['name'] ?: $roll);
$announcements = unseen_announcements_for($student);
$fresh = data_freshness($attendance['section'] ?? null);
$thr = student_threshold($student);
$thrLabel = rtrim(rtrim(number_format($thr, 1), '0'), '.');
$validNow = array_values(array_filter((array)($attendance['subjects'] ?? []), fn($x) => $x['percentage'] !== null));
$allSafe = $attendance && $validNow && !array_filter($validNow, fn($x) => (float)$x['percentage'] < $thr);
$history = $attendance ? record_and_get_history($roll, (float)$attendance['percentage'], 30, (bool)$allSafe) : [];
if ($attendance) record_subject_snapshot($roll, $attendance);
$subjectChanges = $attendance ? subject_changes($roll, $attendance) : [];
$safeSince = $allSafe ? safe_since($roll) : null;
$exam = $attendance ? active_exam_period() : null;
$examRisk = [];
if ($exam) {
    foreach ($validNow as $x) {
        if ((float)$x['percentage'] < SUBJECT_THRESHOLD) {
            $a = bunk_advice((float)$x['attended'], (float)$x['conducted'], SUBJECT_THRESHOLD);
            $examRisk[] = ['label' => $x['label'], 'pct' => (float)$x['percentage'], 'need' => $a && $a[0] === 'recover' ? $a[1] : null];
        }
    }
}
$timetable = $attendance && !empty($attendance['section']) ? get_timetable((string)$attendance['section']) : [];
$todayDate = ist_now()->format('Y-m-d');
$todayCounts = $timetable ? periods_on_date($timetable, $todayDate) : [];
$facultyByCode = $attendance && !empty($attendance['section']) ? get_faculty((string)$attendance['section']) : [];
$subjectByCode = [];
foreach ((array)($attendance['subjects'] ?? []) as $sub) $subjectByCode[$sub['code']] = $sub;
$atRisk = [];
foreach ($todayCounts as $code => $n) {
    $sub = $subjectByCode[$code] ?? null;
    if (!$sub) continue;
    $adv = bunk_advice($sub['attended'] !== null ? (float)$sub['attended'] : null, $sub['conducted'] !== null ? (float)$sub['conducted'] : null, $thr);
    if (!$adv) continue;
    if ($adv[0] === 'recover') $atRisk[] = $sub['label'] . ' is already below ' . $thrLabel . '% — attend today';
    elseif ($adv[1] < $n) $atRisk[] = $sub['label'] . ' is close to ' . $thrLabel . '% — missing today would drop it below';
}
$lastRunTs = $fresh['last_run'] ? strtotime(trim(str_replace('IST', '', $fresh['last_run']))) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Home</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>

  <?php foreach ($announcements as $a): ?>
    <div class="card announcement">
      <div class="announcement-tag"><?= $a['author_type'] === 'admin' ? 'Admin' : 'Class rep' ?><?= $a['dept'] ? ' · ' . esc((string)$a['sec']) : '' ?></div>
      <p class="announcement-text"><?= nl2br(esc((string)$a['message'])) ?></p>
      <form method="post" action="announcement_dismiss.php">
        <?= csrf_field() ?>
        <input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>">
        <button class="small ghost" type="submit">Got it</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="card center">
    <?= render_avatar($student, 'lg', true) ?>
    <div class="hero-text">
      <div class="hero-name"><?= esc($displayName) ?></div>
      <div class="since mono"><?= esc($roll) ?></div>
      <?php if ($attendance && $attendance['section']): ?>
        <div class="since">
          <?= esc($attendance['branch'] ?: ($student['dept'] ?? '')) ?>
          <?php if ($attendance['year']): ?> · Year <?= esc($attendance['year']) ?><?php endif; ?>
          · Section <?= esc($attendance['section']) ?>
        </div>
        <?php if ($attendance['from_date'] || $attendance['to_date']): ?>
          <div class="since">Attendance period: <?= esc($attendance['from_date']) ?><?= $attendance['to_date'] ? ' to ' . esc($attendance['to_date']) : '' ?></div>
        <?php endif; ?>
      <?php elseif ($student['dept'] || $student['sec']): ?>
        <div class="since"><?= esc(trim(($student['dept'] ?? '') . ' · ' . ($student['sec'] ?? ''), ' ·')) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($attendance): ?>
    <p class="fresh<?= $fresh['stale'] ? ' stale' : '' ?>">
      <?php if ($lastRunTs): ?>Attendance updated <?= esc(date('D, j M · g:i A', $lastRunTs)) ?> IST<?php else: ?>Attendance update time unavailable<?php endif; ?>
    </p>
    <?php if ($fresh['stale']): ?>
      <div class="banner warn">Your section's attendance hasn't refreshed recently, so these numbers may be a little behind. It usually fixes itself at the next sync.</div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($safeSince): ?>
    <div class="safe-badge">✓ All subjects safe<?= $safeSince !== $todayDate ? ' · since ' . esc(date('j M', strtotime($safeSince))) : '' ?></div>
  <?php endif; ?>

  <?= render_push_card($student, true) ?>

  <?php if ($atRisk): ?>
    <div class="banner warn nudge">
      <strong>Heads-up for today</strong>
      <?php foreach ($atRisk as $line): ?><div><?= esc($line) ?></div><?php endforeach; ?>
      <?= render_predictor_disclaimer() ?>
    </div>
  <?php endif; ?>

  <?php if (is_cr($student)): ?>
    <a class="card link-card" href="cr_dashboard.php">
      <span>You're the CR for <?= esc(trim(($student['dept'] ?? '') . ' ' . ($student['sec'] ?? ''))) ?: 'your class' ?> — manage reset links</span>
      <span class="chev">›</span>
    </a>
  <?php endif; ?>

  <?php if (!$attendance): ?>
    <div class="banner warn">
      Attendance data isn't available right now. The latest file may not have synced yet.
      Try again in a minute; your account is unaffected.
    </div>
  <?php else:
    $subjects = $attendance['subjects'] ?? [];
    $validSubjects = array_values(array_filter($subjects, fn($s) => $s['percentage'] !== null));
    $belowCount = count(array_filter($validSubjects, fn($s) => (float)$s['percentage'] < $thr));
  ?>

  <?php if ($exam): ?>
    <div class="card exam-card">
      <div class="exam-tag"><?= esc($exam['label']) ?> · <?= esc(date('j M', strtotime((string)$exam['start_date']))) ?><?= $exam['end_date'] !== $exam['start_date'] ? ' – ' . esc(date('j M', strtotime((string)$exam['end_date']))) : '' ?></div>
      <?php if (!$examRisk): ?>
        <h2>You're eligible ✓</h2>
        <p class="hint hint-tight">Every subject is at or above 75%. Keep attending to stay safe.</p>
      <?php else: ?>
        <h2>Attendance at risk for exams</h2>
        <p class="hint hint-tight">These subjects are under 75%:</p>
        <?php foreach ($examRisk as $r): ?>
          <div class="subject-row"><span class="subject-name"><?= esc($r['label']) ?></span>
            <span class="subject-pct warn"><?= number_format($r['pct'], 1) ?>%<?= $r['need'] ? ' · attend next ' . (int)$r['need'] : '' ?></span></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?= render_predictor_disclaimer() ?>
    </div>
  <?php endif; ?>

  <details class="card pct-card" open>
    <summary>
      <div class="pct-label">Overall attendance <span class="chev">▾</span></div>
      <div class="pct"><?= number_format((float)$attendance['percentage'], 2) ?>%</div>
      <div class="bar"><div id="pctBar" style="width:<?= min(max((float)$attendance['percentage'], 0), 100) ?>%"></div></div>
      <div class="status" id="statusPill"></div>
      <div class="since since-top">
        <?= number_format((float)($attendance['attended_total'] ?? 0), 0) ?> attended /
        <?= number_format((float)($attendance['conducted_total'] ?? $attendance['total_classes']), 0) ?> conducted
      </div>
    </summary>

    <?php if ($subjects): ?>
      <div class="subject-list">
        <?php foreach ($subjects as $s):
          $hasPct = $s['percentage'] !== null;
          $warn = $hasPct && $s['percentage'] < $thr;
          $near = $hasPct && !$warn && $s['percentage'] < $thr + 5;
          $adv = bunk_advice($s['attended'] !== null ? (float)$s['attended'] : null, $s['conducted'] !== null ? (float)$s['conducted'] : null, $thr);
          $chat = faculty_chat_link($facultyByCode[$s['code']] ?? null, $student, (string)($attendance['section'] ?? ''), (string)$s['label']);
        ?>
          <div class="subject-row" style="align-items:flex-start;">
            <div>
              <div class="subject-name"><?= esc($s['label']) ?></div>
              <div class="since" style="margin-top:2px;">
                <?= number_format((float)$s['attended'], 0) ?> attended
                <?php if ($s['conducted'] !== null): ?> / <?= number_format((float)$s['conducted'], 0) ?> conducted<?php endif; ?>
              </div>
              <?php if ($adv): ?>
                <div class="advice <?= $adv[0] === 'safe' ? ($adv[1] === 0 ? 'edge' : 'ok') : 'need' ?>">
                  <?php if ($adv[0] === 'safe'): ?>
                    <?= $adv[1] === 0 ? 'No room to miss — attend every class' : 'Can miss ' . $adv[1] . ' more class' . ($adv[1] === 1 ? '' : 'es') ?>
                  <?php else: ?>
                    Attend the next <?= $adv[1] ?> class<?= $adv[1] === 1 ? '' : 'es' ?> to reach <?= esc($thrLabel) ?>%
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($chat): ?>
                <a class="fac-link" data-wa-live href="<?= esc($chat) ?>" target="_blank" rel="noopener">Contact faculty<?= !empty($facultyByCode[$s['code']]['name']) ? ' · ' . esc($facultyByCode[$s['code']]['name']) : '' ?> ↗</a>
              <?php endif; ?>
            </div>
            <span class="subject-pct <?= $warn ? 'warn' : ($near ? 'near' : '') ?>">
              <?= $hasPct ? number_format((float)$s['percentage'], 2) . '%' : '—' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($validSubjects): ?>
        <p class="hint hint-tight">Subject percentage = attended classes ÷ classes conducted for that subject × 100. Each subject is checked independently against <?= esc($thrLabel) ?>%<?= $thr !== 75.0 ? ' (your setting — the official line is 75%)' : '' ?>.</p>
        <?= render_predictor_disclaimer() ?>
      <?php else: ?>
        <p class="hint hint-tight">This is an older attendance export. It contains subject attended counts but not subject-wise conducted totals, so subject percentages cannot be calculated reliably.</p>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <?php if ($belowCount > 0): ?>
    <div class="banner warn">
      ⚠ <?= $belowCount ?> subject<?= $belowCount === 1 ? ' is' : 's are' ?> below <?= esc($thrLabel) ?>%. Missing additional classes in those subjects will reduce them further.
    </div>
  <?php endif; ?>

  <?php $spark = render_sparkline($history); if ($spark): ?>
    <div class="card">
      <h2>Your trend</h2>
      <p class="hint hint-tight">Overall attendance over the last 30 days you opened Echo.</p>
      <?= $spark ?>
      <div class="spark-axis"><span><?= esc(date('j M', strtotime($history[0]['d']))) ?> · <?= number_format((float)$history[0]['pct'], 1) ?>%</span><span>Today · <?= number_format((float)end($history)['pct'], 1) ?>%</span></div>
    </div>
  <?php endif; ?>

  <?= render_subject_changes($subjectChanges) ?>

  <?php if ($exam): ?><details class="card fold"><summary>Predictor tools</summary><?php endif; ?>
  <div class="card">
    <h2>If you miss upcoming classes</h2>
    <p class="hint hint-tight">Pick a date. Weekends and holidays are skipped automatically.</p>
    <label for="untilDate">Project up until</label>
    <input id="untilDate" type="date">
    <div class="hint" id="workingDaysNote"></div>
    <label for="missDays">Days you'll miss (leave blank for none)</label>
    <input id="missDays" type="number" min="0" inputmode="numeric" placeholder="e.g. 3">
    <button id="calcBtn">Calculate</button>
    <div class="result hidden" id="predictOut"></div>
    <?= render_predictor_disclaimer() ?>
  </div>

  <?php if ($timetable): ?>
  <div class="card" id="skipCard">
    <h2>Which subjects drop if I skip a day?</h2>
    <p class="hint hint-tight">Add the days you might miss. Using your timetable, each subject's new percentage is shown. Weekends and holidays are ignored.</p>
    <div class="row" style="gap:8px;align-items:flex-end;">
      <div style="flex:1;"><label for="skipDate">Day</label><input id="skipDate" type="date"></div>
      <button class="small matcha" type="button" id="skipAdd" style="margin-bottom:1px;">Add</button>
    </div>
    <div class="chips" id="skipChips"></div>
    <div class="skip-out" id="skipOut"></div>
    <?= render_predictor_disclaimer() ?>
    <script type="application/json" id="skip-data"><?= json_encode([
        'subjects' => array_values(array_map(fn($x) => ['code' => $x['code'], 'label' => $x['label'], 'attended' => $x['attended'], 'conducted' => $x['conducted']], (array)$attendance['subjects'])),
        'timetable' => array_map(fn($d) => array_values($d), $timetable),
        'holidays' => array_keys(get_holidays()),
        'workdays' => (object)array_map(fn($w) => (int)($w['follow_dow'] ?? 0), get_working_days()),
        'threshold' => $thr,
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Share my status</h2>
    <p class="hint hint-tight">Makes a clean picture with your overall percentage — no roll number — to send to a group chat.</p>
    <button type="button" id="shareBtn" class="ghost">Create share image</button>
    <script type="application/json" id="share-data"><?= json_encode([
        'name' => $displayName,
        'pct' => round((float)$attendance['percentage'], 1),
        'subjects' => count($validSubjects),
        'safe' => count($validSubjects) - $belowCount,
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </div>

  <div class="card">
    <h2>Threshold warning</h2>
    <p class="hint hint-tight">You'll be warned if a projection falls below this line.</p>
    <label for="threshold">Minimum required attendance (%)</label>
    <input id="threshold" type="number" min="0" max="100" value="<?= (int)round($thr) ?>">
  </div>

  <?php if ($exam): ?></details><?php endif; ?>

  <script type="application/json" id="predictor-data"><?= json_encode([
      'total' => $attendance['conducted_total'] ?? $attendance['total_classes'],
      'pct' => $attendance['percentage'],
      'classesPerDay' => classes_per_day(),
      'holidays' => array_keys(get_holidays()),
      'workdays' => array_keys(get_working_days()),
  ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
  <?= render_report_link($student, 'Home') ?>
</div>

<?= render_tabbar('home', (int)$student['id']) ?>
<?php if ($attendance): ?><script src="assets/dashboard.js"></script><script src="assets/skip.js" defer></script><script src="assets/share.js" defer></script><?php endif; ?>
<script src="assets/ui.js" defer></script>
<script src="assets/push.js" defer></script>
</body>
</html>
