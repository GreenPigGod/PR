<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);

$in = require_post_json();
$taskId = (string)($in['taskId'] ?? '');
$completed = (bool)($in['completed'] ?? false);
if ($taskId === '') json_fail(400, 'missing taskId');

$userId = $sess['lw_user_id'];
$path = $completed
    ? "/tasks/{$taskId}/complete"
    : "/tasks/{$taskId}/incomplete";
//?/users/{$userId}
//?/users/{$userId}

[$st, $raw, $json, $err] = works_api($sess, 'POST', $path, null, new stdClass());

if ($st < 200 || $st >= 300) {
    error_log("TASK_TOGGLE_FAILED st=$st err=$err body=$raw");
    json_fail(502, 'lineworks_api_failed');
}
json_ok(['ok' => true]);