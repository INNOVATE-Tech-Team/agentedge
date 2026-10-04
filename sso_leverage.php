<?php
// SSO bounce to Leverage (leverage.innovateonline.com) — same pattern as
// sso_marketing.php, with a richer payload: Leverage needs the agent's
// full org-hierarchy facts (mc_slugs/own_mc_slug/team) to drive its own
// role/market-center/team model, not just role+marketCenter+phone.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';

require_login();

$agent = current_agent();
$perms = current_perms();
$config = cfg();

// Phone from CRM whoami, same lookup sso_marketing.php uses.
$crmBase  = rtrim($config['crm_base'] ?? 'https://bold360.vip/api', '/');
$crmToken = $config['crm_token'] ?? '';
$crmData  = [];
if ($crmToken) {
    $url = $crmBase . '/public/whoami?token=' . urlencode($crmToken) . '&email=' . urlencode($agent['email']);
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) $crmData = json_decode($raw, true) ?: [];
}

$secret = getenv('LEVERAGE_SSO_SECRET') ?: ($config['leverage_sso_secret'] ?? '');
if (!$secret) {
    http_response_code(500);
    echo 'Leverage SSO not configured. Add LEVERAGE_SSO_SECRET to environment or config.php.';
    exit;
}

$payload_data = [
    'id'          => (int)$agent['id'],
    'email'       => $agent['email'],
    'name'        => $agent['name'],
    'photo'       => $agent['photo'] ?? null,
    'phone'       => $crmData['phone'] ?? '',
    'role'        => $perms['role'] ?? 'agent',
    'roles'       => $perms['roles'] ?? [$perms['role'] ?? 'agent'],
    'mcSlugs'     => $perms['mc_slugs'] ?? [],
    'ownMcSlug'   => $perms['own_mc_slug'] ?? '',
    'ownMcSlugs'  => $perms['own_mc_slugs'] ?? [],
    'bicEmail'    => $perms['bic_email'] ?? '',
    'teamId'      => $perms['team_id'] ?? null,
    'ownTeamId'   => $perms['own_team_id'] ?? null,
    'exp'         => time() + 300,
];

$payload = rtrim(strtr(base64_encode(json_encode($payload_data)), '+/', '-_'), '=');
$sig     = hash_hmac('sha256', $payload, $secret);
$token   = $payload . '.' . $sig;

header('Location: https://leverage.innovateonline.com/api/auth/sso?token=' . urlencode($token));
exit;
