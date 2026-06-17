<?php
// Google OAuth handler for AgentEdge.
// ?start=1  → redirect to Google
// ?code=...  → callback from Google
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$c             = cfg();
$client_id     = $c['google_client_id']     ?? '';
$client_secret = $c['google_client_secret'] ?? '';
$redirect_uri  = $c['google_redirect_uri']  ?? ('https://' . $_SERVER['HTTP_HOST'] . '/auth_google.php');

if ($client_id === '') {
    header('Location: login.php?err=google_not_configured'); exit;
}

// ── Step 1: redirect to Google ─────────────────────────────────────────────
if (isset($_GET['start'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = http_build_query([
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── Step 2: callback ────────────────────────────────────────────────────────
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$saved = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (!$code || !$saved || !hash_equals($saved, $state)) {
    header('Location: login.php?err=oauth_state'); exit;
}

// Exchange code for access token
$tokenRaw = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content'       => http_build_query([
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code',
    ]),
    'ignore_errors' => true,
    'timeout'       => 12,
]]));
$token       = $tokenRaw ? json_decode($tokenRaw, true) : null;
$accessToken = $token['access_token'] ?? '';
if (!$accessToken) { header('Location: login.php?err=oauth_token'); exit; }

// Get user info from Google
$userRaw = @file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, stream_context_create(['http' => [
    'header'        => "Authorization: Bearer $accessToken\r\n",
    'timeout'       => 12,
    'ignore_errors' => true,
]]));
$user  = $userRaw ? json_decode($userRaw, true) : null;
$email = strtolower(trim($user['email'] ?? ''));
$name  = $user['name']    ?? $email;
$photo = $user['picture'] ?? null;

if ($email === '') { header('Location: login.php?err=oauth_token'); exit; }

// Accept if: (1) email has a role in AgentEdge's own table (covers staff/admins),
// OR (2) email is in the CRM roster (covers agents).
require_once __DIR__ . '/local_db.php';
$inLocalRoles = false;
if (function_exists('local_db')) {
    $rs = local_db()->prepare("SELECT role FROM agent_roles WHERE email=?");
    $rs->execute([$email]);
    $inLocalRoles = (bool)$rs->fetch();
}

if (!$inLocalRoles) {
    $c    = cfg();
    $base = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $tok  = $c['crm_token'] ?? '';
    $wUrl = $base . '/public/whoami?token=' . urlencode($tok) . '&email=' . urlencode($email);
    $wRaw = @file_get_contents($wUrl, false, stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]));
    $whoami = $wRaw ? json_decode($wRaw, true) : null;
    if (!$whoami || empty($whoami['email'])) {
        header('Location: login.php?err=not_in_roster'); exit;
    }
    if (!empty($whoami['name'])) $name = $whoami['name'];
}

// Create session — same format as bridge login
session_regenerate_id(true);
$_SESSION['agent'] = [
    'id'    => 0,
    'email' => $email,
    'name'  => $name,
    'photo' => $photo,
];
unset($_SESSION['perms']); // force fresh permissions lookup

header('Location: index.php');
exit;
