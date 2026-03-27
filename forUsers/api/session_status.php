<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['access_token']) || empty($_SESSION['user_id'])) {
    echo json_encode([
        'loggedIn' => false,
    ]);
    exit;
}

echo json_encode([
    'loggedIn' => true,
    'userId'   => $_SESSION['user_id'],
    'expiresAt'=> ($_SESSION['access_expires_at'] ?? 0) * 1000, // JS用にms
]);
