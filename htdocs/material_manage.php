<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: materials.php'); exit; }

// A file bigger than post_max_size arrives as an empty POST (token included).
if (request_exceeded_post_limit()) {
    $_SESSION['mat_msg'] = ['err', 'That file is larger than this server allows (' . format_bytes(server_post_limit()) . '). Share it as a link instead.'];
    $fromAdmin = str_contains((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH), '/admin/');
    header('Location: ' . ($fromAdmin ? 'admin/materials.php' : 'materials.php')); exit;
}
csrf_verify();

$back = (string)($_POST['back'] ?? 'materials.php');
$okBack = (str_starts_with($back, 'materials.php') || str_starts_with($back, 'admin/materials.php'))
    && !str_contains($back, '://') && !preg_match('/[\r\n]/', $back);
if (!$okBack) $back = 'materials.php';

$flash = function (string $type, string $text) use ($back) {
    $_SESSION['mat_msg'] = [$type, $text];
    header('Location: ' . $back);
    exit;
};

$do = (string)($_POST['do'] ?? '');

if (in_array($do, ['add_file', 'add_link'], true)) {
    $section = (string)($_POST['section'] ?? '');
    $subject = (string)($_POST['subject'] ?? '');
    $actor = material_actor($section);
    if (!$actor) { http_response_code(403); exit('Not allowed.'); }
    $codes = array_map(fn($x) => $x['code'], section_subjects($section));
    if (!in_array($subject, $codes, true)) $flash('err', 'Unknown subject.');

    try {
        $err = $do === 'add_file'
            ? add_material_file($section, $subject, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), $_FILES['file'] ?? [], $actor)
            : add_material_link($section, $subject, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), (string)($_POST['url'] ?? ''), $actor);
    } catch (Throwable $e) {
        error_log('Echo material add failed: ' . $e->getMessage());
        $err = 'Something went wrong on the server. Please try again.';
    }
    $flash($err ? 'err' : 'ok', $err ?: 'Added.');
}

if ($do === 'delete') {
    $m = get_material((int)($_POST['id'] ?? 0));
    if (!$m) $flash('err', 'That item no longer exists.');
    $actor = material_actor((string)$m['section']);
    if (!$actor) { http_response_code(403); exit('Not allowed.'); }
    delete_material($m, $actor);
    $flash('ok', 'Deleted. The deletion is recorded in the log.');
}

if ($do === 'request_done' || $do === 'request_dismiss') {
    $r = get_material_request((int)($_POST['id'] ?? 0));
    if (!$r) $flash('err', 'That request no longer exists.');
    $actor = material_actor((string)$r['section']);
    if (!$actor) { http_response_code(403); exit('Not allowed.'); }
    set_material_request_status((int)$r['id'], $do === 'request_done' ? 'done' : 'dismissed');
    log_material_event($do === 'request_done' ? 'request_done' : 'request_dismiss', null, mb_substr((string)$r['message'], 0, 150), (string)$r['section'], (string)$r['subject'], $actor);
    $flash('ok', $do === 'request_done' ? 'Marked as done.' : 'Request dismissed.');
}

$flash('err', 'Nothing to do.');
