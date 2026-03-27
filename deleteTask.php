<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);

$rawBody = file_get_contents('php://input') ?: '';
$in = json_decode($rawBody, true);
if (!is_array($in)) json_fail(400, 'invalid json');

$taskId = (string)($in['taskId'] ?? '');
if ($taskId === '') json_fail(400, 'taskId is required');

// LINE WORKS: DELETE /tasks/{taskId}
[$st, $raw, $json] = works_api($sess, 'DELETE', "/tasks/{$taskId}", null, null);
if ($st < 200 || $st >= 300) {
    json_fail(502, 'LINE WORKS deleteTask failed');
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
