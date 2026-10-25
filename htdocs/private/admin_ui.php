<?php
declare(strict_types=1);

/**
 * One shared page frame for every admin page: a sidebar on wide screens and a
 * collapsible menu on phones, so each page no longer carries its own copy of
 * the navigation. Pages call admin_header() first and admin_footer() last.
 */

/** [group => [[file, label, key], ...]] */
function admin_nav_items(): array
{
    return [
        'Students' => [
            ['dashboard.php', 'Overview', 'overview'],
            ['students.php', 'All students', 'students'],
            ['roster.php', 'Import roster', 'roster'],
            ['bulk_links.php', 'Bulk set-password links', 'bulk'],
        ],
        'Timetable & calendar' => [
            ['timetable.php', 'Timetables', 'timetable'],
            ['calendar.php', 'Holiday calendar', 'calendar'],
            ['exams.php', 'Exam periods', 'exams'],
        ],
        'Content' => [
            ['announcements.php', 'Announcements', 'announcements'],
            ['materials.php', 'Study material', 'materials'],
            ['push.php', 'Notifications', 'push'],
        ],
        'Site' => [
            ['settings.php', 'Settings', 'settings'],
            ['logo.php', 'Logo', 'logo'],
            ['checklist.php', 'Launch checklist', 'checklist'],
            ['backup.php', 'Download backup', 'backup'],
            ['export_students.php', 'Export students CSV', 'export'],
        ],
    ];
}

