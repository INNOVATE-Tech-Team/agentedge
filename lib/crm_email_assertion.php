<?php
// Signs the current agent's email so the CRM (coastline-server) backend can
// verify a request is genuinely acting as that agent, instead of trusting a
// bare `email` parameter under the shared crm_token.
//
// Security fix 2026-08-18: every /public/agentedge/* + /public/agent/{id}
// call previously sent `email` as a plain string alongside the shared
// crm_token -- anyone holding that token (passed in URLs, so it can land in
// access logs) could act as *any* agent just by changing the email value.
// This mirrors the HMAC pattern sso_marketing.php already uses for its own
// marketing-site handoff: base64url(json{email,exp}) + '.' + hex_hmac_sha256.
// The CRM verifies that signature before trusting the email at all now.

function crm_signed_email(string $email): string {
    $config = cfg();
    $secret = getenv('AGENTEDGE_HMAC_SECRET') ?: ($config['crm_hmac_secret'] ?? '');
    if (!$secret) {
        throw new \RuntimeException('crm_hmac_secret / AGENTEDGE_HMAC_SECRET is not configured');
    }
    $payload_data = ['email' => $email, 'exp' => time() + 300];
    $payload = rtrim(strtr(base64_encode(json_encode($payload_data)), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $payload, $secret);
    return $payload . '.' . $sig;
}
