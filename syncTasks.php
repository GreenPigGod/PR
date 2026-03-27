<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);
$sess = ensure_user_id($pdo, $sess);
$userId = (string)$sess['lw_user_id'];

$tz = new DateTimeZone('Asia/Tokyo');

/**
 *
 * TODO: ? FETCH: LINEWORKS??API???????UPSERT??????????
 * TODO: ? UPSERT: PHPMyAdmin?????upsert???
 * TODO: ? SELECT: PHPMyAdmin???SELECT *???????????
 * TODO: ? SEND: ????Android??JSON??????(response.json????????)
 * TODO: ? MAIN: ?????????????
 *
 * */



//TODO: ? FETCH: LINEWORKS??API???????UPSERT??????????
function fetch_categories(array $sess, string $userId): array {
    [$st, $raw, $json] = works_api_call($sess, 'GET', "/users/{$userId}/task-categories", ['count' => 100], null);
    if ($st < 200 || $st >= 300 || !is_array($json)) {
        logf('FETCH_CATEGORIES_FAILED', ['status' => $st, 'body' => substr($raw, 0, 300)], 'sync.log');
        return [];
    }

    $cats = $json['categories'] ?? $json['taskCategories'] ?? [];
    if (!is_array($cats)) return [];

    $out = [];
    foreach ($cats as $c) {
        if (!is_array($c)) continue;
        $id = (string)($c['id'] ?? $c['categoryId'] ?? '');
        if ($id === '') continue;

        $out[] = [
            'id' => $id,
            'name' => (string)($c['name'] ?? $c['categoryName'] ?? ''),
        ];
    }
    return $out;
}

