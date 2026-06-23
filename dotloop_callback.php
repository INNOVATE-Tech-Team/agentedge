<?php
// DotLoop OAuth 2.0 — callback / token exchange.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
$email = $agent['email'];

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Validate CSRF state ───────────────────────────────────────────────────────
$returnedState = $_GET['state'] ?? '';
$storedState   = $_SESSION['dotloop_oauth_state'] ?? '';
unset($_SESSION['dotloop_oauth_state']);

if ($returnedState === '' || $returnedState !== $storedState) {
    header('Location: dotloop_connect.php?error=state_mismatch');
    exit;
}

// ── Check for OAuth errors from DotLoop ──────────────────────────────────────
if (!empty($_GET['error'])) {
    $detail = urlencode($_GET['error_description'] ?? $_GET['error']);
    header("Location: dotloop_connect.php?error=oauth_failed&detail={$detail}");
    exit;
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    header('Location: dotloop_connect.php?error=oauth_failed&detail=no_code');
    exit;
}

// ── Exchange authorization code for tokens ────────────────────────────────────
$c            = cfg();
$clientId     = $c['dotloop_client_id']     ?? '';
$clientSecret = $c['dotloop_client_secret'] ?? '';
$redirectUri  = $c['dotloop_redirect_uri'] ?? 'https://agentedge.innovateonline.com/dotloop_callback.php';

$basicAuth = base64_encode($clientId . ':' . $clientSecret);
$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'timeout'       => 20,
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nAuthorization: Basic {$basicAuth}\r\n",
    'content'       => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $redirectUri,
    ]),
    'ignore_errors' => true,
]]);

$raw = @file_get_contents(DOTLOOP_TOKEN_URL, false, $ctx);
if ($raw === false) {
    header('Location: dotloop_connect.php?error=oauth_failed&detail=token_request_failed');
    exit;
}

$tokenData = json_decode($raw, true);
if (empty($tokenData['access_token'])) {
    $detail = urlencode($tokenData['error_description'] ?? $tokenData['error'] ?? substr($raw, 0, 200));
    header("Location: dotloop_connect.php?error=oauth_failed&detail={$detail}");
    exit;
}

// ── Fetch DotLoop profile to get profile_id ───────────────────────────────────
// Save tokens temporarily (without profile_id) so dotloop_api() can use them
$tokenData['profile_id'] = null;
dotloop_save_tokens($email, $tokenData);

// Try /profile/me first; fall back to /profile (returns array, take first)
$profileResult = dotloop_api($email, 'GET', '/profile/me');
if (!$profileResult['ok']) {
    $profileResult = dotloop_api($email, 'GET', '/profile');
}

if (!$profileResult['ok']) {
    local_db()->prepare("DELETE FROM dotloop_tokens WHERE agent_email = ?")->execute([$email]);
    $detail = urlencode($profileResult['error'] ?? 'profile_api_failed');
    header("Location: dotloop_connect.php?error=profile_failed&detail={$detail}");
    exit;
}

// /profile returns an array; /profile/me returns a single object
$profileData = $profileResult['data'];
if (isset($profileData['data']) && is_array($profileData['data'])) {
    $profileData = $profileData['data'][0] ?? [];
}

$profileId = (string)($profileData['id'] ?? '');
if ($profileId === '') {
    local_db()->prepare("DELETE FROM dotloop_tokens WHERE agent_email = ?")->execute([$email]);
    $rawDetail = urlencode(substr(json_encode($profileResult['data']), 0, 200));
    header("Location: dotloop_connect.php?error=profile_failed&detail=no_id_{$rawDetail}");
    exit;
}

// ── Save final tokens with profile_id ────────────────────────────────────────
$tokenData['profile_id'] = $profileId;
dotloop_save_tokens($email, $tokenData);

header('Location: dotloop.php?connected=1');
exit;
