<?php
// Google OAuth callback for AgentEdge.
// google_redirect_uri is blank in config.php, so the redirect URI is computed
// per-host below (matching login.php) — each domain AgentEdge serves from
// (agentedge.innovateonline.com, agents.innovateonline.com, etc.) must be
// added as its own Authorized redirect URI in Google Cloud Console.
// login.php builds the "Sign in with Google" link and stores the CSRF token
// in $_SESSION['google_oauth_state']; Google redirects back here with a code.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (current_agent()) { header('Location: index.php'); exit; }

$c             = cfg();
$client_id     = $c['google_client_id']     ?? '';
$client_secret = $c['google_client_secret'] ?? '';
$redirect_uri  = !empty($c['google_redirect_uri']) ? $c['google_redirect_uri'] : ('https://' . $_SERVER['HTTP_HOST'] . '/auth_google.php');

function google_fail(string $code): void {
    header('Location: login.php?google_err=' . $code);
    exit;
}

if ($client_id === '') {
    google_fail('token_exchange_failed');
}

if (!empty($_GET['error'])) {
    google_fail('access_denied');
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$saved = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if ($code === '' || $saved === '' || !hash_equals($saved, $state)) {
    google_fail('state_mismatch');
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
if (!$accessToken) { google_fail('token_exchange_failed'); }

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

if ($email === '') { google_fail('token_exchange_failed'); }

// Match against tblstaff (Perfex) — the same source of truth password logins use.
$u = db_one(
    "SELECT staffid, email, firstname, lastname, profile_image, active
     FROM tblstaff WHERE email = ? LIMIT 1",
    [$email]
);

if ($u) {
    if (!empty($c['require_active']) && (int)$u['active'] !== 1) {
        google_fail('account_disabled');
    }
    $agent = [
        'id'    => (int)$u['staffid'],
        'email' => $u['email'],
        'name'  => trim($u['firstname'] . ' ' . $u['lastname']) ?: $name,
        'photo' => $u['profile_image'] ?: $photo,
    ];
} else {
    // Not staff in Perfex. Accept if either:
    // (1) this email already has a role assigned locally — covers admins/
    //     super_admins who aren't Perfex staff, or
    // (2) it's in the CRM roster — covers agents not yet synced to tblstaff.
    // We never silently create access for an email that matches neither.
    require_once __DIR__ . '/local_db.php';
    $hasRole = false;
    if (function_exists('local_db')) {
        try {
            $rs = local_db()->prepare("SELECT 1 FROM agent_roles WHERE email = ? LIMIT 1");
            $rs->execute([$email]);
            $hasRole = (bool)$rs->fetchColumn();
        } catch (\Throwable $e) {}
    }

    if (!$hasRole) {
        $whoami = null;
        $base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
        $tok    = $c['crm_token'] ?? '';
        if ($tok !== '') {
            $wUrl = $base . '/public/whoami?token=' . urlencode($tok) . '&email=' . urlencode($email);
            $wRaw = @file_get_contents($wUrl, false, stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]));
            $whoami = $wRaw ? json_decode($wRaw, true) : null;
        }
        if (!$whoami || empty($whoami['email'])) {
            google_fail('user_create_failed');
        }
        if (!empty($whoami['name'])) $name = $whoami['name'];

        // First Google sign-in for a roster agent not in tblstaff — provision a
        // default role so role-gated pages and /api/permissions.php have a row to read.
        try {
            local_db()->prepare("INSERT OR IGNORE INTO agent_roles (email, role) VALUES (?, 'agent')")->execute([$email]);
        } catch (\Throwable $e) {
            google_fail('user_create_failed');
        }
    }

    $agent = ['id' => 0, 'email' => $email, 'name' => $name, 'photo' => $photo];
}

session_regenerate_id(true);
$_SESSION['agent'] = $agent;
unset($_SESSION['perms']); // force fresh permissions lookup
log_login_event($agent['email'], $agent['name'], 'google');

header('Location: index.php');
exit;
