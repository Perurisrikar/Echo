<?php
declare(strict_types=1);
// Downloads a section's timetable as a CSV that Excel opens directly, laid out exactly the way the
// importer reads it (so it also works as a round-trip: download, edit in Excel, upload).
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

$sections = list_sections();
$section = (string)($_GET['section'] ?? '');
if (!isset($sections[$section])) { http_response_code(404); exit('Unknown section.'); }

$perDay = max(1, min(12, classes_per_day()));
$tt = get_timetable($section);
$fac = get_faculty($section);

$out = fopen('php://output', 'w');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="timetable-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $section) . '.csv"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF"; // so Excel reads it as UTF-8
$csv = fn(array $row) => fputcsv($out, $row, ',', '"', '');

$head = ['Day'];
for ($p = 1; $p <= $perDay; $p++) $head[] = 'P' . $p;
$csv($head);
foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'] as $d => $label) {
    $row = [$label];
    for ($p = 1; $p <= $perDay; $p++) $row[] = $tt[$d][$p] ?? '';
    $csv($row);
}
$csv([]);
$csv(['Subject code', 'Faculty', 'Mobile']);
foreach ($sections[$section]['subjects'] as $sub) {
    $f = $fac[$sub['code']] ?? ['name' => '', 'mobile' => ''];
    $csv([$sub['code'], $f['name'], $f['mobile']]);
}
fclose($out);