function admin_nav_html(string $active): string
{
    $html = '';
    foreach (admin_nav_items() as $group => $items) {
        $html .= '<div class="an-group"><div class="an-title">' . esc($group) . '</div>';
        foreach ($items as [$file, $label, $key]) {
            $html .= '<a href="' . $file . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . esc($label) . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '<div class="an-group"><a href="logout.php">Sign out</a></div>';
}

/** Opens the page. $active is a nav key (see admin_nav_items). */
function admin_header(string $title, string $active = ''): void
{
    $nav = admin_nav_html($active);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<title>Echo Admin — ' . esc($title) . '</title>'
        . '<link rel="stylesheet" href="../assets/style.css"></head><body class="admin">'
        . '<div class="admin-shell">'
        . '<aside class="admin-side"><a class="brand" href="dashboard.php">' . brand_mark('../') . 'Echo <span class="muted">· Admin</span></a>' . $nav . '</aside>'
        . '<div class="admin-col">'
        . '<header class="admin-top"><a class="brand" href="dashboard.php">' . brand_mark('../') . 'Echo <span class="muted">· Admin</span></a>'
        . '<details class="admin-menu"><summary>Menu</summary><nav>' . $nav . '</nav></details></header>'
        . '<main class="admin-main">';
}

/** Closes the page and loads scripts (paths relative to /admin/). */
function admin_footer(array $scripts = []): void
{
    echo '</main></div></div>';
    foreach (array_merge($scripts, ['../assets/ui.js']) as $src) {
        echo '<script src="' . esc($src) . '" defer></script>';
    }
    echo '</body></html>';
}

/** Banner for a freshly made reset link (shown once), with copy and WhatsApp buttons. */
function admin_flash_link_html(): string
{
    $link = $_SESSION['flash_link'] ?? null;
    if (!$link) return '';
    $roll = (string)($_SESSION['flash_roll'] ?? '');
    $mobile = $_SESSION['flash_mobile'] ?? null;
    unset($_SESSION['flash_link'], $_SESSION['flash_roll'], $_SESSION['flash_mobile']);
    $wa = whatsapp_link_for($mobile, reset_link_message((string)$link));

    $html = '<div class="banner">Reset link generated for <strong>' . esc($roll) . '</strong> — send it to the student yourself (shown once, expires in 24 hours):'
        . '<div class="linkbox" id="flashLinkBox">' . esc((string)$link) . '</div>'
        . '<div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap;"><button class="small ghost" type="button" id="copyLinkBtn">Copy link</button>';
    $html .= $wa
        ? '<a class="small matcha btn-link" href="' . esc($wa) . '" target="_blank" rel="noopener">Send via WhatsApp</a>'
        : '<span class="hint" style="margin:0;">No mobile number on file — copy and send manually.</span>';
    return $html . '</div></div>';
}

/** A same-site admin page to return to after an action; anything else falls back. Blocks header injection. */
function admin_safe_back(string $back, string $fallback = 'dashboard.php'): string
{
    $ok = preg_match('/^(dashboard|students|class|edit_student)\.php(\?[^\r\n]*)?$/', $back) === 1 && !str_contains($back, '://');
    return $ok ? $back : $fallback;
}

/**
 * Things the admin should act on, most urgent first: [['level' => 'bad'|'warn', 'text' => ..., 'href' => ..., 'cta' => ...]].
 * Empty list means all clear.
 */
function admin_problems(array $classes, array $resetReqs, array $fresh): array
{
    $out = [];
    $add = function (string $level, string $text, string $href, string $cta) use (&$out) { $out[] = compact('level', 'text', 'href', 'cta'); };

    if (!$classes) $add('bad', 'No attendance data found. The scraper has not uploaded anything yet.', 'checklist.php', 'Open checklist');
    if ($fresh['failed_all']) $add('bad', 'The last sync could not fetch: ' . implode(', ', $fresh['failed_all']) . '.', 'dashboard.php#sync', 'See sync history');

    $lastTs = $fresh['last_run'] ? strtotime(trim(str_replace('IST', '', (string)$fresh['last_run']))) : false;
    if ($classes && (!$lastTs || time() - $lastTs > 36 * 3600)) {
        $add('warn', $lastTs ? 'Attendance has not synced for ' . (int)floor((time() - $lastTs) / 3600) . ' hours. Check the cron-job.org job and GitHub Actions.' : 'No sync has been recorded yet.', 'dashboard.php#sync', 'See sync history');
    }

    $sections = list_sections();
    $noTT = [];
    foreach ($sections as $code => $_) if (!get_timetable($code)) $noTT[] = $code;
    if ($noTT) $add('warn', count($noTT) . ' of ' . count($sections) . ' sections have no timetable (' . implode(', ', array_slice($noTT, 0, 6)) . (count($noTT) > 6 ? '…' : '') . ').', 'timetable.php?section=' . rawurlencode($noTT[0]), 'Add timetable');

    $book = faculty_gender_book();
    $noGender = [];
    foreach ($sections as $code => $_) {
        foreach (get_faculty($code) as $f) {
            if ($f['name'] === '' && $f['mobile'] === '') continue;
            if ($f['gender'] === '' && !isset($book[faculty_person_key($f['name'], $f['mobile'])])) $noGender[faculty_person_key($f['name'], $f['mobile'])] = true;
        }
    }
    if ($noGender) $add('warn', count($noGender) . ' faculty member' . (count($noGender) === 1 ? ' has' : 's have') . ' no gender set, so messages say "Sir/Madam".', 'timetable.php', 'Set gender');

    if ($resetReqs) $add('warn', count($resetReqs) . ' password reset request' . (count($resetReqs) === 1 ? ' is' : 's are') . ' waiting.', 'students.php?status=reset', 'Create links');

    $noPass = array_sum(array_column($classes, 'nopass'));
    if ($noPass) $add('warn', $noPass . ' student' . ($noPass === 1 ? ' has' : 's have') . ' not set a password yet.', 'bulk_links.php', 'Send set-up links');

    try {
        $openReq = count(open_material_requests());
    } catch (Throwable $e) { $openReq = 0; }
    if ($openReq) $add('warn', $openReq . ' study-material request' . ($openReq === 1 ? ' is' : 's are') . ' still open (their CR has not marked them done).', 'materials.php', 'View');

    if (site_owner() === '' || contact_email() === '') $add('warn', 'Site owner or contact email is not set, which makes the About and Privacy pages look less trustworthy.', 'settings.php', 'Open settings');

    $eff = server_upload_limit();
    if ($eff < MATERIAL_MAX_BYTES) $add('warn', 'This server caps uploads at ' . format_bytes($eff) . ', below the ' . format_bytes(MATERIAL_MAX_BYTES) . ' Echo allows. Larger files must be added as links.', 'checklist.php', 'Open checklist');

    usort($out, fn($a, $b) => ($a['level'] === 'bad' ? 0 : 1) <=> ($b['level'] === 'bad' ? 0 : 1));
    return $out;
}

function admin_problems_html(array $problems): string
{
    if (!$problems) return '<div class="card prob-ok"><span class="chk ok">✓</span> <strong>Nothing needs your attention.</strong> <span class="hint">Syncs are fresh, timetables are in, and nobody is waiting on you.</span></div>';
    $html = '<div class="card prob"><h2>Needs your attention <span class="prob-count">' . count($problems) . '</span></h2>';
    foreach ($problems as $p) {
        $html .= '<div class="prob-row"><span class="chk ' . ($p['level'] === 'bad' ? 'bad' : 'warn') . '">' . ($p['level'] === 'bad' ? '✕' : '!') . '</span>'
            . '<span class="prob-text">' . esc($p['text']) . '</span>'
            . '<a class="small-link" href="' . esc($p['href']) . '">' . esc($p['cta']) . ' ›</a></div>';
    }
    return $html . '</div>';
}
