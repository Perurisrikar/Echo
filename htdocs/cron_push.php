<?php
declare(strict_types=1);
// Morning heads-up. Point a scheduled job (for example cron-job.org, 7:30 AM IST) at:
//   https://your-site/cron_push.php?key=THE-KEY-SHOWN-IN-ADMIN-NOTIFICATIONS
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/functions.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$key = (string)($_GET['key'] ?? '');
if (!rate_limit('cronpush:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 3600) || !hash_equals(push_cron_key(), $key)) {
    http_response_code(403);
    exit("Not allowed.\n");
}
// Never on a day with no classes: student_daily_brief() already skips students with no classes today.
$r = push_send_daily(false);
echo 'sent=' . $r['sent'] . ' failed=' . $r['failed'] . ' removed=' . $r['removed'] . ' skipped=' . $r['skipped'] . "\n";
if ($r['errors']) echo 'errors=' . implode(' | ', $r['errors']) . "\n";
