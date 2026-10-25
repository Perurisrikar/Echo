<?php
declare(strict_types=1);
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/extras.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/xlsx.php';

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Deterministic HSL colour from a string, for avatar backgrounds. */
function color_for(string $seed): string
{
    $hash = 0;
    foreach (str_split($seed) as $c) {
        $hash = ($hash * 31 + ord($c)) % 360;
    }
    return "hsl({$hash} 38% 34%)";
}

// ---------------------------------------------------------------------
// Attendance (supports both the improved section-based export and the
// previous flat-array export)
// ---------------------------------------------------------------------

/**
 * Normalises one section's export (the scraper's per-section object — same
 * shape whether it arrived as its own file or as one entry in the old
 * combined file) into per-student records, writing them into $cache.
 *
 * {
 *    "section": "CSE_A", "branch": "BSH", "year": "1",
 *    "subjects": ["M&C", "EC", ...],
 *    "hours_conducted": {"M&C": 18, "EC": 21, ...},
 *    "total_conducted": 158,
 *    "students": [{"ht_no":"...", "M&C":"8", ..., "total":"78", "percentage":"49.37"}]
 * }
 *
 * Subject attendance is calculated as attended/conducted * 100; overall
 * attendance as total attended/total conducted * 100 — using the raw counts
 * the export supplies as the source of truth, not whatever percentage the
 * portal itself displayed.
 */
function load_one_section(array &$cache, array $section, string $fallbackKey): void
{
    if (!isset($section['students']) || !is_array($section['students'])) return;

    $sectionName = trim((string)($section['section'] ?? $fallbackKey));

    // The scraper's own 'branch' field is NOT the student's real branch —
    // every section request to the portal hardcodes "br": "BSH" (the
    // first-year common-subjects department code), so that field reads
    // "BSH" for every single section regardless of CSM/CSE/ME/etc. The
    // actual branch is encoded in the section code itself instead:
    // "CSM_D" -> branch CSM, section D; a code with no underscore (ME,
    // CE, EIE, EEE — branches with only one section) has no sub-section.
    if (str_contains($sectionName, '_')) {
        [$branch, $subSection] = explode('_', $sectionName, 2);
    } else {
        $branch = $sectionName;
        $subSection = '';
    }
    $branch = trim($branch);
    $subSection = trim($subSection);

    $year = trim((string)($section['year'] ?? ''));
    $fromDate = trim((string)($section['from_date'] ?? ''));
    $toDate = trim((string)($section['to_date'] ?? ''));
    $subjectCodes = [];
    foreach ((array)($section['subjects'] ?? []) as $code) {
        $code = trim((string)$code);
        if ($code !== '') $subjectCodes[] = $code;
    }
    $hours = [];
    foreach ((array)($section['hours_conducted'] ?? []) as $code => $value) {
        if (is_numeric($value)) $hours[(string)$code] = (float)$value;
    }
    $totalConducted = (float)($section['total_conducted'] ?? 0);
    if ($totalConducted <= 0) {
        $totalConducted = array_sum($hours);
    }

    // If a malformed export omitted subjects[], use the keys in
    // hours_conducted as the subject order.
    if (!$subjectCodes) $subjectCodes = array_keys($hours);

    foreach ($section['students'] as $row) {
        if (!is_array($row) || !isset($row['ht_no'])) continue;
        $roll = strtoupper(trim((string)$row['ht_no']));
        if ($roll === '') continue;

        $subjects = [];
        foreach ($subjectCodes as $code) {
            $attended = is_numeric($row[$code] ?? null) ? (float)$row[$code] : 0.0;
            $conducted = (float)($hours[$code] ?? 0);
            $pct = $conducted > 0 ? ($attended / $conducted) * 100 : null;
            $subjects[] = [
                'code' => $code,
                'label' => subject_label($code),
                'attended' => $attended,
                'conducted' => $conducted,
                'percentage' => $pct,
            ];
        }

        $attendedTotal = is_numeric($row['total'] ?? null) ? (float)$row['total'] : 0.0;
        $overall = $totalConducted > 0
            ? ($attendedTotal / $totalConducted) * 100
            : (is_numeric($row['percentage'] ?? null) ? (float)$row['percentage'] : 0.0);

        $cache[$roll] = [
            'roll_no' => $roll,
            'total_classes' => (int)round($totalConducted),
            'attended_total' => $attendedTotal,
            'conducted_total' => $totalConducted,
            'percentage' => $overall,
            'subjects' => $subjects,
            'section' => $sectionName,
            'branch' => $branch,
            'sub_section' => $subSection,
            'year' => $year,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'section_based' => true,
        ];
    }
}

/**
 * Reads attendance data and normalises it into one record per roll no.
 *
 * Primary source: one JSON file per section in attendance_data_dir (e.g.
 * CSE_A.json, CSM_D.json — whatever the scraper names them), read fresh on
 * every request. A section whose file is missing this run (a scrape that
 * failed or hasn't run yet) simply keeps showing whatever it last loaded
 * successfully — there's no cache to go stale *to*, the file on disk either
 * has fresh data or it doesn't.
 *
 * Falls back to the single combined attendance_json_path file — in either
 * the old multi-section-dict shape or the original flat array — so a site
 * that hasn't switched its scraper/FTP step over to per-section files yet
 * keeps working unchanged.
 */
function load_attendance(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];

    $dataDir = (string)app_config()['attendance_data_dir'];
    $files = [];
    if ($dataDir !== '' && is_dir($dataDir)) {
        foreach (glob($dataDir . '/*.json') ?: [] as $file) {
            $base = basename($file);
            if ($base === '' || $base[0] === '_') continue; // status/meta files, e.g. _scrape_status.json
            if ($base === 'attendance.json') continue;       // old combined file, handled by the fallback below
            $files[] = $file;
        }
    }

    if ($files) {
        foreach ($files as $file) {
            $section = json_decode((string)file_get_contents($file), true);
            if (!is_array($section)) continue;
            load_one_section($cache, $section, pathinfo($file, PATHINFO_FILENAME));
        }
        return $cache;
    }

    // ---- Fallback: the old single combined file ----
    $path = (string)app_config()['attendance_json_path'];
    if (!is_file($path) || !is_readable($path)) return $cache;

    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) return $cache;

    $looksSectionBased = false;
    foreach ($data as $value) {
        if (is_array($value) && isset($value['students']) && is_array($value['students'])) {
            $looksSectionBased = true;
            break;
        }
    }

    if ($looksSectionBased) {
        foreach ($data as $sectionKey => $section) {
            if (is_array($section)) load_one_section($cache, $section, (string)$sectionKey);
        }
        return $cache;
    }

    // Legacy flat array compatibility.
    foreach ($data as $row) {
        if (!is_array($row) || !isset($row['ht_no'])) continue;
        $roll = strtoupper(trim((string)$row['ht_no']));
        if ($roll === '') continue;

        $subjects = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['ht_no', 'total_classes', 'percentage', 'total'], true)) continue;
            if ($value === null || $value === '' || !is_numeric($value)) continue;
            $subjects[] = [
                'code' => (string)$key,
                'label' => subject_label((string)$key),
                'attended' => (float)$value,
                'conducted' => null,
                'percentage' => null,
            ];
        }

        $cache[$roll] = [
            'roll_no' => $roll,
            'total_classes' => (int)($row['total_classes'] ?? 0),
            'attended_total' => is_numeric($row['total'] ?? null) ? (float)$row['total'] : null,
            'conducted_total' => null,
            'percentage' => (float)($row['percentage'] ?? 0),
            'subjects' => $subjects,
            'section' => '',
            'branch' => '',
            'year' => '',
            'from_date' => '',
            'to_date' => '',
            'section_based' => false,
        ];
    }

    return $cache;
}

