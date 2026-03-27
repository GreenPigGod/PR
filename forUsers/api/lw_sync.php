<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api_common.php';

$cfg    = cfg();
$pdo    = pdo_from_cfg($cfg['db']);
$sess   = require_app_session_row($pdo);
$sess   = refresh_access_token($pdo, $sess);
$userId = (string)$sess['lw_user_id'];

if ($userId === '') {
    json_fail(500, 'user_id_missing');
}

$tz = new DateTimeZone('Asia/Tokyo');

// ---------- LINE WORKS API fetch ----------

function fetch_lw_categories(array $sess, string $userId): array {
    [$st, , $json] = works_api($sess, 'GET', "/users/{$userId}/task-categories", ['count' => 100], null);
    if ($st < 200 || $st >= 300 || !is_array($json)) return [];
    $cats = $json['categories'] ?? $json['taskCategories'] ?? [];
    if (!is_array($cats)) return [];
    $out = [];
    foreach ($cats as $c) {
        if (!is_array($c)) continue;
        $id = (string)($c['id'] ?? $c['categoryId'] ?? '');
        if ($id === '') continue;
        $out[] = ['id' => $id, 'name' => (string)($c['name'] ?? $c['categoryName'] ?? '')];
    }
    return $out;
}

function fetch_lw_tasks(array $sess, string $userId, string $categoryId): array {
    $all    = [];
    $cursor = null;
    do {
        $q = ['categoryId' => $categoryId, 'count' => 100, 'status' => 'ALL', 'searchFilterType' => 'ALL'];
        if ($cursor) $q['cursor'] = $cursor;
        [$st, , $json] = works_api($sess, 'GET', "/users/{$userId}/tasks", $q, null);
        if ($st < 200 || $st >= 300 || !is_array($json)) break;
        $tasks = $json['tasks'] ?? $json['taskList'] ?? [];
        if (is_array($tasks)) {
            foreach ($tasks as $t) { if (is_array($t)) $all[] = $t; }
        }
        $cursor = $json['cursor'] ?? null;
    } while ($cursor !== null);

    $out = [];
    foreach ($all as $t) {
        $id = (string)($t['id'] ?? $t['taskId'] ?? '');
        if ($id === '') continue;
        $out[] = [
            'id'       => $id,
            'name'     => (string)($t['title'] ?? $t['name'] ?? ''),
            'deadline' => (string)($t['dueDate'] ?? $t['deadline'] ?? ''),
            'status'   => (string)($t['status'] ?? ''),
            'content'  => (string)($t['content'] ?? ''),
        ];
    }
    return $out;
}

function fetch_lw_calendar_ids(array $sess, string $userId): array {
    [$st, , $json] = works_api($sess, 'GET', "/users/{$userId}/calendar-personals", ['count' => 50], null);
    if ($st < 200 || $st >= 300 || !is_array($json)) return [];
    $list = $json['calendarPersonals'] ?? [];
    $out  = [];
    foreach ($list as $c) {
        $id = (string)($c['calendarId'] ?? '');
        if ($id !== '') $out[] = $id;
    }
    return $out;
}

function fetch_lw_events_range(array $sess, string $userId, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $until): array {
    $out    = [];
    $cursor = null;
    do {
        $q = [
            'fromDateTime'  => $from->format(DATE_ATOM),
            'untilDateTime' => $until->format(DATE_ATOM),
            'count'         => 200,
        ];
        if ($cursor) $q['cursor'] = $cursor;
        [$st, , $json] = works_api($sess, 'GET', "/users/{$userId}/calendars/{$calendarId}/events", $q, null);
        if ($st < 200 || $st >= 300 || !is_array($json)) break;
        $events = $json['events'] ?? [];
        if (!is_array($events)) $events = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $ec = $ev['eventComponents'][0] ?? null;
            if (!is_array($ec)) continue;
            $evId    = (string)($ev['eventId'] ?? $ev['id'] ?? $ec['eventId'] ?? $ec['id'] ?? '');
            $title   = (string)($ec['summary'] ?? '');
            $fromDT  = (string)($ec['start']['dateTime'] ?? $ec['start']['date'] ?? '');
            $untilDT = (string)($ec['end']['dateTime']   ?? $ec['end']['date']   ?? '');
            if ($evId === '' || $title === '' || $fromDT === '' || $untilDT === '') continue;
            $out[] = [
                'id'         => $evId,
                'calendarId' => $calendarId,
                'title'      => $title,
                'from'       => $fromDT,
                'until'      => $untilDT,
                'memo'       => (string)($ec['description'] ?? ''),
            ];
        }
        $cursor = $json['cursor'] ?? null;
    } while ($cursor !== null);
    return $out;
}

// ---------- DB upsert ----------

