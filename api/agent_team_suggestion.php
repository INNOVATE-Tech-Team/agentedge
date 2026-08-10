<?php
// Admin-only: Darwin-derived team role suggestion for one agent, plus their
// current AgentEdge team state (already a leader / already a member of
// something). Used by backoffice_agents.php's edit modal — see
// lib/darwin.php's darwin_team_role_suggestion() for the classification
// rules and why grouping members under a leader can't be automated.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/darwin.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$email = strtolower(trim($_GET['email'] ?? ''));
if ($email === '') { echo json_encode(['ok' => false, 'error' => 'email required']); exit; }

$db = local_db();

$leaderRow = null;
try {
    $stmt = $db->prepare("SELECT id, name FROM teams WHERE leader_email = ? AND enabled = 1");
    $stmt->execute([$email]);
    $leaderRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {}

$memberRow = null;
try {
    $stmt = $db->prepare(
        "SELECT t.id, t.name FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.agent_email = ?"
    );
    $stmt->execute([$email]);
    $memberRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {}

echo json_encode([
    'ok'         => true,
    'suggestion' => darwin_team_role_suggestion($email),
    'isLeaderOf' => $leaderRow ? ['id' => (int)$leaderRow['id'], 'name' => $leaderRow['name']] : null,
    'isMemberOf' => $memberRow ? ['id' => (int)$memberRow['id'], 'name' => $memberRow['name']] : null,
]);
