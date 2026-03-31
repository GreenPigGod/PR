<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

function normalize_due_date_input_c($value): ?string {
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        json_fail(400, 'dueDate must be YYYY-MM-DD');
    }
    return $s;
}

function normalize_optional_string($value): ?string {
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    return $s === '' ? null : $s;
}

/**
 * categoryId が直接来ていればそれを使う。
 * 無ければ category 名から既存カテゴリを探し、無ければ作成する。
 */
function resolve_task_category_id(array $sess, string $userId, ?string $categoryIdInput, ?string $categoryNameInput): ?string {
    $categoryIdInput   = normalize_optional_string($categoryIdInput);
    $categoryNameInput = normalize_optional_string($categoryNameInput);

    if ($categoryIdInput !== null) {
        return $categoryIdInput;
    }
    if ($categoryNameInput === null) {
        return null;
    }

    [$stC, $rawC, $catsJson, $errC] = works_api(
        $sess,
        'GET',
        "/users/{$userId}/task-categories",
        ['count' => 100]
    );

    if ($errC !== '') {
        json_fail(502, 'get task categories curl error: ' . substr($errC, 0, 300));
    }

    if ($stC < 200 || $stC >= 300 || !is_array($catsJson)) {
        json_fail(502, 'failed to get task categories st=' . $stC . ' raw=' . substr((string)$rawC, 0, 300));
    }

    $cats = $catsJson['taskCategories'] ?? $catsJson['categories'] ?? [];
    if (is_array($cats)) {
        foreach ($cats as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string)($c['categoryName'] ?? $c['name'] ?? ''));
            $id   = trim((string)($c['categoryId'] ?? $c['id'] ?? ''));
            if ($id !== '' && mb_strtolower($name) === mb_strtolower($categoryNameInput)) {
                return $id;
            }
        }
    }

    [$stN, $rawN, $newCat, $errN] = works_api(
        $sess,
        'POST',
        "/users/{$userId}/task-categories",
        [],
        ['categoryName' => $categoryNameInput]
    );

    if ($errN !== '') {
        json_fail(502, 'create category curl error: ' . substr($errN, 0, 300));
    }
    if ($stN < 200 || $stN >= 300 || !is_array($newCat)) {
        json_fail(502, 'failed to create category st=' . $stN . ' raw=' . substr((string)$rawN, 0, 300));
    }

    $newCategoryId = trim((string)($newCat['categoryId'] ?? $newCat['id'] ?? ''));
    if ($newCategoryId === '') {
        json_fail(502, 'cannot resolve new categoryId');
    }

    return $newCategoryId;
}

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);

$rawBody = file_get_contents('php://input') ?: '';
$in = json_decode($rawBody, true);
if (!is_array($in)) {
    json_fail(400, 'invalid json');
}

$title = trim((string)($in['title'] ?? ''));
if ($title === '') {
    json_fail(400, 'title is required');
}

/**
 * 公式上 content は required。
 * 未指定なら title を流用して落ちにくくする。
 */
$content = trim((string)($in['content'] ?? ''));
if ($content === '') {
    $content = $title;
}

$categoryName = isset($in['category']) ? trim((string)$in['category']) : null;
$categoryIdIn = isset($in['categoryId']) ? trim((string)$in['categoryId']) : null;
$juchuNum     = trim((string)($in['juchu_num'] ?? ''));

$dueDate = null;
if (array_key_exists('dueDate', $in)) {
    $dueDate = normalize_due_date_input_c($in['dueDate']);
} elseif (array_key_exists('due', $in)) {
    $dueDate = normalize_due_date_input_c($in['due']);
}

try {
    $userId = trim((string)($sess['lw_user_id'] ?? ''));
    if ($userId === '') {
        json_fail(502, 'cannot resolve userId from session');
    }

    /**
     * マイタスク作成なので、基本は assignor / assignee とも自分。
     * 入力に来たときだけ上書き可能にする。
     */
    $assignorId = trim((string)($in['assignorId'] ?? $userId));
    if ($assignorId === '') {
        $assignorId = $userId;
    }

    $assigneeId = trim((string)($in['assigneeId'] ?? $userId));
    if ($assigneeId === '') {
        $assigneeId = $userId;
    }

    /**
     * 1) カテゴリ解決
     */
    $resolvedCategoryId = resolve_task_category_id(
        $sess,
        $userId,
        $categoryIdIn,
        $categoryName
    );

    /**
     * 2) タスク新規作成
     */
    $createBody = [
        'title' => $title,
        'content' => $content,
        'completionCondition' => 'ANY_ONE',
        'assignorId' => $assignorId,
        'assignees' => [
            [
                'assigneeId' => $assigneeId,
                'status' => 'TODO',
            ],
        ],
    ];

    if ($dueDate !== null) {
        $createBody['dueDate'] = $dueDate;
    }
    if ($resolvedCategoryId !== null) {
        $createBody['categoryId'] = $resolvedCategoryId;
    }

    [$stCr, $rawCr, $createdJson, $errCr] = works_api(
        $sess,
        'POST',
        "/users/{$userId}/tasks",
        [],
        $createBody
    );

    logf('TASK_CREATE', [
        'requestBody' => $createBody,
        'status'      => $stCr,
        'raw'         => substr((string)$rawCr, 0, 1000),
        'curlErr'     => $errCr,
    ], 'move_debug.log');

    if ($errCr !== '') {
        json_fail(502, 'createTask curl error: ' . substr($errCr, 0, 300));
    }
    if ($stCr < 200 || $stCr >= 300 || !is_array($createdJson)) {
        json_fail(502, 'createTask failed st=' . $stCr . ' raw=' . substr((string)$rawCr, 0, 500));
    }

    $taskId = trim((string)($createdJson['taskId'] ?? $createdJson['id'] ?? ''));
    if ($taskId === '') {
        json_fail(502, 'cannot resolve taskId from LINE WORKS response raw=' . substr((string)$rawCr, 0, 500));
    }

    /**
     * 3) ローカルDB保存
     */
    $stmt = $pdo->prepare(
        "INSERT INTO pr_tasks (
            lw_user_id,
            task_id,
            category_id,
            task_name,
            deadline,
            completed,
            content,
            juchu_num
        ) VALUES (
            :uid,
            :tid,
            :cid,
            :tname,
            :deadline,
            :completed,
            :content,
            :juchu_num
        )
        ON DUPLICATE KEY UPDATE
            category_id = VALUES(category_id),
            task_name   = VALUES(task_name),
            deadline    = VALUES(deadline),
            completed   = VALUES(completed),
            content     = VALUES(content),
            juchu_num   = VALUES(juchu_num),
            updated_at  = CURRENT_TIMESTAMP"
    );

    $stmt->execute([
        ':uid'       => $userId,
        ':tid'       => $taskId,
        ':cid'       => $resolvedCategoryId ?? '',
        ':tname'     => $title,
        ':deadline'  => $dueDate,
        ':completed' => 'TODO',
        ':content'   => $content,
        ':juchu_num' => $juchuNum,
    ]);

    echo json_encode([
        'ok'          => true,
        'taskId'      => $taskId,
        'categoryId'  => $resolvedCategoryId,
        'requestBody' => $createBody,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    json_fail(
        500,
        'server exception: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
    );
}