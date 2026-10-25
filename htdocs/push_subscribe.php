<?php
declare(strict_types=1);
// Called by assets/push.js to save, change or remove this device's notification subscription. Answers JSON.
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
// Anything PHP prints by accident (a warning, a notice) must never corrupt the JSON the page expects.
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (int $code, array $data) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); }
    echo json_encode($data); exit;
};
set_exception_handler(function (Throwable $e) use ($out) {
    error_log('push_subscribe: ' . $e->getMessage());
    $out(500, ['ok' => false, 'error' => 'Server problem: ' . substr($e->getMessage(), 0, 160)]);
});
register_shutdown_function(function () use ($out) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $out(500, ['ok' => false, 'error' => 'Server problem: ' . substr($e['message'], 0, 160)]);
    }
});

$student = current_student();
if (!$student) $out(401, ['ok' => false, 'error' => 'Please sign in again.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'error' => 'POST only.']);
if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) $out(403, ['ok' => false, 'error' => 'This page timed out. Reload it and try again.']);
if (!rate_limit('push:' . (int)$student['id'], 40, 3600)) $out(429, ['ok' => false, 'error' => 'Too many attempts. Try again later.']);
$cap = push_capability();
if (!$cap['ok']) $out(503, ['ok' => false, 'error' => 'This server cannot send notifications.']);

$action = (string)($_POST['action'] ?? '');
$endpoint = trim((string)($_POST['endpoint'] ?? ''));
if (!push_endpoint_allowed($endpoint)) $out(400, ['ok' => false, 'error' => 'That browser\'s notification service is not supported.']);
$hash = hash('sha256', $endpoint);
$sid = (int)$student['id'];
$ann = !empty($_POST['ann']) ? 1 : 0;
$daily = !empty($_POST['daily']) ? 1 : 0;

if ($action === 'unsubscribe') {
    db()->prepare('DELETE FROM push_subs WHERE endpoint_hash = ? AND student_id = ?')->execute([$hash, $sid]);
    $out(200, ['ok' => true]);
}

if ($action === 'subscribe' || $action === 'prefs') {
    $p256 = (string)($_POST['p256dh'] ?? ''); $auth = (string)($_POST['auth'] ?? '');
    if ($action === 'subscribe') {
        if (strlen(b64u_decode($p256)) !== 65 || strlen(b64u_decode($auth)) < 8 || strlen($p256) > 120 || strlen($auth) > 60) $out(400, ['ok' => false, 'error' => 'The subscription looks invalid.']);
        $ex = db()->prepare('SELECT id FROM push_subs WHERE endpoint_hash = ?'); $ex->execute([$hash]);
        $id = $ex->fetchColumn();
        if ($id) {
            db()->prepare('UPDATE push_subs SET student_id = ?, p256dh = ?, auth = ?, ann = ?, daily = ? WHERE id = ?')->execute([$sid, $p256, $auth, $ann, $daily, $id]);
        } else {
            db()->prepare('INSERT INTO push_subs (student_id, endpoint_hash, endpoint, p256dh, auth, ann, daily) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$sid, $hash, $endpoint, $p256, $auth, $ann, $daily]);
            $id = (int)db()->lastInsertId();
        }
        // A first notification proves the whole chain works, and tells the student it did.
        $row = db()->prepare('SELECT * FROM push_subs WHERE id = ?'); $row->execute([$id]);
        $r = push_send_rows($row->fetchAll(), push_payload('Notifications are on', 'You\'ll get announcements and a morning heads-up here.', 'profile.php', 'welcome'), 600);
        if ($r['sent'] < 1) {
            db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$id]);
            $out(502, ['ok' => false, 'error' => 'Your browser accepted it, but the server could not reach the notification service' . ($r['errors'] ? ' (' . $r['errors'][0] . ')' : '') . '. Please tell the admin.']);
        }
        $out(200, ['ok' => true]);
    }
    db()->prepare('UPDATE push_subs SET ann = ?, daily = ? WHERE endpoint_hash = ? AND student_id = ?')->execute([$ann, $daily, $hash, $sid]);
    $out(200, ['ok' => true]);
}
$out(400, ['ok' => false, 'error' => 'Unknown action.']);
