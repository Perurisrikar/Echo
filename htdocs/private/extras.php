<?php
declare(strict_types=1);
/**
 * Round-3 features: personal threshold, exam periods, device sessions,
 * reset requests, and study materials. Plain functions only — safe to
 * include from anywhere; nothing runs on load.
 */

// ---------------------------------------------------------------------
// Personal warning level
// ---------------------------------------------------------------------

const THRESHOLD_MIN = 60;
const THRESHOLD_MAX = 95;

/** The student's own warning line (default 75). Official eligibility is still 75. */
function student_threshold(array $student): float
{
    $t = (int)($student['my_threshold'] ?? 0);
    return ($t >= THRESHOLD_MIN && $t <= THRESHOLD_MAX) ? (float)$t : 75.0;
}

function save_student_threshold(int $studentId, ?int $value): void
{
    if ($value !== null && ($value < THRESHOLD_MIN || $value > THRESHOLD_MAX)) $value = null;
    if ($value === 75) $value = null;
    db()->prepare('UPDATE students SET my_threshold = ? WHERE id = ?')->execute([$value, $studentId]);
}

// ---------------------------------------------------------------------
// "All safe since" badge
// ---------------------------------------------------------------------

/** Date (Y-m-d) the current unbroken run of all-subjects-safe days began, or null if not safe today. Counts days the student opened Echo. */
function safe_since(string $roll): ?string
{
    try {
        $stmt = db()->prepare('SELECT d, safe FROM attendance_history WHERE roll_no = ? ORDER BY d DESC LIMIT 90');
        $stmt->execute([$roll]);
        $since = null;
        foreach ($stmt->fetchAll() as $r) {
            if ((int)$r['safe'] !== 1) break;
            $since = (string)$r['d'];
        }
        return $since;
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------
// Exam periods
// ---------------------------------------------------------------------

function list_exam_periods(): array
{
    try {
        return db()->query('SELECT id, label, start_date, end_date FROM exam_periods ORDER BY start_date DESC LIMIT 50')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** The exam period happening now, or starting within 14 days. */
function active_exam_period(): ?array
{
    try {
        $row = db()->query('SELECT label, start_date, end_date FROM exam_periods
                            WHERE CURDATE() <= end_date AND CURDATE() >= DATE_SUB(start_date, INTERVAL 14 DAY)
                            ORDER BY start_date ASC LIMIT 1')->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function add_exam_period(string $label, string $start, string $end): bool
{
    $label = trim(mb_substr($label, 0, 80));
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if ($label === '' || !$ok($start) || !$ok($end) || $end < $start) return false;
    db()->prepare('INSERT INTO exam_periods (label, start_date, end_date) VALUES (?, ?, ?)')->execute([$label, $start, $end]);
    return true;
}

function delete_exam_period(int $id): void
{
    db()->prepare('DELETE FROM exam_periods WHERE id = ?')->execute([$id]);
}

// ---------------------------------------------------------------------
// Device sessions ("sign out everywhere")
// ---------------------------------------------------------------------

function current_sid_hash(): string
{
    return hash('sha256', session_id());
}

function register_session(int $studentId): void
{
    try {
        db()->prepare('INSERT INTO user_sessions (sid_hash, student_id, ua, ip) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), last_seen = NOW()')
            ->execute([current_sid_hash(), $studentId, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $_SESSION['tracked'] = 1;
        // keep the table tidy: forget sessions unused for 30 days
        db()->exec('DELETE FROM user_sessions WHERE last_seen < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    } catch (Throwable $e) {
        unset($_SESSION['tracked']);
    }
}

/** False only when this device's session was explicitly revoked. Also refreshes "last seen" every few minutes. */
function session_still_valid(int $studentId): bool
{
    try {
        $stmt = db()->prepare('SELECT 1 FROM user_sessions WHERE sid_hash = ? AND student_id = ?');
        $stmt->execute([current_sid_hash(), $studentId]);
        if (!$stmt->fetchColumn()) return false;
        db()->prepare('UPDATE user_sessions SET last_seen = NOW() WHERE sid_hash = ? AND last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
            ->execute([current_sid_hash()]);
        return true;
    } catch (Throwable $e) {
        return true; // never lock someone out because tracking itself failed
    }
}

function list_sessions(int $studentId): array
{
    try {
        $stmt = db()->prepare('SELECT sid_hash, ua, ip, created_at, last_seen FROM user_sessions WHERE student_id = ? ORDER BY last_seen DESC');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function revoke_session(int $studentId, string $hash): void
{
    db()->prepare('DELETE FROM user_sessions WHERE student_id = ? AND sid_hash = ?')->execute([$studentId, $hash]);
}

function revoke_other_sessions(int $studentId): void
{
    db()->prepare('DELETE FROM user_sessions WHERE student_id = ? AND sid_hash <> ?')->execute([$studentId, current_sid_hash()]);
}

/** "Chrome on Android" style label from a user-agent string. */
function describe_device(string $ua): string
{
    $os = 'Unknown device';
    foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $os = $name; break; }
    }
    $browser = 'Browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    return $browser . ' on ' . $os;
}

// ---------------------------------------------------------------------
// Password-reset requests (student asks; CR/admin sends the link)
// ---------------------------------------------------------------------

/** Records a request for this roll number. Quiet no-op for unknown rolls or repeats within an hour. */
function create_reset_request(string $roll): void
{
    try {
        $stmt = db()->prepare('SELECT id FROM students WHERE roll_no = ?');
        $stmt->execute([strtoupper(trim($roll))]);
        $id = $stmt->fetchColumn();
        if (!$id) return;
        $recent = db()->prepare('SELECT 1 FROM reset_requests WHERE student_id = ? AND handled_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $recent->execute([$id]);
        if ($recent->fetchColumn()) return;
        db()->prepare('INSERT INTO reset_requests (student_id) VALUES (?)')->execute([$id]);
    } catch (Throwable $e) {
    }
}

/** Open requests from the last 7 days; optionally limited to one class (dept+sec). */
function open_reset_requests(?string $dept = null, ?string $sec = null): array
{
    try {
        $sql = 'SELECT rr.id, rr.created_at, s.id AS student_id, s.roll_no, s.name, s.nickname, s.mobile, s.dept, s.sec
                FROM reset_requests rr JOIN students s ON s.id = rr.student_id
                WHERE rr.handled_at IS NULL AND rr.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $args = [];
        if ($dept !== null) { $sql .= ' AND s.dept = ? AND s.sec = ?'; $args = [$dept, (string)$sec]; }
        $stmt = db()->prepare($sql . ' ORDER BY rr.created_at DESC LIMIT 100');
        $stmt->execute($args);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function mark_reset_requests_handled(int $studentId): void
{
    try {
        db()->prepare('UPDATE reset_requests SET handled_at = NOW() WHERE student_id = ? AND handled_at IS NULL')->execute([$studentId]);
    } catch (Throwable $e) {
    }
}

// ---------------------------------------------------------------------
// Study materials
// ---------------------------------------------------------------------

const MATERIAL_MAX_BYTES = 50 * 1024 * 1024;
const MATERIAL_TYPES = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt'  => 'text/plain',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
];

function materials_dir(): ?string
{
    $dir = __DIR__ . '/materials';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
    if (!is_writable($dir)) return null;
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    return $dir;
}

/** Attendance section code (e.g. CSM_D) for a student, or null. */
function section_of_student(array $student): ?string
{
    $att = get_attendance((string)($student['roll_no'] ?? ''));
    $sec = (string)($att['section'] ?? '');
    return $sec !== '' ? $sec : null;
}

/** Subjects of a section: [[code,label], ...] from the scraped data. */
function section_subjects(string $section): array
{
    return list_sections()[$section]['subjects'] ?? [];
}

/** Who may add/delete materials for $section: any admin, or the CR of that section. */
function material_actor(string $section): ?array
{
    if (function_exists('current_admin') && current_admin()) {
        $a = current_admin();
        return ['type' => 'admin', 'student_id' => null, 'label' => 'Admin ' . (string)($a['username'] ?? '')];
    }
    $s = function_exists('current_student') ? current_student() : null;
    if ($s && is_cr($s) && section_of_student($s) === $section) {
        return ['type' => 'cr', 'student_id' => (int)$s['id'], 'label' => 'CR ' . (string)$s['roll_no']];
    }
    return null;
}

function log_material_event(string $action, ?int $materialId, string $title, string $section, string $subject, array $actor, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO material_log (action, material_id, title, section, subject, detail, actor_type, actor_label, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$action, $materialId, mb_substr($title, 0, 150), $section, $subject, mb_substr($detail, 0, 255),
                       $actor['type'], mb_substr($actor['label'], 0, 80), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('Echo material log failed: ' . $e->getMessage());
    }
}

function recent_material_log(int $limit = 100): array
{
    try {
        return db()->query('SELECT action, title, section, subject, detail, actor_label, ip, at FROM material_log ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** subject code => number of resources, for one section. */
function material_counts(string $section): array
{
    try {
        $stmt = db()->prepare('SELECT subject, COUNT(*) AS n FROM materials WHERE section = ? GROUP BY subject');
        $stmt->execute([$section]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[(string)$r['subject']] = (int)$r['n'];
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function list_materials(string $section, string $subject): array
{
    $stmt = db()->prepare('SELECT id, title, description, kind, orig_name, mime, size, url, uploader_label, created_at FROM materials WHERE section = ? AND subject = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$section, $subject]);
    return $stmt->fetchAll();
}

function get_material(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM materials WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function format_bytes(int $n): string
{
    if ($n >= 1048576) return number_format($n / 1048576, 1) . ' MB';
    if ($n >= 1024) return number_format($n / 1024, 0) . ' KB';
    return $n . ' B';
}

/** Adds an uploaded file. Returns an error message, or null on success. */
function add_material_file(string $section, string $subject, string $title, string $desc, array $file, array $actor): ?string
{
    if ($m = upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE), 'file', 'Share it as a link (for example Google Drive) instead.')) return $m;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return 'That file is empty.';
    if ($size > MATERIAL_MAX_BYTES) return 'That file is over ' . format_bytes(MATERIAL_MAX_BYTES) . '. Share it as a link instead.';
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not complete. Please try again.';
    return store_material_file($section, $subject, $title, $desc, (string)$file['tmp_name'], basename((string)($file['name'] ?? 'file')), $actor, true);
}

/**
 * Checks a file that is already on the server (a normal upload, or one assembled from chunks), moves it into the
 * private materials folder under a random name and records it. Returns an error message, or null on success.
 */
function store_material_file(string $section, string $subject, string $title, string $desc, string $path, string $orig, array $actor, bool $isPhpUpload): ?string
{
    $size = (int)@filesize($path);
    if ($size <= 0) return 'That file is empty.';
    if ($size > MATERIAL_MAX_BYTES) return 'That file is over ' . format_bytes(MATERIAL_MAX_BYTES) . '. Share it as a link instead.';

    $orig = basename($orig);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset(MATERIAL_TYPES[$ext])) return 'Allowed types: ' . strtoupper(implode(', ', array_keys(MATERIAL_TYPES))) . '.';

    // Check the file's real first bytes, not just its name.
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    $okMagic = match ($ext) {
        'pdf'                  => str_starts_with($head, '%PDF'),
        'docx', 'pptx', 'xlsx' => str_starts_with($head, "PK"),
        'doc', 'ppt', 'xls'    => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        'png'                  => str_starts_with($head, "\x89PNG"),
        'jpg', 'jpeg'          => str_starts_with($head, "\xFF\xD8"),
        default                => true,
    };
    if (!$okMagic) return "That file doesn't look like a real ." . strtoupper($ext) . ' file.';

    $dir = materials_dir();
    if (!$dir) return "The server won't let Echo store files here. Add it as a link instead.";
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $isPhpUpload ? @move_uploaded_file($path, $dir . '/' . $stored) : (@rename($path, $dir . '/' . $stored) || (@copy($path, $dir . '/' . $stored) && @unlink($path)));
    if (!$moved) return 'The file could not be saved. Please try again.';

    $title = trim(mb_substr($title, 0, 150));
    if ($title === '') $title = pathinfo($orig, PATHINFO_FILENAME);
    $desc = trim(mb_substr($desc, 0, 300));
    db()->prepare('INSERT INTO materials (section, subject, title, description, kind, stored_name, orig_name, mime, size, uploader_type, uploader_student_id, uploader_label)
                   VALUES (?, ?, ?, ?, \'file\', ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$section, $subject, $title, $desc, $stored, mb_substr($orig, 0, 200), MATERIAL_TYPES[$ext], $size, $actor['type'], $actor['student_id'], $actor['label']]);
    $id = (int)db()->lastInsertId();
    log_material_event('upload', $id, $title, $section, $subject, $actor, $orig . ' (' . format_bytes($size) . ')');
    return null;
}

function add_material_link(string $section, string $subject, string $title, string $desc, string $url, array $actor): ?string
{
    $url = trim($url);
    if (!preg_match('~^https?://[^\s]+$~i', $url) || strlen($url) > 500) return 'Enter a full link starting with http:// or https://';
    $title = trim(mb_substr($title, 0, 150));
    if ($title === '') $title = (string)(parse_url($url, PHP_URL_HOST) ?: 'Link');
    db()->prepare('INSERT INTO materials (section, subject, title, description, kind, url, uploader_type, uploader_student_id, uploader_label)
                   VALUES (?, ?, ?, ?, \'link\', ?, ?, ?, ?)')
        ->execute([$section, $subject, $title, trim(mb_substr($desc, 0, 300)), $url, $actor['type'], $actor['student_id'], $actor['label']]);
    $id = (int)db()->lastInsertId();
    log_material_event('add_link', $id, $title, $section, $subject, $actor, $url);
    return null;
}

function delete_material(array $material, array $actor): void
{
    if (($material['kind'] ?? '') === 'file' && !empty($material['stored_name'])) {
        $dir = materials_dir();
        if ($dir) @unlink($dir . '/' . basename((string)$material['stored_name']));
    }
    db()->prepare('DELETE FROM materials WHERE id = ?')->execute([(int)$material['id']]);
    log_material_event('delete', (int)$material['id'], (string)$material['title'], (string)$material['section'], (string)$material['subject'], $actor,
        ($material['kind'] === 'file' ? (string)$material['orig_name'] : (string)$material['url']));
}

// ---- requests ("can someone upload X?") ----

const MATERIAL_REQUEST_KINDS = ['pdf' => 'PDF', 'doc' => 'Word / Docs', 'ppt' => 'Slides / PPT', 'other' => 'Anything'];

function create_material_request(string $section, string $subject, int $studentId, string $kind, string $message): ?string
{
    $message = trim(mb_substr($message, 0, 300));
    if ($message === '') return 'Tell your CR what you need.';
    if (!isset(MATERIAL_REQUEST_KINDS[$kind])) $kind = 'other';
    db()->prepare('INSERT INTO material_requests (section, subject, student_id, kind, message) VALUES (?, ?, ?, ?, ?)')
        ->execute([$section, $subject, $studentId, $kind, $message]);
    return null;
}

/** Open requests for one section, or for all sections when $section is null. */
function open_material_requests(?string $section = null): array
{
    $sql = 'SELECT r.id, r.section, r.subject, r.kind, r.message, r.created_at, s.roll_no, s.name, s.nickname
            FROM material_requests r JOIN students s ON s.id = r.student_id WHERE r.status = \'open\'';
    $args = [];
    if ($section !== null) { $sql .= ' AND r.section = ?'; $args[] = $section; }
    $stmt = db()->prepare($sql . ' ORDER BY r.created_at DESC LIMIT 100');
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function set_material_request_status(int $id, string $status): void
{
    if (!in_array($status, ['done', 'dismissed'], true)) return;
    db()->prepare('UPDATE material_requests SET status = ?, handled_at = NOW() WHERE id = ?')->execute([$status, $id]);
}

function get_material_request(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM material_requests WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Parses php.ini sizes like "2M" / "512K" into bytes. */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** The biggest file this server will accept right now (our cap, PHP's upload and post limits). */
function server_upload_limit(): int
{
    $limits = [MATERIAL_MAX_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = ini_bytes((string)ini_get($k));
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}


// ---------------------------------------------------------------------
// Shared upload helpers
// ---------------------------------------------------------------------

/** Friendly text for a PHP upload error code, or null when the upload arrived fine. */
function upload_error_message(int $code, string $what = 'file', string $hint = ''): ?string
{
    $lim = format_bytes(max(1, server_post_limit()));
    $msg = match ($code) {
        UPLOAD_ERR_OK         => null,
        UPLOAD_ERR_NO_FILE    => 'Choose a ' . $what . ' first.',
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE  => 'That ' . $what . " is larger than this server allows ({$lim}).",
        UPLOAD_ERR_PARTIAL    => 'The upload was cut off part-way (usually a weak connection). Please try again.',
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION  => 'The server could not accept the upload right now. Please tell the admin.',
        default               => 'The upload did not complete. Please try again.',
    };
    return $msg !== null && $hint !== '' && in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? $msg . ' ' . $hint : $msg;
}

/** True when a form post was thrown away for being bigger than post_max_size (PHP then empties $_POST and $_FILES, token included). */
function request_exceeded_post_limit(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/** The server's own ceiling for one upload (the smaller of upload_max_filesize and post_max_size); PHP_INT_MAX if unlimited. */
function server_post_limit(): int
{
    $limits = [];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = ini_bytes((string)ini_get($k));
        if ($b > 0) $limits[] = $b;
    }
    return $limits ? min($limits) : PHP_INT_MAX;
}

/** Largest pixel count we will decode (a tiny PNG can claim to be 30000x30000 and exhaust memory). */
const IMAGE_MAX_PIXELS = 36_000_000;


/** Fetches a URL of this site (short timeout, no redirects followed) for the checklist; null if the server cannot make requests. */
function http_probe(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 65536]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) return null;
        return ['status' => $code, 'body' => substr((string)$body, 0, 4096)];
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) return null;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $ctx, 0, 4096);
    if ($body === false) return null;
    $code = 0;
    foreach ((array)($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    return ['status' => $code, 'body' => $body];
}

// ---------------------------------------------------------------------
// Per-subject "what changed" (built from snapshots taken as students open Echo)
// ---------------------------------------------------------------------

/** Saves this student's per-subject numbers for the data's date. Safe to call on every page view. */
function record_subject_snapshot(string $roll, array $att): void
{
    $subs = array_filter((array)($att['subjects'] ?? []), fn($x) => $x['conducted'] !== null && $x['attended'] !== null);
    if (!$subs) return;
    $d = (string)($att['to_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = date('Y-m-d');
    try {
        $st = db()->prepare('INSERT INTO subject_history (roll_no, d, subject, attended, conducted) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE attended = VALUES(attended), conducted = VALUES(conducted)');
        foreach ($subs as $x) $st->execute([$roll, $d, mb_substr((string)$x['code'], 0, 60), (float)$x['attended'], (float)$x['conducted']]);
        if (random_int(1, 50) === 1) db()->prepare('DELETE FROM subject_history WHERE d < ?')->execute([date('Y-m-d', strtotime('-150 days'))]);
    } catch (Throwable $e) {
        error_log('Echo subject snapshot failed: ' . $e->getMessage());
    }
}

/**
 * Differences between consecutive snapshots, newest first:
 * [['from' => date, 'to' => date, 'items' => [['label','attended','missed']]], ...]
 * Only subjects that actually had classes in that window are listed.
 */
function subject_changes(string $roll, array $att, int $limit = 6): array
{
    $labels = [];
    foreach ((array)($att['subjects'] ?? []) as $x) $labels[(string)$x['code']] = (string)$x['label'];
    try {
        $st = db()->prepare('SELECT d, subject, attended, conducted FROM subject_history WHERE roll_no = ? AND d >= ? ORDER BY d ASC');
        $st->execute([$roll, date('Y-m-d', strtotime('-90 days'))]);
        $byDate = [];
        foreach ($st->fetchAll() as $r) $byDate[(string)$r['d']][(string)$r['subject']] = [(float)$r['attended'], (float)$r['conducted']];
    } catch (Throwable $e) {
        return [];
    }
    $dates = array_keys($byDate);
    $out = [];
    for ($i = 1; $i < count($dates); $i++) {
        $prev = $byDate[$dates[$i - 1]]; $cur = $byDate[$dates[$i]];
        $items = [];
        foreach ($cur as $code => [$a, $c]) {
            if (!isset($prev[$code])) continue;
            $dc = $c - $prev[$code][1]; $da = $a - $prev[$code][0];
            if ($dc <= 0) continue;
            $items[] = ['label' => $labels[$code] ?? $code, 'attended' => (int)round(max(0, $da)), 'missed' => (int)round(max(0, $dc - $da))];
        }
        if ($items) $out[] = ['from' => $dates[$i - 1], 'to' => $dates[$i], 'items' => $items];
    }
    return array_slice(array_reverse($out), 0, $limit);
}

function render_subject_changes(array $changes): string
{
    if (!$changes) return '';
    $html = '<details class="card fold changes"><summary>What changed lately</summary>'
        . '<p class="hint hint-tight">Compared with the last time your numbers were saved. If you skip a few days, one entry can cover several days.</p>';
    foreach ($changes as $c) {
        $from = date('j M', strtotime($c['from'])); $to = date('j M', strtotime($c['to']));
        $html .= '<div class="chg-block"><div class="chg-date">' . esc($from === $to ? $to : "$from → $to") . '</div>';
        foreach ($c['items'] as $it) {
            $total = $it['attended'] + $it['missed'];
            $cls = $it['missed'] === 0 ? 'ok' : ($it['attended'] === 0 ? 'bad' : 'mid');
            $txt = $it['missed'] === 0 ? 'attended all ' . $total : ($it['attended'] === 0 ? 'missed all ' . $total : 'attended ' . $it['attended'] . ', missed ' . $it['missed']);
            $html .= '<div class="chg-row"><span class="chg-dot ' . $cls . '"></span><span class="chg-sub">' . esc($it['label']) . '</span><span class="chg-txt">' . esc($txt) . '</span></div>';
        }
        $html .= '</div>';
    }
    return $html . '</details>';
}

// ---------------------------------------------------------------------
// Study-material browser helpers
// ---------------------------------------------------------------------

/** Every resource in a section, newest first. */
function list_section_materials(string $section): array
{
    try {
        $stmt = db()->prepare('SELECT id, subject, title, description, kind, orig_name, size, url, uploader_label, created_at FROM materials WHERE section = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$section]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

const MATERIAL_CATEGORIES = ['doc' => 'Documents', 'slides' => 'Slides', 'sheet' => 'Sheets', 'image' => 'Images', 'link' => 'Links'];

/** [category key, short badge text] for one material row. */
function material_category(array $m): array
{
    if (($m['kind'] ?? '') !== 'file') {
        $host = strtolower((string)parse_url((string)($m['url'] ?? ''), PHP_URL_HOST));
        $badge = (str_contains($host, 'youtu') ? 'VIDEO' : (str_contains($host, 'drive.google') || str_contains($host, 'docs.google') ? 'DRIVE' : 'LINK'));
        return ['link', $badge];
    }
    $ext = strtolower(pathinfo((string)$m['orig_name'], PATHINFO_EXTENSION));
    $cat = match ($ext) {
        'ppt', 'pptx' => 'slides',
        'xls', 'xlsx' => 'sheet',
        'png', 'jpg', 'jpeg' => 'image',
        default => 'doc',
    };
    return [$cat, strtoupper($ext) ?: 'FILE'];
}

/** "3 days ago" / "today" in plain words. */
function material_age_label(string $ts): string
{
    $days = (int)floor((time() - strtotime($ts)) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 30) return $days . ' days ago';
    return date('j M Y', strtotime($ts));
}
