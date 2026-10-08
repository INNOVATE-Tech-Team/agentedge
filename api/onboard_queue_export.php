<?php
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
