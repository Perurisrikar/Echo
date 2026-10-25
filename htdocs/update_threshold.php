<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: customize.php'); exit; }
csrf_verify();

$raw = trim((string)($_POST['threshold'] ?? ''));
save_student_threshold((int)$student['id'], $raw === '' ? null : (int)$raw);
$_SESSION['customize_msg'] = 'Warning level saved.';
header('Location: customize.php');
exit;
