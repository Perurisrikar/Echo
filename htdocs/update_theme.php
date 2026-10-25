<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: customize.php'); exit; }
csrf_verify();

$accent = (string)($_POST['accent'] ?? '');
$glow = !empty($_POST['glow']);
save_theme((int)$student['id'], $accent !== '' ? $accent : null, $glow);

header('Location: customize.php');
exit;
