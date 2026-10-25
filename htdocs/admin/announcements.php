<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['do'] ?? '') === 'delete') {
        delete_announcement((int)($_POST['id'] ?? 0), true);
    } else {
        $scopes = array_values(array_filter((array)($_POST['scope'] ?? []), 'is_string'));
        $message = (string)($_POST['message'] ?? '');
        if (!$scopes || in_array('all', $scopes, true)) {
            create_announcement($message, null, null, 'admin', null);
        } else {
            // One announcement per ticked class, all with the same text.
            foreach (array_unique($scopes) as $scope) {
                [$dept, $sec] = array_pad(explode('|', $scope, 2), 2, null);
                create_announcement($message, $dept, $sec, 'admin', null);
            }
        }
    }
    header('Location: announcements.php');
    exit;
}

$classes = list_classes();
$announcements = list_announcements_admin();
?>
<?php admin_header('Announcements', 'announcements'); ?>
  <h1>Announcements</h1>
  <p class="sub">Shown once to each student the next time they open their dashboard, until they dismiss it. CRs can post their own, scoped to their own class only.</p>

  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <label for="message">Message</label>
      <textarea id="message" name="message" rows="3" maxlength="500" required placeholder="e.g. Classes suspended tomorrow due to..."></textarea>
      <label style="margin-top:14px;">Who sees this</label>
      <p class="hint hint-tight">Tick one class, several, or Everyone.</p>
      <div class="scope-list" id="scopeList">
        <label class="check"><input type="checkbox" name="scope[]" value="all" checked data-all> <span>Everyone</span></label>
        <?php foreach ($classes as $c): if (!$c['dept'] && !$c['sec']) continue; ?>
          <label class="check"><input type="checkbox" name="scope[]" value="<?= esc($c['dept'] . '|' . $c['sec']) ?>"> <span><?= esc(trim($c['dept'] . ' ' . $c['sec'])) ?></span></label>
        <?php endforeach; ?>
      </div>
      <button type="submit">Post announcement</button>
    </form>
  </div>

  <div class="card" style="padding:0;">
    <?php if (!$announcements): ?>
      <p class="hint" style="padding:20px;margin:0;">No announcements posted yet.</p>
    <?php else: foreach ($announcements as $a): ?>
      <div class="person">
        <div class="person-text" style="max-width:none;">
          <span class="person-sub">
            <?= $a['author_type'] === 'admin' ? 'You' : 'CR ' . esc((string)$a['author_roll']) ?>
            <?= $a['dept'] ? '· ' . esc(trim((string)$a['dept'] . ' ' . (string)$a['sec'])) : '· Everyone' ?>
            · <?= esc(date('d M, g:i A', strtotime((string)$a['created_at']))) ?>
          </span>
          <span class="person-name" style="white-space:normal;font-weight:500;"><?= nl2br(esc((string)$a['message'])) ?></span>
        </div>
        <form method="post" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="small ghost" type="submit">Delete</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php admin_footer(['../assets/announce.js']); ?>
