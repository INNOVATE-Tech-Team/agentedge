<?php
// Active-onboarding export — called by coastline-server's agent-sites sync
// (_sync_agentedge_agent_sites) so real people mid-hire (added to the
// onboarding queue, but who haven't finished the intake form yet) show up
// on the public site instead of being invisible until intake completes.
// Token-gated the same way api/roster_export.php / api/permissions.php are.
//
// GET /api/onboard_queue_export.php?token=...
// Response: { agents: [{ agent_name, email, market_center, state_code,
//              sponsor, start_date, role, added_at }] }
// Deliberately the same {agent_name, email, ...} shape roster_export.php
// uses, so coastline-server can union both without special-casing fields.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

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
    "SELECT agent_name, agent_email AS email, market_center, state_code,
            sponsor, start_date, role, added_at
     FROM onboard_queue WHERE status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['agents' => $rows]);
