<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_verify();

$studentId = (int)($_POST['student_id'] ?? 0);
$stmt = db()->prepare('SELECT roll_no, mobile FROM students WHERE id = ?');
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if ($student) {
    $_SESSION['flash_link'] = generate_reset_link($studentId);
    $_SESSION['flash_roll'] = $student['roll_no'];
    $_SESSION['flash_mobile'] = $student['mobile'] ?? null;
}

// Preserve whatever class filter / sort the admin was looking at.
$back = (string)($_POST['back'] ?? 'dashboard.php');
$back = admin_safe_back($back);

header('Location: ' . $back);
exit;
