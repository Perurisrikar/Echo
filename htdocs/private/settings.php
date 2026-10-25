<?php
declare(strict_types=1);

/**
 * Site settings that the admin edits on the Settings page (stored in the
 * `settings` table). Anything left blank there falls back to config.php and
 * then to a built-in default, so existing installs keep working untouched.
 */

const SETTING_KEYS = ['site_owner', 'contact_email', 'friends_site_url', 'admin_whatsapp', 'org_name', 'classes_per_day', 'vapid_private', 'vapid_public', 'cron_key'];

/** All saved settings, read once per request. */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $r) $cache[(string)$r['k']] = (string)$r['v'];
    } catch (Throwable $e) {
        // table not created yet (before the first schema run) — config.php values apply
    }
    return $cache;
}

/** Saved value, else the config.php value, else ''. */
function setting(string $key): string
{
    $saved = trim(settings_all()[$key] ?? '');
    if ($saved !== '') return $saved;
    return trim((string)(app_config()[$key] ?? ''));
}

/** Saves a setting. An empty value clears it (config.php / default applies again). */
function save_setting(string $key, string $value): void
{
    if (!in_array($key, SETTING_KEYS, true)) return;
    $value = mb_substr(trim($value), 0, 400);
    if ($value === '') {
        db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$key]);
    } else {
        db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, $value]);
    }
    settings_all(true);
}

/** Owner name shown on About / Privacy / Terms; '' if still the placeholder. */
function site_owner(): string
{
    $v = setting('site_owner');
    return strcasecmp($v, 'the site admin') === 0 ? '' : $v;
}

function owner_or_default(): string
{
    $o = site_owner();
    return $o !== '' ? $o : 'the site admin';
}

function contact_email(): string
{
    return setting('contact_email');
}

function friends_site_url(): string
{
    return setting('friends_site_url');
}

/** "Run by … · email" line used in the footer and on the About page. */
function owner_credit_html(): string
{
    $owner = site_owner();
    $html = $owner !== '' ? 'Run by <strong>' . esc($owner) . '</strong>' : 'Run by a student of the class';
    $mail = contact_email();
    if ($mail !== '') $html .= ' · <a href="mailto:' . esc($mail) . '">' . esc($mail) . '</a>';
    return $html;
}

/** Pre-filled message when sending a student their set-password / reset link. */
function reset_link_message(string $link): string
{
    return "Hello! Here is your Echo login link. It lets you set your password and expires in 24 hours: {$link}";
}

// ---------------------------------------------------------------------
// Polite, time-aware messages to faculty
// ---------------------------------------------------------------------

/** "Good morning" / "Good afternoon" / "Good evening" for the current time in India. */
function time_greeting(?DateTimeInterface $at = null): string
{
    $h = (int)($at ?? ist_now())->format('G');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}

/** Sir / Madam, or "Sir/Madam" until the admin has set the faculty member's gender. */
function faculty_salutation(?string $gender): string
{
    return match (strtoupper(trim((string)$gender))) {
        'M' => 'Sir',
        'F' => 'Madam',
        default => 'Sir/Madam',
    };
}

/** The student's real name (never the nickname); ALL-CAPS roster names are tidied to Title Case. */
function student_real_name(array $student): string
{
    $name = trim(preg_replace('/\s+/', ' ', (string)($student['name'] ?? '')) ?? '');
    if ($name === '') return (string)($student['roll_no'] ?? '');
    if ($name === mb_strtoupper($name)) $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    return $name;
}

function faculty_message(?string $gender, string $name, string $roll, string $section, string $subject, ?DateTimeInterface $at = null): string
{
    return time_greeting($at) . ' ' . faculty_salutation($gender) . ", I am {$name} (Roll no {$roll}) from {$section}, messaging you regarding {$subject}.";
}
