<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: profile.php'); exit; }
csrf_verify();

$nickname = trim((string)($_POST['nickname'] ?? ''));
$bio = trim((string)($_POST['bio'] ?? ''));
$nickname = mb_substr($nickname, 0, 40);
$bio = mb_substr($bio, 0, 280);

db()->prepare('UPDATE students SET nickname = ?, bio = ? WHERE id = ?')
    ->execute([$nickname !== '' ? $nickname : null, $bio !== '' ? $bio : null, $student['id']]);

$_SESSION['profile_saved'] = true;
header('Location: profile.php');
exit;
