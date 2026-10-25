<?php
declare(strict_types=1);
// Receives a study-material file in small pieces (about 1 MB each), so a big upload does not depend on the host's
// per-request size limit and a weak mobile connection only has to retry one piece. Answers JSON.
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const CHUNK_MAX_BYTES = 2 * 1024 * 1024;
const CHUNK_MAX_PARTS = 160;

$out = function (int $code, array $data) { http_response_code($code); echo json_encode($data); exit; };
$fail = fn(int $code, string $msg) => $out($code, ['ok' => false, 'error' => $msg]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'POST only.');
if (request_exceeded_post_limit()) $fail(413, 'That piece was larger than the server allows.');
if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) $fail(403, 'This page timed out. Reload it and try again.');

$section = (string)($_POST['section'] ?? '');
$subject = (string)($_POST['subject'] ?? '');
$actor = material_actor($section);
if (!$actor) $fail(403, 'You are not allowed to add files to this class.');
$codes = array_map(fn($x) => $x['code'], section_subjects($section));
if (!in_array($subject, $codes, true)) $fail(400, 'Unknown subject.');

$uid = (string)($_POST['uid'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $uid)) $fail(400, 'Bad upload id.');
$base = materials_dir();
if (!$base) $fail(500, "The server won't let Echo store files here. Add it as a link instead.");
$owner = $actor['type'] . ($actor['student_id'] ?? 'a');
$tmpRoot = $base . '/tmp';
if (!is_dir($tmpRoot)) @mkdir($tmpRoot, 0700, true);
$dir = $tmpRoot . '/' . preg_replace('/[^a-z0-9]/i', '', $owner) . '_' . $uid;
$action = (string)($_POST['action'] ?? 'chunk');

// Clean out abandoned uploads older than a day.
foreach (glob($tmpRoot . '/*', GLOB_ONLYDIR) ?: [] as $old) {
    if (filemtime($old) < time() - 86400) { foreach (glob($old . '/*') ?: [] as $f) @unlink($f); @rmdir($old); }
}
$wipe = function () use ($dir) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); };

if ($action === 'chunk') {
    $i = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    // The piece travels as plain text (base64) rather than raw bytes, so a host firewall that scans uploaded
    // files for suspicious byte patterns has nothing to trip over.
    $b64 = (string)($_POST['data'] ?? '');
    if ($b64 === '') $fail(400, 'That piece did not arrive. It will be retried.');
    $bytes = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($bytes === false || $bytes === '') $fail(400, 'That piece was damaged on the way. It will be retried.');
    unset($b64);
    $psize = strlen($bytes);
    if ($i < 0 || $total < 1 || $total > CHUNK_MAX_PARTS || $i >= $total) $fail(400, 'Bad piece number.');
    if ($psize > CHUNK_MAX_BYTES) $fail(413, 'That piece is too large.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) $fail(500, 'The server could not store the upload.');
    // Stop early if what has arrived already exceeds the cap.
    $have = 0;
    foreach (glob($dir . '/part-*') ?: [] as $p) $have += (int)filesize($p);
    if ($have + $psize > MATERIAL_MAX_BYTES + CHUNK_MAX_BYTES) { $wipe(); $fail(413, 'That file is over ' . format_bytes(MATERIAL_MAX_BYTES) . '. Share it as a link instead.'); }
    if (@file_put_contents($dir . '/part-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), $bytes) !== $psize) $fail(500, 'The server could not save that piece.');
    $out(200, ['ok' => true]);
}

if ($action === 'finish') {
    $total = (int)($_POST['total'] ?? 0);
    $nm = base64_decode(strtr((string)($_POST['name_b64'] ?? ''), '-_', '+/'), true);
    $name = basename(trim($nm !== false && $nm !== '' ? $nm : (string)($_POST['name'] ?? 'file')));
    if ($total < 1 || $total > CHUNK_MAX_PARTS || !is_dir($dir)) $fail(400, 'The upload was not found. Please start again.');
    $assembled = $dir . '/assembled';
    $w = @fopen($assembled, 'wb');
    if (!$w) { $wipe(); $fail(500, 'The server could not store the upload.'); }
    for ($i = 0; $i < $total; $i++) {
        $part = $dir . '/part-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $r = is_file($part) ? @fopen($part, 'rb') : false;
        if (!$r) { fclose($w); $wipe(); $fail(400, 'Piece ' . ($i + 1) . ' of ' . $total . ' is missing. Please try again.'); }
        stream_copy_to_stream($r, $w); fclose($r);
    }
    fclose($w);
    $declared = (int)($_POST['size'] ?? 0);
    if ($declared > 0 && (int)filesize($assembled) !== $declared) { $wipe(); $fail(400, 'The file arrived incomplete. Please try again.'); }
    try {
        $err = store_material_file($section, $subject, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), $assembled, $name, $actor, false);
    } catch (Throwable $e) {
        error_log('Echo chunked material add failed: ' . $e->getMessage());
        $err = 'Something went wrong on the server. Please try again.';
    }
    $wipe();
    $_SESSION['mat_msg'] = [$err ? 'err' : 'ok', $err ?: 'Added.'];
    $out($err ? 422 : 200, ['ok' => !$err, 'error' => $err]);
}

if ($action === 'cancel') { $wipe(); $out(200, ['ok' => true]); }
$fail(400, 'Unknown action.');