function fetch_tasks_for_category(array $sess, string $userId, string $categoryId): array {
    $all = [];
    $cursor = null;

    do {
        $q = [
            'categoryId' => $categoryId,
            'count' => 100,
            'status' => 'ALL',
            'searchFilterType' => 'ALL',
        ];
        if ($cursor) $q['cursor'] = $cursor;

        [$st, $raw, $json] = works_api_call($sess, 'GET', "/users/{$userId}/tasks", $q, null);
        if ($st < 200 || $st >= 300 || !is_array($json)) {
            logf('FETCH_TASKS_FAILED', ['category_id' => $categoryId, 'status' => $st, 'body' => substr($raw, 0, 300)], 'sync.log');
            break;
        }

        $tasks = $json['tasks'] ?? $json['taskList'] ?? [];
        if (is_array($tasks)) {
            foreach ($tasks as $t) {
                if (is_array($t)) $all[] = $t;
            }
        }
        $cursor = $json['cursor'] ?? null;
    } while ($cursor !== null);

    $out = [];
    foreach ($all as $t) {
        $id = (string)($t['id'] ?? $t['taskId'] ?? '');
        if ($id === '') continue;

        $out[] = [
            'id' => $id,
            'name' => (string)($t['title'] ?? $t['name'] ?? ''),
            'deadline' => (string)($t['dueDate'] ?? $t['deadline'] ?? ''),
            'events' => [],
            'status' => (string)($t['status'] ?? ''),
            'content' => (string)($t['content'] ?? ''),
        ];
    }
    return $out;
}
function fetch_calendar_ids(array $sess, string $userId): array {
    [$st, $raw, $json] = works_api($sess, 'GET', "/users/{$userId}/calendar-personals", ['count'=>50], null);
    if ($st < 200 || $st >= 300 || !is_array($json)) return [];

    $list = $json['calendarPersonals'] ?? []; // ??????
    $out = [];
    foreach ($list as $c) {
        $id = (string)($c['calendarId'] ?? '');
        if ($id !== '') $out[] = $id;
    }
    return $out;
}
function fetch_events_range(array $sess, string $userId, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $until): array {
    // ?????? cursor ????????????
    $out = [];
    $cursor = null;

    do {
        $q = [
            'fromDateTime'  => $from->format(DATE_ATOM),
            'untilDateTime' => $until->format(DATE_ATOM),
            'count'         => 200,
        ];
        if ($cursor) $q['cursor'] = $cursor;

        [$st, $raw, $json] = works_api_call($sess, 'GET', "/users/{$userId}/calendars/{$calendarId}/events", $q, null);
        if ($st < 200 || $st >= 300 || !is_array($json)) {
            logf('FETCH_EVENTS_FAILED', ['calendar_id' => $calendarId, 'status' => $st, 'body' => substr($raw, 0, 300)], 'sync.log');
            break;
        }

        $events = $json['events'] ?? [];
        if (!is_array($events)) $events = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) continue;

            $ec = $ev['eventComponents'][0] ?? null;
            if (!is_array($ec)) continue;

            // ? ID? top-level ????? eventComponents ?????
            $evId = (string)($ev['eventId'] ?? $ev['id'] ?? $ec['eventId'] ?? $ec['id'] ?? '');
            if ($evId === '') continue;

            $title = (string)($ec['summary'] ?? '');

            // dateTime ????????????????????????????
            $fromDT  = (string)($ec['start']['dateTime'] ?? $ec['start']['date'] ?? '');
            $untilDT = (string)($ec['end']['dateTime']   ?? $ec['end']['date']   ?? '');

            $memo = (string)($ec['description'] ?? '');

            if ($title === '' || $fromDT === '' || $untilDT === '') continue;

            $out[] = [
                'id' => $evId,
                'calendarId' => $calendarId,
                'title' => $title,
                'from' => $fromDT,
                'until' => $untilDT,
                'memo' => $memo,
            ];
        }


        $cursor = $json['cursor'] ?? null;
    } while ($cursor !== null);

    return $out;
}
function fetch_my_user_id(array $sess): string {
    // GET /users/me ? token ????????????
    [$st, $raw, $json] = works_api($sess, 'GET', "/users/me", null, null);
    if ($st < 200 || $st >= 300 || !is_array($json)) return '';

    // ?????????????????????????
    $id = (string)($json['userId'] ?? $json['id'] ?? '');
    return $id;
}
function ensure_user_id(PDO $pdo, array $sess): array {
    $uid = (string)($sess['lw_user_id'] ?? '');
    if ($uid !== '') return $sess;

    $uid = fetch_my_user_id($sess);
    if ($uid === '') return $sess;

    // ?: app_sessions ????????/????????DB??????
    $stmt = $pdo->prepare("UPDATE lw_app_sessions SET lw_user_id=:uid WHERE id=:id");
    $stmt->execute([':uid'=>$uid, ':id'=>$sess['id']]);

    $sess['lw_user_id'] = $uid;
    return $sess;
}
function works_api_call(array $sess, string $method, string $path, ?array $query = null, $body = null): array {
    $res = works_api($sess, $method, $path, $query, $body);
    if (!is_array($res)) return [0, '', null, null];
    $st  = (int)($res[0] ?? 0);
    $raw = (string)($res[1] ?? '');
    $json = $res[2] ?? null;
    $err  = $res[3] ?? null;
    return [$st, $raw, $json, $err];
}

