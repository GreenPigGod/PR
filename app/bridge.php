<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$cfg = cfg();
$app = $cfg['app'];

$session = $_GET['session'] ?? '';
if ($session === '') {
  http_response_code(400);
  header('Content-Type: text/html; charset=UTF-8');
  echo "<h1>Missing session</h1>";
  exit;
}

$dl = (string)($app['deeplink_base'] ?? '') . rawurlencode($session);

header('Content-Type: text/html; charset=UTF-8');
$hSess = htmlspecialchars($session, ENT_QUOTES);
$hDl   = htmlspecialchars($dl, ENT_QUOTES);

echo "<!doctype html><html><head><meta charset='utf-8'><title>Login OK</title></head><body>";
echo "<h1>Login OK</h1>";
echo "<p>ここまで来たら callback→bridge のリダイレクトは成功しています。</p>";
echo "<p>session = <code>{$hSess}</code></p>";

echo "<p><a href='{$hDl}' style='display:inline-block;padding:12px 16px;border:1px solid #333;border-radius:8px;text-decoration:none;'>アプリを開く</a></p>";
echo "<p>※Chromeは自動遷移をブロックしがちなので「押す方式」にしています。</p>";

echo "</body></html>";
