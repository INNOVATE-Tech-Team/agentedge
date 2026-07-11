<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try {
    // Trailing-12mo production, sourced from Advantage's agent_production_stats —
    // real MLS-derived numbers recomputed nightly, vs. the old Perfex field this
    // replaced (an all-time running total with no date scoping, joined by
    // fuzzy agent-name match). Same CRM roster endpoint AgentEdge already uses
    // elsewhere (mc_action.php, roster_agent.php, company_email_action.php).
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    $url   = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx   = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $raw   = @file_get_contents($url, false, $ctx);
    $roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

    // Lowercase full-name → {volume, deals} map — same shape/key the front-end
    // (lookupProd in backoffice_roster.php) already expects, just a new source.
    $agentMap    = [];
    $totalVolume = 0.0;
    $totalDeals  = 0;
    $crmAgentCount = 0;

    foreach ($roster as $a) {
        $volume = (float)($a['volume12mo'] ?? 0);
        $deals  = (int)($a['deals12mo']    ?? 0);
        if ($volume <= 0 && $deals <= 0) continue;

        $totalVolume += $volume;
        $totalDeals  += $deals;
        $crmAgentCount++;

        $name = strtolower(trim($a['fullName'] ?? ''));
        if ($name === '') continue;
        $agentMap[$name] = ['volume' => $volume, 'deals' => $deals];
    }

    echo json_encode([
        'ok'           => true,
        'total_volume' => $totalVolume,
        'total_deals'  => $totalDeals,
        'crm_agents'   => $crmAgentCount,
        'agents'       => $agentMap,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
