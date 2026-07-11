<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();

if (session_status() === PHP_SESSION_NONE) session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['dotloop_state']          = $state;
$_SESSION['dotloop_api_test_mode']  = true;
$_SESSION['dotloop_api_test_email'] = 'api_test@innovateonline.com';

$c = cfg();
$clientId    = $c['dotloop_client_id'] ?? '';
$redirectUri = 'https://agentedge.innovateonline.com/dotloop_callback.php';

$url = 'https://auth.dotloop.com/oauth/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'state'         => $state,
    'prompt'        => 'login',
]);

header('Location: ' . $url);
exit;