const SUBJECT_THRESHOLD = 75.0;

function subject_label(string $code): string
{
    $names = (array)(app_config()['subject_names'] ?? []);
    if (isset($names[$code])) return (string)$names[$code];
    foreach ($names as $configuredCode => $label) {
        if (strcasecmp((string)$configuredCode, $code) === 0) return (string)$label;
    }
    return strtoupper(str_replace('_', ' ', $code));
}

function get_attendance(string $roll): ?array
{
    return load_attendance()[strtoupper(trim($roll))] ?? null;
}

function attendance_data_available(): bool
{
    return count(load_attendance()) > 0;
}

/** Is $section a single valid section object — the shape one per-section scraper file contains? */
function attendance_section_shape_is_supported(array $section): bool
{
    if (!isset($section['students']) || !is_array($section['students'])) return false;
    if (!isset($section['subjects']) || !is_array($section['subjects'])) return false;
    if (!isset($section['hours_conducted']) || !is_array($section['hours_conducted'])) return false;
    if (!isset($section['total_conducted']) || !is_numeric($section['total_conducted'])) return false;
    foreach ($section['students'] as $student) {
        if (!is_array($student) || !isset($student['ht_no'])) return false;
    }
    return true;
}

/** Is $data a whole old-style combined export — either the flat array, or a dict of sections? */
function attendance_json_shape_is_supported(array $data): bool
{
    if (array_is_list($data)) {
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['ht_no'])) return false;
        }
        return true;
    }

    foreach ($data as $section) {
        if (!is_array($section) || !attendance_section_shape_is_supported($section)) return false;
    }
    return true;
}

/** Sections currently on disk in attendance_data_dir, with file size/freshness — for the admin upload page. */
/** The scraper's last-run summary (_scrape_status.json), or null if it hasn't run yet / isn't present. */
function get_scrape_status(): ?array
{
    $path = (string)app_config()['attendance_data_dir'] . '/_scrape_status.json';
    if (!is_file($path) || !is_readable($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function list_attendance_section_files(): array
{
    $dir = (string)app_config()['attendance_data_dir'];
    $out = [];
    if ($dir === '' || !is_dir($dir)) return $out;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_' || $base === 'attendance.json') continue;
        $out[] = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'modified' => filemtime($file),
            'size' => filesize($file),
        ];
    }
    usort($out, fn($a, $b) => $a['name'] <=> $b['name']);
    return $out;
}

// ---------------------------------------------------------------------
// Roster CSV import
// ---------------------------------------------------------------------

function normalize_roster_header(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;           // strip a UTF-8 BOM
    $h = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $h) ?? '');
    return match ($h) {
        'ROLLNO', 'ROLLNUMBER', 'HTNO', 'HALLTICKETNO' => 'ROLLNO',
        'EMAIL', 'EMAILID', 'EMAILADDRESS'             => 'EMAILID',
        'MOBILE', 'MOBILENO', 'MOBILENUMBER', 'PHONE'  => 'MOBILE',
        'DEPT', 'DEPARTMENT', 'BRANCH'                 => 'DEPT',
        'SEC', 'SECTION'                               => 'SEC',
        default                                        => $h,
    };
}

/**
 * Upserts a roster CSV (columns SNO, ROLLNO, NAME, DEPT, SEC, EMAILID,
 * MOBILE in any order) by roll number. Nicknames, bios, photos and
 * passwords are never touched.
 *
 * @return array{updated:int, inserted:int, skipped:int}
 */
function import_roster_csv($source, array $map = []): array
{
    $updated = 0; $inserted = 0; $skipped = 0;
    $fh = is_resource($source) ? $source : fopen((string)$source, 'r');
    if (!$fh) return compact('updated', 'inserted', 'skipped');

    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return compact('updated', 'inserted', 'skipped'); }
    $col = array_flip(array_map(fn($h) => normalize_roster_header((string)$h), $header));

    // Custom column names typed by the admin: for each field, find the CSV
    // header with that name (case/spacing/punctuation ignored) and use it,
    // overriding the automatic guess. Blank or unchanged = automatic.
    $plain = fn(string $v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^\xEF\xBB\xBF/', '', $v) ?? $v) ?? '');
    $normalised = array_map(fn($h) => $plain((string)$h), $header);
    $notFound = [];
    foreach (['ROLLNO', 'NAME', 'DEPT', 'SEC', 'EMAILID', 'MOBILE'] as $field) {
        $custom = trim((string)($map[$field] ?? ''));
        if ($custom === '' || $plain($custom) === $field) continue;
        $idx = array_search($plain($custom), $normalised, true);
        if ($idx === false) { $notFound[] = $custom; continue; }
        $col[$field] = (int)$idx;
    }
    if (!isset($col['ROLLNO'])) {
        fclose($fh);
        return ['updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notFound' => $notFound, 'noRoll' => true,
                'headers' => array_map('strval', $header)];
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM students WHERE roll_no = ?');
    $upsert = $pdo->prepare(
        'INSERT INTO students (roll_no, name, dept, sec, email, mobile) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), dept = VALUES(dept), sec = VALUES(sec),
                                  email = VALUES(email), mobile = VALUES(mobile)'
    );

    while (($row = fgetcsv($fh)) !== false) {
        if ($row === [null]) continue; // blank line
        $get = fn(string $key) => isset($col[$key], $row[$col[$key]]) ? trim((string)$row[$col[$key]]) : '';
        $roll = strtoupper(preg_replace('/\s+/', '', $get('ROLLNO')) ?? '');
        if ($roll === '') { $skipped++; continue; }

        $exists->execute([$roll]);
        $already = (bool)$exists->fetch();

        $upsert->execute([
            $roll,
            $get('NAME') ?: null,
            $get('DEPT') ?: null,
            $get('SEC') ?: null,
            $get('EMAILID') ?: null,
            $get('MOBILE') ?: null,
        ]);
        if ($already) $updated++; else $inserted++;
    }
    fclose($fh);
    return compact('updated', 'inserted', 'skipped', 'notFound');
}

// ---------------------------------------------------------------------
// Classes, sorting, and the CR role
// ---------------------------------------------------------------------

/**
 * Turns an uploaded roster (.csv or .xlsx) into a readable CSV stream for import_roster_csv().
 * @return resource
 * @throws RuntimeException with a friendly message
 */
function roster_upload_stream(string $tmpPath, string $origName)
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xls') throw new RuntimeException('Old .xls files cannot be read. Open it in Excel and use Save As → Excel Workbook (.xlsx), or save as CSV.');
    if (!in_array($ext, ['csv', 'xlsx', 'xlsm'], true)) throw new RuntimeException('Please upload a .csv or .xlsx file.');
    if ((int)@filesize($tmpPath) > 5 * 1024 * 1024) throw new RuntimeException('That roster file is larger than 5 MB — a class list should be tiny. Check it is the right file.');
    if ($ext === 'csv') {
        $text = (string)file_get_contents($tmpPath);
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        return $fh;
    }
    $best = null;
    foreach (xlsx_read_sheets($tmpPath) as $sheet) {
        if ($best === null || count($sheet['rows']) > count($best['rows'])) $best = $sheet; // the roster is the biggest sheet
    }
    if (!$best || !$best['rows']) throw new RuntimeException('That workbook is empty.');
    $maxCol = 0;
    foreach ($best['rows'] as $cols) $maxCol = max($maxCol, max(array_keys($cols)));
    $fh = fopen('php://temp', 'r+');
    foreach ($best['rows'] as $cols) {
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) $line[] = $cols[$c] ?? '';
        fputcsv($fh, $line, ',', '"', '');
    }
    rewind($fh);
    return $fh;
}

