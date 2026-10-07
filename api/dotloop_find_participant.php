<?php
// Admin tool used by backoffice_sync_health.php: search dotloop_loop_participants
// by name to help find the email DotLoop actually has an agent under, when it
// doesn't match their tblstaff/login email. The result is meant to be saved as
// agent_extra.dotloop_alt_email (via api/agent_extra.php) once confirmed.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent || !is_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$name = trim($_GET['name'] ?? '');
if ($name === '') { echo json_encode(['matches' => []]); exit; }

$parts = array_values(array_filter(preg_split('/\s+/', $name)));
if (!$parts) { echo json_encode(['matches' => []]); exit; }

$where  = [];
$params = [];
foreach ($parts as $p) {
    $where[]  = "LOWER(name) LIKE ?";
    $params[] = '%' . strtolower($p) . '%';
}

// Restricted to agent-ish roles (LISTING_AGENT / BUYER_AGENT / BUYING_AGENT /
// etc) since that's the participant row that stands in for this person as an
// agent, rather than any buyer/seller who happens to share their name.
$stmt = local_db()->prepare(
    "SELECT email, name, role, COUNT(DISTINCT loop_id) AS loop_count
     FROM dotloop_loop_participants
     WHERE role LIKE '%agent%' AND (" . implode(' AND ', $where) . ")
     GROUP BY email, name, role
     ORDER BY loop_count DESC"
);
$stmt->execute($params);
echo json_encode(['matches' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
