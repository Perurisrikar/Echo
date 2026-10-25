<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $in = fn(string $k) => trim((string)($_POST[$k] ?? ''));

    $owner = mb_substr($in('site_owner'), 0, 80);
    $email = $in('contact_email');
    $friends = $in('friends_site_url');
    $wa = preg_replace('/\D+/', '', $in('admin_whatsapp')) ?? '';
    $org = mb_substr($in('org_name'), 0, 100);
    $perDay = (int)$in('classes_per_day');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look right.';
    if ($friends !== '' && !preg_match('~^https?://[^\s]+\.[^\s]+$~i', $friends)) $errors[] = 'The Friends link must start with https:// (for example https://your-site.example.com).';
    if ($wa !== '' && (strlen($wa) < 10 || strlen($wa) > 15)) $errors[] = 'The WhatsApp number should be 10 digits, or include the country code (like 919876543210).';
    if ($perDay < 1 || $perDay > 12) $errors[] = 'Classes per day must be between 1 and 12.';

    if (!$errors) {
        if (strlen($wa) === 10) $wa = '91' . $wa;
        foreach (['site_owner' => $owner, 'contact_email' => $email, 'friends_site_url' => $friends, 'admin_whatsapp' => $wa, 'org_name' => $org, 'classes_per_day' => (string)$perDay] as $k => $v) {
            save_setting($k, $v);
        }
        $saved = true;
        header('Location: settings.php?saved=1');
        exit;
    }
}
$saved = $saved || isset($_GET['saved']);

$val = function (string $k) use ($errors): string {
    if ($errors && isset($_POST[$k])) return (string)$_POST[$k];
    return $k === 'site_owner' ? site_owner() : setting($k);
};

admin_header('Settings', 'settings');
?>
  <h1>Settings</h1>
  <p class="sub">These used to live in <code>config.php</code>. Change them here — nothing to edit or re-upload. Anything left blank falls back to <code>config.php</code>, then to a safe default.</p>

  <?php if ($saved): ?><div class="banner">Saved.</div><?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="banner warn"><?= esc($e) ?></div><?php endforeach; ?>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Who runs this site</h2>
    <p class="hint hint-tight">Shown on the About, Privacy, Terms and Contact pages and in the footer. A real name (or your group's name) makes the site look more trustworthy to visitors and to Google.</p>
    <label for="site_owner">Your name or group name</label>
    <input id="site_owner" name="site_owner" value="<?= esc($val('site_owner')) ?>" maxlength="80" placeholder="e.g. P. Srikar, CSM-D" autocomplete="off">

    <label for="contact_email">Contact email (optional)</label>
    <input id="contact_email" name="contact_email" type="email" value="<?= esc($val('contact_email')) ?>" maxlength="150" placeholder="you@example.com" autocomplete="off">

    <label for="org_name">College name (optional)</label>
    <input id="org_name" name="org_name" value="<?= esc($val('org_name')) ?>" maxlength="100" placeholder="e.g. Vignan Institute of Technology" autocomplete="off">
    <p class="hint hint-tight">If set, the footer says Echo is an independent student project and not run by this college.</p>

    <h2 style="margin-top:22px;">Buttons and numbers</h2>
    <label for="admin_whatsapp">Your WhatsApp number (students message this)</label>
    <input id="admin_whatsapp" name="admin_whatsapp" inputmode="tel" value="<?= esc($val('admin_whatsapp')) ?>" maxlength="20" placeholder="919876543210" autocomplete="off">

    <label for="friends_site_url">Friends site link (optional)</label>
    <input id="friends_site_url" name="friends_site_url" type="url" value="<?= esc($val('friends_site_url')) ?>" maxlength="300" placeholder="https://your-friends-site.example.com" autocomplete="off">
    <p class="hint hint-tight">When set, a “Friends ↗” button appears top-right on every signed-in page. Leave blank to hide it.</p>

    <label for="classes_per_day">Classes per day</label>
    <input id="classes_per_day" name="classes_per_day" type="number" min="1" max="12" value="<?= (int)classes_per_day() ?>">

    <button type="submit" class="matcha">Save settings</button>
  </form>
<?php admin_footer(); ?>
