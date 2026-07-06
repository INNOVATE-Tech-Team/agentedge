<?php
// Retention-status export — called by coastline-server so the Retention page
// on advantage.innovateonline.com can overlay AgentEdge's staff-tracked
// retention_status/notes onto its own team roster. No login; token-gated the
// same way api/permissions.php and api/onboard_push.php are.
//
// GET /api/roster_export.php?token=...
// Response: { agents: [{ canonical_agent_id, agent_name, state_code, market_center,
//              license_exp, retention_status, retention_notes, last_contact_at, added_at }] }
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
    "SELECT canonical_agent_id, agent_name, state_code, market_center, license_exp,
            retention_status, retention_notes, last_contact_at, added_at
     FROM innovate_roster WHERE active = 1"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['agents' => $rows]);
