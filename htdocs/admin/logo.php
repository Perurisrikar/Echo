<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
$admin = require_admin();

$msg = $_SESSION['logo_msg'] ?? null;
unset($_SESSION['logo_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // An upload bigger than post_max_size arrives with an empty $_POST (token included).
    if (request_exceeded_post_limit()) {
        $_SESSION['logo_msg'] = ['err', 'That image is larger than this server allows. Try a smaller one.'];
        header('Location: logo.php'); exit;
    }
    csrf_verify();
    try {
        if (($_POST['do'] ?? '') === 'remove') {
            remove_site_logo();
            $_SESSION['logo_msg'] = ['ok', 'Logo removed. The green dot is back.'];
        } else {
            $error = save_site_logo($_FILES['logo'] ?? []);
            $_SESSION['logo_msg'] = $error ? ['err', $error] : ['ok', 'Logo updated.'];
        }
    } catch (Throwable $e) {
        error_log('Echo logo save failed: ' . $e->getMessage());
        $_SESSION['logo_msg'] = ['err', 'The logo could not be saved because of a server problem.'];
    }
    header('Location: logo.php'); exit;
}
$hasLogo = site_logo_version() !== null;
?>
<?php admin_header('Logo', 'logo'); ?>
  <h1>Site logo</h1>
  <p class="sub">Shown beside “Echo” in the header on every page. A square or wide PNG with a transparent background looks best. JPG, PNG or WEBP, up to 4 MB.</p>

  <?php if ($msg): ?><div class="banner <?= $msg[0] === 'ok' ? 'ok' : 'warn' ?>"><?= esc($msg[1]) ?></div><?php endif; ?>

  <div class="card">
    <h2>Preview</h2>
    <div class="brand" style="margin:12px 0 0;font-size:1.2rem;"><?= brand_mark('../') ?>Echo</div>
    <form method="post" enctype="multipart/form-data" style="margin-top:18px;">
      <?= csrf_field() ?>
      <label for="logo">Choose a logo</label>
      <input id="logo" name="logo" type="file" data-max-bytes="<?= (int)min(4 * 1048576, server_post_limit()) ?>" accept="image/png,image/jpeg,image/webp" required>
      <button type="submit" class="matcha">Save logo</button>
    </form>
    <?php if ($hasLogo): ?>
      <form method="post" style="margin-top:4px;">
        <?= csrf_field() ?><input type="hidden" name="do" value="remove">
        <button type="submit" class="ghost">Remove logo</button>
      </form>
    <?php endif; ?>
  </div>
<?php admin_footer([]); ?>
