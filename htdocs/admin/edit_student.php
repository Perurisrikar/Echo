<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
$admin = require_admin();

$roll = strtoupper(trim((string)($_GET['roll'] ?? '')));
$student = $roll !== '' ? get_student_by_roll($roll) : null;
$attendance = $student ? get_attendance($student['roll_no']) : null;
$saved = $_SESSION['edit_saved'] ?? false;
unset($_SESSION['edit_saved']);

$backUrl = 'edit_student.php?roll=' . rawurlencode($roll);
?>
<?php admin_header('Edit student', 'students'); ?>
  <h1>Edit student</h1>
  <p class="sub">Look up by roll number, then edit the roster fields directly.</p>

  <div class="card">
    <form method="get" action="edit_student.php">
      <label for="roll">Roll number</label>
      <input id="roll" name="roll" value="<?= esc($roll) ?>" autocapitalize="characters" placeholder="e.g. DEMO0001">
      <button type="submit">Find</button>
    </form>
  </div>

  <?php if ($roll !== '' && !$student): ?>
    <div class="banner warn">No student found with roll number <strong><?= esc($roll) ?></strong>.</div>
  <?php elseif ($student): ?>

    <?php if ($saved): ?><div class="banner">Saved.</div><?php endif; ?>

    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div>
          <div class="roll mono"><?= esc($student['roll_no']) ?></div>
          <div class="since">
            <?= $student['password_hash'] ? '<span class="tag set">Password set</span>' : '<span class="tag unset">No password</span>' ?>
            <?php if ($student['is_cr']): ?> <span class="tag set">CR</span><?php endif; ?>
          </div>
        </div>
        <?= number_format((float)($attendance['percentage'] ?? 0), 2) ?>%
      </div>
    </div>

    <?php if ($attendance): ?>
      <div class="card">
        <h2>Attendance by subject</h2>
        <?php if ($attendance['section']): ?>
          <p class="hint hint-tight">Section <?= esc($attendance['section']) ?><?= $attendance['year'] ? ' · Year ' . esc($attendance['year']) : '' ?><?= $attendance['from_date'] ? ' · ' . esc($attendance['from_date']) . ' to ' . esc($attendance['to_date']) : '' ?></p>
        <?php endif; ?>
        <div class="subject-list">
          <?php foreach (($attendance['subjects'] ?? []) as $subject): ?>
            <div class="subject-row">
              <div>
                <div class="subject-name"><?= esc($subject['label']) ?></div>
                <div class="since"><?= number_format((float)$subject['attended'], 0) ?> attended<?= $subject['conducted'] !== null ? ' / ' . number_format((float)$subject['conducted'], 0) . ' conducted' : '' ?></div>
              </div>
              <span class="subject-pct <?= $subject['percentage'] !== null && $subject['percentage'] < SUBJECT_THRESHOLD ? 'warn' : '' ?>"><?= $subject['percentage'] !== null ? number_format((float)$subject['percentage'], 2) . '%' : '—' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Roster details</h2>
      <p class="hint hint-tight">These are overwritten by the next CSV import if they conflict — edit here for a one-off fix.</p>
      <form method="post" action="student_update.php">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
        <input type="hidden" name="roll" value="<?= esc($roll) ?>">
        <label for="name">Name</label>
        <input id="name" name="name" value="<?= esc((string)($student['name'] ?? '')) ?>">
        <div class="two-col">
          <div>
            <label for="dept">Department</label>
            <input id="dept" name="dept" value="<?= esc((string)($student['dept'] ?? '')) ?>">
          </div>
          <div>
            <label for="sec">Section</label>
            <input id="sec" name="sec" value="<?= esc((string)($student['sec'] ?? '')) ?>">
          </div>
        </div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= esc((string)($student['email'] ?? '')) ?>">
        <label for="mobile">Mobile</label>
        <input id="mobile" name="mobile" value="<?= esc((string)($student['mobile'] ?? '')) ?>">
        <button type="submit">Save</button>
      </form>
    </div>

    <div class="card">
      <h2>Nickname &amp; bio</h2>
      <p class="hint hint-tight">Set by the student. Shown here read-only, for context.</p>
      <div class="since"><?= $student['nickname'] ? esc($student['nickname']) : '<span class="muted">Not set</span>' ?></div>
      <?php if ($student['bio']): ?><div class="since" style="margin-top:4px;"><?= nl2br(esc($student['bio'])) ?></div><?php endif; ?>
    </div>

    <div class="card">
      <h2>Class Representative</h2>
      <p class="hint hint-tight">A CR can send reset links to classmates in <?= esc(trim(($student['dept'] ?? '') . ' ' . ($student['sec'] ?? ''))) ?: 'their class' ?> only — no roster, calendar, or attendance-import access.</p>
      <form method="post" action="cr_toggle.php">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
        <input type="hidden" name="back" value="<?= esc($backUrl) ?>">
        <input type="hidden" name="make_cr" value="<?= $student['is_cr'] ? '0' : '1' ?>">
        <button type="submit" class="<?= $student['is_cr'] ? 'ghost' : '' ?>"><?= $student['is_cr'] ? 'Remove CR status' : 'Make CR' ?></button>
      </form>
    </div>

    <div class="card">
      <h2>Password</h2>
      <form method="post" action="reset_link.php">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
        <input type="hidden" name="back" value="<?= esc($backUrl) ?>">
        <button type="submit" class="ghost"><?= $student['password_hash'] ? 'Send reset link' : 'Send set-password link' ?></button>
      </form>
    </div>

  <?php endif; ?>
<?php admin_footer([]); ?>
