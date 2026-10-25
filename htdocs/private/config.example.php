<?php
// Copy this file to config.php (same folder) and fill it in.
// config.php is never included in updates, so upgrades won't overwrite it.
return [
    'db_host' => 'localhost',
    'db_name' => 'REPLACE_WITH_YOUR_DB_NAME',
    'db_user' => 'REPLACE_WITH_YOUR_DB_USER',
    'db_pass' => 'REPLACE_WITH_YOUR_DB_PASSWORD',

    // Your site's public URL, no trailing slash. Used to build reset links.
    'app_url' => 'https://yoursite.example.com',

    // Server path of the JSON your GitHub Action uploads (not a URL).
    // 'attendance_json_path' => __DIR__ . '/../data/attendance.json',

    // Optional: a long random string used to sign form tokens. If left out,
    // one is derived from your database credentials. Don't change it often —
    // forms open at that moment would need a reload.
    // 'app_secret' => 'paste-a-long-random-string-here',

    // Your WhatsApp number, international format, digits only.
    // 'admin_whatsapp' => '919876543210',

    // Periods per working day, used by the predictor.
    // 'classes_per_day' => 7,

    // Shown on the About / Privacy / Terms / Contact pages. Use your real
    // name or a class/group name, and make sure any statement is accurate.
    // 'site_owner'    => 'Your Name',
    // 'contact_email' => 'you@example.com',   // optional
    // 'org_name'      => 'Your College Name', // optional; adds an "independent project" notice

    // Your separate friends/social site. Shown as a button top-right on
    // every page once a student is signed in. Leave unset/blank to hide it.
    // 'friends_site_url' => 'https://your-friends-site.example.com',

    // Optional: friendly names for subject codes in the section-based
    // attendance export. Subject percentages are calculated from each
    // section's hours_conducted values; this map only changes labels.
    // 'subject_names' => [
    //     'aep' => 'Applied Engineering Physics',
    //     'edc' => 'Engineering Drawing & Computing',
    //     'bee' => 'Basic Electrical Engineering',
    //     'pps' => 'Programming for Problem Solving',
    // ],
];