//TODO: ? UPSERT: PHPMyAdmin?????upsert???
function upsert_user(PDO $pdo, string $userId): void {
    $stmt = $pdo->prepare(
        "INSERT INTO lw_users (lw_user_id) VALUES (:uid)
         ON DUPLICATE KEY UPDATE lw_user_id = lw_user_id"
    );
    $stmt->execute([':uid' => $userId]);
}
function upsert_category(PDO $pdo, string $userId, string $categoryId, string $categoryName, ?int $sortOrder = null): void {
    $stmt = $pdo->prepare(
        "INSERT INTO lw_categories (lw_user_id, category_id, category_name, sort_order)
         VALUES (:uid, :cid, :cname, :sort)
         ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), sort_order = VALUES(sort_order), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':cid' => $categoryId,
        ':cname' => $categoryName,
        ':sort' => $sortOrder,
    ]);
}
function upsert_calendar(PDO $pdo, string $userId, string $calendarId, ?string $calendarName = null): void {
    $stmt = $pdo->prepare(
        "INSERT INTO lw_calendars (lw_user_id, calendar_id, calendar_name)
         VALUES (:uid, :calid, :calname)
         ON DUPLICATE KEY UPDATE calendar_name = VALUES(calendar_name), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':calid' => $calendarId,
        ':calname' => $calendarName,
    ]);
}
function upsert_task(PDO $pdo, string $userId, string $taskId, string $categoryId, string $taskName, ?string $deadline, string $completed, ?string $content): void {
    // deadline????????????NULL?
    $deadlineDate = null;
    if ($deadline !== null && $deadline !== '') {
        // ISO8601?Y-m-d?????
        $dt = DateTime::createFromFormat('Y-m-d', substr($deadline, 0, 10));
        if ($dt !== false) {
            $deadlineDate = $dt->format('Y-m-d');
        }
    }

    // completed?ENUM????
    $statusMap = [
        'DONE' => 'DONE',
        'TODO' => 'TODO'
    ];
    $status = $statusMap[$completed] ?? 'NOT_STARTED';

    $stmt = $pdo->prepare(
        "INSERT INTO lw_tasks (lw_user_id, task_id, category_id, task_name, deadline, completed, content)
         VALUES (:uid, :tid, :cid, :tname, :deadline, :completed, :content)
         ON DUPLICATE KEY UPDATE
           category_id = VALUES(category_id),
           task_name = VALUES(task_name),
           deadline = VALUES(deadline),
           completed = VALUES(completed),
           content = VALUES(content),
           deleted = 0,
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':tid' => $taskId,
        ':cid' => $categoryId,
        ':tname' => $taskName,
        ':deadline' => $deadlineDate,
        ':completed' => $status,
        ':content' => $content,
    ]);
}
function upsert_event(PDO $pdo, string $userId, string $eventId, string $calendarId, ?string $taskId, string $title, string $startAt, string $endAt, ?string $memo): void {
    // ISO8601??????DATETIME?????
    $tz = new DateTimeZone('Asia/Tokyo');

    $startDt = new DateTime($startAt);
    $startDt->setTimezone($tz);
    $startFormatted = $startDt->format('Y-m-d H:i:s');

    $endDt = new DateTime($endAt);
    $endDt->setTimezone($tz);
    $endFormatted = $endDt->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO lw_events (lw_user_id, event_id, calendar_id, task_id, title, start_at, end_at, memo, deleted)
   VALUES (:uid, :eid, :calid, :tid, :title, :start, :end, :memo, 0)
   ON DUPLICATE KEY UPDATE
     calendar_id = VALUES(calendar_id),
     title       = VALUES(title),
     start_at    = VALUES(start_at),
     end_at      = VALUES(end_at),
     memo        = VALUES(memo),
     deleted     = 0"
    );

    $stmt->execute([
        ':uid'   => $userId,
        ':eid'   => $eventId,
        ':calid' => $calendarId,
        ':tid'   => $taskId,
        ':title' => $title,
        ':start' => $startFormatted,
        ':end'   => $endFormatted,
        ':memo'  => $memo,
    ]);

}

/**
 * API?????????????deleted=1????
 */
