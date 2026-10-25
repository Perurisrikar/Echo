<?php
declare(strict_types=1);

/**
 * Stateless CSRF protection.
 *
 * Tokens are signed (HMAC) rather than stored in the PHP session, so they
 * keep working even when the host wipes session files, the browser drops
 * the session cookie, or a page is left open for hours. That session loss is
 * what caused the "form expired" errors on shared hosting.
 *
 * A token is "<issued-at>.<random>.<signature>" and is bound to who is
 * signed in (student id / admin id / nobody), so a token copied from one
 * account can't be replayed against another. It is valid for 24 hours.
 */
const CSRF_TTL_SECONDS = 86400;

function csrf_secret(): string
{
    $cfg = app_config();
    $base = (string)($cfg['app_secret'] ?? '');
    if ($base === '') {
        // No dedicated secret configured: derive one from values only the
        // site owner has (database credentials), so nothing extra to set up.
        $base = ($cfg['db_pass'] ?? '') . '|' . ($cfg['db_user'] ?? '') . '|' . ($cfg['db_name'] ?? '');
    }
    return hash('sha256', 'echo-csrf-v2|' . $base, true);
}

function csrf_scope(): string
{
    if (!empty($_SESSION['admin_id']))   return 'a' . (int)$_SESSION['admin_id'];
    if (!empty($_SESSION['student_id'])) return 's' . (int)$_SESSION['student_id'];
    return '0';
}

function csrf_sign(string $issued, string $nonce, string $scope): string
{
    return hash_hmac('sha256', $issued . '.' . $nonce . '.' . $scope, csrf_secret());
}

function csrf_token(): string
{
    $issued = (string)time();
    $nonce  = bin2hex(random_bytes(8));
    return $issued . '.' . $nonce . '.' . csrf_sign($issued, $nonce, csrf_scope());
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_valid(?string $token): bool
{
    if (!is_string($token) || substr_count($token, '.') !== 2) return false;
    [$issued, $nonce, $sig] = explode('.', $token);
    if (!ctype_digit($issued) || !ctype_xdigit($nonce)) return false;
    $age = time() - (int)$issued;
    if ($age < -300 || $age > CSRF_TTL_SECONDS) return false;
    return hash_equals(csrf_sign($issued, $nonce, csrf_scope()), $sig);
}

/**
 * Stops the request if the token is bad. Instead of a bare error it shows a
 * friendly page that sends the person straight back to the form (which will
 * carry a fresh token) rather than leaving them stuck.
 */
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (csrf_valid(is_string($token) ? $token : '')) return;

    $back = 'index.php';
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($ref !== '' && $host !== '' && (parse_url($ref, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST))) {
        $path = (string)(parse_url($ref, PHP_URL_PATH) ?? '');
        $query = parse_url($ref, PHP_URL_QUERY);
        $back = $path . ($query ? '?' . $query : '');
    }

    http_response_code(403);
    header('Cache-Control: no-store');
    $safe = htmlspecialchars($back, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta http-equiv="refresh" content="3;url=' . $safe . '">'
       . '<title>Echo — Please retry</title><link rel="stylesheet" href="assets/style.css"></head>'
       . '<body><main class="wrap"><div class="card"><h1>That page timed out</h1>'
       . '<p>Nothing was lost or changed. Reloading the form now…</p>'
       . '<p><a class="btn" href="' . $safe . '">Reload the form</a></p></div></main></body></html>';
    exit;
}
