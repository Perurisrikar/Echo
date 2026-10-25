<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/extras.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES     = 15;

function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    // Keep sessions for a week and store them in our own folder. On shared
    // hosting the default session folder is cleaned out within minutes, which
    // silently logged people out.
    $lifetime = 7 * 24 * 3600;
    $dir = __DIR__ . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) {
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_student(): ?array
{
    if (empty($_SESSION['student_id'])) return null;
    $stmt = db()->prepare('SELECT ' . STUDENT_COLS . ' FROM students WHERE id = ?');
    $stmt->execute([$_SESSION['student_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function require_student(): array
{
    $s = current_student();
    if (!$s) { header('Location: index.php'); exit; }
    // This device's session may have been ended from the "Devices" page.
    if (!empty($_SESSION['tracked']) && !session_still_valid((int)$s['id'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    }
    return $s;
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) { header('Location: login.php'); exit; }
    return $a;
}

function is_locked(array $row): bool
{
    return !empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time();
}

/** Records a failed login for a students/admins row; locks it out past the threshold. */
function register_failed_attempt(string $table, int $id, int $currentAttempts): void
{
    $attempts    = $currentAttempts + 1;
    $lockedUntil = null;
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
        $attempts    = 0;
    }
    $stmt = db()->prepare("UPDATE {$table} SET failed_attempts = ?, locked_until = ? WHERE id = ?");
    $stmt->execute([$attempts, $lockedUntil, $id]);
}

function clear_failed_attempts(string $table, int $id): void
{
    $stmt = db()->prepare("UPDATE {$table} SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$id]);
}

/**
 * Returns the login row for a roll number, creating it (with no password
 * set) the first time that roll number is seen. This is how new students
 * who show up in the attendance JSON get an account automatically, with
 * no manual import step — the admin just sends them a set-password link.
 */
function ensure_student_account(string $roll, ?array $attendance = null): array
{
    $roll = strtoupper(trim($roll));
    $stmt = db()->prepare('SELECT ' . STUDENT_COLS . ' FROM students WHERE roll_no = ?');
    $stmt->execute([$roll]);
    $row = $stmt->fetch();
    if ($row) {
        // The improved attendance export is authoritative for branch/section.
        // Keep manually imported name/email/mobile data untouched.
        if ($attendance && !empty($attendance['section_based'])) {
            $branch = trim((string)($attendance['branch'] ?? ''));
            $section = trim((string)($attendance['sub_section'] ?? ''));
            if ($branch !== '' || $section !== '') {
                if ((string)($row['dept'] ?? '') !== $branch || (string)($row['sec'] ?? '') !== $section) {
                    db()->prepare('UPDATE students SET dept = ?, sec = ? WHERE id = ?')->execute([$branch ?: null, $section ?: null, $row['id']]);
                    $row['dept'] = $branch ?: null;
                    $row['sec'] = $section ?: null;
                }
            }
        }
        return $row;
    }

    db()->prepare('INSERT IGNORE INTO students (roll_no, dept, sec) VALUES (?, ?, ?)')->execute([
        $roll,
        $attendance && !empty($attendance['branch']) ? $attendance['branch'] : null,
        $attendance && !empty($attendance['sub_section']) ? $attendance['sub_section'] : null,
    ]);
    $stmt->execute([$roll]);
    return $stmt->fetch();
}
