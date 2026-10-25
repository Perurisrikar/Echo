<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/auth.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/admin_ui.php';
start_secure_session();
require_admin();

$classes = list_classes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $scope = (string)($_POST['scope'] ?? '');
    $includeSet = !empty($_POST['include_set']);
    if ($scope === 'all') { $dept = null; $sec = null; }
    else { [$dept, $sec] = array_pad(explode('|', $scope, 2), 2, ''); }

    $cell = function ($v): string {
        $v = (string)($v ?? '');
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="echo-set-password-links-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ROLLNO', 'NAME', 'MOBILE', 'LINK', 'WHATSAPP']);
    foreach (students_by_class($dept, $sec, 'roll_no', 'asc') as $s) {
        if (!$includeSet && !empty($s['password_hash'])) continue;
        $link = generate_reset_link((int)$s['id']);
        $wa = whatsapp_link_for($s['mobile'] ?? null, reset_link_message($link)) ?? '';
        fputcsv($out, [$cell($s['roll_no']), $cell($s['name'] ?? ''), $cell($s['mobile'] ?? ''), $link, $wa]);
    }
    fclose($out);
    exit;
}
?>
<?php admin_header('Bulk set-password links', 'bulk'); ?>
  <h1>Bulk set-password links</h1>
  <p class="sub">For a new batch: creates a fresh 24-hour link for every student in a class and downloads them as a CSV (roll number, name, mobile, link, WhatsApp link). Each link replaces any earlier unused one for that student. Treat the file like passwords — delete it once the links are sent.</p>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <label for="scope">Class</label>
      <select id="scope" name="scope">
        <option value="all">Everyone</option>
        <?php foreach ($classes as $c): if (!$c['dept'] && !$c['sec']) continue; ?>
          <option value="<?= esc($c['dept'] . '|' . $c['sec']) ?>"><?= esc(trim($c['dept'] . ' ' . $c['sec'])) ?> (<?= (int)$c['count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <label class="check" style="margin-top:14px;"><input type="checkbox" name="include_set" value="1"> <span>Also students who already have a password</span></label>
      <button type="submit" class="matcha">Generate &amp; download CSV</button>
    </form>
  </div>
<?php admin_footer([]); ?>
