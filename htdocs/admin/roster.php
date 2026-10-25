<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$error = '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_exceeded_post_limit()) {
        $error = 'That file is larger than this server allows (' . format_bytes(server_post_limit()) . ').';
    } else {
        csrf_verify();
        $f = $_FILES['roster'] ?? [];
        $uploadErr = upload_error_message((int)($f['error'] ?? UPLOAD_ERR_NO_FILE), 'file');
        if ($uploadErr) {
            $error = $uploadErr;
        } else {
            try {
                $stream = roster_upload_stream((string)$f['tmp_name'], (string)($f['name'] ?? ''));
                $summary = import_roster_csv($stream, (array)($_POST['map'] ?? []));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                error_log('Echo roster import failed: ' . $e->getMessage());
                $error = 'The roster could not be imported because of a server problem.';
            }
        }
    }
}

admin_header('Import roster', 'roster');
?>
  <h1>Import roster</h1>
  <p class="sub">Upload an Excel (<code>.xlsx</code>) or CSV file with columns like <code>ROLLNO, NAME, DEPT, SEC, EMAILID, MOBILE</code>
     (any column order is fine). Existing roll numbers get their name, department, section, email and mobile
     updated; new roll numbers are added. Nicknames, bios, photos and passwords are never touched.</p>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label for="roster">Roster file (.xlsx or .csv)</label>
      <input id="roster" name="roster" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-max-bytes="<?= (int)min(5 * 1048576, server_post_limit()) ?>" required>

      <h2 style="margin-top:20px;">Column names in your file</h2>
      <p class="hint hint-tight">Type what each column is called in <em>your</em> file. The boxes start with the usual names, so if your file already uses them, leave everything as is. Capital letters and spaces don't matter.</p>
      <div class="map-grid">
        <?php foreach (['ROLLNO' => 'Roll number (required)', 'NAME' => 'Name', 'DEPT' => 'Department', 'SEC' => 'Section', 'EMAILID' => 'Email', 'MOBILE' => 'Mobile'] as $k => $label): ?>
          <div>
            <label for="map_<?= $k ?>"><?= esc($label) ?></label>
            <input id="map_<?= $k ?>" name="map[<?= $k ?>]" value="<?= esc((string)($_POST['map'][$k] ?? $k)) ?>" autocomplete="off">
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="matcha">Import</button>
      <?php if ($error): ?><div class="banner warn" style="margin-top:14px;"><?= esc($error) ?></div><?php endif; ?>
    </form>
    <?php if ($summary && !empty($summary['noRoll'])): ?>
      <div class="banner warn" style="margin-top:16px;">
        Couldn't find the roll number column. Type its exact name from your file in the “Roll number” box.
        Columns found in your file: <strong><?= esc(implode(', ', $summary['headers'] ?? [])) ?></strong>
      </div>
    <?php elseif ($summary): ?>
      <div class="banner" style="margin-top:16px;">
        Updated <?= (int)$summary['updated'] ?>, added <?= (int)$summary['inserted'] ?>,
        skipped <?= (int)$summary['skipped'] ?> row(s) with no roll number.
      </div>
      <?php if (!empty($summary['notFound'])): ?>
        <div class="banner warn">These names weren't found in your file, so those fields were read automatically instead: <strong><?= esc(implode(', ', $summary['notFound'])) ?></strong></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php admin_footer(); ?>
