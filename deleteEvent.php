<?php
declare(strict_types=1);

error_log("deleteEvent.php start");

require_once __DIR__ . '/api_common.php';

function fail(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function ensure_user_id(PDO $pdo, array $sess): array {
    $uid = (string)($sess['lw_user_id'] ?? '');
    if ($uid !== '') return $sess;

    $uid = fetch_my_user_id($sess);
    if ($uid === '') return $sess;

    // ?: app_sessions ????????/????????DB??????
    $stmt = $pdo->prepare("UPDATE pr_app_sessions SET lw_user_id=:uid WHERE id=:id");
    $stmt->execute([':uid'=>$uid, ':id'=>$sess['id']]);

    $sess['lw_user_id'] = $uid;
    return $sess;
}

try {
    $cfg = cfg();
    $pdo = pdo_from_cfg($cfg['db']);

    $sess = require_app_session_row($pdo);
    $sess = refresh_access_token($pdo, $sess);
    $sess = ensure_user_id($pdo, $sess);

    $userId = (string)($sess['lw_user_id'] ?? '');
    if ($userId === '') fail(401, ['ok'=>false, 'error'=>'no userId']);

    $rawBody = file_get_contents('php://input') ?: '';
    error_log("Raw body: " . $rawBody);

    $in = json_decode($rawBody, true);
    if (!is_array($in)) $in = [];

    $eventId    = (string)($in['eventId'] ?? '');
    $calendarId = (string)($in['calendarId'] ?? '');
    if ($eventId === '' || $calendarId === '') {
        fail(400, [
            'ok'=>false,
            'error'=>'eventId, calendarId are required',
            'received'=>['eventId'=>$eventId, 'calendarId'=>$calendarId],
        ]);
    }

    $accessToken = (string)($sess['access_token'] ?? '');
    if ($accessToken === '') fail(401, ['ok'=>false, 'error'=>'no accessToken']);

    $url = "https://www.worksapis.com/v1.0/users/{$userId}/calendars/{$calendarId}/events/{$eventId}";
    error_log("DELETE url: " . $url);

    $ch = curl_init($url);
    if ($ch === false) {
        fail(500, ['ok'=>false, 'error'=>'curl_init failed', 'url'=>$url]);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        fail(502, ['ok'=>false, 'error'=>'curl_exec failed', 'details'=>$curlError, 'status'=>$statusCode]);
    }

    // 成功: LINE WORKSのDELETEは204が基本
    if ($statusCode === 204) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true, 'deleted'=>true, 'status'=>$statusCode], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // それ以外: エラー詳細
    $errorDetail = null;
    if ($response !== '') {
        $errorDetail = json_decode($response, true);
        if ($errorDetail === null) $errorDetail = ['rawText'=>$response];
    }

    fail(($statusCode > 0 ? $statusCode : 502), [
        'ok'=>false,
        'status'=>$statusCode,
        'raw'=>$response,
        'error'=>$errorDetail,
    ]);

} catch (Throwable $e) {
    error_log("deleteEvent.php exception: " . $e->getMessage());
    error_log($e->getTraceAsString());
    fail(500, ['ok'=>false, 'error'=>'server exception', 'message'=>$e->getMessage()]);
}

