<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

try {
    $cfg = cfg();
    $pdo = pdo_from_cfg($cfg['db']);

    $sess = require_app_session_row($pdo);

    $in = require_post_json();

    $operation    = (string)($in['operation'] ?? '');
    $errorMessage = (string)($in['errorMessage'] ?? '');
    $stackTrace   = (string)($in['stackTrace'] ?? '');
    $timestamp    = (int)($in['timestamp'] ?? 0);
    $deviceModel  = (string)($in['deviceModel'] ?? '');
    $osVersion    = (string)($in['osVersion'] ?? '');
    $sdkVersion   = (int)($in['sdkVersion'] ?? 0);

    if ($operation === '') {
        json_fail(400, 'operation is required');
    }

    $st = $pdo->prepare(
        'INSERT INTO app_error_logs
            (user_id, operation, error_message, stack_trace, client_timestamp, device_model, os_version, sdk_version, created_at)
         VALUES
            (:uid, :op, :msg, :trace, :ts, :model, :os, :sdk, NOW())'
    );
    $st->execute([
        ':uid'   => $sess['user_db_id'],
        ':op'    => $operation,
        ':msg'   => mb_substr($errorMessage, 0, 1000),
        ':trace' => mb_substr($stackTrace, 0, 10000),
        ':ts'    => $timestamp,
        ':model' => mb_substr($deviceModel, 0, 100),
        ':os'    => mb_substr($osVersion, 0, 20),
        ':sdk'   => $sdkVersion,
    ]);

    json_ok(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
    error_log("reportError.php exception: " . $e->getMessage());
    error_log($e->getTraceAsString());
    json_fail(500, 'server exception');
}
