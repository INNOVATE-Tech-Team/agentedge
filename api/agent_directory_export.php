<?php
// Agent directory export — every current agent with an AgentEdge intake
// profile (not just those with an active innovate_roster row), for
// coastline-server's nightly agent-sites sync (activates
// website.innovateonline.com/<slug> pages and the public /agents
// directory). Same output shape as api/roster_export.php (agent_name,
// email, market_center, state_code) so the sync's matching logic doesn't
// need to change — only the source query does.
//
// Why this exists instead of reusing roster_export.php: that endpoint
// reads innovate_roster WHERE active = 1, a narrower sales-roster table.
// Recruiters, BICs, and newly-onboarded agents often have a full intake
// profile long before (or without ever getting) an active innovate_roster
// row, so roster_export.php alone silently drops them from the public
// site/directory. This mirrors backoffice_agents.php's own "current
// agents" query instead: every agent_intake row not yet terminated.
//
// GET /api/agent_directory_export.php?token=...
// Response: { agents: [{ agent_name, email, market_center, state_code, onboarding_state }] }
// onboarding_state (lib/onboarding_export.php): 'onboarding_complete' once
// Complete Onboarding (Stage 1 and Stage 2) is done; the website makes a new
// agent's page only then (innovate-advantage-server #275, Nov 11, 2026).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/onboarding_export.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$c     = cfg();
$token = $c['crm_token'] ?? '';
$given = trim($_GET['token'] ?? $_SERVER['HTTP_X_AGENTEDGE_TOKEN'] ?? '');

if ($token === '' || $given === '') {
    http_response_code(401);
    echo json_encode(['error' => 'crm_token not configured or missing']);
    exit;
}
if (!hash_equals($token, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

$rows = local_db()->query(
    "SELECT i.full_name AS agent_name, i.email, i.office_location AS market_center,
            i.license_state AS state_code, aa.terminated_date
     FROM agent_intake i
     LEFT JOIN agent_admin aa ON LOWER(aa.email) = i.email"
)->fetchAll(PDO::FETCH_ASSOC);

$agents = [];
foreach ($rows as $r) {
    if (!empty($r['terminated_date'])) continue;
    unset($r['terminated_date']);
    $agents[] = $r;
}
$agents = add_onboarding_states(local_db(), $agents);

echo json_encode(['agents' => $agents]);
