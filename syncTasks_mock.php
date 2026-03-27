<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// syncTasks.php の buildResponseFromDB() と同じ JSON 構造をそのまま返すモック
// 認証不要・DB不要・LINE WORKS API 不要

echo json_encode([
    'categories' => [
        [
            'categoryId'   => 'cat-1',
            'categoryName' => '修理',
            'tasks' => [
                [
                    'taskId'    => 'task-1',
                    'taskName'  => '田中様@エアコン修理',
                    'deadline'  => '2026-04-10',
                    'completed' => 'TODO',
                    'content'   => "機種: 日立RAS-X40L2\n症状: 冷えない",
                    'juchuNum'  => 'J-2024-001',
                    'events'    => [
                        [
                            'eventId'    => 'ev-1',
                            'calendarId' => 'cal-abc123',
                            'title'      => '田中様@エアコン修理 訪問',
                            'from'       => '2026-03-28 10:00:00',
                            'until'      => '2026-03-28 12:00:00',
                            'memo'       => '部品持参・駐車場あり',
                            'emo_score'  => null,
                        ],
                    ],
                ],
                [
                    'taskId'    => 'task-2',
                    'taskName'  => '鈴木様@給湯器交換',
                    'deadline'  => '2026-03-31',
                    'completed' => 'TODO',
                    'content'   => '',
                    'juchuNum'  => 'J-2024-002',
                    'events'    => [],
                ],
                [
                    'taskId'    => 'task-3',
                    'taskName'  => '佐藤様@洗濯機修理',
                    'deadline'  => '2026-04-02',
                    'completed' => 'DONE',
                    'content'   => '完了済み',
                    'juchuNum'  => '',
                    'events'    => [
                        [
                            'eventId'    => 'ev-2',
                            'calendarId' => 'cal-abc123',
                            'title'      => '佐藤様@洗濯機修理 完了',
                            'from'       => '2026-03-25 13:00:00',
                            'until'      => '2026-03-25 15:00:00',
                            'memo'       => '',
                            'emo_score'  => null,
                        ],
                    ],
                ],
            ],
        ],
        [
            'categoryId'   => 'cat-2',
            'categoryName' => '商談',
            'tasks' => [
                [
                    'taskId'    => 'task-4',
                    'taskName'  => '山田様@見積もり提出',
                    'deadline'  => '2026-04-05',
                    'completed' => 'TODO',
                    'content'   => 'エアコン3台分の見積もり',
                    'juchuNum'  => '',
                    'events'    => [
                        [
                            'eventId'    => 'ev-3',
                            'calendarId' => 'cal-abc123',
                            'title'      => '山田様 打合せ',
                            'from'       => '2026-04-01 14:00:00',
                            'until'      => '2026-04-01 15:00:00',
                            'memo'       => '資料持参',
                            'emo_score'  => null,
                        ],
                    ],
                ],
            ],
        ],
        [
            'categoryId'   => 'cat-3',
            'categoryName' => '巡回',
            'tasks' => [],
        ],
    ],
    'incompleteEvents' => [
        [
            'eventId'    => 'ev-4',
            'calendarId' => 'cal-abc123',
            'title'      => '社内朝礼',
            'from'       => '2026-03-28 08:30:00',
            'until'      => '2026-03-28 09:00:00',
            'memo'       => '',
            'emo_score'  => null,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
