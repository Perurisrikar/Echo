<?php
declare(strict_types=1);
require_once __DIR__ . '/schema.php';

// Everything in Echo (today's date, the calendar, "Good morning", sync times) is India time, whatever the host's clock says.
date_default_timezone_set('Asia/Kolkata');

/**
 * Site settings from private/config.php, merged over safe defaults so an
 * older config file that lacks a newer setting keeps working.
 */
function app_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit('Setup needed: copy private/config.example.php to private/config.php and fill in your database details.');
    }
    $loaded = require $file;

    $cfg = array_merge([
        'db_host'              => 'localhost',
        'db_name'              => '',
        'db_user'              => '',
        'db_pass'              => '',
        'app_url'              => '',
        'attendance_json_path' => __DIR__ . '/../data/attendance.json',
        // Folder the scraper's per-section files (CSE_A.json, CSM_D.json, ...)
        // land in. Checked first; attendance_json_path above is only a
        // fallback for sites still using one combined file.
        'attendance_data_dir'  => __DIR__ . '/../data',
        'admin_whatsapp'       => '',
        'classes_per_day'      => 7,
        // Shown on the About / Privacy / Terms / Contact pages.
        'site_owner'           => 'the site admin',
        'contact_email'        => '',
        // Optional: the college this site is for. If set, the footer states
        // the site is an independent student project, not run by that college.
        'org_name'             => '',
        // Your separate friends/social site — shown as a button top-right
        // on every logged-in page. Leave blank to hide the button entirely.
        'friends_site_url'     => '',
    ], is_array($loaded) ? $loaded : []);
    return $cfg;
}

/** Returns a shared PDO connection, created (and the schema checked) on first use. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = app_config();
        $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        try { $pdo->exec("SET time_zone = '+05:30'"); } catch (Throwable $e) { /* not fatal */ }
        ensure_schema($pdo);
    }
    return $pdo;
}

/** Public base URL used in reset links. Prefer the configured value over the request's Host header. */
function app_url(): string
{
    $configured = rtrim((string)app_config()['app_url'], '/');
    if ($configured !== '' && !str_contains($configured, 'yoursite')) return $configured;

    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
