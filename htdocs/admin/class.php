<?php
declare(strict_types=1);
// Old address of the per-class student list. The searchable list in students.php replaces it,
// so any bookmark or old link lands there with the same class selected.
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

$dept = isset($_GET['dept']) ? trim((string)$_GET['dept']) : null;
$sec = isset($_GET['sec']) ? trim((string)$_GET['sec']) : null;
$to = 'students.php';
if ($dept !== null || $sec !== null) {
    foreach (students_by_class($dept ?? '', $sec ?? '') as $s) {
        if (!empty($s['section'])) { $to .= '?section=' . rawurlencode((string)$s['section']); break; }
    }
}
header('Location: ' . $to);
exit;
