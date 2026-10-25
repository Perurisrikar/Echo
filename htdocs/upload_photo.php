<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: profile.php'); exit; }

// If the upload exceeded the server's post_max_size, PHP discards the whole
// request (including the CSRF token) — report that clearly instead of a
// confusing "form expired" message.
if (request_exceeded_post_limit()) {
    $_SESSION['photo_error'] = 'That image is larger than this server allows (' . format_bytes(server_post_limit()) . '). Try a smaller one.';
    header('Location: profile.php');
    exit;
}

csrf_verify();

try {
    $error = empty($_FILES['photo'])
        ? 'Choose an image first.'
        : save_uploaded_photo((int)$student['id'], $_FILES['photo']);
} catch (Throwable $e) {
    error_log('Echo photo upload failed: ' . $e->getMessage());
    $error = 'Your photo could not be saved because of a server problem. Please tell the admin (System page has details).';
}

$_SESSION['photo_error'] = $error;
header('Location: profile.php');
exit;
