<?php
// OAuth callback — dotloop redirects here after the agent authorizes.
// Exchanges the code for tokens and stores them, then redirects back to the dashboard.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../lib/dotloop.php';
$agent = require_login();

if (session_status() === PHP_SESSION_NONE) session_start();

// Verify CSRF state.
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['dotloop_oauth_state'] ?? '')) {
    unset($_SESSION['dotloop_oauth_state']);
    header('Location: /?dotloop_error=state');
    exit;
}
unset($_SESSION['dotloop_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: /?dotloop_error=nocode');
    exit;
}

$c = cfg();

// Exchange code for tokens.
$basicAuth = base64_encode($c['dotloop_client_id'] . ':' . $c['dotloop_client_secret']);
$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'timeout'       => 15,
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nAuthorization: Basic {$basicAuth}\r\n",
    'content'       => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $c['dotloop_redirect_uri'],
    ]),
    'ignore_errors' => true,
]]);
$resp = @file_get_contents(DOTLOOP_TOKEN_URL, false, $ctx);

$tok = $resp ? json_decode($resp, true) : null;
if (empty($tok['access_token'])) {
    header('Location: /?dotloop_error=token');
    exit;
}

// Fetch the agent's dotloop profile ID so profile-scoped API calls work.
$profileId = null;
$me_ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 10,
    'header'        => "Authorization: Bearer {$tok['access_token']}\r\nAccept: application/json\r\n",
    'ignore_errors' => true,
]]);
$me_resp = @file_get_contents(DOTLOOP_API_BASE . '/me', false, $me_ctx);
if ($me_resp) {
    $me       = json_decode($me_resp, true);
    $profiles = $me['profiles'] ?? ($me['data']['profiles'] ?? []);
    if (!empty($profiles[0]['id'])) {
        $profileId = (string)$profiles[0]['id'];
    }
}

dotloop_save_tokens($agent['email'], [
    'access_token'  => $tok['access_token'],
    'refresh_token' => $tok['refresh_token'] ?? null,
    'expires_in'    => $tok['expires_in']    ?? 3600,
    'profile_id'    => $profileId,
]);

header('Location: /dotloop.php?connected=1');
exit;
