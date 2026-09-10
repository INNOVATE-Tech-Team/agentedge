<?php
// Thin client for Advantage's Phase 2A activity-only performance pilot
// endpoints (/public/agentedge/performance/*) — same token + signed-email-
// assertion pattern as the existing buyback_fub_identity/fub_users calls,
// never a bare email. AgentEdge never recomputes any of this itself.
//
// Used two ways: an agent's own self-service pages sign their own email
// (see api/performance_*.php); the Coach Dashboard signs the TARGET
// agent's email instead (coach_agent_detail.php) — AgentEdge's own
// existing page-level authorization (is_super_admin(), same gate as the
// rest of Coach Dashboard) already decides who may view whom, so this
// layer only ever answers "is this person in the activity pilot, and if
// so what's their activity data" — it does not re-check who's asking.
//
// Advantage returns a 403 for anyone not on a pilot-scoped team (see
// _resolve_agentedge_pilot_user) — callers must treat that as "nothing
// to show", never as an error to surface, per the "unavailable metrics
// are intentionally absent" rule (no zeroed-out UI for a non-pilot agent).

require_once __DIR__ . '/crm_email_assertion.php';

function performance_api_get(string $path, string $forEmail, array $extraParams = []): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';

    try {
        $signedEmail = crm_signed_email($forEmail);
    } catch (\Throwable $e) {
        return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'data' => null, 'inPilot' => false];
    }

    $params = array_merge(['token' => $token, 'email' => $signedEmail], $extraParams);
    $url = $base . '/public/agentedge/performance/' . ltrim($path, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Could not reach the CRM: ' . $err, 'data' => null, 'inPilot' => false];
    }
    $decoded = json_decode($resp, true);
    $ok = $status >= 200 && $status < 300;
    return [
        'ok'      => $ok,
        'status'  => $status,
        // 403 specifically means "not in the pilot" (see
        // _resolve_agentedge_pilot_user) -- distinct from any other
        // failure so callers can render "not available" rather than an
        // error message for the expected, common case.
        'inPilot' => $status !== 403,
        'error'   => $ok ? null : ($decoded['detail'] ?? $decoded['error'] ?? 'request failed'),
        'data'    => $decoded,
    ];
}
