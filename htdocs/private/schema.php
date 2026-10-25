<?php
declare(strict_types=1);

/**
 * The database builds and repairs itself. On the first request after an
 * upload, missing tables and columns are created — nothing to import or
 * run by hand. Every step is safe to repeat.
 *
 * Bump SCHEMA_VERSION whenever run_schema_updates() gains a new step.
 */
const SCHEMA_VERSION = 19;

/** Everything about the signed-in student except the (large) photo blob. */
const STUDENT_COLS = 'id, roll_no, name, dept, sec, email, mobile, nickname, bio, photo_updated_at, '
    . 'theme_accent, theme_glow, is_cr, my_threshold, password_hash, failed_attempts, locked_until, created_at';

function ensure_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $current = 0;
        try {
            $row = $pdo->query("SELECT v FROM app_meta WHERE k = 'schema_version'")->fetch();
            $current = $row ? (int)$row['v'] : 0;
        } catch (Throwable $e) {
            $current = 0; // app_meta doesn't exist yet
        }
        if ($current >= SCHEMA_VERSION) return;

        run_schema_updates($pdo);
    } catch (Throwable $e) {
        error_log('Echo schema update failed: ' . $e->getMessage());
    }
}

function schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
    return $stmt !== false && $stmt->fetch() !== false;
}

function schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt !== false && $stmt->fetch() !== false;
}

