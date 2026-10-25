<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';

session_cache_limiter(''); // we set our own caching headers below
start_secure_session();
$viewer = current_student();
$viewerIsAdmin = current_admin() !== null;
session_write_close();    // don't hold the session lock while streaming an image

if (!$viewer && !$viewerIsAdmin) { http_response_code(403); exit; }

$roll = strtoupper(trim((string)($_GET['roll'] ?? '')));
if (!preg_match('/^[A-Z0-9]{4,20}$/', $roll)) { http_response_code(404); exit; }

$stmt = db()->prepare('SELECT id, photo_blob, photo_mime, photo_updated_at FROM students WHERE roll_no = ?');
$stmt->execute([$roll]);
$row = $stmt->fetch();

if (!$row || $row['photo_blob'] === null) { http_response_code(404); exit; }

// Only the owner themself, or the admin, may see a real photo.
$isOwner = $viewer && (int)$viewer['id'] === (int)$row['id'];
if (!$isOwner && !$viewerIsAdmin) { http_response_code(403); exit; }

$bytes = $row['photo_blob'];
if (is_resource($bytes)) $bytes = stream_get_contents($bytes);

header('Content-Type: ' . ($row['photo_mime'] ?: 'image/jpeg'));
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=31536000, immutable');
if ($row['photo_updated_at']) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($row['photo_updated_at'])) . ' GMT');
}
echo $bytes;
