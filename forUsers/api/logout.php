<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api_common.php';

$cfg = cfg();
$pdo = pdo_from_cfg($cfg['db']);

$token = require_bearer();
$hash  = sha256hex($token);

$pdo->prepare('DELETE FROM lw_app_sessions WHERE session_hash = :h')
    ->execute([':h' => $hash]);

json_ok(['ok' => true]);
