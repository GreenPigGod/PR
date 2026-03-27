<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

function extract_task_category_id(?array $taskJson): string {
    if (!is_array($taskJson)) {
        return '';
    }

    if (!empty($taskJson['categoryId']) && is_string($taskJson['categoryId'])) {
        return trim($taskJson['categoryId']);
    }

    if (!empty($taskJson['taskCategoryId']) && is_string($taskJson['taskCategoryId'])) {
        return trim($taskJson['taskCategoryId']);
    }

    if (isset($taskJson['category']) && is_array($taskJson['category'])) {
        $category = $taskJson['category'];

        if (!empty($category['categoryId']) && is_string($category['categoryId'])) {
            return trim($category['categoryId']);
        }

        if (!empty($category['id']) && is_string($category['id'])) {
            return trim($category['id']);
        }
    }

    return '';
}

function normalize_due_date_input($value): ?string {
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

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$sess = require_app_session_row($pdo);
$sess = refresh_access_token($pdo, $sess);

$rawBody = file_get_contents('php://input') ?: '';
$in = json_decode($rawBody, true);
if (!is_array($in)) {
    json_fail(400, 'invalid json');
}

$taskId = trim((string)($in['taskId'] ?? ''));
if ($taskId === '') {
    json_fail(400, 'taskId is required');
}

$catName = array_key_exists('category', $in)
    ? trim((string)$in['category'])
    : null;

$dueDateSpecified = array_key_exists('dueDate', $in) || array_key_exists('due', $in);
$dueDate = null;
if (array_key_exists('dueDate', $in)) {
    $dueDate = normalize_due_date_input($in['dueDate']);
} elseif (array_key_exists('due', $in)) {
    $dueDate = normalize_due_date_input($in['due']);
}

$status = null;
if (array_key_exists('status', $in)) {
    $status = strtoupper(trim((string)$in['status']));
    if ($status !== 'TODO' && $status !== 'DONE') {
        json_fail(400, 'status must be TODO or DONE');
    }
}

$juchuNum = array_key_exists('juchu_num', $in) ? trim((string)$in['juchu_num']) : null;

try {
    $moved = false;
    $updated = false;
    $resolvedCategoryId = null;

    /**
     * 1) カテゴリー変更
     *    - category 名から categoryId を解決
     *    - なければ作成
     *    - move API は toCategoryId を使う
     */
    if ($catName !== null && $catName !== '') {
        $userId = trim((string)($sess['lw_user_id'] ?? ''));
        if ($userId === '') {
            json_fail(502, 'cannot resolve userId from session');
        }

        $categoryId = '';

        [$stC, $rawC, $catsJson, $errC] = works_api(
            $sess,
            'GET',
            "/users/{$userId}/task-categories",
            ['count' => 100]
        );

        if ($errC !== '') {
            json_fail(502, 'get task categories curl error: ' . substr($errC, 0, 300));
        }

        if ($stC >= 200 && $stC < 300 && is_array($catsJson)) {
            $cats = $catsJson['taskCategories'] ?? $catsJson['categories'] ?? [];
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (!is_array($c)) {
                        continue;
                    }

                    $name = trim((string)($c['categoryName'] ?? $c['name'] ?? ''));
                    $id   = trim((string)($c['categoryId'] ?? $c['id'] ?? ''));

                    if ($id !== '' && mb_strtolower($name) === mb_strtolower($catName)) {
                        $categoryId = $id;
                        break;
                    }
                }
            }
        }

        if ($categoryId === '') {
            [$stN, $rawN, $newCat, $errN] = works_api(
                $sess,
                'POST',
                "/users/{$userId}/task-categories",
                [],
                ['categoryName' => $catName]
            );

            if ($errN !== '') {
                json_fail(502, 'create category curl error: ' . substr($errN, 0, 300));
            }

            if ($stN < 200 || $stN >= 300 || !is_array($newCat)) {
                json_fail(
                    502,
                    "failed to create category st={$stN} raw=" . substr((string)$rawN, 0, 300)
                );
            }

            $categoryId = trim((string)($newCat['categoryId'] ?? $newCat['id'] ?? ''));
            if ($categoryId === '') {
                json_fail(502, 'cannot resolve new categoryId');
            }
        }

        $resolvedCategoryId = $categoryId;

        // 同じカテゴリなら move をスキップ
        $currentCategoryId = '';
        [$stT, $rawT, $taskJson, $errT] = works_api(
            $sess,
            'GET',
            "/tasks/{$taskId}"
        );

        if ($errT === '' && $stT >= 200 && $stT < 300 && is_array($taskJson)) {
            $currentCategoryId = extract_task_category_id($taskJson);
        }

        if ($currentCategoryId === '' || $currentCategoryId !== $categoryId) {
            [$stM, $rawM, $moveJson, $errM] = works_api(
                $sess,
                'POST',
                "/users/{$userId}/tasks/{$taskId}/move",
                [],
                ['toCategoryId' => $categoryId]
            );

            logf('TASK_MOVE', [
                'taskId'       => $taskId,
                'userId'       => $userId,
                'toCategoryId' => $categoryId,
                'status'       => $stM,
                'raw'          => substr((string)$rawM, 0, 500),
                'curlErr'      => $errM,
            ], 'move_debug.log');

            if ($errM !== '') {
                json_fail(
                    502,
                    "task move curl error uid={$userId} toCategoryId={$categoryId} err=" . substr($errM, 0, 300)
                );
            }

            if ($stM < 200 || $stM >= 300) {
                json_fail(
                    502,
                    "task move failed st={$stM} uid={$userId} toCategoryId={$categoryId} raw=" . substr((string)$rawM, 0, 300)
                );
            }

            $moved = true;
        }
    }

    /**
     * 2) タスク本体更新
     */
    $body = [];

    if (array_key_exists('title', $in)) {
        $body['title'] = (string)$in['title'];
    }

    if (array_key_exists('content', $in)) {
        $body['content'] = (string)$in['content'];
    }

    if ($dueDateSpecified) {
        $body['dueDate'] = $dueDate; // null を送れば期限解除
    }

    if ($status !== null) {
        $body['status'] = $status;
    }

    if (!empty($body)) {
        [$stU, $rawU, $jsonU, $errU] = works_api(
            $sess,
            'PATCH',
            "/tasks/{$taskId}",
            [],
            $body
        );

        logf('TASK_PATCH', [
            'taskId'  => $taskId,
            'body'    => $body,
            'status'  => $stU,
            'raw'     => substr((string)$rawU, 0, 500),
            'curlErr' => $errU,
        ], 'move_debug.log');

        if ($errU !== '') {
            json_fail(502, 'updateTask curl error: ' . substr($errU, 0, 300));
        }

        if ($stU < 200 || $stU >= 300) {
            json_fail(
                502,
                "updateTask failed st={$stU} raw=" . substr((string)$rawU, 0, 300)
            );
        }

        $updated = true;
    }

    if ($juchuNum !== null) {
        $stmtJ = $pdo->prepare(
            "UPDATE lw_tasks SET juchu_num = :juchu_num, updated_at = CURRENT_TIMESTAMP WHERE task_id = :task_id"
        );
        $stmtJ->execute([':juchu_num' => $juchuNum, ':task_id' => $taskId]);
    }

    echo json_encode([
        'ok'         => true,
        'taskId'     => $taskId,
        'moved'      => $moved,
        'updated'    => $updated,
        'categoryId' => $resolvedCategoryId,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    json_fail(
        500,
        'server exception: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
    );
}