/**
 * Every student on the live roster, merged with their attendance figures.
 * The attendance JSON is the source of truth for who has an account at
 * all (new roll numbers there get auto-provisioned); name/dept/sec/etc.
 * come from whatever the last roster CSV import set.
 */
function all_students_with_attendance(): array
{
    $attendance = load_attendance();
    ksort($attendance);
    $students = [];
    foreach ($attendance as $roll => $figures) {
        $students[] = array_merge($figures, ensure_student_account($roll, $figures));
    }
    return $students;
}

/** Distinct (dept, sec) classes on the roster, each with a student count and how many are under 75%. */
function list_classes(): array
{
    $classes = [];
    foreach (all_students_with_attendance() as $s) {
        $dept = trim((string)($s['dept'] ?? ''));
        $sec = trim((string)($s['sec'] ?? ''));
        $key = $dept . '|' . $sec;
        if (!isset($classes[$key])) {
            $classes[$key] = [
                'dept' => $dept,
                'sec' => $sec,
                'section' => (string)($s['section'] ?? ''),
                'nopass' => 0,
                'year' => (string)($s['year'] ?? ''),
                'from_date' => (string)($s['from_date'] ?? ''),
                'to_date' => (string)($s['to_date'] ?? ''),
                'count' => 0,
                'below75' => 0,
                'crs' => [],
            ];
        }
        $classes[$key]['count']++;
        if (empty($s['password_hash'])) $classes[$key]['nopass']++;
        if ((float)$s['percentage'] < SUBJECT_THRESHOLD) $classes[$key]['below75']++;
        if (!empty($s['is_cr'])) $classes[$key]['crs'][] = $s['nickname'] ?: $s['roll_no'];
    }
    ksort($classes);
    return array_values($classes);
}

const SORT_FIELDS = ['roll_no', 'name', 'dept', 'percentage', 'password_hash'];

/**
 * Students filtered to one class (pass null for both to get everyone,
 * for the admin's "All classes" view), sorted by a whitelisted field.
 */
function students_by_class(?string $dept, ?string $sec, string $sortField = 'roll_no', string $sortDir = 'asc'): array
{
    $students = all_students_with_attendance();
    if ($dept !== null) {
        $students = array_values(array_filter($students, fn($s) =>
            trim((string)($s['dept'] ?? '')) === $dept && trim((string)($s['sec'] ?? '')) === $sec
        ));
    }

    if (!in_array($sortField, SORT_FIELDS, true)) $sortField = 'roll_no';
    $dir = $sortDir === 'desc' ? -1 : 1;
    usort($students, function ($a, $b) use ($sortField, $dir) {
        $av = $a[$sortField] ?? '';
        $bv = $b[$sortField] ?? '';
        if ($sortField === 'percentage') return ((float)$av <=> (float)$bv) * $dir;
        if ($sortField === 'password_hash') return (((bool)$av) <=> ((bool)$bv)) * $dir;
        return strcasecmp((string)$av, (string)$bv) * $dir;
    });
    return $students;
}

/** Full row for one student by roll number, or null — admin/CR lookup only. */
function get_student_by_roll(string $roll): ?array
{
    $roll = strtoupper(trim($roll));
    if ($roll === '') return null;
    $stmt = db()->prepare('SELECT ' . STUDENT_COLS . ' FROM students WHERE roll_no = ?');
    $stmt->execute([$roll]);
    return $stmt->fetch() ?: null;
}

/** Updates the roster-owned fields (what a CSV import would also set). Admin-only action. */
function admin_update_student(int $studentId, array $fields): void
{
    db()->prepare('UPDATE students SET name = ?, dept = ?, sec = ?, email = ?, mobile = ? WHERE id = ?')->execute([
        trim((string)($fields['name'] ?? '')) ?: null,
        trim((string)($fields['dept'] ?? '')) ?: null,
        trim((string)($fields['sec'] ?? '')) ?: null,
        trim((string)($fields['email'] ?? '')) ?: null,
        trim((string)($fields['mobile'] ?? '')) ?: null,
        $studentId,
    ]);
}

function set_cr_status(int $studentId, bool $isCr): void
{
    db()->prepare('UPDATE students SET is_cr = ? WHERE id = ?')->execute([$isCr ? 1 : 0, $studentId]);
}

/** True if the signed-in student holds the CR role. */
function is_cr(array $student): bool
{
    return !empty($student['is_cr']);
}

/** True if a CR is allowed to act on a given target student — same class only, never cross-class. */
function cr_can_manage(array $cr, array $target): bool
{
    $crDept = trim((string)($cr['dept'] ?? ''));
    $crSec = trim((string)($cr['sec'] ?? ''));
    if ($crDept === '' && $crSec === '') return false; // a CR with no class on file can't manage anyone
    return $crDept === trim((string)($target['dept'] ?? '')) && $crSec === trim((string)($target['sec'] ?? ''));
}

/** Like require_student(), but also requires the CR role — otherwise sends them back to their own dashboard. */
function require_cr(): array
{
    $student = require_student();
    if (!is_cr($student)) { header('Location: dashboard.php'); exit; }
    return $student;
}

// ---------------------------------------------------------------------
// Announcements
// ---------------------------------------------------------------------

const ANNOUNCEMENT_MAX_LEN = 500;

/** Admin posts with $dept/$sec both null for everyone; a CR's post is always scoped to their own class. */
function create_announcement(string $message, ?string $dept, ?string $sec, string $authorType, ?int $authorStudentId): void
{
    $message = trim(mb_substr($message, 0, ANNOUNCEMENT_MAX_LEN));
    if ($message === '') return;
    db()->prepare('INSERT INTO announcements (author_type, author_student_id, dept, sec, message) VALUES (?, ?, ?, ?, ?)')
        ->execute([$authorType, $authorStudentId, $dept ?: null, $sec ?: null, $message]);
    // Tell everyone who turned notifications on; a failure here must never stop the announcement itself.
    try { push_notify_announcement($message, $dept ?: null, $sec ?: null, $authorType === 'admin' ? 'Admin' : 'Class rep'); } catch (Throwable $e) { error_log('Echo push: ' . $e->getMessage()); }
}

/** Unseen announcements relevant to this student: global ones, plus their own class's, newest first. */
function unseen_announcements_for(array $student): array
{
    $stmt = db()->prepare(
        "SELECT a.* FROM announcements a
         LEFT JOIN announcement_seen s ON s.announcement_id = a.id AND s.student_id = ?
         WHERE s.student_id IS NULL
           AND ((a.dept IS NULL AND a.sec IS NULL) OR (a.dept = ? AND a.sec = ?))
         ORDER BY a.created_at DESC"
    );
    $stmt->execute([$student['id'], (string)($student['dept'] ?? ''), (string)($student['sec'] ?? '')]);
    return $stmt->fetchAll();
}

