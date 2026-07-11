<?php
// DotLoop OAuth 2.0 — initiate authorization code flow.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$agent = require_login();

$c            = cfg();
$clientId     = $c['dotloop_client_id'] ?? '';
$redirectUri  = $c['dotloop_redirect_uri'] ?? 'https://agentedge.innovateonline.com/dotloop_callback.php';

if ($clientId === '') {
    header('Location: dotloop_connect.php?error=not_configured');
    exit;
}

// Generate CSRF state token and store in session
if (session_status() === PHP_SESSION_NONE) session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['dotloop_oauth_state'] = $state;

$params = http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    
    'state'         => $state,
]);

header('Location: https://auth.dotloop.com/oauth/authorize?' . $params);
exit;
