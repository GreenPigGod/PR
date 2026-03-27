<?php
declare(strict_types=1);

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(400);
    echo '<h1>Missing session</h1>';
    exit;
}

$hSession = htmlspecialchars($session, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>認証中...</title>
<style>body{font-family:system-ui;background:#0b1220;color:#e5e7eb;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}</style>
</head>
<body>
<p>ログイン中です...</p>
<span id="s" data-v="<?= $hSession ?>" hidden></span>
<script>
(function () {
    var v = document.getElementById('s').dataset.v;
    try {
        localStorage.setItem('app_session', v);
        location.replace('index.html');
    } catch (e) {
        document.body.textContent = 'セッション保存に失敗しました: ' + e.message;
    }
}());
</script>
</body>
</html>
