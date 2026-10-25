<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$me = require_cr();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cr_dashboard.php'); exit; }
csrf_verify();

$targetId = (int)($_POST['student_id'] ?? 0);
$stmt = db()->prepare('SELECT ' . STUDENT_COLS . ' FROM students WHERE id = ?');
$stmt->execute([$targetId]);
$target = $stmt->fetch();

// The scope check that actually matters: a CR can only act on someone in
// their own class, verified server-side — never trust a posted roll/id alone.
if ($target && cr_can_manage($me, $target)) {
    $_SESSION['flash_link'] = generate_reset_link($targetId);
    $_SESSION['flash_roll'] = $target['roll_no'];
    $_SESSION['flash_mobile'] = $target['mobile'] ?? null;
}

header('Location: cr_dashboard.php');
exit;
