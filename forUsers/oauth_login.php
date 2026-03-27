<?php
session_start();

$cfg        = require __DIR__ . '/../config.php';
$clientId   = $cfg['lw']['client_id'];
$redirectUri = $cfg['for_users']['redirect_uri'];

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$query = http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'scope'         => 'openid profile user.read calendar.read task.read',
    'state'         => $state,
]);

$authUrl = "https://auth.worksmobile.com/oauth2/v2.0/authorize?{$query}";
header('Location: ' . $authUrl);
exit;
