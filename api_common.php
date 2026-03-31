<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function require_post_json(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_fail(405, 'method_not_allowed');
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: 'null', true);
    if (!is_array($json)) json_fail(400, 'invalid_json');
    return $json;
}


function require_app_session_row(PDO $pdo): array {
    $appSession = require_bearer();
    $h = sha256hex($appSession);

    $st = $pdo->prepare(
        'SELECT s.user_id, s.expires_at, u.lw_user_id
       FROM pr_app_sessions s
       JOIN pr_users u ON u.id = s.user_id
      WHERE s.session_hash = :h
      LIMIT 1'
    );
    $st->execute([':h' => $h]);
    $row = $st->fetch();
    if (!$row) {
        logf('SESSION_NOT_FOUND', ['hash_prefix' => substr($h, 0, 8)], 'auth.log');
        json_fail(401, 'invalid_session');
    }

    if ((int)$row['expires_at'] < time()) {
        logf('SESSION_EXPIRED', ['user_id' => $row['user_id'], 'expired_at' => date('c', (int)$row['expires_at'])], 'auth.log');
        json_fail(401, 'session_expired');
    }

    $tok = $pdo->prepare('SELECT access_token, refresh_token, expires_at FROM pr_user_tokens WHERE user_id=:uid');
    $tok->execute([':uid' => (int)$row['user_id']]);
    $trow = $tok->fetch();
    if (!$trow) {
        logf('TOKENS_MISSING', ['user_id' => (int)$row['user_id']], 'auth.log');
        json_fail(401, 'missing_tokens');
    }

    return [
        'user_db_id' => (int)$row['user_id'],
        'lw_user_id' => (string)$row['lw_user_id'],
        'access_token' => (string)$trow['access_token'],
        'refresh_token'=> (string)$trow['refresh_token'],
        'token_expires_at' => (int)$trow['expires_at'],
    ];
}

function refresh_access_token(PDO $pdo, array $sess): array {
    $cfg = cfg();
    $lw = $cfg['lw'];

    // 期限60秒前なら更新
    if ($sess['token_expires_at'] > time() + 60) return $sess;

    logf('TOKEN_REFRESH_START', [
        'user_db_id'  => $sess['user_db_id'],
        'expires_at'  => date('c', $sess['token_expires_at']),
        'now'         => date('c'),
    ], 'auth.log');

    $post = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $sess['refresh_token'],
        'client_id' => $lw['client_id'],
        'client_secret' => $lw['client_secret'],
    ]);

    $ch = curl_init($lw['token_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $st < 200 || $st >= 300) {
        logf('TOKEN_REFRESH_FAILED', [
            'user_db_id'  => $sess['user_db_id'],
            'http_status' => $st,
            'curl_error'  => $err,
            'body'        => substr((string)$body, 0, 500),
        ], 'auth.log');
        error_log("REFRESH_FAILED st=$st err=$err body=".$body);
        json_fail(401, 'refresh_failed');
    }

    $tok = json_decode((string)$body, true);
    $access = (string)($tok['access_token'] ?? '');
    $refresh = (string)($tok['refresh_token'] ?? $sess['refresh_token']);
    $expiresIn = (int)($tok['expires_in'] ?? 0);
    if ($access === '' || $expiresIn <= 0) json_fail(401, 'refresh_invalid_response');

    $expiresAt = time() + $expiresIn;

    $pdo->prepare(
        'REPLACE INTO pr_user_tokens (user_id, access_token, refresh_token, expires_at)
     VALUES (:uid,:at,:rt,:ea)'
    )->execute([
        ':uid' => $sess['user_db_id'],
        ':at' => $access,
        ':rt' => $refresh,
        ':ea' => $expiresAt,
    ]);

    logf('TOKEN_REFRESH_OK', [
        'user_db_id'     => $sess['user_db_id'],
        'new_expires_at' => date('c', $expiresAt),
    ], 'auth.log');

    $sess['access_token'] = $access;
    $sess['refresh_token'] = $refresh;
    $sess['token_expires_at'] = $expiresAt;
    return $sess;
}

function works_api(array $sess, string $method, string $path, array $query = null, $body = null): array {
    $url = 'https://www.worksapis.com/v1.0' . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $headers = [
        'Authorization: Bearer ' . $sess['access_token'],
        'Accept: application/json',
    ];

    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen((string)$payload);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $raw = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') $json = json_decode($raw, true);

    return [$st, (string)$raw, $json, (string)$err];
}