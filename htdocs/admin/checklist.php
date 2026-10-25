<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$cfg = app_config();
$checks = [];   // [status ok|warn|bad, title, detail]
$add = function (string $status, string $title, string $detail = '') use (&$checks) { $checks[] = [$status, $title, $detail]; };

// ---- core ----
$add(version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'bad', 'PHP version', PHP_VERSION);
try {
    $v = db()->query("SELECT v FROM app_meta WHERE k = 'schema_version'")->fetchColumn();
    $add((int)$v >= SCHEMA_VERSION ? 'ok' : 'bad', 'Database up to date', 'Schema ' . (int)$v . ' of ' . SCHEMA_VERSION . ((int)$v < SCHEMA_VERSION ? ' — open any page once to update it.' : ''));
} catch (Throwable $e) {
    $add('bad', 'Database connection', 'Could not read the database.');
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$add($https ? 'ok' : 'bad', 'HTTPS', $https ? 'This page was loaded securely.' : 'You are on plain HTTP. Logins can be seen by others on the network.');
$url = (string)($cfg['app_url'] ?? '');
$add(($url !== '' && !str_contains($url, 'yoursite.example')) ? 'ok' : 'bad', 'Site address (app_url)', $url !== '' ? $url : 'Not set — reset links need it.');

// ---- data ----
$dir = (string)$cfg['attendance_data_dir'];
$files = list_attendance_section_files();
$add($files ? 'ok' : 'bad', 'Attendance files', $files ? count($files) . ' section file(s) found.' : 'No section files in the data folder yet. Run the GitHub Action.');
$st = get_scrape_status();
if (!$st) {
    $add('warn', 'Last sync', 'No _scrape_status.json yet.');
} else {
    try {
        $when = new DateTime(trim(str_replace('IST', '', (string)($st['last_run'] ?? ''))), new DateTimeZone('Asia/Kolkata'));
        $hours = round((time() - $when->getTimestamp()) / 3600, 1);
    } catch (Throwable $e) {
        $hours = null;
    }
    $failed = (array)($st['failed'] ?? []);
    $add(($hours !== null && $hours < 26) ? 'ok' : 'warn', 'Last sync', ($st['last_run'] ?? 'unknown') . ($hours !== null ? " (~{$hours}h ago)" : ''));
    $add(!$failed ? 'ok' : 'warn', 'Sections in last sync', !$failed ? 'All updated.' : 'Failed: ' . implode(', ', $failed));
}
$sections = list_sections();
$noTT = [];
foreach ($sections as $code => $info) if (!get_timetable($code)) $noTT[] = $code;
$add(!$noTT ? 'ok' : 'warn', 'Timetables', !$noTT ? 'Every section has one.' : 'Missing for: ' . implode(', ', $noTT) . ' (Today\'s classes and the skip predictor need them).');

// ---- folders that need to be writable ----
foreach (['sessions' => 'Sessions (stay signed in)', 'backups' => 'Automatic backups', 'materials' => 'Study material uploads'] as $folder => $label) {
    $path = __DIR__ . '/../private/' . $folder;
    if (!is_dir($path)) @mkdir($path, 0700, true);
    $add(is_dir($path) && is_writable($path) ? 'ok' : 'warn', $label, is_dir($path) && is_writable($path) ? 'Writable.' : 'The server won\'t let Echo write to private/' . $folder . '. ' . ($folder === 'materials' ? 'Use links instead of uploads.' : ''));
}
$add(extension_loaded('gd') ? 'ok' : 'warn', 'Image processing (GD)', extension_loaded('gd') ? 'Photos and the logo can be uploaded.' : 'GD is off — photo and logo uploads won\'t work.');
$eff = server_upload_limit();
$add($eff >= MATERIAL_MAX_BYTES ? 'ok' : 'warn', 'Largest upload', format_bytes($eff) . ' for study material'
    . ($eff >= MATERIAL_MAX_BYTES ? '.' : ' — this server caps uploads below the ' . format_bytes(MATERIAL_MAX_BYTES) . ' Echo allows (upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Bigger files should be added as links; on cPanel you can raise it under “MultiPHP INI Editor”.'));

// ---- security / legitimacy ----
$webroot = realpath(__DIR__ . '/..');
$exposed = [];
foreach (['.git', '.env', '.github'] as $name) if ($webroot && file_exists($webroot . '/' . $name)) $exposed[] = $name;
$add(!$exposed ? 'ok' : 'bad', 'Hidden folders in the site root', !$exposed ? 'None found.' : 'Found: ' . implode(', ', $exposed) . ' — delete them from the host (the site blocks access, but they shouldn\'t be there).');
$add(is_file(__DIR__ . '/../private/.htaccess') ? 'ok' : 'bad', 'private/ folder protected', is_file(__DIR__ . '/../private/.htaccess') ? '.htaccess in place.' : 'private/.htaccess is missing.');
$add(is_file(__DIR__ . '/../.htaccess') ? 'ok' : 'warn', 'Site .htaccess', is_file(__DIR__ . '/../.htaccess') ? 'Present (HTTPS redirect, security headers).' : 'Missing.');
$owner = site_owner();
$add($owner !== '' ? 'ok' : 'warn', 'Site owner on About/Privacy pages', $owner !== '' ? $owner : 'Still the default. Set it in Settings — a real name or group name looks more legitimate.');
$add(contact_email() !== '' ? 'ok' : 'warn', 'Contact email', contact_email() ?: 'Not set (optional, but helps trust). Set it in Settings.');
$add(friends_site_url() !== '' ? 'ok' : 'warn', 'Friends site button', friends_site_url() ?: 'Not set — the button is hidden. Set it in Settings.');
// ---- are the private folders really closed from the web? (a live test, not just "file exists") ----
$base = rtrim(app_url(), '/');
$probeTargets = ['private' => 'private', 'private/materials' => 'study-material folder', 'private/backups' => 'backups folder', 'data' => 'data folder'];
$probeResults = [];
foreach ($probeTargets as $rel => $label) {
    $dirPath = __DIR__ . '/../' . $rel;
    if (!is_dir($dirPath) && !@mkdir($dirPath, 0700, true)) continue;
    $marker = $dirPath . '/probe-check.txt';
    if (!is_file($marker)) @file_put_contents($marker, 'echo-probe-' . bin2hex(random_bytes(4)));
    $expect = (string)@file_get_contents($marker);
    if ($expect === '') continue;
    $r = http_probe($base . '/' . $rel . '/probe-check.txt');
    if ($r === null) $probeResults[$label] = ['unknown', $base . '/' . $rel . '/probe-check.txt'];
    else $probeResults[$label] = [str_contains($r['body'], $expect) ? 'open' : 'closed', $base . '/' . $rel . '/probe-check.txt'];
}
$open = array_keys(array_filter($probeResults, fn($x) => $x[0] === 'open'));
$unknown = array_keys(array_filter($probeResults, fn($x) => $x[0] === 'unknown'));
if ($open) {
    $add('bad', 'Private folders reachable from the internet', 'Anyone can fetch files from: ' . implode(', ', $open) . '. The host is ignoring .htaccess — move to a host that honours it, or tell me and I will switch uploads to the database.');
} elseif ($probeResults && !$unknown) {
    $add('ok', 'Private folders really are blocked', 'Tested live: files in ' . implode(', ', array_keys($probeResults)) . ' cannot be fetched over the web.');
} elseif ($probeResults) {
    $links = implode(' · ', array_map(fn($k) => $k . ': ' . $probeResults[$k][1], $unknown));
    $add('warn', 'Private folders — live test not possible', 'This server cannot call itself to test. Open each link in your browser; you should see “Forbidden” or “Not found”, never the text “echo-probe”. ' . $links);
}
$pc = push_capability();
$add($pc['ok'] ? 'ok' : 'warn', 'Notifications (push)', $pc['ok'] ? push_counts()['students'] . ' student(s) have them on. Details under Notifications.' : 'This server cannot send them: ' . implode('; ', $pc['problems']) . '.');
$backup = latest_backup_info();
$add($backup ? 'ok' : 'warn', 'Automatic backup', $backup ? 'Latest: ' . date('j M, g:i A', $backup['time']) : 'None yet — it runs when you open the admin dashboard.');

$counts = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($checks as $c) $counts[$c[0]]++;
$icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'];
?>
<?php admin_header('Launch checklist', 'checklist'); ?>
  <h1>Launch checklist</h1>
  <p class="sub"><?= $counts['ok'] ?> good · <?= $counts['warn'] ?> to look at · <?= $counts['bad'] ?> need fixing. Refresh the page after changing something.</p>
  <div class="card" style="padding:0 20px;">
    <?php foreach ($checks as [$status, $title, $detail]): ?>
      <div class="chk-row">
        <span class="chk <?= $status ?>"><?= $icon[$status] ?></span>
        <div class="mat-main"><div class="mat-title"><?= esc($title) ?></div><div class="mat-meta"><?= esc($detail) ?></div></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php admin_footer(); ?>