function lw_upsert_category(PDO $pdo, string $userId, string $catId, string $catName, int $sort): void {
    $pdo->prepare(
        "INSERT INTO lw_categories (lw_user_id, category_id, category_name, sort_order)
         VALUES (:uid,:cid,:cname,:sort)
         ON DUPLICATE KEY UPDATE category_name=VALUES(category_name), sort_order=VALUES(sort_order), updated_at=CURRENT_TIMESTAMP"
    )->execute([':uid' => $userId, ':cid' => $catId, ':cname' => $catName, ':sort' => $sort]);
}

function lw_upsert_task(PDO $pdo, string $userId, string $taskId, string $catId, string $name, ?string $deadline, string $status, ?string $content): void {
    $deadlineDate = null;
    if ($deadline !== null && $deadline !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', substr($deadline, 0, 10));
        if ($dt !== false) $deadlineDate = $dt->format('Y-m-d');
    }
    $completed = match ($status) { 'DONE' => 'DONE', 'TODO' => 'TODO', default => 'NOT_STARTED' };
    $pdo->prepare(
        "INSERT INTO lw_tasks (lw_user_id, task_id, category_id, task_name, deadline, completed, content)
         VALUES (:uid,:tid,:cid,:tname,:deadline,:completed,:content)
         ON DUPLICATE KEY UPDATE
           category_id=VALUES(category_id), task_name=VALUES(task_name),
           deadline=VALUES(deadline), completed=VALUES(completed), content=VALUES(content),
           deleted=0, updated_at=CURRENT_TIMESTAMP"
    )->execute([':uid' => $userId, ':tid' => $taskId, ':cid' => $catId, ':tname' => $name, ':deadline' => $deadlineDate, ':completed' => $completed, ':content' => $content]);
}

function lw_upsert_event(PDO $pdo, string $userId, string $evId, string $calId, string $title, string $startAt, string $endAt, ?string $memo): void {
    $tz      = new DateTimeZone('Asia/Tokyo');
    $startFmt = (new DateTime($startAt))->setTimezone($tz)->format('Y-m-d H:i:s');
    $endFmt   = (new DateTime($endAt))->setTimezone($tz)->format('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO lw_events (lw_user_id, event_id, calendar_id, task_id, title, start_at, end_at, memo, deleted)
         VALUES (:uid,:eid,:calid,NULL,:title,:start,:end,:memo,0)
         ON DUPLICATE KEY UPDATE
           title=VALUES(title), start_at=VALUES(start_at), end_at=VALUES(end_at), memo=VALUES(memo), deleted=0"
    )->execute([':uid' => $userId, ':eid' => $evId, ':calid' => $calId, ':title' => $title, ':start' => $startFmt, ':end' => $endFmt, ':memo' => $memo]);
}

