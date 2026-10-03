<?php
// AgentEdge — Write-back endpoint called by Advantage after it creates a new
// canonical_agents row for an unmatched roster agent. Stamps the Advantage UUID
// into innovate_roster.canonical_agent_id so future roster syncs use the stable
// UUID instead of an email match (which breaks whenever emails drift).
//
// POST /api/roster_link.php
// Body: { token, email, canonical_agent_id }
// Response: { ok, updated }   updated=0 means the row already had an ID (idempotent)

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$c     = cfg();
$token = $c['crm_token'] ?? '';
$given = trim($body['token'] ?? $_SERVER['HTTP_X_AGENTEDGE_TOKEN'] ?? '');
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$email = strtolower(trim($body['email'] ?? ''));
$uuid  = trim($body['canonical_agent_id'] ?? '');

if ($email === '' || $uuid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email and canonical_agent_id are required']);
    exit;
}

// Only stamp rows that don't have an ID yet — never overwrite an existing link.
$stmt = local_db()->prepare(
    "UPDATE innovate_roster SET canonical_agent_id = ?
     WHERE LOWER(TRIM(email)) = ?
       AND (canonical_agent_id IS NULL OR canonical_agent_id = '')"
);
$stmt->execute([$uuid, $email]);

echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
