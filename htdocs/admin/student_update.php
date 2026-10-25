<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: edit_student.php'); exit; }
csrf_verify();

$studentId = (int)($_POST['student_id'] ?? 0);
$roll = (string)($_POST['roll'] ?? '');

if ($studentId > 0) {
    admin_update_student($studentId, $_POST);
    $_SESSION['edit_saved'] = true;
}

header('Location: edit_student.php?roll=' . rawurlencode($roll));
exit;
