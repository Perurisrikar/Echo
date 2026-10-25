<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$sections = list_sections();
$section = (string)($_GET['section'] ?? $_POST['section'] ?? (array_key_first($sections) ?? ''));
if (!isset($sections[$section])) $section = (string)(array_key_first($sections) ?? '');
$perDay = max(1, min(12, classes_per_day()));
$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
$codes = $section !== '' ? array_map(fn($x) => $x['code'], $sections[$section]['subjects']) : [];
$labels = [];
if ($section !== '') foreach ($sections[$section]['subjects'] as $sub) $labels[$sub['code']] = $sub['label'];

$saved = false;
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section !== '') {
    if (request_exceeded_post_limit()) {
        $error = 'That file is larger than this server allows (' . format_bytes(server_post_limit()) . '). A timetable sheet should be tiny — save just the timetable.';
    } else {
        csrf_verify();
        $do = (string)($_POST['do'] ?? 'save');

        if ($do === 'upload') {
            $f = $_FILES['sheet'] ?? [];
            $upErr = upload_error_message((int)($f['error'] ?? UPLOAD_ERR_NO_FILE), 'file');
            if ($upErr) {
                $error = $upErr;
            } else {
                try {
                    $res = parse_timetable_upload((string)$f['tmp_name'], (string)($f['name'] ?? ''), $section, $codes, $labels);
                    $res['file'] = mb_substr(basename((string)($f['name'] ?? '')), 0, 80);
                    if (!$res['found']) {
                        $error = 'Could not find a timetable in that file. ' . implode(' ', $res['notes']) . ' Use "Download template" for a layout that always works.';
                    } else {
                        $_SESSION['tt_import'] = ['section' => $section] + $res;
                        header('Location: timetable.php?section=' . rawurlencode($section) . '&imported=1');
                        exit;
                    }
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Echo timetable import failed: ' . $e->getMessage());
                    $error = 'That file could not be read. Try saving it again as .xlsx, or use the template.';
                }
            }
        } else {
            $grid = (array)($_POST['tt'] ?? []);
            $facRows = (array)($_POST['fac'] ?? []);
            save_timetable($section, $grid, $codes);
            save_faculty($section, $facRows, $codes);
            foreach ((array)($_POST['copy_to'] ?? []) as $other) {
                if (!is_string($other) || $other === $section || !isset($sections[$other])) continue;
                $otherCodes = array_map(fn($x) => $x['code'], $sections[$other]['subjects']);
                save_timetable($other, $grid, $otherCodes);
                save_faculty($other, $facRows, $otherCodes);
            }
            unset($_SESSION['tt_import']);
            $saved = true;
        }
    }
}

// An uploaded sheet pre-fills the form (nothing is saved until you press Save).
$import = null;
if (isset($_GET['imported']) && !empty($_SESSION['tt_import']) && ($_SESSION['tt_import']['section'] ?? '') === $section) {
    $import = $_SESSION['tt_import'];
}
$tt = $section !== '' ? get_timetable($section) : [];
$fac = $section !== '' ? get_faculty($section) : [];
if ($import) {
    $tt = $import['grid'];
    foreach ($import['faculty'] as $code => $row) {
        $fac[$code] = ['name' => $row['name'], 'mobile' => $row['mobile'], 'gender' => $fac[$code]['gender'] ?? ''];
    }
}
$book = faculty_gender_book();
$withNumber = 0;
$genderSet = 0;
foreach ($codes as $c) {
    $f = $fac[$c] ?? null;
    if (!$f || ($f['name'] === '' && $f['mobile'] === '')) continue;
    $withNumber++;
    if (($f['gender'] ?? '') !== '' || isset($book[faculty_person_key($f['name'], $f['mobile'])])) $genderSet++;
}
$sectionsWithoutTT = [];
foreach ($sections as $code => $info) if (!get_timetable($code)) $sectionsWithoutTT[] = $code;

