<?php
declare(strict_types=1);
error_log("updateEvent.php start");
require_once __DIR__ . '/api_common.php';
$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);
$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);
$sess = ensure_user_id($pdo, $sess);
$userId = (string)($sess['lw_user_id'] ?? '');
if ($userId === '') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'no userId'], JSON_UNESCAPED_UNICODE);
    exit;
}
$rawBody = file_get_contents('php://input') ?: '';
error_log("Raw body: " . $rawBody);
$in = json_decode($rawBody, true);
if (!is_array($in)) {
    error_log("JSON decode failed");
    $in = [];
}
$tz = 'Asia/Tokyo';
$eventId    = (string)($in['eventId'] ?? '');
$calendarId = (string)($in['calendarId'] ?? '');
$title      = (string)($in['title'] ?? '');
$start = normalize_lw_datetime((string)$in['startDateTime'], $tz);
$end   = normalize_lw_datetime((string)$in['endDateTime'],   $tz);
/*
$start      = (string)($in['start'] ?? '');
$end        = (string)($in['end'] ?? '');*/
$note       = (string)($in['note'] ?? '');
$emo_score  = (int)($in['emo_score'] ?? '');
error_log("Params - eventId: $eventId, calendarId: $calendarId, title: $title");
error_log("DateTime - start: $start, end: $end");
if ($eventId === '' || $calendarId === '' || $start === '' || $end === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'eventId, calendarId, start, end are required',
        'received' => compact('eventId', 'calendarId', 'start', 'end')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare(
    "UPDATE lw_events
   SET emo_score = :emo
   WHERE lw_user_id = :uid
     AND event_id = :eid
     AND calendar_id = :calid"
);

$stmt->execute([
    ':emo'   => $emo_score,
    ':uid'   => $userId,
    ':eid'   => $eventId,
    ':calid' => $calendarId,
]);
$path = "/users/{$userId}/calendars/{$calendarId}/events/{$eventId}";
$timeZone = (string)($in['timeZone'] ?? 'Asia/Tokyo');
$body = [
    'eventComponents' => [
        [
            'eventId' => $eventId,
            'summary' => $title,
            'description' => $note,
            'start' => ['dateTime' => $start, 'timeZone' => $timeZone],
            'end'   => ['dateTime' => $end,   'timeZone' => $timeZone],
        ]
    ]
];
$result = works_api($sess, 'PUT', $path, null, $body);
$statusCode = (int)($result[0] ?? 0);
$rawResponse = (string)($result[1] ?? '');
$jsonResponse = $result[2] ?? null;
$errorInfo = $result[3] ?? null;
error_log("API result: " . json_encode($result));
if (!is_array($result) || count($result) < 2) {
    error_log("Invalid API response format");
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid API response format',
        'status' => $statusCode,
        'response' => $jsonResponse
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($statusCode < 200 || $statusCode >= 300) {
    error_log("LINE WORKS API error: $statusCode");
    http_response_code($statusCode > 0 ? $statusCode : 502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'LINE WORKS API error',
        'status' => $statusCode,
        'response' => $jsonResponse
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'updated' => true,
    'status' => $statusCode,
    'response' => $jsonResponse
], JSON_UNESCAPED_UNICODE);
exit;

function ensure_user_id(PDO $pdo, array $sess): array {
    $uid = (string)($sess['lw_user_id'] ?? '');
    if ($uid !== '') return $sess;

    $uid = fetch_my_user_id($sess);
    if ($uid === '') return $sess;

    $stmt = $pdo->prepare("UPDATE lw_app_sessions SET lw_user_id=:uid WHERE id=:id");
    $stmt->execute([':uid'=>$uid, ':id'=>$sess['id']]);

    $sess['lw_user_id'] = $uid;
    return $sess;
}

/**
 * LINE WORKS向け: start/end.dateTime を必ず "YYYY-MM-DDTHH:mm:ss" に正規化する
 *
 * 受け入れる例:
 * - "2026-02-03 07:55:00"
 * - "2026-02-03T07:55:00"
 * - "2026-02-03 07:55" / "2026-02-03T07:55"（秒なし）
 * - "2026-02-03"（時刻なし→00:00:00）
 * - "2026-02-03T07:55:00+09:00" / "2026-02-03T07:55:00Z"（オフセット付き）
 *
 * 方針:
 * - 入力にオフセット/Zが付いていればそれを尊重して $outTz に変換して出力
 * - 付いていなければ「入力は $inTz のローカル時刻」として解釈して出力
 */
function normalize_lw_datetime($value, string $inTz = 'Asia/Tokyo', string $outTz = 'Asia/Tokyo'): string
{
    if ($value === null) return '';
    $s = trim((string)$value);
    if ($s === '') return '';

    $outZone = new DateTimeZone($outTz);

    // まず「Z / +09:00 / -0500」などが付いているかをざっくり判定
    $hasOffset = (bool)preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $s);

    // 正規化のため、"YYYY-MM-DD HH:MM:SS" を "YYYY-MM-DDTHH:MM:SS" に寄せる（軽い前処理）
    // ※ただしタイムゾーンが末尾につく場合もあるので、空白1個のケースだけを慎重に処理
    // "2026-02-03 07:55:00" → "2026-02-03T07:55:00"
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $s)) {
        $s = preg_replace('/\s+/', 'T', $s, 1);
    }

    // 試すフォーマット一覧（順番が大事：より厳密→緩い）
    $formats = [
        'Y-m-d\TH:i:sP', // 2026-02-03T07:55:00+09:00
        'Y-m-d\TH:i:sO', // 2026-02-03T07:55:00+0900
        'Y-m-d\TH:i:s\Z',// 2026-02-03T07:55:00Z （※Zを文字扱い。実質UTC扱いにしたいので後で調整）
        'Y-m-d\TH:i:s',  // 2026-02-03T07:55:00
        'Y-m-d\TH:i',    // 2026-02-03T07:55
        'Y-m-d',         // 2026-02-03
    ];

    // Z形式だけは DateTime がUTCとして解釈しづらい場合があるので先に置換してもOK
    // "....Z" を "....+00:00" に変換
    if (str_ends_with($s, 'Z')) {
        $s = substr($s, 0, -1) . '+00:00';
        $hasOffset = true;
        // 変換後に合うフォーマットも追加
        array_unshift($formats, 'Y-m-d\TH:i:sP');
    }

    foreach ($formats as $fmt) {
        $zoneForParse = $hasOffset ? $outZone : new DateTimeZone($inTz);
        $dt = DateTimeImmutable::createFromFormat($fmt, $s, $zoneForParse);
        if ($dt instanceof DateTimeImmutable) {
            $errs = DateTimeImmutable::getLastErrors();
            if (($errs['warning_count'] ?? 0) === 0 && ($errs['error_count'] ?? 0) === 0) {
                // オフセット付き入力なら outTz へ変換して出力
                if ($hasOffset) $dt = $dt->setTimezone($outZone);
                return $dt->format('Y-m-d\TH:i:s');
            }
        }
    }

    // 最後の砦：DateTimeImmutable に投げる（多少雑でも吸収）
    try {
        $dt = $hasOffset
            ? new DateTimeImmutable($s) // offsetありならそのまま解釈
            : new DateTimeImmutable($s, new DateTimeZone($inTz)); // なしならinTz扱い

        $dt = $dt->setTimezone($outZone);
        return $dt->format('Y-m-d\TH:i:s');
    } catch (Exception $e) {
        // どうしてもダメなら空文字（呼び出し側で400にするのが安全）
        return '';
    }
}