function run_schema_updates(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (
        k VARCHAR(50) NOT NULL PRIMARY KEY,
        v VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        roll_no VARCHAR(20) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $studentColumns = [
        'name'             => 'VARCHAR(120) NULL',
        'dept'             => 'VARCHAR(60) NULL',
        'sec'              => 'VARCHAR(10) NULL',
        'email'            => 'VARCHAR(150) NULL',
        'mobile'           => 'VARCHAR(20) NULL',
        'nickname'         => 'VARCHAR(40) NULL',
        'bio'              => 'VARCHAR(280) NULL',
        'photo_updated_at' => 'DATETIME NULL',
        'photo_blob'       => 'MEDIUMBLOB NULL',
        'photo_mime'       => 'VARCHAR(50) NULL',
        'theme_accent'     => 'VARCHAR(20) NULL',
        'theme_glow'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_cr'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'my_threshold'     => 'TINYINT UNSIGNED NULL',
    ];
    foreach ($studentColumns as $column => $definition) {
        if (!schema_column_exists($pdo, 'students', $column)) {
            $pdo->exec('ALTER TABLE students ADD COLUMN `' . $column . '` ' . $definition);
        }
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        INDEX idx_token_hash (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $holidaysExisted = schema_table_exists($pdo, 'holidays');
    $pdo->exec('CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Seed the Telangana list only when the table is brand new, so holidays
    // you later remove in the admin calendar never come back.
    if (!$holidaysExisted) {
        $insert = $pdo->prepare('INSERT IGNORE INTO holidays (holiday_date, name) VALUES (?, ?)');
        foreach (default_holidays() as $date => $name) {
            $insert->execute([$date, $name]);
        }
    }

    // Global (dept/sec both NULL, admin only) or class-scoped (CR limited to
    // their own dept+sec, enforced in code, not here) announcements.
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        author_type ENUM('admin','cr') NOT NULL,
        author_student_id INT NULL,
        dept VARCHAR(60) NULL,
        sec VARCHAR(10) NULL,
        message VARCHAR(500) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (author_student_id) REFERENCES students(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec('CREATE TABLE IF NOT EXISTS announcement_seen (
        announcement_id INT NOT NULL,
        student_id INT NOT NULL,
        seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (announcement_id, student_id),
        FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // The site logo shown beside "Echo" (single row, id = 1). Kept in the
    // database for the same reason photos are: file writes are unreliable here.
    $pdo->exec('CREATE TABLE IF NOT EXISTS site_logo (
        id TINYINT NOT NULL PRIMARY KEY,
        mime VARCHAR(40) NOT NULL,
        data MEDIUMBLOB NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // One overall-percentage snapshot per student per day, for the trend line.
    $pdo->exec('CREATE TABLE IF NOT EXISTS attendance_history (
        roll_no VARCHAR(30) NOT NULL,
        d DATE NOT NULL,
        pct DECIMAL(5,2) NOT NULL,
        PRIMARY KEY (roll_no, d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Recent admin sign-in attempts, shown on the admin dashboard.
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_logins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NULL,
        username VARCHAR(60) NOT NULL,
        ok TINYINT(1) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        ua VARCHAR(160) NOT NULL,
        at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Weekly timetable per attendance section (e.g. CSM_D): Monday=1 ... Friday=5.
    $pdo->exec('CREATE TABLE IF NOT EXISTS timetable (
        section VARCHAR(30) NOT NULL,
        dow TINYINT NOT NULL,
        period TINYINT NOT NULL,
        subject VARCHAR(60) NOT NULL,
        PRIMARY KEY (section, dow, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // One row per scraper run, so the admin can see which sections fail over time.
    $pdo->exec('CREATE TABLE IF NOT EXISTS scrape_log (
        run_at VARCHAR(40) NOT NULL PRIMARY KEY,
        succeeded INT NOT NULL,
        total INT NOT NULL,
        failed TEXT NOT NULL,
        recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Light request limiting for public pages.
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
        k VARCHAR(100) NOT NULL PRIMARY KEY,
        window_start INT NOT NULL,
        n INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Faculty contact per section+subject (entered on the Timetable page).
    $pdo->exec('CREATE TABLE IF NOT EXISTS faculty (
        section VARCHAR(30) NOT NULL,
        subject VARCHAR(60) NOT NULL,
        name VARCHAR(80) NOT NULL DEFAULT \'\',
        mobile VARCHAR(25) NOT NULL DEFAULT \'\',
        PRIMARY KEY (section, subject)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // "All subjects safe" flag on each daily snapshot (for the safe-since badge).
    if (!schema_column_exists($pdo, 'attendance_history', 'safe')) {
        $pdo->exec('ALTER TABLE attendance_history ADD COLUMN safe TINYINT(1) NOT NULL DEFAULT 0');
    }

    // ---- Study materials ----
    $pdo->exec('CREATE TABLE IF NOT EXISTS materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section VARCHAR(30) NOT NULL,
        subject VARCHAR(60) NOT NULL,
        title VARCHAR(150) NOT NULL,
        description VARCHAR(300) NOT NULL DEFAULT \'\',
        kind VARCHAR(10) NOT NULL,
        stored_name VARCHAR(80) NULL,
        orig_name VARCHAR(200) NULL,
        mime VARCHAR(100) NULL,
        size INT NOT NULL DEFAULT 0,
        url VARCHAR(500) NULL,
        uploader_type VARCHAR(10) NOT NULL,
        uploader_student_id INT NULL,
        uploader_label VARCHAR(80) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (section, subject)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Every upload, link, edit and delete is recorded here and never removed.
    $pdo->exec('CREATE TABLE IF NOT EXISTS material_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(20) NOT NULL,
        material_id INT NULL,
        title VARCHAR(150) NOT NULL,
        section VARCHAR(30) NOT NULL,
        subject VARCHAR(60) NOT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT \'\',
        actor_type VARCHAR(10) NOT NULL,
        actor_label VARCHAR(80) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS material_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section VARCHAR(30) NOT NULL,
        subject VARCHAR(60) NOT NULL,
        student_id INT NOT NULL,
        kind VARCHAR(20) NOT NULL,
        message VARCHAR(300) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT \'open\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        handled_at DATETIME NULL,
        KEY (section, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // ---- Exam periods, device sessions, reset requests ----
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(80) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (
        sid_hash CHAR(64) NOT NULL PRIMARY KEY,
        student_id INT NOT NULL,
        ua VARCHAR(160) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        handled_at DATETIME NULL,
        KEY (student_id, handled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // ---- v17: settings, faculty gender, working weekend days ----
    // Site settings editable from the admin Settings page (so config.php never needs editing).
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(50) NOT NULL PRIMARY KEY,
        v VARCHAR(400) NOT NULL DEFAULT \'\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Faculty gender ('M', 'F' or '' = not set yet) drives Sir / Madam in messages.
    if (!schema_column_exists($pdo, 'faculty', 'gender')) {
        $pdo->exec('ALTER TABLE faculty ADD COLUMN gender CHAR(1) NOT NULL DEFAULT \'\'');
    }

    // A weekend date that is a normal college day (e.g. a working Saturday).
    // follow_dow = which weekday's timetable it follows (1-5), or NULL for none.
    $pdo->exec('CREATE TABLE IF NOT EXISTS working_days (
        day_date DATE NOT NULL PRIMARY KEY,
        follow_dow TINYINT NULL,
        note VARCHAR(120) NOT NULL DEFAULT \'\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Per-subject snapshots (one row per student, data date and subject) so "what changed" can be shown.
    $pdo->exec('CREATE TABLE IF NOT EXISTS subject_history (
        roll_no VARCHAR(30) NOT NULL,
        d DATE NOT NULL,
        subject VARCHAR(60) NOT NULL,
        attended DECIMAL(6,1) NOT NULL,
        conducted DECIMAL(6,1) NOT NULL,
        PRIMARY KEY (roll_no, d, subject)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Web-push subscriptions (one row per browser/device a student turned notifications on for).
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        endpoint VARCHAR(700) NOT NULL,
        p256dh VARCHAR(120) NOT NULL,
        auth VARCHAR(60) NOT NULL,
        ann TINYINT(1) NOT NULL DEFAULT 1,
        daily TINYINT(1) NOT NULL DEFAULT 1,
        last_daily DATE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_ok TIMESTAMP NULL,
        UNIQUE KEY (endpoint_hash),
        KEY (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([(string)SCHEMA_VERSION]);
}

/**
 * Telangana State Government 2026 general (mandatory) holidays, from the
 * official notification. Eid/Moharam/Eid Milad depend on moon sighting and
 * can move by a day — adjust them in the admin calendar when confirmed.
 */
function default_holidays(): array
{
    return [
        '2026-01-14' => 'Bhogi',
        '2026-01-15' => 'Sankranti / Pongal',
        '2026-01-26' => 'Republic Day',
        '2026-02-15' => 'Maha Shivaratri',
        '2026-03-03' => 'Holi',
        '2026-03-19' => 'Ugadi',
        '2026-03-21' => 'Eid ul Fitr (Ramzan)',
        '2026-03-27' => 'Sri Rama Navami',
        '2026-04-03' => 'Good Friday',
        '2026-04-14' => "Dr. B.R. Ambedkar's Birthday",
        '2026-05-27' => 'Eid ul Azha (Bakrid)',
        '2026-06-02' => 'Telangana Formation Day',
        '2026-06-26' => 'Shahadat Imam Hussain / 10th Moharam',
        '2026-08-10' => 'Bonalu',
        '2026-08-15' => 'Independence Day',
        '2026-08-26' => 'Eid Milad-un-Nabi',
        '2026-09-04' => 'Sri Krishna Ashtami',
        '2026-09-14' => 'Vinayaka Chavithi',
        '2026-10-02' => 'Mahatma Gandhi Jayanti',
        '2026-10-20' => 'Vijaya Dasami (Dussehra)',
        '2026-10-21' => 'Following day of Vijaya Dasami',
        '2026-11-08' => 'Deepavali',
        '2026-11-24' => 'Kartika Purnima / Guru Nanak Jayanti',
        '2026-12-25' => 'Christmas',
    ];
}
