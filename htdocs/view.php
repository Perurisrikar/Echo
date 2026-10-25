<?php
declare(strict_types=1);
// In-site viewer for a study-material file: PDFs, images, text and Word files open right here;
// everything else gets an "Open in app" button that hands the file straight to the phone's own app.
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';

start_secure_session();
$m = get_material((int)($_GET['id'] ?? 0));
if (!$m) { http_response_code(404); exit('Not found.'); }

$isAdmin = (bool)current_admin();
$student = $isAdmin ? null : current_student();
if (!$isAdmin && !$student) { header('Location: index.php'); exit; }
if (!$isAdmin && section_of_student($student) !== $m['section']) { http_response_code(403); exit('Not allowed.'); }

if ($m['kind'] === 'link') {
    $url = (string)$m['url'];
    if (!preg_match('~^https?://~i', $url)) { http_response_code(400); exit; }
    header('Location: ' . $url);
    exit;
}

$ext = strtolower(pathinfo((string)$m['orig_name'], PATHINFO_EXTENSION));
$mime = MATERIAL_TYPES[$ext] ?? 'application/octet-stream';
$mode = match ($ext) {
    'pdf' => 'pdf',
    'png', 'jpg', 'jpeg' => 'image',
    'txt' => 'text',
    'docx' => 'docx',
    default => 'other',
};
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)$m['orig_name']) ?: 'file';
$fileUrl = 'material_download.php?id=' . (int)$m['id'];
$back = $isAdmin ? 'admin/materials.php' : 'materials.php';
$title = (string)$m['title'];
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= esc($title) ?> — Echo</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="viewer-page">
<header class="viewer-bar">
  <a class="viewer-back" href="<?= esc($back) ?>" id="viewerBack" aria-label="Back">‹ <span>Back</span></a>
  <div class="viewer-title" title="<?= esc($title) ?>"><?= esc($title) ?></div>
</header>
<div class="viewer-actionbar">
  <button type="button" class="vbtn ghost" id="shareBtn" disabled hidden>Open in…</button>
  <a class="vbtn ghost" href="<?= esc($fileUrl) ?>&amp;dl=1" id="dlBtn">Download</a>
  <div class="viewer-zoom" id="pdfTools" hidden>
    <button type="button" class="vbtn ghost sq" id="zoomOut" aria-label="Zoom out">−</button>
    <span id="pageInfo" aria-live="polite">Loading…</span>
    <button type="button" class="vbtn ghost sq" id="zoomIn" aria-label="Zoom in">+</button>
  </div>
</div>

<main class="viewer-main" id="viewer"
      data-mode="<?= esc($mode) ?>" data-url="<?= esc($fileUrl) ?>" data-name="<?= esc($name) ?>" data-mime="<?= esc($mime) ?>"
      data-size="<?= (int)$m['size'] ?>" data-id="<?= (int)$m['id'] ?>" data-ext="<?= esc(strtoupper($ext)) ?>" data-back="<?= esc($back) ?>">
  <div class="viewer-status" id="viewerStatus">Opening…</div>
  <noscript><p class="viewer-status">Turn on JavaScript to preview this file, or use Download.</p></noscript>
</main>

<script src="assets/viewer.js" defer></script>
</body>
</html>