function mark_announcement_seen(int $announcementId, int $studentId): void
{
    db()->prepare('INSERT IGNORE INTO announcement_seen (announcement_id, student_id) VALUES (?, ?)')
        ->execute([$announcementId, $studentId]);
}

/** All announcements, newest first — for the admin management page. */
function list_announcements_admin(): array
{
    return db()->query(
        "SELECT a.*, s.roll_no AS author_roll FROM announcements a
         LEFT JOIN students s ON s.id = a.author_student_id ORDER BY a.created_at DESC"
    )->fetchAll();
}

/** Only this CR's own class-scoped posts, newest first. */
function list_announcements_by(int $authorStudentId): array
{
    $stmt = db()->prepare('SELECT * FROM announcements WHERE author_student_id = ? ORDER BY created_at DESC');
    $stmt->execute([$authorStudentId]);
    return $stmt->fetchAll();
}

/** Admin can delete any announcement; a CR may only delete their own. */
function delete_announcement(int $id, bool $isAdmin, ?int $actingStudentId = null): void
{
    if ($isAdmin) {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    } elseif ($actingStudentId !== null) {
        db()->prepare('DELETE FROM announcements WHERE id = ? AND author_student_id = ?')->execute([$id, $actingStudentId]);
    }
}

// ---------------------------------------------------------------------
// Photos (stored in the database, served by photo.php)
// ---------------------------------------------------------------------

function photo_url(string $roll, ?string $updatedAt): ?string
{
    if (!$updatedAt) return null;
    return 'photo.php?roll=' . rawurlencode(strtoupper(trim($roll))) . '&v=' . strtotime($updatedAt);
}

/**
 * Validates an uploaded image, re-draws it through GD as a plain 480x480
 * JPEG (nothing from the original file survives), and stores it in the
 * student's row.
 *
 * @param array $file one entry of $_FILES
 * @return string|null an error message, or null on success
 */
function save_uploaded_photo(int $studentId, array $file): ?string
{
    if (!extension_loaded('gd')) {
        return 'Photo uploads need the GD extension, which is switched off on this server.';
    }
    if ($m = upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE), 'image')) return $m;
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return 'That image is too large (max 8 MB).';
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not complete. Please try again.';
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return "That file doesn't look like a valid image.";
    }
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w * $h > IMAGE_MAX_PIXELS) return 'That picture has too many pixels to process. Please use a smaller one.';

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        default        => false,
    };
    if (!$src) {
        return 'Please upload a JPG, PNG, or WEBP image.';
    }

    // Centre-crop to a square, resize, and flatten onto white so
    // transparent PNGs don't turn black when saved as JPEG.
    $side = min($w, $h);
    $out = imagecreatetruecolor(480, 480);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 480, 480, $side, $side);
    imagedestroy($src);

    ob_start();
    $ok = imagejpeg($out, null, 85);
    $bytes = ob_get_clean();
    imagedestroy($out);

    if (!$ok || $bytes === false || $bytes === '') {
        return 'Could not process the image. Please try another one.';
    }

    $stmt = db()->prepare('UPDATE students SET photo_blob = ?, photo_mime = ?, photo_updated_at = NOW() WHERE id = ?');
    $stmt->bindValue(1, $bytes, PDO::PARAM_LOB);
    $stmt->bindValue(2, 'image/jpeg');
    $stmt->bindValue(3, $studentId, PDO::PARAM_INT);
    $stmt->execute();

    return null;
}

// ---------------------------------------------------------------------
// Settings-derived helpers
// ---------------------------------------------------------------------

/**
 * Generates a single-use, 24-hour reset/set-password link for a student,
 * invalidating any still-outstanding one first. Used by both the admin
 * dashboard and a CR's (scoped) reset-link action — same mechanism either
 * way, just gated differently by the caller.
 */
function generate_reset_link(int $studentId): string
{
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE student_id = ? AND used_at IS NULL')
        ->execute([$studentId]);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 24 * 3600);

    db()->prepare('INSERT INTO password_resets (student_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$studentId, $tokenHash, $expiresAt]);
    mark_reset_requests_handled($studentId);

    return app_url() . '/reset_password.php?token=' . $token;
}

function admin_whatsapp_link(string $message = ''): string
{
    return whatsapp_link_for(setting('admin_whatsapp'), $message) ?? 'https://wa.me/';
}

/**
 * A wa.me link for any phone number (e.g. one imported from the roster
 * CSV), or null if there's no usable number. A bare 10-digit Indian
 * mobile number gets the country code added automatically; anything
 * already carrying a country code is left alone.
 */
function whatsapp_link_for(?string $rawNumber, string $message = ''): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$rawNumber) ?? '';
    if (strlen($digits) === 10) {
        $digits = '91' . $digits; // bare local number — assume India, same as elsewhere in this app
    }
    if (strlen($digits) < 10) return null;

    $url = "https://wa.me/{$digits}";
    if ($message !== '') $url .= '?text=' . rawurlencode($message);
    return $url;
}

function classes_per_day(): int
{
    return max(1, min(12, (int)(setting('classes_per_day') ?: 7)));
}

// ---------------------------------------------------------------------
// Curated profile theme (no free-form CSS/JS, by design)
// ---------------------------------------------------------------------

const THEME_ACCENTS = [
    'matcha' => '#8FAE5E',
    'ochre'  => '#B8874A',
    'brick'  => '#B5654F',
    'ash'    => '#8A948F',
    'ink'    => '#2D332F',
];

function theme_accent_hex(?string $key): ?string
{
    return $key !== null ? (THEME_ACCENTS[$key] ?? null) : null;
}

function save_theme(int $studentId, ?string $accentKey, bool $glow): void
{
    $accentKey = ($accentKey !== null && isset(THEME_ACCENTS[$accentKey])) ? $accentKey : null;
    db()->prepare('UPDATE students SET theme_accent = ?, theme_glow = ? WHERE id = ?')
        ->execute([$accentKey, $glow ? 1 : 0, $studentId]);
}

/**
 * Renders an avatar. The initial comes from the nickname (or the last two
 * characters of the roll number) — never from the real name. Whether a
 * photo may be shown is decided by the caller via $showPhoto.
 */
function render_avatar(array $student, string $size = 'md', bool $showPhoto = true): string
{
    $roll = (string)$student['roll_no'];
    $nick = trim((string)($student['nickname'] ?? ''));
    $initial = $nick !== '' ? mb_strtoupper(mb_substr($nick, 0, 1)) : substr($roll, -2);

    $accentHex = theme_accent_hex($student['theme_accent'] ?? null);
    $glowOn = !empty($student['theme_glow']) && $accentHex;

    $classes = 'avatar ' . $size . ($glowOn ? ' glow' : '');
    $style = $glowOn ? '--glow:' . $accentHex . ';' : '';

    $photo = $showPhoto ? photo_url($roll, $student['photo_updated_at'] ?? null) : null;
    if (!$photo) $style .= 'background:' . color_for($roll) . ';';
    $inner = $photo ? '<img src="' . esc($photo) . '" alt="">' : esc($initial);

    return '<div class="' . $classes . '" style="' . $style . '">' . $inner . '</div>';
}

