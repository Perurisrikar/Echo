<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$me = require_cr();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cr_dashboard.php'); exit; }
csrf_verify();

if (($_POST['do'] ?? '') === 'delete') {
    delete_announcement((int)($_POST['id'] ?? 0), false, (int)$me['id']);
} else {
    $dept = trim((string)($me['dept'] ?? ''));
    $sec = trim((string)($me['sec'] ?? ''));
    if ($dept !== '' && $sec !== '') {
        create_announcement((string)($_POST['message'] ?? ''), $dept, $sec, 'cr', (int)$me['id']);
    }
}

header('Location: cr_dashboard.php');
exit;
