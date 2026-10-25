<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';

session_cache_limiter('');
start_secure_session();

$m = get_material((int)($_GET['id'] ?? 0));
if (!$m) { http_response_code(404); exit('Not found.'); }

// Only the section's own students and admins may fetch a file.
$allowed = false;
if (current_admin()) {
    $allowed = true;
} else {
    $s = current_student();
    $allowed = $s && section_of_student($s) === $m['section'];
}
session_write_close();
if (!$allowed) { http_response_code(403); exit('Not allowed.'); }

if ($m['kind'] === 'link') {
    $url = (string)$m['url'];
    if (!preg_match('~^https?://~i', $url)) { http_response_code(400); exit; }
    header('Location: ' . $url);
    exit;
}

$dir = materials_dir();
$path = $dir ? $dir . '/' . basename((string)$m['stored_name']) : '';
if (!$dir || !is_file($path)) { http_response_code(404); exit('This file is no longer available.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$inline = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'txt'], true) && empty($_GET['dl']);
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)$m['orig_name']) ?: 'file';

header('Content-Type: ' . (MATERIAL_TYPES[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
// Chrome's built-in PDF viewer refuses to open a PDF served with a sandbox policy, and a PDF cannot run page script
// in our origin anyway, so the lock-down policy is for every other type.
if ($ext !== 'pdf') header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
header('Cache-Control: private, max-age=3600');
readfile($path);
