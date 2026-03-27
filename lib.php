<?php
declare(strict_types=1);

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function sha256hex(string $s): string {
    return hash('sha256', $s);
}

function json_fail(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $data): never {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logf(string $tag, array $data, string $file = 'oauth_debug.log'): void {
    $line = "[" . date('c') . "][" . $tag . "] " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(__DIR__ . '/' . $file, $line, FILE_APPEND);
}

function boot_session(): void {
    $cfg = cfg();
    $save = (string)($cfg['sec']['session_save_path'] ?? '');

    if ($save !== '') {
        if (!is_dir($save)) @mkdir($save, 0700, true);
        if (is_dir($save) && is_writable($save)) {
            session_save_path($save);
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => 'nansyungijutsu.com',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
function require_app_session(): array {
    $cfg = cfg();
    $pdo = pdo_from_cfg($cfg['db']);

    // ??????? "Authorization: Bearer {app_session}"
    $appSession = require_bearer();
    $hash = sha256hex($appSession);

    $st = $pdo->prepare("
        SELECT
            s.session_hash,
            s.expires_at,
            u.id AS user_id,
            u.lw_user_id
        FROM lw_app_sessions s
        JOIN lw_users u ON u.id = s.user_id
        WHERE s.session_hash = :h
        LIMIT 1
    ");
    $st->execute([':h' => $hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) json_fail(401, 'invalid_session');

    $now = time();
    if ((int)$row['expires_at'] < $now) {
        json_fail(401, 'expired_session');
    }

    if (!empty($cfg['sec']['app_session_slide'])) {
        $ttl = (int)($cfg['sec']['app_session_ttl'] ?? (60*60*24*14));
        $newExp = $now + $ttl;

        $pdo->prepare("UPDATE lw_app_sessions SET expires_at = :ea WHERE session_hash = :h")
            ->execute([':ea' => $newExp, ':h' => $hash]);

        $row['expires_at'] = $newExp;
    }

    // ??????????????
    $row['app_session'] = $appSession;
    return $row;
}

function cleanup_oauth_session(): void {
    $cfg = cfg();
    $sec = $cfg['sec'] ?? [];
    $now = time();

    if (!isset($_SESSION['lw_oauth_states'])) $_SESSION['lw_oauth_states'] = [];
    if (!isset($_SESSION['lw_oauth_done']))   $_SESSION['lw_oauth_done']   = [];

    foreach ($_SESSION['lw_oauth_states'] as $st => $exp) {
        if ((int)$exp < $now) unset($_SESSION['lw_oauth_states'][$st]);
    }
    foreach ($_SESSION['lw_oauth_done'] as $st => $info) {
        $exp = (int)($info['exp'] ?? 0);
        if ($exp < $now) unset($_SESSION['lw_oauth_done'][$st]);
    }
}

function pdo_from_cfg(array $db): PDO {
    $dsn = 'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4';
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function require_bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        $hh = apache_request_headers();
        if (isset($hh['Authorization'])) $h = $hh['Authorization'];
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) json_fail(401, 'missing bearer');
    return trim($m[1]);
}


