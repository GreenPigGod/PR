<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib.php';

$cfg          = cfg();
$lw           = $cfg['lw'];
$forUsers     = $cfg['for_users'];
$sec          = $cfg['sec'];
$clientId     = $lw['client_id'];
$clientSecret = $lw['client_secret'];
$redirectUri  = $forUsers['redirect_uri'];
$returnUrl    = $forUsers['return_url'];

function fu_log(string $msg): void {
    error_log('[forUsers/oauth_callback] ' . $msg);
}

// 1. code チェック
if (!isset($_GET['code'])) {
    fu_log('code missing');
    http_response_code(400);
    exit('code がありません');
}

$code  = $_GET['code'];
$state = $_GET['state'] ?? '';

// 2. CSRF state 検証
if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    fu_log('state mismatch');
    http_response_code(400);
    exit('invalid state');
}
unset($_SESSION['oauth_state']);
session_write_close();

// 3. code → token
$post = http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
]);

$ch = curl_init($lw['token_url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_TIMEOUT        => 30,
]);
$body = curl_exec($ch);
$st   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false || $st < 200 || $st >= 300) {
    fu_log("token exchange failed st=$st err=$err");
    http_response_code(500);
    exit('トークン取得に失敗しました');
}

$tok          = json_decode((string)$body, true);
$accessToken  = (string)($tok['access_token']  ?? '');
$refreshToken = (string)($tok['refresh_token'] ?? '');
$expiresIn    = (int)($tok['expires_in'] ?? 0);

if ($accessToken === '' || $expiresIn <= 0) {
    fu_log('token parse failed');
    http_response_code(500);
    exit('トークンのレスポンスが不正です');
}
$expiresAt = time() + $expiresIn;

// 4. /users/me
$ch = curl_init($lw['user_me_url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 30,
]);
$meBody = curl_exec($ch);
$meSt   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($meBody === false || $meSt < 200 || $meSt >= 300) {
    fu_log("users/me failed st=$meSt");
    http_response_code(500);
    exit('/users/me の取得に失敗しました');
}

$me       = json_decode((string)$meBody, true);
$lwUserId = (string)($me['userId'] ?? '');
if ($lwUserId === '') {
    http_response_code(500);
    exit('userId が取得できませんでした');
}

// 5. DB保存 (Androidと同じ pr_app_sessions を使用)
$pdo = pdo_from_cfg($cfg['db']);
$pdo->beginTransaction();

$pdo->prepare('INSERT INTO pr_users (lw_user_id) VALUES (:u) ON DUPLICATE KEY UPDATE lw_user_id = lw_user_id')
    ->execute([':u' => $lwUserId]);

$userDbId = (int)$pdo->query(
    'SELECT id FROM pr_users WHERE lw_user_id = ' . $pdo->quote($lwUserId)
)->fetchColumn();

if ($userDbId <= 0) {
    $pdo->rollBack();
    http_response_code(500);
    exit('ユーザーIDの取得に失敗しました');
}

$pdo->prepare('REPLACE INTO pr_user_tokens (user_id, access_token, refresh_token, expires_at) VALUES (:uid,:at,:rt,:ea)')
    ->execute([':uid' => $userDbId, ':at' => $accessToken, ':rt' => $refreshToken, ':ea' => $expiresAt]);

$appSession     = b64url(random_bytes(32));
$appSessionHash = sha256hex($appSession);
$appTtl         = (int)($sec['app_session_ttl'] ?? (60 * 60 * 24 * 14));
$appExp         = time() + $appTtl;

$pdo->prepare('INSERT INTO pr_app_sessions (session_hash, user_id, expires_at) VALUES (:h,:uid,:ea) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), expires_at=VALUES(expires_at)')
    ->execute([':h' => $appSessionHash, ':uid' => $userDbId, ':ea' => $appExp]);

$pdo->commit();

// 6. auth_bridge へリダイレクト
header('Location: ' . $returnUrl . '?session=' . rawurlencode($appSession), true, 302);
exit;
