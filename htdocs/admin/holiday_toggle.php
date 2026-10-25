<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
start_secure_session();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: calendar.php'); exit; }
csrf_verify();

$action = (string)($_POST['action'] ?? '');
$y = (int)($_POST['y'] ?? date('Y'));
$m = (int)($_POST['m'] ?? date('n'));
$isDate = fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
$notice = '';

if ($action === 'bulk_weekend') {
    // "Make these Saturdays working days" / "...back to days off" for a chosen set of dates in the month.
    $dates = array_values(array_filter((array)($_POST['dates'] ?? []), fn($d) => is_string($d) && $isDate($d)));
    $follow = (int)($_POST['follow'] ?? 0);
    $working = ($_POST['mode'] ?? '') === 'working';
    $n = $dates ? set_working_days($dates, $working, $follow ?: null) : 0;
    if ($n > 0 && !empty($_POST['notify'])) {
        sort($dates);
        $names = array_map(fn($d) => date('D j M', strtotime($d)), array_slice($dates, 0, 6));
        $list = implode(', ', $names) . (count($dates) > 6 ? ' and ' . (count($dates) - 6) . ' more' : '');
        $dowNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
        $msg = $working
            ? 'Please note: ' . $list . (count($dates) === 1 ? ' is a working day' : ' are working days') . ' — classes will be held.'
                . ($follow && isset($dowNames[$follow]) ? ' The ' . $dowNames[$follow] . ' timetable will be followed.' : '')
            : 'Update: ' . $list . (count($dates) === 1 ? ' is' : ' are') . ' a day off again — no classes.';
        create_announcement($msg, null, null, 'admin', null);
    }
    $notice = $n === 0 ? 'nothing' : ($working ? "work$n" : "off$n");
} else {
    $date = (string)($_POST['date'] ?? '');
    if ($isDate($date)) {
        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name !== '') {
                db()->prepare('INSERT INTO holidays (holiday_date, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)')
                    ->execute([$date, mb_substr($name, 0, 120)]);
                db()->prepare('DELETE FROM working_days WHERE day_date = ?')->execute([$date]);
            }
        } elseif ($action === 'remove') {
            db()->prepare('DELETE FROM holidays WHERE holiday_date = ?')->execute([$date]);
        } elseif ($action === 'work') {
            set_working_days([$date], true, (int)($_POST['follow'] ?? 0) ?: null);
        } elseif ($action === 'dayoff') {
            set_working_days([$date], false);
        }
    }
}

header('Location: calendar.php?y=' . $y . '&m=' . $m . ($notice !== '' ? '&done=' . $notice : ''));
exit;
