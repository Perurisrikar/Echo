<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: materials.php'); exit; }
csrf_verify();

$section = section_of_student($student);
$subject = (string)($_POST['subject'] ?? '');
$back = 'materials.php?subject=' . rawurlencode($subject);

$codes = $section ? array_map(fn($x) => $x['code'], section_subjects($section)) : [];
if (!$section || !in_array($subject, $codes, true)) {
    $_SESSION['mat_msg'] = ['err', 'Unknown subject.'];
    header('Location: materials.php'); exit;
}
if (!rate_limit('matreq:' . $student['id'], 10, 86400)) {
    $_SESSION['mat_msg'] = ['err', "You've sent a lot of requests today. Please try again tomorrow."];
    header('Location: ' . $back); exit;
}
$err = create_material_request($section, $subject, (int)$student['id'], (string)($_POST['kind'] ?? 'other'), (string)($_POST['message'] ?? ''));
$_SESSION['mat_msg'] = $err ? ['err', $err] : ['ok', 'Request sent to your CR. They\'ll see it on their Study page.'];
header('Location: ' . $back);
exit;
