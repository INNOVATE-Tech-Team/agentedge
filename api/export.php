<?php
/**
 * Internal export API — dumps market centers, agent roles, and the active
 * roster so the CRM's /admin/sync-agentedge endpoint can push authoritative
 * role/MC data back into the Postgres database.
 *
 * Auth: ?secret=<crm_token> — same shared secret AgentEdge uses for CRM calls.
 * This endpoint should never be linked publicly; it contains PII (emails).
 */
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$c      = cfg();
$secret = $c['crm_token'] ?? '';
if ($secret === '' || ($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$db = local_db();

// All market centers (including disabled — CRM decides whether to create them)
$market_centers = $db->query(
    "SELECT slug, name, state_code, enabled, bic_email, mc_leader_email
     FROM market_centers
     ORDER BY state_code, sort_ord, name"
)->fetchAll(PDO::FETCH_ASSOC);

// All role assignments
$agent_roles_raw = $db->query(
    "SELECT email, role, mc_slugs, own_mc_slug, bic_email
     FROM agent_roles"
)->fetchAll(PDO::FETCH_ASSOC);

$agent_roles = [];
foreach ($agent_roles_raw as $r) {
    $r['mc_slugs'] = json_decode($r['mc_slugs'] ?: '[]', true) ?: [];
    $agent_roles[] = $r;
}

// Active roster entries (agent name → MC assignment, used as email-less fallback)
$roster = $db->query(
    "SELECT agent_name, state_code, market_center
     FROM innovate_roster
     WHERE active = 1 AND market_center != ''
     ORDER BY state_code, agent_name"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'market_centers' => $market_centers,
    'agent_roles'    => $agent_roles,
    'roster'         => $roster,
]);
