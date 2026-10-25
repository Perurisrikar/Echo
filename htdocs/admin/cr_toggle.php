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
$makeCr = !empty($_POST['make_cr']);
if ($studentId > 0) set_cr_status($studentId, $makeCr);

$back = (string)($_POST['back'] ?? 'dashboard.php');
$back = admin_safe_back($back);

header('Location: ' . $back);
exit;