function lw_mark_deleted_tasks(PDO $pdo, string $userId, array $ids): void {
    if (empty($ids)) {
        $pdo->prepare("UPDATE lw_tasks SET deleted=1 WHERE lw_user_id=:uid AND deleted=0")->execute([':uid' => $userId]);
        return;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE lw_tasks SET deleted=1 WHERE lw_user_id=? AND task_id NOT IN ({$ph}) AND deleted=0")
        ->execute(array_merge([$userId], $ids));
}

function lw_mark_deleted_events(PDO $pdo, string $userId, array $ids, string $fromDt, string $untilDt): void {
    if (empty($ids)) {
        $pdo->prepare("UPDATE lw_events SET deleted=1 WHERE lw_user_id=:uid AND start_at>=:from AND start_at<:until AND deleted=0")
            ->execute([':uid' => $userId, ':from' => $fromDt, ':until' => $untilDt]);
        return;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE lw_events SET deleted=1 WHERE lw_user_id=? AND start_at>=? AND start_at<? AND event_id NOT IN ({$ph}) AND deleted=0")
        ->execute(array_merge([$userId, $fromDt, $untilDt], $ids));
}

// ---------- DB select → response ----------

function lw_build_response(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare("SELECT category_id, category_name FROM lw_categories WHERE lw_user_id=:uid ORDER BY sort_order ASC");
    $stmt->execute([':uid' => $userId]);
    $cats = $stmt->fetchAll();

    $result = [];
    foreach ($cats as $cat) {
        $catId = $cat['category_id'];

        $stmt2 = $pdo->prepare(
            "SELECT task_id, task_name, deadline, completed, content
             FROM lw_tasks
             WHERE lw_user_id=:uid AND category_id=:cid AND deleted=0
             ORDER BY task_id ASC"
        );
        $stmt2->execute([':uid' => $userId, ':cid' => $catId]);
        $tasks = $stmt2->fetchAll();

        $taskList = [];
        foreach ($tasks as $task) {
            $stmt3 = $pdo->prepare(
                "SELECT event_id, calendar_id, title, start_at, end_at, memo
                 FROM lw_events
                 WHERE lw_user_id=:uid AND task_id=:tid AND deleted=0
                 ORDER BY start_at ASC"
            );
            $stmt3->execute([':uid' => $userId, ':tid' => $task['task_id']]);
            $evRows = $stmt3->fetchAll();

            $eventList = [];
            foreach ($evRows as $ev) {
                $eventList[] = [
                    'eventId'    => $ev['event_id'],
                    'calendarId' => $ev['calendar_id'],
                    'title'      => $ev['title'],
                    'from'       => $ev['start_at'],
                    'until'      => $ev['end_at'],
                    'memo'       => $ev['memo'] ?? '',
                ];
            }

            $taskList[] = [
                'taskId'    => $task['task_id'],
                'taskName'  => $task['task_name'],
                'deadline'  => $task['deadline'] ?? '',
                'completed' => $task['completed'],
                'content'   => $task['content'] ?? '',
                'events'    => $eventList,
            ];
        }

        $result[] = [
            'categoryId'   => $catId,
            'categoryName' => $cat['category_name'],
            'tasks'        => $taskList,
        ];
    }

    $stmt4 = $pdo->prepare(
        "SELECT event_id, calendar_id, title, start_at, end_at, memo
         FROM lw_events
         WHERE lw_user_id=:uid AND task_id IS NULL AND deleted=0
         ORDER BY start_at ASC"
    );
    $stmt4->execute([':uid' => $userId]);
    $incRows = $stmt4->fetchAll();

    $incompleteList = [];
    foreach ($incRows as $ev) {
        $incompleteList[] = [
            'eventId'    => $ev['event_id'],
            'calendarId' => $ev['calendar_id'],
            'title'      => $ev['title'],
            'from'       => $ev['start_at'],
            'until'      => $ev['end_at'],
            'memo'       => $ev['memo'] ?? '',
        ];
    }

    return ['categories' => $result, 'incompleteEvents' => $incompleteList];
}

// ---------- main ----------

// 1. カテゴリ + タスクを取得
$lwCategories   = fetch_lw_categories($sess, $userId);
$fetchedTaskIds = [];
$tasksByCategory = [];

foreach ($lwCategories as $cat) {
    $catId   = $cat['id'];
    $tasks   = fetch_lw_tasks($sess, $userId, $catId);
    $tasksByCategory[$catId] = ['name' => $cat['name'], 'tasks' => $tasks];
    foreach ($tasks as $t) { $fetchedTaskIds[] = $t['id']; }
}

// 2. カレンダーイベントを取得（前後1ヶ月）
$now      = new DateTimeImmutable('now', $tz);
$fromAll  = $now->modify('-1 month');
$untilAll = $now->modify('+1 month');

$calendarIds     = fetch_lw_calendar_ids($sess, $userId);
$allEvents       = [];
$fetchedEventIds = [];

foreach ($calendarIds as $calId) {
    $windowStart = $fromAll;
    while ($windowStart < $untilAll) {
        $windowEnd = $windowStart->modify('+30 days');
        if ($windowEnd > $untilAll) $windowEnd = $untilAll;
        $chunk = fetch_lw_events_range($sess, $userId, $calId, $windowStart, $windowEnd);
        foreach ($chunk as $e) {
            $fetchedEventIds[] = $e['id'];
            $allEvents[]       = $e;
        }
        $windowStart = $windowEnd;
    }
}

// 3. DBに保存
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO lw_users (lw_user_id) VALUES (:u) ON DUPLICATE KEY UPDATE lw_user_id=lw_user_id")
        ->execute([':u' => $userId]);

    $sort = 0;
    foreach ($tasksByCategory as $catId => $catData) {
        lw_upsert_category($pdo, $userId, $catId, $catData['name'], $sort++);
        foreach ($catData['tasks'] as $t) {
            lw_upsert_task($pdo, $userId, $t['id'], $catId, $t['name'], $t['deadline'], $t['status'], $t['content']);
        }
    }

    foreach ($allEvents as $ev) {
        lw_upsert_event($pdo, $userId, $ev['id'], $ev['calendarId'], $ev['title'], $ev['from'], $ev['until'], $ev['memo']);
    }

    lw_mark_deleted_tasks($pdo, $userId, array_unique($fetchedTaskIds));

    $fromStr  = $fromAll->format('Y-m-d H:i:s');
    $untilStr = $untilAll->format('Y-m-d H:i:s');
    lw_mark_deleted_events($pdo, $userId, array_unique($fetchedEventIds), $fromStr, $untilStr);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[lw_sync] DB error: ' . $e->getMessage());
    json_fail(500, 'db_error');
}

// 4. DBから読み直してレスポンス返却
json_ok(lw_build_response($pdo, $userId));
