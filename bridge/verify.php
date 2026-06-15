<?php
// ---------------------------------------------------------------------------
// AgentEdge  <-  Perfex bridge   (login + dashboard data)
// ---------------------------------------------------------------------------
// Deploy ON innovateonline.com (cPanel), in the agentedge.innovateonline.com
// subdomain folder, reachable at https://agentedge.innovateonline.com/verify.php
//
// AgentEdge POSTs JSON {token, ...}:
//   • {email, password}            -> verifies the login, returns the agent
//   • {action:"dashboard", staffid}-> returns that agent's Perfex RE numbers
// Read-only DB user; never returns the password hash.
//
// >>> FILL IN the DB password below on the server, then save. <<<
// ---------------------------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);   // PHP 8.1+: return errors instead of throwing
header('Content-Type: application/json');

$BRIDGE_TOKEN = 'PUT-SHARED-SECRET-HERE';     // must match auth_bridge_token in AgentEdge config.php
$DB_HOST = 'localhost';
$DB_NAME = 'innovate_agents';
$DB_USER = 'innovate_agentedge_ro';
$DB_PASS = 'PUT-READ-ONLY-DB-PASSWORD-HERE';

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!hash_equals($BRIDGE_TOKEN, (string)($in['token'] ?? ''))) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

$db = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db','detail'=>$db->connect_error]); exit; }
$db->set_charset('utf8mb4');

$action = $in['action'] ?? 'login';

// ---- Dashboard numbers (by staffid) ---------------------------------------
if ($action === 'dashboard') {
    $staffid = (string)($in['staffid'] ?? '');
    if ($staffid === '') { echo json_encode(['ok'=>false]); exit; }
    $out = ['ok'=>true,'hasData'=>false,
            'tiles'=>['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],
            'cap'=>null,'network'=>[]];
    $st = $db->prepare("SELECT id, agent_id, agent_total_sales_volume, agent_total_closed_deals, agent_residual_income_earned FROM tblre_transaction_agents WHERE staffid = ? LIMIT 1");
    if ($st) {
        $st->bind_param('s', $staffid); $st->execute();
        $me = $st->get_result()->fetch_assoc(); $st->close();
        if ($me) {
            $out['hasData'] = true;
            $out['tiles']['volume']      = (float)$me['agent_total_sales_volume'];
            $out['tiles']['closedDeals'] = (int)$me['agent_total_closed_deals'];
            $out['tiles']['residual']    = (float)$me['agent_residual_income_earned'];
            $myKey = ($me['agent_id'] !== null && $me['agent_id'] !== '') ? (string)$me['agent_id'] : (string)$me['id'];
            $dl = $db->prepare("SELECT t.agent_total_sales_volume, t.agent_total_closed_deals, t.agent_residual_income_earned, s.firstname, s.lastname FROM tblre_transaction_agents t LEFT JOIN tblstaff s ON s.staffid = t.staffid WHERE t.recruit_source_agent_id = ? ORDER BY t.agent_total_sales_volume DESC");
            if ($dl) {
                $dl->bind_param('s', $myKey); $dl->execute(); $res = $dl->get_result();
                while ($row = $res->fetch_assoc()) {
                    $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'Agent';
                    $out['network'][] = ['name'=>$name,'volume'=>(float)$row['agent_total_sales_volume'],'deals'=>(int)$row['agent_total_closed_deals'],'residual'=>(float)$row['agent_residual_income_earned']];
                }
                $dl->close();
            }
            $out['tiles']['recruits'] = count($out['network']);
        }
    }
    echo json_encode($out); exit;
}

// ---- Login (email + password) ---------------------------------------------
$email    = trim((string)($in['email'] ?? ''));
$password = (string)($in['password'] ?? '');
if ($email === '' || $password === '') { echo json_encode(['ok'=>false]); exit; }
$stmt = $db->prepare("SELECT staffid, email, firstname, lastname, password, active FROM tblstaff WHERE email = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'query','detail'=>$db->error]); exit; }
$stmt->bind_param('s', $email); $stmt->execute();
$u = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$u || (int)$u['active'] !== 1 || !password_verify($password, (string)$u['password'])) { echo json_encode(['ok'=>false]); exit; }
echo json_encode(['ok'=>true,'staffid'=>(int)$u['staffid'],'email'=>$u['email'],'name'=>trim(($u['firstname']??'').' '.($u['lastname']??''))]);
