<?php
declare(strict_types=1);
require_once __DIR__ . '/private/auth.php';
require_once __DIR__ . '/private/csrf.php';
require_once __DIR__ . '/private/functions.php';
start_secure_session();
$student = require_student();

$section = section_of_student($student);
$subjects = $section ? section_subjects($section) : [];
$byCode = [];
foreach ($subjects as $s) $byCode[$s['code']] = $s['label'];

$code = (string)($_GET['subject'] ?? '');
if ($code !== '' && !isset($byCode[$code])) $code = '';

$actor = $section ? material_actor($section) : null;   // CR of this section (or an admin) can edit
// Only editors get the per-subject manage page; everyone else gets the one-page browser, pre-filtered if a subject was given.
$initialSubject = '';
if (!$actor && $code !== '') { $initialSubject = $code; $code = ''; }
$manageSubject = $actor && $code !== '';
$all = ($section && !$manageSubject) ? list_section_materials($section) : [];
$typeCounts = [];
foreach ($all as $m) { [$cat] = material_category($m); $typeCounts[$cat] = ($typeCounts[$cat] ?? 0) + 1; }
$msg = $_SESSION['mat_msg'] ?? null;
unset($_SESSION['mat_msg']);

$counts = $section ? material_counts($section) : [];
$items = ($section && $code !== '') ? list_materials($section, $code) : [];
$requests = ($actor && $section) ? open_material_requests($section) : [];
$back = $code !== '' ? 'materials.php?subject=' . rawurlencode($code) : 'materials.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Echo — Study material</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#C5D8A4">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body class="has-tabbar">
<div class="wrap">
  <?= render_topbar() ?>

  <?php if ($msg): ?><div class="banner <?= $msg[0] === 'ok' ? '' : 'warn' ?>"><?= esc($msg[1]) ?></div><?php endif; ?>

  <?php if (!$section): ?>
    <h1>Study material</h1>
    <p class="sub">Your class isn't set up yet, so there are no subjects to show. Check back after the next attendance sync.</p>

  <?php elseif (!$manageSubject): ?>
    <h1>Study material</h1>
    <p class="sub" id="matSummary"><?= count($all) ?> resource<?= count($all) === 1 ? '' : 's' ?> across <?= count(array_filter($counts)) ?> of <?= count($subjects) ?> subjects · <?= esc($section) ?></p>

    <?php if ($actor && $requests): ?>
      <div class="card">
        <h2>Requests from your class (<?= count($requests) ?>)</h2>
        <p class="hint hint-tight">Upload what they asked for, then mark it done.</p>
        <?php foreach ($requests as $r): ?>
          <div class="mat-row">
            <div class="mat-main">
              <div class="mat-title"><?= esc($byCode[$r['subject']] ?? $r['subject']) ?> · <?= esc(MATERIAL_REQUEST_KINDS[$r['kind']] ?? 'Anything') ?></div>
              <div class="mat-meta"><?= esc($r['nickname'] ?: $r['roll_no']) ?> · <?= esc(date('j M, g:i A', strtotime((string)$r['created_at']))) ?></div>
              <div class="mat-desc"><?= nl2br(esc((string)$r['message'])) ?></div>
              <a class="fac-link" href="materials.php?subject=<?= esc(rawurlencode($r['subject'])) ?>">Open subject to upload ›</a>
            </div>
            <form method="post" action="material_manage.php" class="mat-actions">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="materials.php">
              <button class="small matcha" name="do" value="request_done" type="submit">Done</button>
              <button class="small ghost" name="do" value="request_dismiss" type="submit">Dismiss</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($actor): ?>
      <div class="card">
        <h2>Add or manage files</h2>
        <p class="hint hint-tight">Pick a subject to upload a file, add a link, or delete something.</p>
        <div class="chip-row">
          <?php foreach ($subjects as $s): ?><a class="chip" href="materials.php?subject=<?= esc(rawurlencode($s['code'])) ?>"><?= esc($s['label']) ?> <span class="chip-n"><?= (int)($counts[$s['code']] ?? 0) ?></span></a><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$all): ?>
      <div class="card empty-state">
        <div class="empty-icon" aria-hidden="true">📚</div>
        <h2>Nothing here yet</h2>
        <p class="hint">No notes or slides have been added for your class. Ask your CR for what you need using the box below.</p>
      </div>
    <?php else: ?>
      <div class="mat-browser" id="matBrowser" data-initial-subject="<?= esc($initialSubject) ?>">
        <div class="card mat-tools">
          <div class="mat-search">
            <input type="search" id="matSearch" placeholder="Search notes, slides, links…" aria-label="Search study material" autocomplete="off">
          </div>
          <div class="chip-row" id="matSubjects" role="group" aria-label="Filter by subject">
            <button type="button" class="chip on" data-subject="" aria-pressed="true">All <span class="chip-n"><?= count($all) ?></span></button>
            <?php foreach ($subjects as $s): $n = (int)($counts[$s['code']] ?? 0); if (!$n) continue; ?>
              <button type="button" class="chip" data-subject="<?= esc($s['code']) ?>" aria-pressed="false"><?= esc($s['label']) ?> <span class="chip-n"><?= $n ?></span></button>
            <?php endforeach; ?>
          </div>
          <div class="mat-tools-row">
            <div class="chip-row small" id="matTypes" role="group" aria-label="Filter by type">
              <button type="button" class="chip on" data-type="" aria-pressed="true">Any type</button>
              <?php foreach (MATERIAL_CATEGORIES as $k => $label): if (empty($typeCounts[$k])) continue; ?>
                <button type="button" class="chip" data-type="<?= $k ?>" aria-pressed="false"><?= esc($label) ?> <span class="chip-n"><?= (int)$typeCounts[$k] ?></span></button>
              <?php endforeach; ?>
              <button type="button" class="chip" data-type="saved" aria-pressed="false" id="matSavedChip">★ Saved <span class="chip-n" id="matSavedN">0</span></button>
            </div>
            <select id="matSort" aria-label="Sort by">
              <option value="new">Newest first</option>
              <option value="old">Oldest first</option>
              <option value="az">A to Z</option>
              <option value="subject">By subject</option>
            </select>
          </div>
          <div class="mat-count" id="matCount" aria-live="polite"></div>
        </div>

        <div id="matList">
          <?php foreach ($all as $m):
            [$cat, $badge] = material_category($m);
            $isFile = $m['kind'] === 'file';
            $open = $isFile ? 'view.php?id=' . (int)$m['id'] : (string)$m['url'];
            $dlUrl = 'material_download.php?id=' . (int)$m['id'] . '&amp;dl=1';
            $isNew = time() - strtotime((string)$m['created_at']) < 7 * 86400;
            $subjLabel = $byCode[$m['subject']] ?? $m['subject'];
            $search = mb_strtolower($m['title'] . ' ' . $m['description'] . ' ' . $subjLabel . ' ' . ($isFile ? $m['orig_name'] : parse_url((string)$m['url'], PHP_URL_HOST)) . ' ' . $badge);
          ?>
            <article class="mat-card" data-id="<?= (int)$m['id'] ?>" data-subject="<?= esc((string)$m['subject']) ?>" data-subject-label="<?= esc($subjLabel) ?>" data-type="<?= $cat ?>"
                     data-ts="<?= (int)strtotime((string)$m['created_at']) ?>" data-title="<?= esc(mb_strtolower((string)$m['title'])) ?>" data-search="<?= esc($search) ?>">
              <span class="mat-badge t-<?= $cat ?>"><?= esc($badge) ?></span>
              <div class="mat-main">
                <a class="mat-title" href="<?= esc($open) ?>"<?= $isFile ? '' : ' target="_blank" rel="noopener noreferrer"' ?>><?= esc($m['title']) ?></a>
                <?php if ($isNew): ?><span class="new-tag">NEW</span><?php endif; ?>
                <div class="mat-meta">
                  <span class="subj-pill"><?= esc($subjLabel) ?></span>
                  <?= $isFile ? esc(format_bytes((int)$m['size'])) : esc((string)(parse_url((string)$m['url'], PHP_URL_HOST) ?: 'external link')) ?>
                  · <?= esc(strtok((string)$m['uploader_label'], ' ') ?: 'Admin') ?> · <?= esc(material_age_label((string)$m['created_at'])) ?>
                </div>
                <?php if ($m['description'] !== ''): ?><div class="mat-desc"><?= esc($m['description']) ?></div><?php endif; ?>
                <div class="mat-btns">
                  <a class="small-btn" href="<?= esc($open) ?>"<?= $isFile ? '' : ' target="_blank" rel="noopener noreferrer"' ?>><?= $isFile ? 'View' : 'Visit link' ?></a>
                  <?php if ($isFile): ?><a class="small-btn ghost" href="<?= $dlUrl ?>">Download</a><?php endif; ?>
                </div>
              </div>
              <button type="button" class="star" data-star aria-pressed="false" aria-label="Save for later" title="Save for later">★</button>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="card empty-state" id="matEmpty" hidden>
          <div class="empty-icon" aria-hidden="true">🔍</div>
          <h2>No match</h2>
          <p class="hint">Nothing fits those filters. Try fewer words, or clear the filters.</p>
          <button type="button" class="small ghost" id="matClear">Clear filters</button>
        </div>
      </div>
    <?php endif; ?>

    <details class="card fold" id="askCr" <?= !$all ? 'open' : '' ?>>
      <summary>Can't find something? Ask your CR</summary>
      <p class="hint hint-tight">Tell your CR what you need and they can add it. You'll see it here once it's uploaded.</p>
      <form method="post" action="material_request.php">
        <?= csrf_field() ?>
        <label for="rs">Subject</label>
        <select id="rs" name="subject"><?php foreach ($subjects as $s): ?><option value="<?= esc($s['code']) ?>" <?= $s['code'] === $initialSubject ? 'selected' : '' ?>><?= esc($s['label']) ?></option><?php endforeach; ?></select>
        <label for="rk">What kind</label>
        <select id="rk" name="kind"><?php foreach (MATERIAL_REQUEST_KINDS as $k => $label): ?><option value="<?= esc($k) ?>"><?= esc($label) ?></option><?php endforeach; ?></select>
        <label for="rm">What do you need?</label>
        <textarea id="rm" name="message" rows="3" maxlength="300" required placeholder="e.g. Unit 3 slides, or previous year question papers"></textarea>
        <button type="submit">Send request</button>
      </form>
    </details>

  <?php else: ?>
    <a class="back" href="materials.php">‹ All study material</a>
    <h1><?= esc($byCode[$code]) ?></h1>
    <p class="sub"><?= count($items) ?> resource<?= count($items) === 1 ? '' : 's' ?> · <?= esc($section) ?></p>

    <div class="card" style="padding:0 20px;">
      <?php if (!$items): ?>
        <p class="hint" style="padding:20px 0;margin:0;">Nothing here yet. Add a file or a link below.</p>
      <?php else: foreach ($items as $m):
        $isFile = $m['kind'] === 'file';
        $href = $isFile ? 'view.php?id=' . (int)$m['id'] : (string)$m['url'];
        $ext = $isFile ? strtoupper(pathinfo((string)$m['orig_name'], PATHINFO_EXTENSION)) : 'LINK';
      ?>
        <div class="mat-row">
          <span class="mat-type"><?= esc($ext) ?></span>
          <div class="mat-main">
            <a class="mat-title" href="<?= esc($href) ?>" target="_blank" rel="noopener noreferrer"><?= esc($m['title']) ?></a>
            <div class="mat-meta">
              <?= $isFile ? esc(format_bytes((int)$m['size'])) . ' · ' : esc((string)(parse_url((string)$m['url'], PHP_URL_HOST) ?: 'external link')) . ' · ' ?>
              <?= esc($m['uploader_label']) ?> · <?= esc(date('j M Y', strtotime((string)$m['created_at']))) ?>
            </div>
            <?php if ($m['description'] !== ''): ?><div class="mat-desc"><?= esc($m['description']) ?></div><?php endif; ?>
          </div>
          <?php if ($actor): ?>
            <form method="post" action="material_manage.php" class="mat-actions" data-confirm="Delete this resource? This is recorded in the log.">
              <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="back" value="<?= esc($back) ?>">
              <button class="small ghost" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($actor): ?>
      <div class="card">
        <h2>Add a file</h2>
        <p class="hint hint-tight">PDF, Word, PowerPoint, Excel, text or image · up to <?= esc(format_bytes(server_upload_limit())) ?> here. Everything you add is recorded in a log.</p>
        <form method="post" action="material_manage.php" enctype="multipart/form-data" data-chunk-upload>
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="add_file"><input type="hidden" name="section" value="<?= esc($section) ?>">
          <input type="hidden" name="subject" value="<?= esc($code) ?>"><input type="hidden" name="back" value="<?= esc($back) ?>">
          <label for="mt">Title</label><input id="mt" name="title" maxlength="150" placeholder="e.g. Unit 2 notes">
          <label for="md">Short description (optional)</label><input id="md" name="description" maxlength="300">
          <label for="mf">File</label><input id="mf" name="file" type="file" required data-max-bytes="<?= (int)server_upload_limit() ?>" data-too-big="Add it as a link (for example Google Drive) instead." accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg">
          <button type="submit" class="matcha">Upload</button>
        </form>
      </div>
      <div class="card">
        <h2>Add a link</h2>
        <p class="hint hint-tight">For anything big — a Google Drive folder, a YouTube playlist, a website.</p>
        <form method="post" action="material_manage.php">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="add_link"><input type="hidden" name="section" value="<?= esc($section) ?>">
          <input type="hidden" name="subject" value="<?= esc($code) ?>"><input type="hidden" name="back" value="<?= esc($back) ?>">
          <label for="lt">Title</label><input id="lt" name="title" maxlength="150">
          <label for="ld">Short description (optional)</label><input id="ld" name="description" maxlength="300">
          <label for="lu">Link</label><input id="lu" name="url" type="url" required placeholder="https://…">
          <button type="submit" class="matcha">Add link</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?= render_tabbar('study', (int)$student['id']) ?>
<script src="assets/ui.js" defer></script>
<script src="assets/materials.js" defer></script>
<script src="assets/upload.js" defer></script>
</body>
</html>
