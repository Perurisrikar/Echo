<?php
declare(strict_types=1);

/**
 * Reads .xlsx (and .csv) uploads with no extension or library: an .xlsx is a
 * zip of XML files, so this reads the zip directly (gzinflate is part of core
 * PHP) and parses the few XML parts it needs. Used for timetable import.
 *
 * Everything here is defensive: uploads are untrusted, so sizes are capped
 * before anything is inflated and malformed files raise RuntimeException
 * with a plain-English message.
 */

const XLSX_MAX_FILE_BYTES  = 5 * 1024 * 1024;   // an uploaded workbook
const XLSX_MAX_ENTRY_BYTES = 20 * 1024 * 1024;  // any one file inside it, once inflated
const XLSX_MAX_ROWS        = 3000;
const XLSX_MAX_COLS        = 80;

/** Reads the named zip entries (exact names) into [name => bytes]. */
function zip_read_entries(string $path, array $names): array
{
    $size = @filesize($path);
    if ($size === false || $size < 22) throw new RuntimeException('That file is empty or not a valid Excel file.');
    if ($size > XLSX_MAX_FILE_BYTES) throw new RuntimeException('That workbook is larger than ' . (XLSX_MAX_FILE_BYTES >> 20) . ' MB. A timetable should be far smaller — save just the timetable sheet.');
    $data = file_get_contents($path);
    if ($data === false) throw new RuntimeException('Could not read the uploaded file.');

    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false || $eocd + 22 > strlen($data)) throw new RuntimeException('This does not look like an .xlsx file. Save it as "Excel Workbook (.xlsx)" and try again.');
    $e = unpack('vdisk/vcddisk/ventriesdisk/ventries/Vcdsize/Vcdoff', substr($data, $eocd + 4, 16));
    $pos = $e['cdoff'];
    $out = [];
    $want = array_flip($names);

    for ($i = 0; $i < $e['entries'] && $i < 2000; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") break;
        $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($data, $pos + 10, 32 - 4));
        // offsets: method@10 time@12 date@14 crc@16 csize@20 usize@24 nlen@28 elen@30 clen@32
        $nameLen = $h['nlen'];
        $localOff = unpack('V', substr($data, $pos + 42, 4))[1];
        $name = substr($data, $pos + 46, $nameLen);
        $pos += 46 + $nameLen + $h['elen'] + $h['clen'];
        if (!isset($want[$name])) continue;
        if ($h['usize'] > XLSX_MAX_ENTRY_BYTES) throw new RuntimeException('The workbook contains an unusually large part and was not read.');
        if (substr($data, $localOff, 4) !== "PK\x03\x04") throw new RuntimeException('The workbook is damaged.');
        $l = unpack('vnlen/velen', substr($data, $localOff + 26, 4));
        $raw = substr($data, $localOff + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        if ($h['method'] === 0) $out[$name] = $raw;
        elseif ($h['method'] === 8) {
            $inf = @gzinflate($raw, XLSX_MAX_ENTRY_BYTES);
            if ($inf === false) throw new RuntimeException('The workbook is damaged.');
            $out[$name] = $inf;
        } else throw new RuntimeException('Unsupported compression inside the workbook.');
    }
    return $out;
}

/** All entry names in the zip (to find worksheets). */
function zip_entry_names(string $path): array
{
    $data = file_get_contents($path, false, null, 0, XLSX_MAX_FILE_BYTES + 1);
    if ($data === false) return [];
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) return [];
    $e = unpack('vdisk/vcddisk/ventriesdisk/ventries/Vcdsize/Vcdoff', substr($data, $eocd + 4, 16));
    $pos = $e['cdoff'];
    $names = [];
    for ($i = 0; $i < $e['entries'] && $i < 2000; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") break;
        $h = unpack('vnlen/velen/vclen', substr($data, $pos + 28, 6));
        $names[] = substr($data, $pos + 46, $h['nlen']);
        $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }
    return $names;
}

