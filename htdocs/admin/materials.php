<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$sections = list_sections();
$section = (string)($_GET['section'] ?? (array_key_first($sections) ?? ''));
if (!isset($sections[$section])) $section = (string)(array_key_first($sections) ?? '');
$subjects = $section !== '' ? $sections[$section]['subjects'] : [];
$byCode = [];
foreach ($subjects as $s) $byCode[$s['code']] = $s['label'];
$code = (string)($_GET['subject'] ?? '');
if (!isset($byCode[$code])) $code = (string)(array_key_first($byCode) ?? '');

$msg = $_SESSION['mat_msg'] ?? null;
unset($_SESSION['mat_msg']);
$items = ($section !== '' && $code !== '') ? list_materials($section, $code) : [];
$requests = open_material_requests(null);
$log = recent_material_log(100);
$back = 'admin/materials.php?section=' . rawurlencode($section) . '&subject=' . rawurlencode($code);
$actionLabels = ['upload' => 'Uploaded', 'add_link' => 'Added link', 'delete' => 'Deleted', 'request_done' => 'Request done', 'request_dismiss' => 'Request dismissed'];
?>
<?php admin_header('Study material', 'materials'); ?>
  <h1>Study material</h1>
  <p class="sub">Manage resources for any class. CRs can manage their own class from the Study tab. Every upload, link and deletion is logged below.</p>
  <?php if ($msg): ?><div class="banner <?= $msg[0] === 'ok' ? '' : 'warn' ?>"><?= esc($msg[1]) ?></div><?php endif; ?>

  <?php if (!$sections): ?>
    <div class="banner warn">No sections yet — run the scraper first.</div>
  <?php else: ?>
  <form method="get" class="card">
    <div class="map-grid">
      <div><label for="section">Section</label>
        <select id="section" name="section" data-autosubmit>
          <?php foreach ($sections as $c => $info): ?><option value="<?= esc($c) ?>" <?= $c === $section ? 'selected' : '' ?>><?= esc($c) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="subject">Subject</label>
        <select id="subject" name="subject" data-autosubmit>
          <?php foreach ($subjects as $s): ?><option value="<?= esc($s['code']) ?>" <?= $s['code'] === $code ? 'selected' : '' ?>><?= esc($s['label']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <noscript><button type="submit">Open</button></noscript>
  </form>

  <div class="card" style="padding:0 20px;">
    <?php if (!$items): ?>
      <p class="hint" style="padding:20px 0;margin:0;">No resources for this subject yet.</p>
    <?php else: foreach ($items as $m): $isFile = $m['kind'] === 'file'; ?>
      <div class="mat-row">
        <span class="mat-type"><?= $isFile ? esc(strtoupper(pathinfo((string)$m['orig_name'], PATHINFO_EXTENSION))) : 'LINK' ?></span>
        <div class="mat-main">
          <a class="mat-title" target="_blank" rel="noopener noreferrer" href="<?= $isFile ? '../view.php?id=' . (int)$m['id'] : esc((string)$m['url']) ?>"><?= esc($m['title']) ?></a>
          <div class="mat-meta"><?= $isFile ? esc(format_bytes((int)$m['size'])) . ' · ' : '' ?><?= esc($m['uploader_label']) ?> · <?= esc(date('j M Y, g:i A', strtotime((string)$m['created_at']))) ?></div>
          <?php if ($m['description'] !== ''): ?><div class="mat-desc"><?= esc($m['description']) ?></div><?php endif; ?>
        </div>
        <form method="post" action="../material_manage.php" class="mat-actions" data-confirm="Delete this resource? This is recorded in the log.">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <input type="hidden" name="back" value="<?= esc($back) ?>">
          <button class="small ghost" type="submit">Delete</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($code !== ''): ?>
  <div class="card">
    <h2>Add a file</h2>
    <p class="hint hint-tight">Up to <?= esc(format_bytes(server_upload_limit())) ?> on this server. For bigger files, add a link instead.</p>
    <form method="post" action="../material_manage.php" enctype="multipart/form-data" data-chunk-upload>
      <?= csrf_field() ?><input type="hidden" name="do" value="add_file"><input type="hidden" name="section" value="<?= esc($section) ?>">
      <input type="hidden" name="subject" value="<?= esc($code) ?>"><input type="hidden" name="back" value="<?= esc($back) ?>">
      <label for="mt">Title</label><input id="mt" name="title" maxlength="150">
      <label for="md">Short description (optional)</label><input id="md" name="description" maxlength="300">
      <label for="mf">File</label><input id="mf" name="file" type="file" required data-max-bytes="<?= (int)server_upload_limit() ?>" data-too-big="Add it as a link (for example Google Drive) instead." accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg">
      <button type="submit" class="matcha">Upload</button>
    </form>
  </div>
  <div class="card">
    <h2>Add a link</h2>
    <form method="post" action="../material_manage.php">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_link"><input type="hidden" name="section" value="<?= esc($section) ?>">
      <input type="hidden" name="subject" value="<?= esc($code) ?>"><input type="hidden" name="back" value="<?= esc($back) ?>">
      <label for="lt">Title</label><input id="lt" name="title" maxlength="150">
      <label for="ld">Short description (optional)</label><input id="ld" name="description" maxlength="300">
      <label for="lu">Link</label><input id="lu" name="url" type="url" required placeholder="https://…">
      <button type="submit" class="matcha">Add link</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <h2>Open requests (all classes)</h2>
    <?php if (!$requests): ?><p class="hint hint-tight">None right now.</p><?php else: foreach ($requests as $r): ?>
      <div class="mat-row">
        <div class="mat-main">
          <div class="mat-title"><?= esc($r['section']) ?> · <?= esc($r['subject']) ?> · <?= esc(MATERIAL_REQUEST_KINDS[$r['kind']] ?? 'Anything') ?></div>
          <div class="mat-meta"><?= esc($r['nickname'] ?: $r['roll_no']) ?> · <?= esc(date('j M, g:i A', strtotime((string)$r['created_at']))) ?></div>
          <div class="mat-desc"><?= nl2br(esc((string)$r['message'])) ?></div>
        </div>
        <form method="post" action="../material_manage.php" class="mat-actions">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= esc($back) ?>">
          <button class="small matcha" name="do" value="request_done" type="submit">Done</button>
          <button class="small ghost" name="do" value="request_dismiss" type="submit">Dismiss</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>Activity log</h2>
    <p class="hint hint-tight">Last <?= count($log) ?> actions. Entries are never removed, even when a file is deleted.</p>
    <div class="tt-scroll">
      <table class="log-table">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Where</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($log as $l): ?>
          <tr>
            <td><?= esc(date('j M, g:i A', strtotime((string)$l['at']))) ?></td>
            <td><?= esc($l['actor_label']) ?><div class="muted"><?= esc($l['ip']) ?></div></td>
            <td><?= esc($actionLabels[$l['action']] ?? $l['action']) ?>: <?= esc($l['title']) ?></td>
            <td><?= esc($l['section']) ?> · <?= esc($l['subject']) ?></td>
            <td><?= esc($l['detail']) ?></td>
          </tr>
        <?php endforeach; if (!$log): ?><tr><td colspan="5" class="muted">Nothing logged yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php admin_footer(['../assets/announce.js', '../assets/upload.js']); ?>