// ---------------------------------------------------------------------
// Public (logged-out) pages: header + footer
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Site logo (shown beside "Echo")
// ---------------------------------------------------------------------

/** Version stamp of the stored logo (changes when it's replaced), or null if none is set. */
function site_logo_version(): ?int
{
    static $v = false;
    if ($v === false) {
        try {
            $row = db()->query('SELECT UNIX_TIMESTAMP(updated_at) AS t FROM site_logo WHERE id = 1')->fetch();
            $v = $row ? (int)$row['t'] : null;
        } catch (Throwable $e) {
            $v = null;
        }
    }
    return $v;
}

/**
 * The little mark before the word "Echo": the uploaded logo if there is
 * one, otherwise the default green dot. $base is the path back to the site
 * root ('' for top-level pages, '../' from inside admin/).
 */
function brand_mark(string $base = ''): string
{
    $v = site_logo_version();
    if ($v === null) return '<span class="dot"></span>';
    return '<img class="brand-logo" src="' . esc($base . 'logo.php?v=' . $v) . '" alt="">';
}

/** Validates, resizes (keeping transparency) and stores the logo. Returns an error message or null. */
function save_site_logo(array $file): ?string
{
    if (!extension_loaded('gd')) return 'Logo uploads need the GD extension, which is switched off on this server.';
    if ($m = upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE), 'image')) return $m;
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) return 'That image is too large (max 4 MB).';
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not complete. Please try again.';

    $info = @getimagesize($file['tmp_name']);
    if (!$info) return "That file doesn't look like a valid image.";
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w * $h > IMAGE_MAX_PIXELS) return 'That picture has too many pixels to process. Please use a smaller one.';
    $src = match ($type) {
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        default        => false,
    };
    if (!$src) return 'Please upload a PNG, JPG, or WEBP image.';

    // Fit inside 256x256 without cropping; keep alpha so a transparent PNG stays transparent.
    $scale = min(1, 256 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    ob_start();
    $ok = imagepng($out, null, 8);
    $bytes = ob_get_clean();
    imagedestroy($out);
    if (!$ok || $bytes === false || $bytes === '') return 'Could not process the image. Please try another one.';

    $stmt = db()->prepare('REPLACE INTO site_logo (id, mime, data, updated_at) VALUES (1, ?, ?, NOW())');
    $stmt->bindValue(1, 'image/png');
    $stmt->bindValue(2, $bytes, PDO::PARAM_LOB);
    $stmt->execute();
    return null;
}

function remove_site_logo(): void
{
    db()->exec('DELETE FROM site_logo WHERE id = 1');
}

// ---------------------------------------------------------------------
// Upgrades: bunk advice, freshness, trend, shortage list, admin log
// ---------------------------------------------------------------------

/**
 * For one subject: how many more classes can be missed while staying at or
 * above the threshold, or how many in a row must be attended to get back.
 * Returns ['safe', n] or ['recover', n], or null if there's no data.
 */
function bunk_advice(?float $attended, ?float $conducted, float $threshold = SUBJECT_THRESHOLD): ?array
{
    if ($attended === null || $conducted === null || $conducted <= 0 || $threshold <= 0 || $threshold >= 100) return null;
    $pct = $attended / $conducted * 100;
    if ($pct >= $threshold) {
        // missing x further classes: attended / (conducted + x) >= t
        $x = (int)floor($attended * 100 / $threshold - $conducted + 1e-9);
        return ['safe', max(0, $x)];
    }
    // attending y further classes: (attended + y) / (conducted + y) >= t
    $y = (int)ceil(($threshold * $conducted - 100 * $attended) / (100 - $threshold) - 1e-9);
    return ['recover', max(1, $y)];
}

/** Human freshness info for the dashboards, from _scrape_status.json and the section's file. */
function data_freshness(?string $section = null): array
{
    record_scrape_status();
    $st = get_scrape_status();
    $out = ['last_run' => null, 'stale' => false, 'failed_all' => [], 'section_failed' => false];
    if ($st) {
        $out['last_run'] = (string)($st['last_run'] ?? '') ?: null;
        $out['failed_all'] = array_values((array)($st['failed'] ?? []));
        if ($section !== null && in_array($section, $out['failed_all'], true)) $out['section_failed'] = true;
    }
    if ($section !== null) {
        $file = (string)app_config()['attendance_data_dir'] . '/' . $section . '.json';
        if (is_file($file) && time() - filemtime($file) > 36 * 3600) $out['stale'] = true;
    }
    if ($out['section_failed']) $out['stale'] = true;
    return $out;
}

