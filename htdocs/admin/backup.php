<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

// Download only on an explicit POST-free GET with a same-session marker is overkill here;
// the admin session is already required. Contains password hashes — keep the file private.
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="echo-backup-' . date('Y-m-d') . '.json"');
header('Cache-Control: no-store');
echo json_encode(build_backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
