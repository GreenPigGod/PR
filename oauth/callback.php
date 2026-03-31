<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

boot_session();
cleanup_oauth_session();

$cfg = cfg();
$lw  = $cfg['lw'];
$app = $cfg['app'];
$sec = $cfg['sec'];

$state = $_GET['state'] ?? '';
$code  = $_GET['code'] ?? '';

logf('CALLBACK_IN', [
  'uri' => $_SERVER['REQUEST_URI'] ?? '',
  'sid' => session_id(),
  'cookie' => $_COOKIE[session_name()] ?? '<no-cookie>',
  'get_state' => $state !== '' ? '[present]' : '<missing>',
  'get_code'  => $code  !== '' ? '[present]' : '<missing>',
  'states_count' => isset($_SESSION['lw_oauth_states']) ? count($_SESSION['lw_oauth_states']) : 0,
  'done_count'   => isset($_SESSION['lw_oauth_done']) ? count($_SESSION['lw_oauth_done']) : 0,
  'save_path'    => session_save_path(),
]);

if ($state === '' || $code === '') json_fail(400, 'missing state/code');

$returnUrl = (string)($app['return_url'] ?? '');
if ($returnUrl === '') json_fail(500, 'missing app.return_url');

//
// 1) 二重callback対策：done があれば同じ session で bridge へ
//
if (isset($_SESSION['lw_oauth_done'][$state])) {
  $info = $_SESSION['lw_oauth_done'][$state];
  $appSession = (string)($info['app_session'] ?? '');
  if ($appSession !== '') {
    header('Location: ' . $returnUrl . '?session=' . rawurlencode($appSession), true, 302);
    exit;
  }
}

//
// 2) state 検証
//
if (!isset($_SESSION['lw_oauth_states'][$state])) {
  logf('STATE_MISS', [
    'state' => $state,
    'sid' => session_id(),
    'cookie' => $_COOKIE[session_name()] ?? '<no-cookie>',
  ]);
  json_fail(400, 'invalid state');
}

// ここで消さない（失敗時にリロードすると詰むので、成功後に消す）

try {
  //
  // 3) code -> token
  //
  $post = http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => $lw['client_id'],
    'client_secret' => $lw['client_secret'],
    'redirect_uri'  => $lw['redirect_uri'],
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
    error_log("TOKEN_EXCHANGE_FAILED st=$st err=$err body=".$body);
    json_fail(500, 'token exchange failed');
  }

  $tok = json_decode((string)$body, true);
  $accessToken  = (string)($tok['access_token']  ?? '');
  $refreshToken = (string)($tok['refresh_token'] ?? '');
  $expiresIn    = (int)($tok['expires_in'] ?? 0);
  if ($accessToken === '' || $refreshToken === '' || $expiresIn <= 0) {
    error_log("TOKEN_PARSE_FAILED body=".$body);
    json_fail(500, 'invalid token response');
  }
  $expiresAt = time() + $expiresIn;

  //
  // 4) users/me
  //
  $ch = curl_init($lw['user_me_url']);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$accessToken],
    CURLOPT_TIMEOUT        => 30,
  ]);
  $meBody = curl_exec($ch);
  $meSt   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $meErr  = curl_error($ch);
  curl_close($ch);

  if ($meBody === false || $meSt < 200 || $meSt >= 300) {
    error_log("USER_ME_FAILED st=$meSt err=$meErr body=".$meBody);
    json_fail(500, 'users/me failed');
  }
  $me = json_decode((string)$meBody, true);
  $lwUserId = (string)($me['userId'] ?? '');
  if ($lwUserId === '') json_fail(500, 'missing userId');

  //
  // 5) DB保存
  //
  $pdo = pdo_from_cfg($cfg['db']);
  $pdo->beginTransaction();
  $pdo->prepare(
  'INSERT INTO pr_users (lw_user_id) VALUES (:u)
   ON DUPLICATE KEY UPDATE lw_user_id = lw_user_id'
)->execute([':u' => $lwUserId]);

$userDbId = (int)$pdo->query(
  "SELECT id FROM pr_users WHERE lw_user_id = " . $pdo->quote($lwUserId)
)->fetchColumn();

if ($userDbId <= 0) json_fail(500, 'pr_users lookup failed');

$pdo->prepare(
  'REPLACE INTO pr_user_tokens (user_id, access_token, refresh_token, expires_at)
   VALUES (:uid,:at,:rt,:ea)'
)->execute([
  ':uid' => $userDbId,
  ':at'  => $accessToken,
  ':rt'  => $refreshToken,
  ':ea'  => $expiresAt,
]);

// sessionは hash で保存する設計のまま
$appSession     = b64url(random_bytes(32));
$appSessionHash = sha256hex($appSession);
$appTtl = (int)($sec['app_session_ttl'] ?? (60*60*24*14));
$appExp = time() + $appTtl;

$pdo->prepare(
  'INSERT INTO pr_app_sessions (session_hash, user_id, expires_at)
   VALUES (:h,:uid,:ea)
   ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), expires_at=VALUES(expires_at)'
)->execute([
  ':h'   => $appSessionHash,
  ':uid' => $userDbId,
  ':ea'  => $appExp,
]);


  $pdo->commit();

  //
  // 6) 成功したので state を消す + doneキャッシュ
  //
  unset($_SESSION['lw_oauth_states'][$state]);
  $_SESSION['lw_oauth_done'][$state] = [
    'exp' => time() + (int)$sec['done_ttl'],
    'app_session' => $appSession,
  ];

  //
  // 7) bridge へ
  //
  header('Location: ' . $returnUrl . '?session=' . rawurlencode($appSession), true, 302);
  exit;

} catch (Throwable $e) {
  error_log("CALLBACK_FATAL: " . $e->getMessage());
  error_log($e->getTraceAsString());
  json_fail(500, 'callback internal error (see server error log)');
}