/** Stores today's overall percentage for a student (one row per day) and returns their recent history, oldest first. */
function record_and_get_history(string $roll, float $pct, int $days = 30, bool $safe = false): array
{
    try {
        db()->prepare('INSERT INTO attendance_history (roll_no, d, pct, safe) VALUES (?, CURDATE(), ?, ?) ON DUPLICATE KEY UPDATE pct = VALUES(pct), safe = VALUES(safe)')
            ->execute([$roll, round($pct, 2), $safe ? 1 : 0]);
        $stmt = db()->prepare('SELECT d, pct FROM attendance_history WHERE roll_no = ? AND d >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY d ASC');
        $stmt->execute([$roll, $days]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Inline SVG sparkline for [{d, pct}, ...]; empty string if there aren't at least 2 points. */
function render_sparkline(array $rows): string
{
    if (count($rows) < 2) return '';
    $vals = array_map(fn($r) => (float)$r['pct'], $rows);
    $min = min($vals); $max = max($vals);
    if ($max - $min < 2) { $mid = ($max + $min) / 2; $min = $mid - 1; $max = $mid + 1; }
    $w = 280; $h = 56; $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $n > 1 ? $i / ($n - 1) * $w : 0;
        $y = $h - 4 - ($v - $min) / ($max - $min) * ($h - 8);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }

    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Attendance trend">'
        . '<polyline fill="none" stroke="var(--matcha-deep)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>'
        . '</svg>';
}

/** Classmates under the subject threshold in at least one subject: [{student, subjects: [{label, pct}]}]. */
function class_shortage_list(string $dept, string $sec): array
{
    $out = [];
    foreach (students_by_class($dept, $sec, 'roll_no', 'asc') as $s) {
        $att = get_attendance((string)$s['roll_no']);
        if (!$att) continue;
        $low = [];
        foreach ((array)($att['subjects'] ?? []) as $sub) {
            if ($sub['percentage'] !== null && (float)$sub['percentage'] < SUBJECT_THRESHOLD) {
                $low[] = ['label' => $sub['label'], 'pct' => (float)$sub['percentage']];
            }
        }
        if ($low) $out[] = ['student' => $s, 'subjects' => $low, 'overall' => (float)$att['percentage']];
    }
    return $out;
}

function log_admin_login(?int $adminId, string $username, bool $ok): void
{
    try {
        db()->prepare('INSERT INTO admin_logins (admin_id, username, ok, ip, ua) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId, mb_substr($username, 0, 60), $ok ? 1 : 0,
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
        db()->exec('DELETE FROM admin_logins WHERE at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    } catch (Throwable $e) {
        // logging must never block signing in
    }
}

function recent_admin_logins(int $limit = 10): array
{
    try {
        return db()->query('SELECT username, ok, ip, ua, at FROM admin_logins ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------------------------------------------------------------
// Round 2: timetable, scrape log, rate limits, backups, report link
// ---------------------------------------------------------------------

function ist_now(): DateTime
{
    return new DateTime('now', new DateTimeZone('Asia/Kolkata'));
}

/** Every attendance section on file: code => ['subjects' => [[code,label],...], 'count' => n]. */
function list_sections(): array
{
    $out = [];
    foreach (load_attendance() as $rec) {
        $sec = (string)($rec['section'] ?? '');
        if ($sec === '') continue;
        if (!isset($out[$sec])) {
            $out[$sec] = ['subjects' => array_map(fn($x) => ['code' => $x['code'], 'label' => $x['label']], (array)($rec['subjects'] ?? [])), 'count' => 0];
        }
        $out[$sec]['count']++;
    }
    ksort($out);
    return $out;
}

/** [dow => [period => subjectCode]] for a section. */
function get_timetable(string $section): array
{
    $tt = [];
    try {
        $stmt = db()->prepare('SELECT dow, period, subject FROM timetable WHERE section = ? ORDER BY dow, period');
        $stmt->execute([$section]);
        foreach ($stmt->fetchAll() as $r) $tt[(int)$r['dow']][(int)$r['period']] = (string)$r['subject'];
    } catch (Throwable $e) {
    }
    return $tt;
}

/** Replaces a section's timetable. $grid is [dow => [period => subjectCode|'']]; only codes in $allowed are kept. */
function save_timetable(string $section, array $grid, array $allowed): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM timetable WHERE section = ?')->execute([$section]);
    $ins = $pdo->prepare('INSERT INTO timetable (section, dow, period, subject) VALUES (?, ?, ?, ?)');
    foreach ($grid as $dow => $periods) {
        if ((int)$dow < 1 || (int)$dow > 5 || !is_array($periods)) continue;
        foreach ($periods as $period => $code) {
            $code = trim((string)$code);
            if ($code === '' || !in_array($code, $allowed, true) || (int)$period < 1 || (int)$period > 12) continue;
            $ins->execute([$section, (int)$dow, (int)$period, $code]);
        }
    }
}

/** Subject code => number of periods on a given date (Y-m-d), or [] on weekends/holidays/no timetable. */
function periods_on_date(array $timetable, string $date): array
{
    if (isset(get_holidays()[$date])) return [];
    $dow = (int)date('N', strtotime($date));
    if ($dow >= 6) {
        // Weekend: only if the admin marked it a working day, and then only if it follows a weekday's timetable.
        $w = get_working_days()[$date] ?? null;
        if (!$w || empty($w['follow_dow'])) return [];
        $dow = (int)$w['follow_dow'];
    }
    if (empty($timetable[$dow])) return [];
    return array_count_values(array_values($timetable[$dow]));
}

/** Records the scraper's latest run (once per run) so the admin can see history. */
function record_scrape_status(): void
{
    $st = get_scrape_status();
    if (!$st || empty($st['last_run'])) return;
    try {
        db()->prepare('INSERT IGNORE INTO scrape_log (run_at, succeeded, total, failed) VALUES (?, ?, ?, ?)')
            ->execute([(string)$st['last_run'], count((array)($st['succeeded'] ?? [])), (int)($st['total_configured'] ?? 0),
                       json_encode(array_values((array)($st['failed'] ?? [])))]);
    } catch (Throwable $e) {
    }
}

function recent_scrape_runs(int $limit = 14): array
{
    try {
        $rows = db()->query('SELECT run_at, succeeded, total, failed FROM scrape_log ORDER BY run_at DESC LIMIT ' . (int)$limit)->fetchAll();
        foreach ($rows as &$r) $r['failed'] = json_decode((string)$r['failed'], true) ?: [];
        return array_reverse($rows);
    } catch (Throwable $e) {
        return [];
    }
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Fixed-window limiter. Returns false once $max hits have happened for $key within $windowSeconds. */
function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    try {
        $k = substr(hash('sha256', $key), 0, 40);
        $now = time();
        $pdo = db();
        $pdo->prepare('INSERT INTO rate_limits (k, window_start, n) VALUES (?, ?, 1)
                       ON DUPLICATE KEY UPDATE n = IF(window_start < ?, 1, n + 1), window_start = IF(window_start < ?, ?, window_start)')
            ->execute([$k, $now, $now - $windowSeconds, $now - $windowSeconds, $now]);
        $stmt = $pdo->prepare('SELECT n FROM rate_limits WHERE k = ?');
        $stmt->execute([$k]);
        if (random_int(1, 50) === 1) $pdo->exec('DELETE FROM rate_limits WHERE window_start < ' . ($now - 86400));
        return (int)$stmt->fetchColumn() <= $max;
    } catch (Throwable $e) {
        return true; // never lock people out because the limiter itself failed
    }
}

function rate_limited_page(): void
{
    http_response_code(429);
    header('Retry-After: 600');
    exit('Too many attempts from your connection. Please wait a few minutes and try again.');
}

/** WhatsApp link to the admin pre-filled with who/where, for the "Report a problem" button. */
function report_problem_link(?array $student, string $page): string
{
    $roll = $student['roll_no'] ?? 'unknown';
    $att = $student ? get_attendance((string)$roll) : null;
    $msg = time_greeting() . ", I would like to report a problem on Echo.\n\nRoll no: {$roll}\nSection: " . ($att['section'] ?? '-') . "\nPage: {$page}\n\nWhat went wrong: ";
    return admin_whatsapp_link($msg);
}

function render_report_link(?array $student, string $page): string
{
    return '<p class="report-link"><a href="' . esc(report_problem_link($student, $page)) . '" target="_blank" rel="noopener">Something look wrong? Report a problem →</a></p>';
}

/** Everything worth restoring if the database were lost (no photos). */
function build_backup(): array
{
    $pdo = db();
    $dump = fn(string $sql) => $pdo->query($sql)->fetchAll();
    return [
        'created_at' => date('c'),
        'app' => 'echo',
        'students' => $dump('SELECT id, roll_no, name, nickname, bio, dept, sec, email, mobile, is_cr, theme_accent, theme_glow, password_hash, created_at FROM students'),
        'holidays' => $dump('SELECT holiday_date, name FROM holidays'),
        'announcements' => $dump('SELECT id, author_type, author_student_id, dept, sec, message, created_at FROM announcements'),
        'timetable' => $dump('SELECT section, dow, period, subject FROM timetable'),
        'admins' => $dump('SELECT id, username, password_hash FROM admins'),
        'faculty' => $dump('SELECT section, subject, name, mobile, gender FROM faculty'),
        'working_days' => $dump('SELECT day_date, follow_dow, note FROM working_days'),
        'settings' => $dump('SELECT k, v FROM settings'),
    ];
}

/** Writes a dated backup into private/backups at most once a day, keeping the newest 7. Silent on failure. */
function maybe_auto_backup(): ?string
{
    $dir = __DIR__ . '/backups';
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
        if (!is_writable($dir)) return null;
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        // One per day; a random suffix keeps the name unguessable even if a host ignores .htaccess.
        $today = glob($dir . '/echo-backup-' . date('Y-m-d') . '-*.json') ?: [];
        if ($today) return $today[0];
        $file = $dir . '/echo-backup-' . date('Y-m-d') . '-' . bin2hex(random_bytes(6)) . '.json';
        if (@file_put_contents($file, json_encode(build_backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return null;
        $all = glob($dir . '/echo-backup-*.json') ?: [];
        rsort($all);
        foreach (array_slice($all, 7) as $old) @unlink($old);
        return $file;
    } catch (Throwable $e) {
        return null;
    }
}

function latest_backup_info(): ?array
{
    $all = glob(__DIR__ . '/backups/echo-backup-*.json') ?: [];
    if (!$all) return null;
    rsort($all);
    return ['name' => basename($all[0]), 'time' => filemtime($all[0]), 'count' => count($all)];
}

/** [subjectCode => ['name' => ..., 'mobile' => ..., 'gender' => 'M'|'F'|'']] for a section. */
function get_faculty(string $section): array
{
    $out = [];
    try {
        $stmt = db()->prepare('SELECT subject, name, mobile, gender FROM faculty WHERE section = ?');
        $stmt->execute([$section]);
        foreach ($stmt->fetchAll() as $r) $out[(string)$r['subject']] = ['name' => (string)$r['name'], 'mobile' => (string)$r['mobile'], 'gender' => (string)$r['gender']];
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Replaces a section's faculty list. $rows is [subjectCode => ['name'=>, 'mobile'=>, 'gender'=>]].
 * A row with no gender submitted keeps the gender already saved for that subject, and a faculty
 * member's gender is remembered by name + number, so an Excel import (which has no gender column)
 * never wipes what you set by hand. Blank name+mobile rows are dropped.
 */
function save_faculty(string $section, array $rows, array $allowed): void
{
    $pdo = db();
    $existing = get_faculty($section);
    $byPerson = [];
    foreach (faculty_gender_book() as $k => $g) $byPerson[$k] = $g;
    foreach ($existing as $e) if ($e['gender'] !== '') $byPerson[faculty_person_key($e['name'], $e['mobile'])] = $e['gender'];

    $pdo->prepare('DELETE FROM faculty WHERE section = ?')->execute([$section]);
    $ins = $pdo->prepare('INSERT INTO faculty (section, subject, name, mobile, gender) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as $code => $row) {
        if (!is_string($code) || !in_array($code, $allowed, true) || !is_array($row)) continue;
        $name = trim(mb_substr((string)($row['name'] ?? ''), 0, 80));
        $mobile = trim(preg_replace('/[^0-9+]/', '', (string)($row['mobile'] ?? '')) ?? '');
        $mobile = mb_substr($mobile, 0, 20);
        if ($name === '' && $mobile === '') continue;
        if (array_key_exists('gender', $row)) {
            $gender = strtoupper(trim((string)$row['gender']));
            $gender = in_array($gender, ['M', 'F'], true) ? $gender : '';
        } else {
            $gender = $existing[$code]['gender'] ?? ($byPerson[faculty_person_key($name, $mobile)] ?? '');
        }
        $ins->execute([$section, $code, $name, $mobile, $gender]);
    }
}

/** Stable key for "the same person" across sections: the mobile number if present, else the lower-cased name. */
function faculty_person_key(string $name, string $mobile): string
{
    $digits = substr(preg_replace('/\D+/', '', $mobile) ?? '', -10);
    return $digits !== '' ? 'm' . $digits : 'n' . mb_strtolower(trim($name));
}

/** [personKey => gender] for every faculty member with a gender set in any section. */
function faculty_gender_book(): array
{
    $out = [];
    try {
        foreach (db()->query("SELECT name, mobile, gender FROM faculty WHERE gender <> ''")->fetchAll() as $r) {
            $out[faculty_person_key((string)$r['name'], (string)$r['mobile'])] = (string)$r['gender'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * WhatsApp personal-chat link to a subject's faculty with a polite, time-of-day greeting; null if no
 * number is on file. Uses the student's real name, not the nickname. The link carries data-wa-live
 * (see ui.js) so the greeting is refreshed to the moment of the tap.
 */
function faculty_chat_link(?array $fac, array $student, string $section, string $subjectLabel): ?string
{
    if (!$fac || trim((string)($fac['mobile'] ?? '')) === '') return null;
    $msg = faculty_message($fac['gender'] ?? '', student_real_name($student), (string)($student['roll_no'] ?? ''), $section, $subjectLabel);
    return whatsapp_link_for($fac['mobile'], $msg);
}

/** Neutral (not red) note shown wherever we predict or project attendance. */
function render_predictor_disclaimer(): string
{
    return '<p class="disclaimer">ⓘ Predictions are estimates, not exact. We can\'t know in advance how many official holidays or cancelled classes there will be, so these figures are our best guess.</p>';
}

function render_public_header(string $active = ''): string
{
    $links = ['about.php' => 'About', 'privacy.php' => 'Privacy', 'terms.php' => 'Terms', 'contact.php' => 'Contact'];
    $html = '<header class="site-header"><div class="site-header-inner">'
        . '<a class="brand" href="index.php">' . brand_mark() . 'Echo</a><nav class="site-nav">';
    foreach ($links as $href => $label) {
        $html .= '<a href="' . $href . '"' . ($active === $href ? ' class="active"' : '') . '>' . $label . '</a>';
    }
    return $html . '</nav></div></header>';
}

function render_public_footer(): string
{
    $year = date('Y');
    return '<footer class="site-footer"><div class="site-footer-inner">'
        . '<p>Echo is an independent, student-run attendance tool built for classmates. '
        . (setting('org_name') !== '' ? 'It is not run by, or officially affiliated with, ' . esc(setting('org_name')) . '.' : 'It is not an official product of, or officially affiliated with, any college or university.')
        . '</p><p class="site-footer-owner">' . owner_credit_html() . '</p>'
        . '<nav class="site-footer-links">'
        . '<a href="about.php">About</a><a href="privacy.php">Privacy Policy</a>'
        . '<a href="terms.php">Terms of Use</a><a href="contact.php">Contact</a>'
        . '</nav><p class="site-footer-copy">© ' . $year . ' Echo</p>'
        . '</div></footer>';
}

function render_topbar(): string
{
    $friendsUrl = friends_site_url();
    $friendsBtn = $friendsUrl !== ''
        ? '<a class="topbar-friends" href="' . esc($friendsUrl) . '" target="_blank" rel="noopener">Friends ↗</a>'
        : '';

    return '<div class="topbar"><div class="brand">' . brand_mark() . 'Echo</div>'
        . '<div class="topbar-right">' . $friendsBtn . '<a class="quiet" href="logout.php">Sign out</a></div></div>';
}

function render_tabbar(string $active, int $meId): string
{
    $tabs = [
        'home' => ['dashboard.php', 'Home',
            '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>'],
        'timetable' => ['timetable.php', 'Timetable',
            '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'],
        'calendar' => ['calendar.php', 'Calendar',
            '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'],
        'study' => ['materials.php', 'Study',
            '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5"/>'],
        'me' => ['profile.php', 'Me',
            '<circle cx="12" cy="8.5" r="3.6"/><path d="M4.5 20.5c0-4.1 3.4-7 7.5-7s7.5 2.9 7.5 7"/>'],
    ];

    $html = '<nav class="tabbar"><div class="tabbar-inner">';
    foreach ($tabs as $key => [$href, $label, $icon]) {
        $html .= '<a href="' . $href . '"' . ($key === $active ? ' class="active"' : '') . '>'
            . '<span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true">' . $icon . '</svg></span>'
            . '<span>' . $label . '</span></a>';
    }
    return $html . '</div></nav>';
}

// ---------------------------------------------------------------------
// Holiday calendar
// ---------------------------------------------------------------------

/** All named holidays, keyed by 'Y-m-d' => name. */
function get_holidays(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (db()->query('SELECT holiday_date, name FROM holidays')->fetchAll() as $r) {
        $cache[$r['holiday_date']] = $r['name'];
    }
    return $cache;
}

/** Weekend days the admin marked as normal college days: 'Y-m-d' => ['follow_dow' => int|null, 'note' => string]. */
function get_working_days(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT day_date, follow_dow, note FROM working_days')->fetchAll() as $r) {
            $cache[(string)$r['day_date']] = ['follow_dow' => $r['follow_dow'] !== null ? (int)$r['follow_dow'] : null, 'note' => (string)$r['note']];
        }
    } catch (Throwable $e) {
    }
    return $cache;
}

/** True for a Saturday/Sunday that is a day off (a weekend the admin made a working day is not "weekend"). */
function is_weekend(DateTimeImmutable $d): bool
{
    if (!in_array((int)$d->format('N'), [6, 7], true)) return false;
    return !isset(get_working_days()[$d->format('Y-m-d')]);
}

/** Marks (or unmarks) weekend dates as working days. $follow = weekday 1-5 whose timetable they use, or null. */
function set_working_days(array $dates, bool $working, ?int $follow = null, string $note = ''): int
{
    $pdo = db();
    $n = 0;
    foreach ($dates as $date) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
        if (!$d || $d->format('Y-m-d') !== $date || !in_array((int)$d->format('N'), [6, 7], true)) continue;
        if ($working) {
            $pdo->prepare('INSERT INTO working_days (day_date, follow_dow, note) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE follow_dow = VALUES(follow_dow), note = VALUES(note)')
                ->execute([$date, ($follow !== null && $follow >= 1 && $follow <= 5) ? $follow : null, mb_substr($note, 0, 120)]);
            $pdo->prepare('DELETE FROM holidays WHERE holiday_date = ?')->execute([$date]); // working beats a holiday on the same date
        } else {
            $pdo->prepare('DELETE FROM working_days WHERE day_date = ?')->execute([$date]);
        }
        $n++;
    }
    return $n;
}

/** All Saturdays (or Sundays) of a month as 'Y-m-d'. */
function weekend_dates_in_month(int $year, int $month, int $isoDow = 6): array
{
    $out = [];
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $last = (int)$first->format('t');
    for ($i = 0; $i < $last; $i++) {
        $d = $first->modify("+$i days");
        if ((int)$d->format('N') === $isoDow) $out[] = $d->format('Y-m-d');
    }
    return $out;
}

function holiday_name_for(DateTimeImmutable $d): ?string
{
    return get_holidays()[$d->format('Y-m-d')] ?? null;
}

function is_working_day(DateTimeImmutable $d): bool
{
    return !is_weekend($d) && holiday_name_for($d) === null;
}

function count_working_days(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    if ($end < $start) return 0;
    $count = 0;
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        if (is_working_day($d)) $count++;
    }
    return $count;
}

/** Colour key shown under every calendar. */
function render_calendar_legend(): string
{
    return '<div class="cal-legend">'
        . '<span><span class="swatch sw-holiday"></span>Holiday</span>'
        . '<span><span class="swatch sw-weekend"></span>Weekend (day off)</span>'
        . '<span><span class="swatch sw-working"></span>Working weekend</span>'
        . '<span><span class="swatch sw-today"></span>Today</span></div>';
}

/**
 * Renders a month grid. When $editable is true (admin), open working days
 * get "+ Add" and holidays get "Remove", posting to holiday_toggle.php.
 */
function render_month_calendar(int $year, int $month, bool $editable): string
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int)$first->format('t');
    $startOffset = (int)$first->format('N') - 1; // Monday-first grid
    $todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');

    $html = '<div class="cal-grid">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $lbl) {
        $html .= '<div class="cal-head">' . esc($lbl) . '</div>';
    }
    for ($i = 0; $i < $startOffset; $i++) {
        $html .= '<div class="cal-day empty"></div>';
    }
    $workingDays = get_working_days();
    $dowNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $d = $first->modify('+' . ($day - 1) . ' days');
        $dateStr = $d->format('Y-m-d');
        $isWeekendDate = in_array((int)$d->format('N'), [6, 7], true);
        $work = $isWeekendDate ? ($workingDays[$dateStr] ?? null) : null;
        $weekend = $isWeekendDate && !$work;
        $holiday = holiday_name_for($d);

        $classes = 'cal-day' . ($weekend ? ' weekend' : '') . ($holiday ? ' holiday' : '')
                 . ($work && !$holiday ? ' working' : '') . ($dateStr === $todayStr ? ' today' : '');
        $hidden = '<input type="hidden" name="date" value="' . esc($dateStr) . '">'
                . '<input type="hidden" name="y" value="' . $year . '"><input type="hidden" name="m" value="' . $month . '">';

        $html .= '<div class="' . $classes . '"><div class="daynum">' . $day . '</div>';
        if ($holiday) {
            $html .= '<div class="holiday-name">' . esc($holiday) . '</div>';
            if ($editable) {
                $html .= '<form method="post" action="holiday_toggle.php">' . csrf_field() . $hidden
                    . '<input type="hidden" name="action" value="remove">'
                    . '<button class="small ghost" type="submit">Remove</button></form>';
            }
        } elseif ($work) {
            $follow = $work['follow_dow'] ? 'Follows ' . $dowNames[$work['follow_dow']] : 'No timetable';
            $html .= '<div class="work-name">Working day</div><div class="work-sub">' . esc($work['note'] !== '' ? $work['note'] : $follow) . '</div>';
            if ($editable) {
                $html .= '<form method="post" action="holiday_toggle.php">' . csrf_field() . $hidden
                    . '<input type="hidden" name="action" value="dayoff">'
                    . '<button class="small ghost" type="submit">Day off</button></form>';
            }
        } elseif ($editable && $isWeekendDate) {
            $opts = '<option value="0">No timetable</option>';
            foreach ($dowNames as $n => $label) $opts .= '<option value="' . $n . '">Follow ' . $label . '</option>';
            $html .= '<details><summary>Working?</summary>'
                . '<form method="post" action="holiday_toggle.php">' . csrf_field() . $hidden
                . '<input type="hidden" name="action" value="work">'
                . '<select name="follow" aria-label="Timetable to follow">' . $opts . '</select>'
                . '<button class="small" type="submit">Make working day</button></form></details>';
        } elseif ($editable) {
            $html .= '<details><summary>+ Add</summary>'
                . '<form method="post" action="holiday_toggle.php">' . csrf_field() . $hidden
                . '<input type="hidden" name="action" value="add">'
                . '<input type="text" name="name" placeholder="Holiday name" required maxlength="120">'
                . '<button class="small" type="submit">Add</button></form></details>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}


