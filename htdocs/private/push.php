<?php
declare(strict_types=1);

/**
 * Web push notifications (RFC 8030 / 8291 / 8292), written without any library so it runs on plain shared hosting.
 * Needs PHP's openssl (with EC support) and curl; both are normally present.
 */

function b64u(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function b64u_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/** Push services we are willing to contact. Stops a student from pointing the server at some other address. */
function push_endpoint_allowed(string $endpoint): bool
{
    $p = parse_url($endpoint);
    if (!$p || empty($p['host']) || strlen($endpoint) > 700) return false;
    $host = strtolower((string)$p['host']);
    if (!empty(app_config()['push_allow_test_host']) && $host === strtolower((string)app_config()['push_allow_test_host'])) return true;
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['port']) && (int)$p['port'] !== 443) return false;
    foreach (['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'] as $ok) {
        if ($host === $ok || str_ends_with($host, '.' . $ok)) return true;
    }
    return false;
}

/** What this server can do: ['ok' => bool, 'problems' => [string]]. */
function push_capability(): array
{
    $problems = [];
    if (!extension_loaded('openssl')) $problems[] = 'PHP openssl extension is missing';
    elseif (!function_exists('openssl_pkey_derive')) $problems[] = 'PHP is too old for openssl_pkey_derive';
    elseif (!in_array('aes-128-gcm', openssl_get_cipher_methods(), true)) $problems[] = 'openssl has no AES-128-GCM';
    if (!function_exists('curl_init') || !function_exists('curl_multi_init')) $problems[] = 'PHP curl extension is missing';
    return ['ok' => !$problems, 'problems' => $problems];
}

function push_ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function push_new_ec_key()
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('Could not create an EC key');
    return $k;
}

/** The site's VAPID key pair, created on first use: ['key' => resource, 'public' => base64url]. */
function push_vapid(): array
{
    static $cached = null;
    if ($cached) return $cached;
    $pem = setting('vapid_private');
    $key = $pem !== '' ? @openssl_pkey_get_private($pem) : false;
    if (!$key) {
        $new = push_new_ec_key();
        openssl_pkey_export($new, $out);
        save_setting('vapid_private', (string)$out);
        save_setting('vapid_public', b64u(push_ec_public_raw($new)));
        $key = $new;
    }
    return $cached = ['key' => $key, 'public' => b64u(push_ec_public_raw($key))];
}