function mark_deleted_tasks(PDO $pdo, string $userId, array $fetchedTaskIds): void {
    if (empty($fetchedTaskIds)) {
        $stmt = $pdo->prepare(
            "UPDATE lw_tasks SET deleted = 1
             WHERE lw_user_id = :uid AND deleted = 0"
        );
        $stmt->execute([':uid' => $userId]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($fetchedTaskIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE lw_tasks SET deleted = 1
         WHERE lw_user_id = ?
           AND task_id NOT IN ({$placeholders})
           AND deleted = 0"
    );
    $stmt->execute(array_merge([$userId], $fetchedTaskIds));
}

/**
 * ??????API??????????????deleted=1????
 */
function mark_deleted_events(PDO $pdo, string $userId, array $fetchedEventIds, string $fromDt, string $untilDt): void {
    if (empty($fetchedEventIds)) {
        $stmt = $pdo->prepare(
            "UPDATE lw_events SET deleted = 1
             WHERE lw_user_id = :uid
               AND start_at >= :from
               AND start_at < :until
               AND deleted = 0"
        );
        $stmt->execute([':uid' => $userId, ':from' => $fromDt, ':until' => $untilDt]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($fetchedEventIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE lw_events SET deleted = 1
         WHERE lw_user_id = ?
           AND start_at >= ?
           AND start_at < ?
           AND event_id NOT IN ({$placeholders})
           AND deleted = 0"
    );
    $params = array_merge([$userId, $fromDt, $untilDt], $fetchedEventIds);
    $stmt->execute($params);
}

//TODO: ? SELECT: PHPMyAdmin???SELECT *???????????

/**
 * ???????????????
 */
function select_categories(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare(
        "SELECT category_id, category_name, sort_order
         FROM lw_categories
         WHERE lw_user_id = :uid
         ORDER BY sort_order ASC"
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ????????????????
 */
function select_tasks_by_category(PDO $pdo, string $userId, string $categoryId): array {
    $stmt = $pdo->prepare(
        "SELECT task_id, task_name, deadline, completed, content, juchu_num
         FROM lw_tasks
         WHERE lw_user_id = :uid AND category_id = :cid AND deleted = 0
         ORDER BY task_id ASC"
    );
    $stmt->execute([':uid' => $userId, ':cid' => $categoryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ????????????????
 */
function select_events_by_task(PDO $pdo, string $userId, string $taskId): array {
    $stmt = $pdo->prepare(
        "SELECT event_id, calendar_id, title, start_at, end_at, memo, emo_score
         FROM lw_events
         WHERE lw_user_id = :uid AND task_id = :tid AND deleted = 0
         ORDER BY start_at ASC"
    );
    $stmt->execute([':uid' => $userId, ':tid' => $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ??????????????incomplete????
 */
function select_incomplete_events(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare(
        "SELECT event_id, calendar_id, title, start_at, end_at, memo
         FROM lw_events
         WHERE lw_user_id = :uid AND task_id IS NULL AND deleted = 0
         ORDER BY start_at ASC"
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//TODO: ? SEND: ????Android??JSON??????(response.json????????)

/**
 * DB??????????Android??JSON?????????
 */
function buildResponseFromDB(PDO $pdo, string $userId): array {
    $categories = select_categories($pdo, $userId);
    $result = [];

    foreach ($categories as $cat) {
        $catId = $cat['category_id'];
        $tasks = select_tasks_by_category($pdo, $userId, $catId);

        $taskList = [];
        foreach ($tasks as $task) {
            $events = select_events_by_task($pdo, $userId, $task['task_id']);

            $eventList = [];
            foreach ($events as $ev) {
                $eventList[] = [
                    'eventId'    => $ev['event_id'],
                    'calendarId' => $ev['calendar_id'],
                    'title'      => $ev['title'],
                    'from'       => $ev['start_at'],
                    'until'      => $ev['end_at'],
                    'memo'       => $ev['memo'] ?? '',
                    'emo_score'  => $ev['emo_score'],
                ];
            }

            $taskList[] = [
                'taskId'    => $task['task_id'],
                'taskName'  => $task['task_name'],
                'deadline'  => $task['deadline'] ?? '',
                'completed' => $task['completed'],
                'content'   => $task['content'] ?? '',
                'events'    => $eventList,
                'juchuNum'  => $task['juchu_num'] ?? ''
            ];
        }

        $result[] = [
            'categoryId'   => $catId,
            'categoryName' => $cat['category_name'],
            'tasks'        => $taskList,
        ];
    }

    $incompleteEvents = select_incomplete_events($pdo, $userId);
    $incompleteList = [];
    foreach ($incompleteEvents as $ev) {
        $incompleteList[] = [
            'eventId'    => $ev['event_id'],
            'calendarId' => $ev['calendar_id'],
            'title'      => $ev['title'],
            'from'       => $ev['start_at'],
            'until'      => $ev['end_at'],
            'memo'       => $ev['memo'] ?? '',
            'emo_score'  => $ev['emo_score'],
        ];
    }

    return [
        'categories'       => $result,
        'incompleteEvents' => $incompleteList,
    ];
}

//TODO: ? MAIN: ????????????


// ---- main ----
$userId = (string)$sess['lw_user_id'];
logf('SYNC_START', ['user_id' => $userId, 'token_expires_at' => date('c', $sess['token_expires_at']), 'now' => date('c')], 'sync.log');

// 1) categories -> tasks
$categories = fetch_categories($sess, $userId);

$tasksByCategory = [];
$fetchedTaskIds = [];

foreach ($categories as $c) {
    $catId = (string)$c['id'];
    $catName = (string)$c['name'];

    $tasksByCategory[$catId] = [
        'categoryId' => $catId,
        'categoryName' => $catName,
        'tasks' => [],
    ];

    $tasks = fetch_tasks_for_category($sess, $userId, $catId);

    foreach ($tasks as $t) {
        $tid = (string)$t['id'];
        $fetchedTaskIds[] = $tid;
        $tasksByCategory[$catId]['tasks'][] = [
            'taskId' => $tid,
            'taskName' => (string)$t['name'],
            'deadline' => (string)$t['deadline'],
            'completed' => $t['status'],
            'content' => (string)$t['content'],
        ];
    }
}

// 2) calendars -> events (range split)
$calendarIds = fetch_calendar_ids($sess, $userId);

$now = new DateTimeImmutable('now', $tz);
$fromAll = $now->modify('-1 months');
$untilAll = $now->modify('+1 months');

$incomplete = [];
$fetchedEventIds = [];

foreach ($calendarIds as $calId) {
    $windowStart = $fromAll;

    while ($windowStart < $untilAll) {
        $windowEnd = $windowStart->modify('+30 days');
        if ($windowEnd > $untilAll) $windowEnd = $untilAll;

        $chunk = fetch_events_range($sess, $userId, $calId, $windowStart, $windowEnd);

        foreach ($chunk as $e) {
            $fetchedEventIds[] = (string)$e['id'];
            $incomplete[] = [
                'eventId'    => (string)$e['id'],
                'calendarId' => (string)$e['calendarId'],
                'title'      => (string)$e['title'],
                'from'       => (string)$e['from'],
                'until'      => (string)$e['until'],
                'memo'       => $e['memo'],
            ];
        }

        $windowStart = $windowEnd;
    }
}

$pdo->beginTransaction();

try {
    // 1) ??????
    upsert_user($pdo, $userId);

    // 2) ???????
    foreach ($calendarIds as $calId) {
        upsert_calendar($pdo, $userId, $calId, null);
    }

    // 3) ???????????
    $sortOrder = 0;
    foreach ($tasksByCategory as $catId => $catData) {
        upsert_category($pdo, $userId, $catId, $catData['categoryName'], $sortOrder++);

        foreach ($catData['tasks'] as $task) {
            upsert_task(
                $pdo,
                $userId,
                $task['taskId'],
                $catId,
                $task['taskName'],
                $task['deadline'],
                $task['completed'],
                $task['content']
            );

        }
    }

    // 5) incompleteEvents??????????????????
    foreach ($incomplete as $event) {
        upsert_event(
            $pdo,
            $userId,
            $event['eventId'],
            $event['calendarId'],
            null,  // ???????????
            $event['title'],
            $event['from'],
            $event['until'],
            $event['memo']
        );
    }

    // API?????????????deleted=1????
    mark_deleted_tasks($pdo, $userId, array_unique($fetchedTaskIds));

    // ??????API??????????????deleted=1????
    $fromAllStr  = $fromAll->format('Y-m-d H:i:s');
    $untilAllStr = $untilAll->format('Y-m-d H:i:s');
    mark_deleted_events($pdo, $userId, array_unique($fetchedEventIds), $fromAllStr, $untilAllStr);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    logf('SYNC_DB_ERROR', ['message' => $e->getMessage()], 'sync.log');
    error_log("syncTasks DB save error: " . $e->getMessage());
}

// ? DB???????????????????
$response = buildResponseFromDB($pdo, $userId);
logf('SYNC_DONE', ['user_id' => $userId, 'categories' => count($response['categories']), 'incomplete_events' => count($response['incompleteEvents'])], 'sync.log');
json_ok($response);