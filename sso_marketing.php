<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';

require_login();

$agent  = current_agent();
$perms  = current_perms();
$config = cfg();

// Fetch phone + marketCenter from CRM whoami
$crmBase  = rtrim($config['crm_base'] ?? 'https://bold360.vip/api', '/');
$crmToken = $config['crm_token'] ?? '';
$crmData  = [];
if ($crmToken) {
    $url = $crmBase . '/public/whoami?token=' . urlencode($crmToken) . '&email=' . urlencode($agent['email']);
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) $crmData = json_decode($raw, true) ?: [];
}

$secret = getenv('MARKETING_SSO_SECRET') ?: ($config['marketing_sso_secret'] ?? '');
if (!$secret) {
    http_response_code(500);
    echo 'Marketing SSO not configured. Add MARKETING_SSO_SECRET to environment or config.php.';
    exit;
}

$payload_data = [
    'id'           => (int)$agent['id'],
    'email'        => $agent['email'],
    'name'         => $agent['name'],
    'photo'        => $agent['photo'] ?? null,
    'role'         => $perms['role'] ?? 'agent',
    'marketCenter' => $crmData['marketCenter'] ?? ($perms['own_mc_slug'] ?? ''),
    'phone'        => $crmData['phone'] ?? '',
    'exp'          => time() + 300,
];

$payload = rtrim(strtr(base64_encode(json_encode($payload_data)), '+/', '-_'), '=');
$sig     = hash_hmac('sha256', $payload, $secret);
$token   = $payload . '.' . $sig;

header('Location: https://marketing.innovateonline.com/auth/sso?token=' . urlencode($token));
exit;
