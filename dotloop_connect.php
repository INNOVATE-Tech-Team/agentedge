<?php
// Starts the dotloop OAuth flow. Agents click "Connect dotloop" → land here →
// get redirected to dotloop's authorize page → come back via dotloop_callback.php.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

// CSRF guard — store a random state in the session; callback verifies it.
if (session_status() === PHP_SESSION_NONE) session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['dotloop_oauth_state'] = $state;

$c = cfg();
$url = 'https://auth.dotloop.com/oauth2/auth?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $c['dotloop_client_id'],
    'redirect_uri'  => $c['dotloop_redirect_uri'],
    'scope'         => 'openid',
    'state'         => $state,
]);
header('Location: ' . $url);
exit;
