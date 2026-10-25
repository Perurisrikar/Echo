<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

$cell = function ($v): string {
    $v = (string)($v ?? '');
    // Stop spreadsheet apps from running a cell that starts like a formula.
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v;
    return $v;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="echo-students-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
fputcsv($out, ['ROLLNO', 'NAME', 'NICKNAME', 'DEPT', 'SEC', 'EMAILID', 'MOBILE', 'IS_CR', 'HAS_PASSWORD', 'OVERALL_PCT']);
foreach (students_by_class(null, null, 'roll_no', 'asc') as $s) {
    fputcsv($out, [
        $cell($s['roll_no']), $cell($s['name'] ?? ''), $cell($s['nickname'] ?? ''), $cell($s['dept'] ?? ''), $cell($s['sec'] ?? ''),
        $cell($s['email'] ?? ''), $cell($s['mobile'] ?? ''), !empty($s['is_cr']) ? 'yes' : 'no',
        !empty($s['password_hash']) ? 'yes' : 'no', isset($s['percentage']) ? number_format((float)$s['percentage'], 2, '.', '') : '',
    ]);
}
fclose($out);
