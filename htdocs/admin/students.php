<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

const STUDENTS_PER_PAGE = 40;

// ---- filters (all in the URL, so a filtered view can be bookmarked or refreshed) ----
$q       = trim((string)($_GET['q'] ?? ''));
$secF    = trim((string)($_GET['section'] ?? ''));
$status  = (string)($_GET['status'] ?? 'all');
$sort    = (string)($_GET['sort'] ?? 'roll');
$page    = max(1, (int)($_GET['page'] ?? 1));
if (!in_array($status, ['all', 'nopass', 'cr', 'low', 'reset'], true)) $status = 'all';
if (!in_array($sort, ['roll', 'name', 'low', 'high'], true)) $sort = 'roll';

$all = all_students_with_attendance();
$sections = [];
foreach ($all as $s) $sections[(string)($s['section'] ?? '')] = true;
unset($sections['']);
ksort($sections);

$resetIds = [];
foreach (open_reset_requests() as $rq) $resetIds[(int)$rq['student_id']] = true;

// counts for the filter chips are for the chosen section, before the status filter applies
$inSection = array_values(array_filter($all, fn($s) => $secF === '' || (string)($s['section'] ?? '') === $secF));
$counts = ['all' => count($inSection), 'nopass' => 0, 'cr' => 0, 'low' => 0, 'reset' => 0];
foreach ($inSection as $s) {
    if (empty($s['password_hash'])) $counts['nopass']++;
    if (!empty($s['is_cr'])) $counts['cr']++;
    if ((float)$s['percentage'] < SUBJECT_THRESHOLD) $counts['low']++;
    if (isset($resetIds[(int)($s['id'] ?? 0)])) $counts['reset']++;
}

$needle = mb_strtolower($q);
$rows = array_values(array_filter($inSection, function ($s) use ($needle, $status, $resetIds) {
    if ($needle !== '') {
        $hay = mb_strtolower(($s['roll_no'] ?? '') . ' ' . ($s['name'] ?? '') . ' ' . ($s['nickname'] ?? '') . ' ' . ($s['mobile'] ?? ''));
        if (!str_contains($hay, $needle)) return false;
    }
    return match ($status) {
        'nopass' => empty($s['password_hash']),
        'cr'     => !empty($s['is_cr']),
        'low'    => (float)$s['percentage'] < SUBJECT_THRESHOLD,
        'reset'  => isset($resetIds[(int)($s['id'] ?? 0)]),
        default  => true,
    };
}));
usort($rows, fn($a, $b) => match ($sort) {
    'name'  => strcasecmp((string)($a['name'] ?? '~'), (string)($b['name'] ?? '~')),
    'low'   => (float)$a['percentage'] <=> (float)$b['percentage'],
    'high'  => (float)$b['percentage'] <=> (float)$a['percentage'],
    default => strcasecmp((string)$a['roll_no'], (string)$b['roll_no']),
});

$total = count($rows);
$pages = max(1, (int)ceil($total / STUDENTS_PER_PAGE));
$page = min($page, $pages);
$slice = array_slice($rows, ($page - 1) * STUDENTS_PER_PAGE, STUDENTS_PER_PAGE);

/** URL for this page with some filters changed. */
$url = function (array $change = []) use ($q, $secF, $status, $sort, $page): string {
    $p = array_merge(['q' => $q, 'section' => $secF, 'status' => $status, 'sort' => $sort, 'page' => $page], $change);
    if (!array_key_exists('page', $change)) $p['page'] = 1;
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 'all' && !($v === 1) && $v !== 'roll');
    return 'students.php' . ($p ? '?' . http_build_query($p) : '');
};
$here = $url(['page' => $page]);

$chips = ['all' => 'Everyone', 'low' => 'Under 75%', 'nopass' => 'No password yet', 'reset' => 'Reset requested', 'cr' => 'CRs'];
$sectionInfo = $secF !== '' ? (list_sections()[$secF] ?? null) : null;
$sectionDept = '';
if ($secF !== '' && $inSection) { $sectionDept = trim((string)($inSection[0]['dept'] ?? '')); }

