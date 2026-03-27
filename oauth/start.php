<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

boot_session();
cleanup_oauth_session();

$cfg = cfg();
$lw  = $cfg['lw'];
$sec = $cfg['sec'];

$state = b64url(random_bytes(32));
$_SESSION['lw_oauth_states'][$state] = time() + (int)$sec['state_ttl'];

$scope = 'openid profile user.read calendar task';

$params = http_build_query([
  'response_type' => 'code',
  'client_id'     => $lw['client_id'],
  'redirect_uri'  => $lw['redirect_uri'],
  'scope'         => $scope,
  'state'         => $state,
]);

logf('START', [
  'uri' => $_SERVER['REQUEST_URI'] ?? '',
  'sid' => session_id(),
  'cookie' => $_COOKIE[session_name()] ?? '<no-cookie>',
  'save_path' => session_save_path(),
  'state' => $state,
  'states_count' => count($_SESSION['lw_oauth_states']),
]);

header('Referrer-Policy: no-referrer');
header('Location: '.$lw['authorize_url'].'?'.$params, true, 302);
exit;

