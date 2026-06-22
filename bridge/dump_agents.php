<?php
/**
 * dump_agents.php — returns all active tblstaff rows as JSON so the
 * Lightsail import script can upsert them into innovate.users.
 *
 * Deploy: copy to agentedge.innovateonline.com docroot alongside verify.php
 * Protected: same auth_bridge_token as verify.php (in config.php)
 *
 * POST body (JSON):
 *   { "token": "<auth_bridge_token>", "action": "dump" }
 *
 * Response:
 *   { "ok": true, "agents": [ { staffid, firstname, lastname, email, phone,
 *                                phonenumber } ] }
 */

mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$req = json_decode($raw, true) ?: [];

// ── auth ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config.php';   // defines $config
$BRIDGE_TOKEN = $config['auth_bridge_token'] ?? '';

if (empty($req['token']) || $req['token'] !== $BRIDGE_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (($req['action'] ?? '') !== 'dump') {
    echo json_encode(['ok' => false, 'error' => 'action must be dump']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────────────
$DB_HOST = $config['db_host'] ?? 'localhost';
$DB_USER = $config['db_user'] ?? '';
$DB_PASS = $config['db_pass'] ?? '';
$DB_NAME = $config['db_name'] ?? 'innovate_agents';

$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db', 'detail' => $db->connect_error]);
    exit;
}

$result = $db->query(
    "SELECT staffid, firstname, lastname, email, phonenumber
       FROM tblstaff
      WHERE active = 1
      ORDER BY staffid"
);

if (!$result) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'query', 'detail' => $db->error]);
    exit;
}

$agents = [];
while ($row = $result->fetch_assoc()) {
    $agents[] = [
        'staffid'   => (int)$row['staffid'],
        'firstname' => $row['firstname'] ?? '',
        'lastname'  => $row['lastname']  ?? '',
        'email'     => strtolower(trim($row['email'] ?? '')),
        'phone'     => $row['phonenumber'] ?? '',
    ];
}
$db->close();

echo json_encode(['ok' => true, 'count' => count($agents), 'agents' => $agents]);
