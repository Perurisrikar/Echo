<?php
declare(strict_types=1);
// Serves the site logo. Public on purpose: it appears on the sign-in page.
require_once __DIR__ . '/private/db.php';

$row = null;
try {
    $row = db()->query('SELECT mime, data, UNIX_TIMESTAMP(updated_at) AS t FROM site_logo WHERE id = 1')->fetch();
} catch (Throwable $e) {
    $row = null;
}
if (!$row) { http_response_code(404); exit; }

$bytes = $row['data'];
if (is_resource($bytes)) $bytes = stream_get_contents($bytes);

header('Content-Type: ' . ($row['mime'] ?: 'image/png'));
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
// The URL carries ?v=<timestamp>, so a new logo gets a new URL and can be cached hard.
header('Cache-Control: public, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$row['t']) . ' GMT');
echo $bytes;
