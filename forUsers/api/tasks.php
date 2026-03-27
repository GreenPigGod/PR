<?php
// api/tasks.php

header('Content-Type: application/json; charset=utf-8');
session_start();

// 認証チェック
if (empty($_SESSION['access_token']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];

$cfg = require __DIR__ . '/../../config.php';
$db  = $cfg['db'];

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'categories'       => [],
        'incompleteEvents' => [],
        'message'          => 'DB接続に失敗しました',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "
        SELECT
            t.id,
            t.user_id,
            t.category_id,
            t.lw_task_id,
            t.title,
            t.deadline,
            c.name AS category_name
        FROM tasks AS t
        LEFT JOIN task_categories AS c
            ON t.category_id = c.id
        WHERE t.user_id = :uid
        ORDER BY
            c.id IS NULL,
            c.id,
            t.deadline,
            t.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $currentUserId]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'categories'       => [],
        'incompleteEvents' => [],
        'message'          => 'タスク取得に失敗しました',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$categoriesMap    = [];
$incompleteEvents = [];

foreach ($rows as $r) {
    $task = [
        'id'        => (int)$r['id'],
        'title'     => $r['title'],
        'deadline'  => $r['deadline'],
        'lwTaskId'  => $r['lw_task_id'],
    ];

    $catId   = $r['category_id'];
    $catName = $r['category_name'] ?? '';

    if (empty($catId)) {
        $incompleteEvents[] = $task;
    } else {
        $catId = (int)$catId;
        if (!isset($categoriesMap[$catId])) {
            $categoriesMap[$catId] = [
                'categoryId'   => $catId,
                'categoryName' => $catName !== '' ? $catName : ('カテゴリID ' . $catId),
                'tasks'        => [],
            ];
        }
        $categoriesMap[$catId]['tasks'][] = $task;
    }
}

$categories = array_values($categoriesMap);

echo json_encode([
    'categories'       => $categories,
    'incompleteEvents' => $incompleteEvents,
    'message'          => 'タスクを取得しました。',
], JSON_UNESCAPED_UNICODE);