function push_der_to_raw(string $der): string
{
    $i = 2; $parts = [];
    for ($n = 0; $n < 2; $n++) {
        if (ord($der[$i]) !== 0x02) throw new RuntimeException('Bad signature');
        $len = ord($der[$i + 1]); $v = substr($der, $i + 2, $len); $i += 2 + $len;
        $parts[] = str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $parts[0] . $parts[1];
}

function push_vapid_header(string $endpoint): string
{
    $v = push_vapid();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = contact_email() !== '' ? 'mailto:' . contact_email() : rtrim(app_url(), '/');
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    openssl_sign($head . '.' . $claims, $der, $v['key'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $head . '.' . $claims . '.' . b64u(push_der_to_raw($der)) . ', k=' . $v['public'];
}

/** RFC 8291 aes128gcm body for one subscription. $eph / $salt are only for tests. */
function push_encrypt(string $payload, string $uaPublic, string $authSecret, $eph = null, ?string $salt = null): string
{
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) < 8) throw new InvalidArgumentException('Bad subscription keys');
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPublic;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $peer = openssl_pkey_get_public($pem);
    if (!$peer) throw new InvalidArgumentException('Bad subscription public key');
    $eph = $eph ?: push_new_ec_key();
    $asPublic = push_ec_public_raw($eph);
    $secret = openssl_pkey_derive($peer, $eph, 32);
    if ($secret === false) throw new RuntimeException('ECDH failed');
    $salt = $salt ?? random_bytes(16);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Encryption failed');
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

/**
 * Sends one payload to many subscription rows (push_subs rows). Dead subscriptions (404/410) are removed.
 * Returns ['sent' => n, 'failed' => n, 'removed' => n, 'errors' => [first few messages]].
 */
function push_send_rows(array $rows, array $payload, int $ttl = 86400): array
{
    $res = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];
    if (!$rows) return $res;
    $cap = push_capability();
    if (!$cap['ok']) { $res['failed'] = count($rows); $res['errors'][] = implode('; ', $cap['problems']); return $res; }
    @set_time_limit(90);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (array_chunk($rows, 25) as $chunk) {
        $mh = curl_multi_init(); $handles = [];
        foreach ($chunk as $row) {
            $id = (int)$row['id'];
            try {
                if (!push_endpoint_allowed((string)$row['endpoint'])) throw new RuntimeException('endpoint not allowed');
                $body = push_encrypt($json, b64u_decode((string)$row['p256dh']), b64u_decode((string)$row['auth']));
                $ch = curl_init((string)$row['endpoint']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . push_vapid_header((string)$row['endpoint']),
                        'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
                        'TTL: ' . $ttl, 'Urgency: normal', 'Content-Length: ' . strlen($body),
                    ],
                ]);
                curl_multi_add_handle($mh, $ch); $handles[$id] = $ch;
            } catch (Throwable $e) {
                $res['failed']++; if (count($res['errors']) < 3) $res['errors'][] = $e->getMessage();
            }
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        foreach ($handles as $id => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch); $resp = (string)curl_multi_getcontent($ch);
            if ($code >= 200 && $code < 300) {
                $res['sent']++;
                try { db()->prepare('UPDATE push_subs SET last_ok = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]); } catch (Throwable $e) {}
            } elseif ($code === 404 || $code === 410) {
                $res['removed']++;
                try { db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$id]); } catch (Throwable $e) {}
            } else {
                $res['failed']++;
                if (count($res['errors']) < 3) $res['errors'][] = $code ? 'HTTP ' . $code . ' ' . mb_substr($resp, 0, 80) : ($err ?: 'no response');
            }
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $res;
}

function push_payload(string $title, string $body, string $url = 'dashboard.php', string $tag = 'echo'): array
{
    return ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 300), 'url' => $url, 'tag' => $tag];
}

/** Everyone (or one class) who has announcement notifications on. */
function push_notify_announcement(string $message, ?string $dept, ?string $sec, string $authorLabel): array
{
    try {
        $sql = 'SELECT p.* FROM push_subs p JOIN students s ON s.id = p.student_id WHERE p.ann = 1';
        $args = [];
        if ($dept && $sec) { $sql .= ' AND s.dept = ? AND s.sec = ?'; $args = [$dept, $sec]; }
        $st = db()->prepare($sql); $st->execute($args);
        return push_send_rows($st->fetchAll(), push_payload('New announcement · ' . $authorLabel, $message, 'dashboard.php', 'announcement'));
    } catch (Throwable $e) {
        error_log('Echo push announcement failed: ' . $e->getMessage());
        return ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => [$e->getMessage()]];
    }
}

/** Today's classes plus any subject at risk, for one student; null when there is nothing worth a notification. */
function student_daily_brief(array $student): ?array
{
    $att = get_attendance((string)$student['roll_no']);
    if (!$att || empty($att['section'])) return null;
    $tt = get_timetable((string)$att['section']);
    $today = ist_now()->format('Y-m-d');
    $counts = $tt ? periods_on_date($tt, $today) : [];
    if (!$counts) return null;
    $thr = student_threshold($student);
    $thrLabel = rtrim(rtrim(number_format($thr, 1), '0'), '.');
    $byCode = [];
    foreach ((array)($att['subjects'] ?? []) as $s) $byCode[$s['code']] = $s;
    $risk = [];
    foreach ($counts as $code => $n) {
        $s = $byCode[$code] ?? null;
        if (!$s) continue;
        $adv = bunk_advice($s['attended'] !== null ? (float)$s['attended'] : null, $s['conducted'] !== null ? (float)$s['conducted'] : null, $thr);
        if (!$adv) continue;
        if ($adv[0] === 'recover') $risk[] = $s['label'] . ' is below ' . $thrLabel . '% — attend today';
        elseif ($adv[1] < $n) $risk[] = $s['label'] . ' is close to ' . $thrLabel . '% — don\'t miss it today';
    }
    $labels = array_map(fn($c) => $byCode[$c]['label'] ?? $c, array_keys($counts));
    $total = array_sum($counts);
    $body = $total . ' class' . ($total === 1 ? '' : 'es') . ' today: ' . implode(', ', array_slice($labels, 0, 6)) . (count($labels) > 6 ? '…' : '.');
    if ($risk) $body .= ' ⚠ ' . implode(' · ', array_slice($risk, 0, 2));
    $name = trim((string)($student['nickname'] ?: ''));
    return push_payload(time_greeting() . ($name !== '' ? ', ' . $name : ''), $body, 'dashboard.php', 'daily');
}