function xlsx_xml(string $xml): SimpleXMLElement
{
    // Drop the default namespace so plain element names work; keep prefixed ones (r:id).
    $xml = preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1) ?? $xml;
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$x) throw new RuntimeException('The workbook could not be read (damaged XML).');
    return $x;
}

function xlsx_col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
    $n = 0;
    for ($i = 0, $l = strlen($letters); $i < $l; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return max(0, $n - 1);
}

function xlsx_clean(string $v): string
{
    $v = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $v);
    return trim($v);
}

function xlsx_number_text(string $v): string
{
    if (!is_numeric($v)) return trim($v);
    if (stripos($v, 'e') !== false) return sprintf('%.0f', (float)$v);
    if (str_contains($v, '.')) { $f = (float)$v; return $f == floor($f) ? (string)(int)$f : rtrim(rtrim($v, '0'), '.'); }
    return $v;
}

/** @return list<array{name:string, rows:array<int,array<int,string>>}> rows are [rowIndex => [colIndex => text]] (0-based), merged cells filled in. */
function xlsx_read_sheets(string $path): array
{
    $names = zip_entry_names($path);
    if (!in_array('xl/workbook.xml', $names, true)) {
        throw new RuntimeException('This does not look like an .xlsx file (an older .xls cannot be read — open it in Excel and use Save As → Excel Workbook).');
    }
    $sheetFiles = array_values(array_filter($names, fn($n) => preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)));
    $parts = zip_read_entries($path, array_merge(['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml'], $sheetFiles));

    $shared = [];
    if (isset($parts['xl/sharedStrings.xml'])) {
        $ss = xlsx_xml($parts['xl/sharedStrings.xml']);
        foreach ($ss->si as $si) {
            $t = '';
            foreach ($si->xpath('.//t') ?: [] as $tn) $t .= (string)$tn;
            $shared[] = $t;
        }
    }

    $rels = [];
    if (isset($parts['xl/_rels/workbook.xml.rels'])) {
        foreach (xlsx_xml($parts['xl/_rels/workbook.xml.rels'])->Relationship as $r) {
            $t = (string)$r['Target'];
            $rels[(string)$r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
    }

    $sheets = [];
    $wb = xlsx_xml($parts['xl/workbook.xml']);
    $n = 0;
    foreach ($wb->sheets->sheet ?? [] as $sh) {
        $n++;
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $file = $rels[$rid] ?? ('xl/worksheets/sheet' . $n . '.xml');
        if (!isset($parts[$file])) continue;
        $sheets[] = ['name' => (string)$sh['name'], 'rows' => xlsx_parse_sheet(xlsx_xml($parts[$file]), $shared)];
    }
    if (!$sheets) throw new RuntimeException('No sheets were found in that workbook.');
    return $sheets;
}

function xlsx_parse_sheet(SimpleXMLElement $ws, array $shared): array
{
    $rows = [];
    $r = -1;
    foreach ($ws->sheetData->row ?? [] as $row) {
        $r = isset($row['r']) ? ((int)$row['r'] - 1) : $r + 1;
        if ($r >= XLSX_MAX_ROWS) break;
        $c = -1;
        foreach ($row->c as $cell) {
            $c = isset($cell['r']) ? xlsx_col_index((string)$cell['r']) : $c + 1;
            if ($c >= XLSX_MAX_COLS) continue;
            $type = (string)$cell['t'];
            if ($type === 's') $val = $shared[(int)$cell->v] ?? '';
            elseif ($type === 'inlineStr') { $val = ''; foreach ($cell->xpath('.//t') ?: [] as $tn) $val .= (string)$tn; }
            elseif ($type === 'b') $val = ((string)$cell->v) === '1' ? 'TRUE' : 'FALSE';
            elseif ($type === 'str' || $type === 'e') $val = (string)$cell->v;
            else $val = xlsx_number_text((string)$cell->v);
            $val = xlsx_clean($val);
            if ($val !== '') $rows[$r][$c] = $val;
        }
    }
    // Fill merged ranges (a lab block across 3 periods, a "LUNCH" cell down every day).
    foreach ($ws->mergeCells->mergeCell ?? [] as $m) {
        if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', strtoupper((string)$m['ref']), $mm)) continue;
        [$c1, $r1, $c2, $r2] = [xlsx_col_index($mm[1]), (int)$mm[2] - 1, xlsx_col_index($mm[3]), (int)$mm[4] - 1];
        $v = $rows[$r1][$c1] ?? '';
        if ($v === '' || ($r2 - $r1) > 60 || ($c2 - $c1) > XLSX_MAX_COLS) continue;
        for ($rr = $r1; $rr <= min($r2, XLSX_MAX_ROWS - 1); $rr++) {
            for ($cc = $c1; $cc <= min($c2, XLSX_MAX_COLS - 1); $cc++) {
                if (!isset($rows[$rr][$cc])) $rows[$rr][$cc] = $v;
            }
        }
    }
    ksort($rows);
    foreach ($rows as &$cols) ksort($cols);
    return $rows;
}

/** CSV in the same shape as one xlsx sheet. */
function csv_read_rows(string $path): array
{
    $size = @filesize($path);
    if ($size === false || $size > XLSX_MAX_FILE_BYTES) throw new RuntimeException('That file is too large for a timetable.');
    $text = file_get_contents($path);
    if ($text === false) throw new RuntimeException('Could not read the uploaded file.');
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $first = strtok($text, "\n") ?: '';
    $delim = ',';
    foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    $r = 0;
    while (($line = fgetcsv($fh, 0, $delim, '"', '')) !== false && $r < XLSX_MAX_ROWS) {
        foreach ($line as $c => $v) {
            if ($c >= XLSX_MAX_COLS) break;
            $v = xlsx_clean((string)$v);
            if ($v !== '') $rows[$r][$c] = $v;
        }
        $r++;
    }
    fclose($fh);
    return $rows;
}

// ---------------------------------------------------------------------
// Timetable detection
// ---------------------------------------------------------------------

function tt_norm(string $s): string
{
    return trim(preg_replace('/[^A-Z0-9&]+/', ' ', strtoupper($s)) ?? '');
}

function tt_day_of(string $cell): ?int
{
    static $map = ['MON' => 1, 'MONDAY' => 1, 'TUE' => 2, 'TUES' => 2, 'TUESDAY' => 2, 'WED' => 3, 'WEDNESDAY' => 3,
                   'THU' => 4, 'THUR' => 4, 'THURS' => 4, 'THURSDAY' => 4, 'FRI' => 5, 'FRIDAY' => 5, 'SAT' => 6, 'SATURDAY' => 6];
    return $map[tt_norm($cell)] ?? null;
}

function tt_is_break(string $cell): bool
{
    return (bool)preg_match('/^(LUNCH|BREAK|SHORT BREAK|LUNCH BREAK|RECESS|INTERVAL|TEA BREAK|LB|LUNCH\s*[-–]\s*BREAK)$/', tt_norm($cell));
}

/** Subject code (from $codes) a cell refers to, or null. */
function tt_match_code(string $cell, array $codes, array $labels = []): ?string
{
    $n = ' ' . tt_norm($cell) . ' ';
    if (trim($n) === '') return null;
    $viaCode = tt_match_code_only($n, $codes);
    if ($viaCode !== null || !$labels) return $viaCode;
    // Sheets often spell the subject out (e.g. "Applied Physics Lab") instead of using the short code.
    foreach ($labels as $code => $label) {
        $l = tt_norm((string)$label);
        if ($l !== '' && trim($n) === $l) return (string)$code;
    }
    foreach ($labels as $code => $label) {
        $l = tt_norm((string)$label);
        if (strlen($l) >= 4 && (str_contains($n, ' ' . $l . ' ') || (strlen(trim($n)) >= 6 && str_contains(' ' . $l . ' ', $n)))) return (string)$code;
    }
    return null;
}

function tt_match_code_only(string $n, array $codes): ?string
{
    $byLen = $codes;
    usort($byLen, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($byLen as $code) {
        if (' ' . tt_norm($code) . ' ' === $n) return $code; // exact (ignoring spaces/punctuation case)
    }
    foreach ($byLen as $code) {
        $c = tt_norm($code);
        if ($c !== '' && str_contains($n, ' ' . $c . ' ')) return $code; // code appears as whole word(s) in the cell
    }
    return null;
}

/** Transposes [r => [c => v]] to [c => [r => v]]. */
function tt_transpose(array $rows): array
{
    $t = [];
    foreach ($rows as $r => $cols) foreach ($cols as $c => $v) $t[$c][$r] = $v;
    ksort($t);
    return $t;
}

/** Finds day rows: [[row, dow, labelCol], ...] needing at least three different weekdays. */
function tt_find_day_rows(array $rows): array
{
    $found = [];
    foreach ($rows as $r => $cols) {
        foreach ($cols as $c => $v) {
            if ($c > 3) break;
            $d = tt_day_of($v);
            if ($d !== null) { $found[] = [$r, $d, $c]; break; }
        }
    }
    return count(array_unique(array_column($found, 1))) >= 3 ? $found : [];
}

/**
 * Turns one sheet into a timetable.
 * @param string[] $codes the section's subject codes
 * @return array{grid:array,unmatched:array,faculty:array,notes:array,found:bool}
 */
function timetable_from_rows(array $rows, array $codes, int $maxPeriods = 12, array $labels = []): array
{
    $res = ['grid' => [], 'unmatched' => [], 'faculty' => [], 'notes' => [], 'found' => false];
    $grid = $rows;
    $dayRows = tt_find_day_rows($grid);
    if (!$dayRows) { // days might run across the top instead of down the side
        $grid = tt_transpose($rows);
        $dayRows = tt_find_day_rows($grid);
        if ($dayRows) $res['notes'][] = 'Days were across the top of your sheet, so it was read sideways.';
    }
    if (!$dayRows) {
        $res['notes'][] = 'Could not find the days (Mon, Tue, Wed…) in the first columns of the sheet.';
    } else {
        $res['found'] = true;
        $labelCol = min(array_column($dayRows, 2));
        $firstRow = $dayRows[0][0];

        $maxCol = 0;
        foreach ($dayRows as [$r]) $maxCol = max($maxCol, empty($grid[$r]) ? 0 : max(array_keys($grid[$r])));
        // Header row: nearest non-empty row above the first day row.
        $header = [];
        for ($r = $firstRow - 1; $r >= max(0, $firstRow - 4); $r--) if (!empty($grid[$r])) { $header = $grid[$r]; break; }

        $periodCols = [];
        for ($c = $labelCol + 1; $c <= $maxCol; $c++) {
            $vals = [];
            foreach ($dayRows as [$r]) { $v = $grid[$r][$c] ?? ''; if ($v !== '') $vals[] = $v; }
            $hdr = $header[$c] ?? '';
            if (!$vals) continue;                                             // empty spacer column
            if (tt_is_break($hdr)) continue;                                  // header says break
            if (count(array_filter($vals, 'tt_is_break')) === count($vals)) continue; // every cell is a break
            $periodCols[] = $c;
        }
        if (count($periodCols) > $maxPeriods) {
            $res['notes'][] = 'The sheet has ' . count($periodCols) . ' period columns; only the first ' . $maxPeriods . ' were used.';
            $periodCols = array_slice($periodCols, 0, $maxPeriods);
        }

        foreach ($dayRows as [$r, $dow]) {
            if ($dow > 5) { $res['notes'][] = 'Saturday row ignored (use the calendar to make a Saturday follow a weekday).'; continue; }
            foreach ($periodCols as $i => $c) {
                $text = $grid[$r][$c] ?? '';
                if ($text === '' || tt_is_break($text)) continue;
                $code = tt_match_code($text, $codes, $labels);
                if ($code !== null) $res['grid'][$dow][$i + 1] = $code;
                else $res['unmatched'][] = ['dow' => $dow, 'period' => $i + 1, 'text' => $text];
            }
        }
        $res['notes'] = array_values(array_unique($res['notes']));
    }

    // Faculty table: a header row mentioning faculty/teacher, rows below it.
    foreach ($rows as $r => $cols) {
        $facCol = $mobCol = null;
        $subCols = [];
        foreach ($cols as $c => $v) {
            $u = tt_norm($v);
            if ($facCol === null && preg_match('/\b(FACULTY|TEACHER|STAFF|LECTURER|PROFESSOR|HANDLED BY|INCHARGE|IN CHARGE)\b/', $u)) $facCol = $c;
            elseif ($mobCol === null && preg_match('/\b(MOBILE|PHONE|CONTACT|CELL|WHATSAPP)\b/', $u)) $mobCol = $c;
            elseif (preg_match('/\b(SUBJECT|COURSE|PAPER|CODE)\b/', $u)) $subCols[] = $c;
        }
        if ($facCol === null) continue;
        $blank = 0;
        $end = min($r + 40, XLSX_MAX_ROWS);
        for ($rr = $r + 1; $rr <= $end; $rr++) {
            $rc = $rows[$rr] ?? [];
            if (!$rc) { if (++$blank >= 2) break; continue; }
            $blank = 0;
            $name = trim(preg_replace('/\s+/', ' ', (string)($rc[$facCol] ?? '')) ?? '');
            $mobile = $mobCol !== null ? preg_replace('/[^0-9+]/', '', xlsx_number_text((string)($rc[$mobCol] ?? ''))) : '';
            if ($name === '' && $mobile === '') continue;
            $search = $subCols ?: array_keys(array_diff_key($rc, [$facCol => 1, (int)$mobCol => 1]));
            $code = null;
            foreach ($search as $c) { $code = tt_match_code((string)($rc[$c] ?? ''), $codes, $labels); if ($code !== null) break; }
            if ($code === null) continue;
            if (!isset($res['faculty'][$code])) $res['faculty'][$code] = ['name' => $name, 'mobile' => (string)$mobile];
        }
        if ($res['faculty']) break;
    }
    if (!$res['faculty']) $res['notes'][] = 'No faculty list found (that is fine — add names and numbers below).';
    return $res;
}

/** Reads an uploaded timetable file and picks the best sheet. Throws RuntimeException with a friendly message. */
function parse_timetable_upload(string $tmpPath, string $origName, string $section, array $codes, array $labels = []): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xls') throw new RuntimeException('Old .xls files cannot be read. Open it in Excel and use Save As → Excel Workbook (.xlsx).');
    if (!in_array($ext, ['xlsx', 'xlsm', 'csv'], true)) throw new RuntimeException('Please upload an .xlsx or .csv file.');

    if ($ext === 'csv') {
        $r = timetable_from_rows(csv_read_rows($tmpPath), $codes, 12, $labels);
        $r['sheet'] = '';
        return $r;
    }
    $sheets = xlsx_read_sheets($tmpPath);
    $order = $sheets;
    usort($order, function ($a, $b) use ($section) {
        $sa = tt_norm($a['name']) === tt_norm($section) ? 0 : 1;
        $sb = tt_norm($b['name']) === tt_norm($section) ? 0 : 1;
        return $sa <=> $sb;
    });
    $best = null;
    foreach ($order as $sh) {
        $r = timetable_from_rows($sh['rows'], $codes, 12, $labels);
        $r['sheet'] = $sh['name'];
        if ($r['found']) { $r['sheets'] = count($sheets); return $r; }
        $best ??= $r;
    }
    $best['sheets'] = count($sheets);
    return $best;
}
