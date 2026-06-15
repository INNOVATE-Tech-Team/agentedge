<?php
// ---------------------------------------------------------------------------
// AgentEdge  <-  Perfex login bridge
// ---------------------------------------------------------------------------
// Deploy this ON innovateonline.com (cPanel), inside public_html, e.g.:
//     public_html/agentedge-auth/verify.php
// reachable at:
//     https://innovateonline.com/agentedge-auth/verify.php
//
// AgentEdge (on bold360.vip) POSTs JSON {token, email, password}. This script
// verifies the password against tblstaff (Perfex bcrypt) and returns the agent.
// It only ever runs a SELECT (read-only DB user) and never returns the hash.
//
// >>> FILL IN the two secrets below on the server, then save. <<<
// ---------------------------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);   // PHP 8.1+: return errors instead of throwing
header('Content-Type: application/json');

$BRIDGE_TOKEN = 'PUT-SHARED-SECRET-HERE';        // must match auth_bridge_token in AgentEdge config.php
$DB_HOST = 'localhost';
$DB_NAME = 'innovate_agents';                     // the Perfex database
$DB_USER = 'innovate_agentedge_ro';               // the READ-ONLY MySQL user
$DB_PASS = 'PUT-READ-ONLY-DB-PASSWORD-HERE';      // that user's password

// --- no edits needed below --------------------------------------------------
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (!hash_equals($BRIDGE_TOKEN, (string)($in['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$email    = trim((string)($in['email'] ?? ''));
$password = (string)($in['password'] ?? '');
if ($email === '' || $password === '') { echo json_encode(['ok' => false]); exit; }

$db = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db', 'detail' => $db->connect_error]);
    exit;
}
$db->set_charset('utf8mb4');

$stmt = $db->prepare(
    "SELECT staffid, email, firstname, lastname, password, active
     FROM tblstaff WHERE email = ? LIMIT 1"
);
if (!$stmt) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'query', 'detail' => $db->error]); exit; }
$stmt->bind_param('s', $email);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u || (int)$u['active'] !== 1 || !password_verify($password, (string)$u['password'])) {
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'staffid' => (int)$u['staffid'],
    'email'   => $u['email'],
    'name'    => trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')),
]);