admin_header('Timetables', 'timetable');
?>
  <h1>Timetables</h1>
  <p class="sub">Set each section's weekly timetable once — type it in, or upload an Excel sheet. Students then get “Today's classes”, a heads-up when a class is at risk, and a Contact-faculty button for each subject.</p>

  <?php if (!$sections): ?>
    <div class="banner warn">No attendance data yet — run the scraper first so sections and subjects exist.</div>
  <?php else: ?>
    <?php if ($saved): ?><div class="banner">Timetable saved.</div><?php endif; ?>
    <?php if ($error): ?><div class="banner warn"><?= esc($error) ?></div><?php endif; ?>

    <div class="tt-top">
      <form method="get" class="card">
        <label for="section">Section</label>
        <select id="section" name="section" data-autosubmit>
          <?php foreach ($sections as $code => $info): ?>
            <option value="<?= esc($code) ?>" <?= $code === $section ? 'selected' : '' ?>><?= esc($code) ?> (<?= (int)$info['count'] ?> students)<?= in_array($code, $sectionsWithoutTT, true) ? ' — no timetable' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($sectionsWithoutTT): ?><p class="hint hint-tight"><?= count($sectionsWithoutTT) ?> of <?= count($sections) ?> sections still have no timetable.</p><?php endif; ?>
        <noscript><button type="submit">Open</button></noscript>
      </form>

      <form method="post" enctype="multipart/form-data" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="<?= esc($section) ?>">
        <input type="hidden" name="do" value="upload">
        <label for="sheet">Upload an Excel timetable for <?= esc($section) ?></label>
        <input id="sheet" name="sheet" type="file" accept=".xlsx,.xlsm,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" data-max-bytes="<?= (int)min(5 * 1048576, server_post_limit()) ?>" data-too-big="A timetable sheet should be tiny — save just that sheet." required>
        <p class="hint hint-tight">Days (Mon–Fri) down the side and periods across, with subject names or codes in the cells. A faculty list (name and mobile beside each subject) is picked up too. Nothing is saved until you check it below and press Save.</p>
        <div class="row" style="gap:8px;flex-wrap:wrap;">
          <button type="submit" class="matcha">Read file</button>
          <a class="btn ghost btn-link" href="timetable_template.php?section=<?= esc(rawurlencode($section)) ?>">Download template</a>
        </div>
      </form>
    </div>

    <?php if ($import): ?>
      <div class="banner">
        Read <strong><?= esc((string)$import['file']) ?></strong><?= $import['sheet'] !== '' ? ' (sheet “' . esc((string)$import['sheet']) . '”)' : '' ?>:
        <?= array_sum(array_map('count', $import['grid'])) ?> periods and <?= count($import['faculty']) ?> faculty found.
        <strong>Check it, then press Save.</strong> The file has no gender column, so faculty keep the gender you set before — until set, messages say “Sir/Madam”.
        <?php foreach ($import['notes'] as $n): ?><div class="hint" style="margin:6px 0 0;"><?= esc((string)$n) ?></div><?php endforeach; ?>
      </div>
      <?php if (!empty($import['unmatched'])): ?>
        <div class="banner warn">
          These cells didn't match any subject of <?= esc($section) ?> — pick them by hand below:
          <?php foreach (array_slice($import['unmatched'], 0, 12) as $u): ?>
            <span class="tag unset"><?= esc($days[$u['dow']] ?? '?') ?> P<?= (int)$u['period'] ?>: <?= esc(mb_substr((string)$u['text'], 0, 40)) ?></span>
          <?php endforeach; ?>
          <?php if (count($import['unmatched']) > 12): ?> and <?= count($import['unmatched']) - 12 ?> more<?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="<?= esc($section) ?>">
      <input type="hidden" name="do" value="save">
      <h2>Weekly timetable — <?= esc($section) ?></h2>
      <div class="tt-scroll">
        <table class="tt-table">
          <thead><tr><th></th><?php for ($p = 1; $p <= $perDay; $p++): ?><th>P<?= $p ?></th><?php endfor; ?></tr></thead>
          <tbody>
          <?php foreach ($days as $d => $label): ?>
            <tr><th><?= $label ?></th>
              <?php for ($p = 1; $p <= $perDay; $p++): $cur = $tt[$d][$p] ?? ''; ?>
                <td><select name="tt[<?= $d ?>][<?= $p ?>]" aria-label="<?= $label ?> period <?= $p ?>">
                  <option value="">—</option>
                  <?php foreach ($sections[$section]['subjects'] as $sub): ?>
                    <option value="<?= esc($sub['code']) ?>" <?= $cur === $sub['code'] ? 'selected' : '' ?>><?= esc($sub['label']) ?></option>
                  <?php endforeach; ?>
                </select></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($import): $over = 0; foreach ($import['grid'] as $dd => $pp) foreach ($pp as $pn => $_) if ($pn > $perDay) $over++; if ($over): ?>
        <div class="banner warn" style="margin-top:12px;"><?= $over ?> imported period(s) fall after period <?= $perDay ?> and are not shown. Raise “Classes per day” in Settings first if you need them.</div>
      <?php endif; endif; ?>

      <h2 style="margin-top:24px;">Faculty contacts</h2>
      <p class="hint hint-tight">Optional. Each subject gets a “Contact faculty” button that opens a WhatsApp chat with a polite message such as “<?= esc(time_greeting()) ?> Sir, I am … from <?= esc($section) ?>, messaging you regarding …”. <strong>Set Sir or Madam for each person</strong> — until then the message says “Sir/Madam”. Mobile: 10 digits, or with country code.</p>
      <?php if ($withNumber > 0): ?><p class="hint hint-tight"><?= $genderSet ?> of <?= $withNumber ?> faculty have a gender set.</p><?php endif; ?>
      <div class="fac-grid">
        <?php foreach ($sections[$section]['subjects'] as $sub): $f = $fac[$sub['code']] ?? ['name' => '', 'mobile' => '', 'gender' => '']; 
            $g = $f['gender'] !== '' ? $f['gender'] : ($book[faculty_person_key($f['name'], $f['mobile'])] ?? ''); ?>
          <div class="fac-row">
            <div class="fac-subject"><?= esc($sub['label']) ?></div>
            <input name="fac[<?= esc($sub['code']) ?>][name]" value="<?= esc($f['name']) ?>" placeholder="Faculty name" maxlength="80" autocomplete="off" aria-label="Faculty name for <?= esc($sub['label']) ?>">
            <input name="fac[<?= esc($sub['code']) ?>][mobile]" value="<?= esc($f['mobile']) ?>" placeholder="Mobile number" inputmode="tel" maxlength="20" autocomplete="off" aria-label="Mobile for <?= esc($sub['label']) ?>">
            <select name="fac[<?= esc($sub['code']) ?>][gender]" aria-label="Sir or Madam for <?= esc($sub['label']) ?>">
              <option value="" <?= $g === '' ? 'selected' : '' ?>>Not set (Sir/Madam)</option>
              <option value="M" <?= $g === 'M' ? 'selected' : '' ?>>Sir</option>
              <option value="F" <?= $g === 'F' ? 'selected' : '' ?>>Madam</option>
            </select>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($sections) > 1): ?>
        <label style="margin-top:16px;">Also apply this timetable and these faculty contacts to</label>
        <div class="scope-list">
          <?php foreach ($sections as $code => $info): if ($code === $section) continue; ?>
            <label class="check"><input type="checkbox" name="copy_to[]" value="<?= esc($code) ?>"> <span><?= esc($code) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="hint hint-tight">Only subjects that the other section also has are copied.</p>
      <?php endif; ?>
      <button type="submit" class="matcha">Save timetable</button>
    </form>
  <?php endif; ?>
<?php admin_footer(['../assets/announce.js']); ?>
