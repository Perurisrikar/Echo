<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_verify();

$id = (int)($_POST['announcement_id'] ?? 0);
if ($id > 0) mark_announcement_seen($id, (int)$student['id']);

header('Location: dashboard.php');
exit;
