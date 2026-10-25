# Echo — student attendance portal

Echo is a small, self-hosted web app that lets students of a college section
see their attendance, predict how many classes they can miss, follow the
timetable and holidays, and get study material in one place. It is plain
**PHP 8 + MySQL** with no frameworks and no Composer, so it runs on any cheap
shared host (it was built for free cPanel-style hosting).

Attendance numbers come from a scraper you run yourself (for example a
scheduled GitHub Action) that uploads a JSON file to the server. Echo only
reads that file; it never logs in to the college system.

> **Privacy first.** The attendance JSON contains real students' roll numbers.
> Never commit it. `.gitignore` already excludes it, and a fake
> `htdocs/data/attendance.sample.json` shows the format.

## Features

**For students**
- Overall and per-subject attendance with a safe/at-risk indicator, a personal
  threshold, and a date-based absence predictor ("how many can I miss?").
- "What changed lately": per-subject history built from the daily numbers.
- Holiday calendar (holidays in red, optional working Saturdays) and a timetable.
- Condonation helper and session list (see and sign out other devices).
- **Study material browser**: search, subject and type filters, sort, saved
  items, NEW tags. Files open **inside the site** (PDF, images, text, Word),
  with an "Open in…" button that hands the file to another app on the phone.
- "Contact faculty" buttons that open WhatsApp with a polite, time-aware
  message ("Good evening Sir, I am … from …") using the student's real name.
- Optional **web push notifications** for announcements and a morning
  heads-up with that day's classes.
- Light/dark theme, accent colours, profile photo, installable as an app (PWA).

**For class representatives (CRs)**
- Add and manage study material for their own section, post announcements,
  and create password-reset links for classmates.

**For the admin**
- Student management (search, edit, bulk reset links as CSV, roster import).
- Timetable import from an **Excel sheet**, per-faculty gender (for Sir/Madam).
- Holiday calendar editor with "tell all students" announcements.
- A "needs attention" summary, launch checklist, notification status/test page,
  settings page, and a database backup download.

## Tech and design notes

- PHP 8+, PDO with prepared statements everywhere, MySQL/MariaDB.
- The database builds and migrates itself on first request (`private/schema.php`,
  tracked by `SCHEMA_VERSION`). No SQL import step.
- Passwords: bcrypt. Reset links: single-use, 24 h, only the hash is stored.
- Stateless HMAC-signed CSRF tokens, login and request rate limits.
- Strict Content-Security-Policy: no inline scripts or handlers at all. The only
  third-party script host is `cdnjs.cloudflare.com` (pdf.js and mammoth, used by
  the in-site file viewer).
- Uploaded study files are stored outside any public path under
  `private/materials/`, checked by size, extension and file signature, and are
  served only through an authorisation check (`material_download.php`).
- Uploads are sent in small chunks (`material_chunk.php`) because some free hosts
  block large single uploads.
- Web push uses a self-written VAPID + RFC 8291 implementation on top of
  `openssl` and `curl` (no libraries). Dead subscriptions are removed automatically.
- Times use `Asia/Kolkata` (set in `private/db.php`; change it for another region).

## Requirements

- PHP 8.1+ (8.4 tested) with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `zlib`
  (the Excel timetable import unpacks `.xlsx` files itself, so it needs `zlib`).
- MySQL or MariaDB.
- Apache or an Apache-compatible host that honours `.htaccess` (the security
  headers and the `private/` and `data/` deny rules live there). On nginx you
  must reproduce those rules in the server config.
- HTTPS strongly recommended (required for push notifications and installing the app).

## Installing

1. Create a MySQL database and user (on cPanel hosts both are usually prefixed
   with your account name).
2. Upload everything inside `htdocs/` to your web root. Keep the `admin/`,
   `assets/`, `data/` and `private/` folders where they are.
3. Copy `private/config.example.php` to `private/config.php` and fill in the
   database details and `app_url`. **`config.php` is never part of an update,
   and must never be committed.**
4. Open any page once; the tables are created automatically.
5. Open `/setup.php` once to create the admin account, then **delete `setup.php`
   from the server**.
6. Log in at `/admin/login.php` and open **Settings** (your name, contact
   WhatsApp number, classes per day) and **Launch checklist**.
7. Have your scraper upload its JSON into `data/`: either one file per section
   (`CSE_A.json`, `CSM_D.json`, …) or a single combined `attendance.json`
   (`attendance_data_dir` / `attendance_json_path` in `config.php` change the location).
8. Import students (Admin → Roster), then give each student a reset link
   (Admin → Bulk links, or the per-student button) so they can set a password.

Updating later: upload the new files (not `config.php`) and load a page. The
schema upgrades itself.

## Attendance JSON format

One object per section, keyed by section name (see
`htdocs/data/attendance.sample.json`):

```json
{
  "CSM_D": {
    "section": "CSM_D", "branch": "BSH", "year": "1",
    "from_date": "2026-08-01", "to_date": "2026-10-04",
    "subjects": ["M&C", "AEP"],
    "hours_conducted": { "M&C": 21, "AEP": 12 },
    "total_conducted": 33,
    "students": [
      { "sno": "1", "ht_no": "DEMO0001", "M&C": "14", "AEP": "9",
        "total": "23", "percentage": "69.70" }
    ]
  }
}
```

- Subject % = student's attended ÷ the section's `hours_conducted` for that subject.
- Overall % = student's `total` ÷ `total_conducted`.
- The older flat-array export is still readable, but it has no per-subject totals,
  so subject values are shown as attended counts.

## Notifications (optional)

Open **Admin → Notifications**. It checks that your PHP can send push messages,
that the server can reach the browsers' push services, and gives you a test
button. The morning heads-up needs a scheduled call to `cron_push.php?key=…`
(the page shows the exact URL). Free hosts have no cron, so use a free service
such as cron-job.org. Each domain you serve needs its own subscription and cron
link, because browser notification subscriptions are per site.

## Security checklist before going public

- [ ] `private/config.php`, `data/attendance.json`, uploaded files and sessions
      are **not** in the repository (`.gitignore` covers them; check with `git status`).
- [ ] No tokens or passwords in the history. If one was ever committed, rotate it;
      deleting the file is not enough.
- [ ] `setup.php` and any diagnostic pages are deleted from the live server.
- [ ] Your personal WhatsApp number is not left as a default in the code
      (set it in Admin → Settings).
- [ ] Scraper credentials live in GitHub Actions secrets, never in the workflow file.
- [ ] Students know what is stored; `privacy.php` and `terms.php` describe it.
      Edit them to match your college and local rules.

## Limits to know about

- Everything depends on the scraper's data being correct and on the host's limits
  (upload size, outbound network). On free hosts the largest upload is often
  around 20 MB; larger material can be added as a link.
- Host gateways (for example nginx/openresty in front of PHP) can block or
  replace some requests; the app shows the host's status when that happens.
- This is an independent student project, not an official college system.

## License

[MIT](LICENSE).
