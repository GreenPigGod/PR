<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Method Not Allowed');
}

$in = require_post_json();

$categoryName = trim((string)($in['categoryName'] ?? ''));
if ($categoryName === '') json_fail(400, 'categoryName is required');
if (mb_strlen($categoryName) > 100) json_fail(400, 'categoryName too long');

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);
$sess = require_app_session_row($pdo); // access_token/refresh_token 等が入ってる想定

// userId は "me" でも動くことが多いが、APIによっては実IDが必要になるので取得しておく
[$stMe, $rawMe, $me] = works_api($sess, 'GET', '/users/me', null, null);
if ($stMe < 200 || $stMe >= 300 || !is_array($me)) {
    json_fail(502, 'failed to fetch /users/me', ['status'=>$stMe, 'raw'=>$rawMe]);
}
$userId = (string)($me['userId'] ?? $me['id'] ?? 'me');
if ($userId === '') $userId = 'me';

$body = [
    // ドキュメント上は categoryName が一般的
    'categoryName' => $categoryName,
];

[$st, $raw, $json] = works_api($sess, 'POST', "/users/{$userId}/task-categories", null, $body);
if ($st < 200 || $st >= 300 || !is_array($json)) {
    json_fail(502, 'LINE WORKS createCategory failed', ['status'=>$st, 'raw'=>$raw, 'resp'=>$json]);
}

$categoryId = (string)($json['categoryId'] ?? $json['id'] ?? ($json['category']['categoryId'] ?? '') ?? '');
$nameOut    = (string)($json['categoryName'] ?? $json['name'] ?? ($json['category']['categoryName'] ?? $categoryName));

json_ok([
    'ok' => true,
    'category' => [
        'id' => $categoryId,
        'name' => $nameOut,
    ],
]);

