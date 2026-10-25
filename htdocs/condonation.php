<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

// Admin: any class (?dept=&sec=). CR: their own class only.
$isAdmin = current_admin() !== null;
if ($isAdmin) {
    $dept = trim((string)($_GET['dept'] ?? ''));
    $sec = trim((string)($_GET['sec'] ?? ''));
} else {
    $me = require_cr();
    $dept = trim((string)($me['dept'] ?? ''));
    $sec = trim((string)($me['sec'] ?? ''));
}
if ($dept === '' && $sec === '') { http_response_code(400); exit('No class selected.'); }

$className = trim($dept . ' ' . $sec);
$rows = class_shortage_list($dept, $sec);
$today = ist_now()->format('j F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance shortage — <?= esc($className) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="print-page">
<div class="wrap wide">
  <div class="no-print" style="display:flex;gap:10px;margin-bottom:14px;">
    <a class="btn ghost small-btn" style="width:auto;margin:0;" href="<?= $isAdmin ? 'admin/dashboard.php' : 'cr_dashboard.php' ?>">‹ Back</a>
    <button type="button" class="small matcha" id="printBtn">Print / save as PDF</button>
  </div>
  <h1>Attendance shortage — <?= esc($className) ?></h1>
  <p class="sub">As of <?= esc($today) ?> · students below 75% in at least one subject · <?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?>.
     “Needed” is how many classes in a row each student must attend to reach 75% in that subject (an estimate — holidays and cancellations aren't known in advance).</p>
  <div class="card" style="padding:0 16px;">
    <?php if (!$rows): ?>
      <p class="hint" style="padding:18px 0;margin:0;">Nobody in this class is below 75% in any subject.</p>
    <?php else: ?>
    <div class="tt-scroll">
    <table class="log-table print-table">
      <thead><tr><th>#</th><th>Roll no</th><th>Name</th><th>Overall</th><th>Subject</th><th>Attended / Held</th><th>%</th><th>Needed</th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($rows as $row): $st = $row['student']; $att = get_attendance((string)$st['roll_no']); $i++; $first = true;
        foreach ((array)($att['subjects'] ?? []) as $sub):
          if ($sub['percentage'] === null || (float)$sub['percentage'] >= SUBJECT_THRESHOLD) continue;
          $adv = bunk_advice((float)$sub['attended'], (float)$sub['conducted'], SUBJECT_THRESHOLD); ?>
        <tr>
          <td><?= $first ? $i : '' ?></td>
          <td class="mono"><?= $first ? esc($st['roll_no']) : '' ?></td>
          <td><?= $first ? esc($st['name'] ?: ($st['nickname'] ?: '—')) : '' ?></td>
          <td><?= $first ? number_format($row['overall'], 1) . '%' : '' ?></td>
          <td><?= esc($sub['label']) ?></td>
          <td><?= number_format((float)$sub['attended'], 0) ?> / <?= number_format((float)$sub['conducted'], 0) ?></td>
          <td><?= number_format((float)$sub['percentage'], 1) ?>%</td>
          <td><?= $adv && $adv[0] === 'recover' ? (int)$adv[1] : '—' ?></td>
        </tr>
      <?php $first = false; endforeach; endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script src="assets/print.js" defer></script>
</body>
</html>