/** Sends the morning brief to every subscriber who wants it. $force ignores "already sent today". */
function push_send_daily(bool $force = false): array
{
    $today = ist_now()->format('Y-m-d');
    $tot = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => [], 'skipped' => 0];
    $rows = db()->query('SELECT p.*, s.id AS sid FROM push_subs p JOIN students s ON s.id = p.student_id WHERE p.daily = 1')->fetchAll();
    $byStudent = [];
    foreach ($rows as $r) {
        if (!$force && (string)($r['last_daily'] ?? '') === $today) { $tot['skipped']++; continue; }
        $byStudent[(int)$r['student_id']][] = $r;
    }
    foreach ($byStudent as $sid => $subs) {
        $st = db()->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$sid]);
        $student = $st->fetch();
        $brief = $student ? student_daily_brief($student) : null;
        if (!$brief) { $tot['skipped'] += count($subs); continue; }
        $r = push_send_rows($subs, $brief, 6 * 3600);
        foreach (['sent', 'failed', 'removed'] as $k) $tot[$k] += $r[$k];
        $tot['errors'] = array_slice(array_merge($tot['errors'], $r['errors']), 0, 3);
        if ($r['sent'] > 0) {
            $ids = array_map(fn($x) => (int)$x['id'], $subs);
            db()->prepare('UPDATE push_subs SET last_daily = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$today]);
        }
    }
    return $tot;
}

function push_counts(): array
{
    try {
        $subs = (int)db()->query('SELECT COUNT(*) FROM push_subs')->fetchColumn();
        $people = (int)db()->query('SELECT COUNT(DISTINCT student_id) FROM push_subs')->fetchColumn();
        return ['devices' => $subs, 'students' => $people];
    } catch (Throwable $e) {
        return ['devices' => 0, 'students' => 0];
    }
}

function push_student_subscribed(int $studentId): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM push_subs WHERE student_id = ? LIMIT 1'); $st->execute([$studentId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** The secret in the daily-reminder URL (created on first use). */
function push_cron_key(): string
{
    $k = setting('cron_key');
    if ($k === '') { $k = bin2hex(random_bytes(16)); save_setting('cron_key', $k); }
    return $k;
}

/** The notifications card for a student (profile page; the home page shows it only until they have turned it on). */
function render_push_card(array $student, bool $prompt = false): string
{
    if (!push_capability()['ok']) return '';
    $vapid = push_vapid();
    return '<div class="card push-card" id="pushCard" hidden data-key="' . esc($vapid['public']) . '" data-token="' . esc(csrf_token()) . '" data-prompt="' . ($prompt ? '1' : '0') . '" data-have="' . (push_student_subscribed((int)$student['id']) ? '1' : '0') . '">'
        . '<h2>Notifications</h2>'
        . '<p class="hint hint-tight" id="pushMsg">Get a heads-up on this device for announcements and each morning\'s classes.</p>'
        . '<div id="pushPrefs" hidden>'
        . '<label class="check"><input type="checkbox" id="pushAnn" checked><span>Announcements from my class and the admin</span></label>'
        . '<label class="check"><input type="checkbox" id="pushDaily" checked><span>Morning heads-up: today\'s classes and subjects at risk</span></label>'
        . '</div>'
        . '<div class="row" style="gap:8px;flex-wrap:wrap;margin-top:10px;">'
        . '<button type="button" class="matcha" id="pushOn" hidden>Turn on notifications</button>'
        . '<button type="button" class="ghost" id="pushOff" hidden>Turn off on this device</button>'
        . ($prompt ? '<button type="button" class="ghost" id="pushLater" hidden>Not now</button>' : '')
        . '</div></div>';
}
