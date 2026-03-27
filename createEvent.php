<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Method Not Allowed');
}

$in = require_post_json();

$title      = trim((string)($in['title'] ?? ''));
$note       = (string)($in['note'] ?? '');
$start      = trim((string)($in['start'] ?? ''));
$end        = trim((string)($in['end'] ?? ''));
$otherParty = trim((string)($in['otherParty'] ?? ''));
$category   = trim((string)($in['category'] ?? ''));
$taskId     = trim((string)($in['taskId'] ?? ''));
if ($taskId === '') $taskId = null;

if ($title === '') json_fail(400, 'title is required');
if ($start === '' || $end === '') json_fail(400, 'start and end are required');
$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);
$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);

[$stMe, $rawMe, $me] = works_api($sess, 'GET', '/users/me', null, null);
if ($stMe < 200 || $stMe >= 300 || !is_array($me)) {
    json_fail(502, 'failed to fetch /users/me', ['status'=>$stMe, 'raw'=>$rawMe]);
}
$userId = (string)($me['userId'] ?? $me['id'] ?? 'me');
if ($userId === '') $userId = 'me';


function to_local_datetime(string $atom): string {
    // 例: 2025-11-08T15:00:00+09:00 → 2025-11-08T15:00:00
    if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $atom, $m)) return $m[1];
    return $atom;
}

$desc = $note;
if ($otherParty !== '') {
    $desc .= ($desc === '' ? '' : "\n\n") . "OtherParty: " . $otherParty;
}

$eventComponent = [
    'summary' => $title,
    'description' => $desc,
    'start' => [
        'dateTime' => to_local_datetime($start),
        'timeZone' => 'Asia/Tokyo',
    ],
    'end' => [
        'dateTime' => to_local_datetime($end),
        'timeZone' => 'Asia/Tokyo',
    ],
];

// category が数字だけなら eventの categoryId として入れる（LINE WORKS側の「予定カテゴリ」）
if ($category !== '' && preg_match('/^\d+$/', $category)) {
    $eventComponent['categoryId'] = $category;
}

$body = [
    'eventComponents' => [$eventComponent],
];

// category がUUIDっぽい/長いIDなら「作成先カレンダーID」とみなして指定カレンダーへ
$isCalendarIdLike = ($category !== '' && preg_match('/^[A-Za-z0-9._@\-]{10,}$/', $category) && !preg_match('/^\d+$/', $category));

if ($isCalendarIdLike) {
    $path = "/users/{$userId}/calendars/{$category}/events";
} else {
    // 基本カレンダー
    $path = "/users/{$userId}/calendar/events";
}

[$st, $raw, $json] = works_api($sess, 'POST', $path, null, $body);
if ($st < 200 || $st >= 300 || !is_array($json)) {
    json_fail(502, 'LINE WORKS createEvent failed', ['status'=>$st, 'raw'=>$raw, 'resp'=>$json, 'sent'=>$body, 'path'=>$path]);
}

$eventId = (string)($json['eventId'] ?? $json['id'] ?? $json['eventComponents'][0]['eventId'] ?? '');

if ($eventId !== '') {
    $calendarId = $isCalendarIdLike ? $category : '';
    $tz = new DateTimeZone('Asia/Tokyo');
    $startDt = (new DateTime($start))->setTimezone($tz)->format('Y-m-d H:i:s');
    $endDt   = (new DateTime($end))->setTimezone($tz)->format('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO lw_events (lw_user_id, event_id, calendar_id, task_id, title, start_at, end_at, memo, deleted)
         VALUES (:uid, :eid, :calid, :tid, :title, :start, :end, :memo, 0)
         ON DUPLICATE KEY UPDATE
           calendar_id = VALUES(calendar_id),
           task_id     = VALUES(task_id),
           title       = VALUES(title),
           start_at    = VALUES(start_at),
           end_at      = VALUES(end_at),
           memo        = VALUES(memo)"
    )->execute([
        ':uid'   => $userId,
        ':eid'   => $eventId,
        ':calid' => $calendarId,
        ':tid'   => $taskId,
        ':title' => $title,
        ':start' => $startDt,
        ':end'   => $endDt,
        ':memo'  => $note ?: null,
    ]);
}

json_ok([
    'ok' => true,
    'event' => [
        'eventId' => $eventId,
        'title' => $title,
        'start' => $start,
        'end' => $end,
        'path' => $path,
    ],
]);