admin_header('Students', 'students');
?>
  <h1>Students</h1>
  <p class="sub"><?= count($all) ?> student<?= count($all) === 1 ? '' : 's' ?> in <?= count($sections) ?> section<?= count($sections) === 1 ? '' : 's' ?>. Search by name, roll number or mobile.</p>

  <?= admin_flash_link_html() ?>

  <form method="get" class="card filters" id="filterForm">
    <div class="filter-main">
      <input type="search" name="q" value="<?= esc($q) ?>" placeholder="Search name, roll number or mobile…" aria-label="Search students" autocomplete="off">
      <select name="section" aria-label="Section" data-autosubmit>
        <option value="">All sections</option>
        <?php foreach (array_keys($sections) as $code): ?><option value="<?= esc($code) ?>" <?= $code === $secF ? 'selected' : '' ?>><?= esc($code) ?></option><?php endforeach; ?>
      </select>
      <select name="sort" aria-label="Sort by" data-autosubmit>
        <option value="roll" <?= $sort === 'roll' ? 'selected' : '' ?>>Roll number</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A–Z</option>
        <option value="low" <?= $sort === 'low' ? 'selected' : '' ?>>Lowest attendance</option>
        <option value="high" <?= $sort === 'high' ? 'selected' : '' ?>>Highest attendance</option>
      </select>
      <button type="submit" class="matcha">Search</button>
    </div>
    <input type="hidden" name="status" value="<?= esc($status) ?>">
    <div class="chips-row">
      <?php foreach ($chips as $k => $label): ?>
        <a class="fchip<?= $status === $k ? ' on' : '' ?>" href="<?= esc($url(['status' => $k])) ?>"><?= esc($label) ?> <b><?= (int)$counts[$k] ?></b></a>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if ($secF !== ''): ?>
    <div class="card section-strip">
      <div><strong><?= esc($secF) ?></strong><?= $sectionDept ? ' <span class="muted">· ' . esc($sectionDept) . '</span>' : '' ?></div>
      <div class="strip-links">
        <a href="timetable.php?section=<?= esc(rawurlencode($secF)) ?>">Timetable</a>
        <a href="materials.php?section=<?= esc(rawurlencode($secF)) ?>">Study material</a>
        <?php if ($inSection): ?><a href="../condonation.php?dept=<?= esc(rawurlencode((string)($inSection[0]['dept'] ?? ''))) ?>&sec=<?= esc(rawurlencode((string)($inSection[0]['sec'] ?? ''))) ?>">Printable shortage list</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card stu-list">
    <?php if (!$slice): ?>
      <p class="hint" style="margin:6px 0;">No students match. <a href="students.php">Clear filters</a></p>
    <?php endif; ?>
    <?php foreach ($slice as $s):
        $pct = (float)$s['percentage'];
        $tone = $pct < SUBJECT_THRESHOLD ? 'bad' : ($pct < SUBJECT_THRESHOLD + 5 ? 'near' : 'ok');
        $name = trim((string)($s['name'] ?? ''));
        $initial = mb_strtoupper(mb_substr($name !== '' ? $name : (string)$s['roll_no'], 0, 1));
        $sid = (int)($s['id'] ?? 0);
    ?>
      <div class="stu-row">
        <div class="stu-av" style="background:<?= esc(color_for((string)$s['roll_no'])) ?>"><?= esc($initial) ?></div>
        <div class="stu-main">
          <div class="stu-name"><?= $name !== '' ? esc($name) : '<span class="muted">No name on roster</span>' ?>
            <?php if (!empty($s['nickname'])): ?><span class="muted"> “<?= esc((string)$s['nickname']) ?>”</span><?php endif; ?></div>
          <div class="stu-sub"><span class="mono"><?= esc((string)$s['roll_no']) ?></span> · <?= esc((string)($s['section'] ?? '—')) ?><?= !empty($s['mobile']) ? ' · ' . esc((string)$s['mobile']) : '' ?></div>
          <div class="stu-badges">
            <?php if (!empty($s['is_cr'])): ?><span class="tag set">CR</span><?php endif; ?>
            <?php if (empty($s['password_hash'])): ?><span class="tag unset">No password</span><?php endif; ?>
            <?php if (isset($resetIds[$sid])): ?><span class="tag warn">Reset requested</span><?php endif; ?>
          </div>
        </div>
        <div class="stu-pct <?= $tone ?>"><?= number_format($pct, 1) ?>%</div>
        <div class="stu-actions">
          <a class="btn small ghost btn-link" href="edit_student.php?roll=<?= esc(rawurlencode((string)$s['roll_no'])) ?>">Edit</a>
          <details class="more"><summary class="btn small ghost" aria-label="More actions for <?= esc((string)$s['roll_no']) ?>">⋯</summary>
            <div class="more-pop">
              <form method="post" action="reset_link.php"><?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= $sid ?>"><input type="hidden" name="back" value="<?= esc($here) ?>">
                <button type="submit"><?= !empty($s['password_hash']) ? 'Create reset link' : 'Create set-password link' ?></button></form>
              <form method="post" action="cr_toggle.php"><?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= $sid ?>"><input type="hidden" name="back" value="<?= esc($here) ?>">
                <input type="hidden" name="make_cr" value="<?= !empty($s['is_cr']) ? '0' : '1' ?>">
                <button type="submit"><?= !empty($s['is_cr']) ? 'Remove CR status' : 'Make class rep (CR)' ?></button></form>
            </div></details>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn small ghost btn-link" href="<?= esc($url(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> students</span>
      <?php if ($page < $pages): ?><a class="btn small ghost btn-link" href="<?= esc($url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
    </div>
  <?php else: ?>
    <p class="hint" style="text-align:center;"><?= $total ?> student<?= $total === 1 ? '' : 's' ?> shown.</p>
  <?php endif; ?>
<?php admin_footer(['../assets/admin.js']); ?>
