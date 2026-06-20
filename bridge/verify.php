<?php
mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');

$BRIDGE_TOKEN = '69f875969332c128a5523ad1cfa4ed2bc06ea29b75a9f484';
$DB_HOST = 'localhost';
$DB_NAME = 'innovate_agents';
$DB_USER = 'innovate_agentedge_ro';
$DB_PASS = 'Innovate2026!';

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!hash_equals($BRIDGE_TOKEN, (string)($in['token'] ?? ''))) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

$db = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db','detail'=>$db->connect_error]); exit; }
$db->set_charset('utf8mb4');

$action = $in['action'] ?? 'login';

if ($action === 'dashboard' || $action === 'dashboard_by_email') {
    if ($action === 'dashboard_by_email') {
        $email = trim((string)($in['email'] ?? ''));
        if ($email === '') { echo json_encode(['ok'=>false]); exit; }
        $su = $db->prepare("SELECT staffid FROM tblstaff WHERE email = ? LIMIT 1");
        if (!$su) { echo json_encode(['ok'=>false,'error'=>'lookup']); exit; }
        $su->bind_param('s', $email); $su->execute();
        $sr = $su->get_result()->fetch_assoc(); $su->close();
        if (!$sr) {
            echo json_encode(['ok'=>true,'hasData'=>false,'tiles'=>['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],'cap'=>null,'network'=>[]]);
            exit;
        }
        $staffid = (string)$sr['staffid'];
    } else {
        $staffid = (string)($in['staffid'] ?? '');
        if ($staffid === '') { echo json_encode(['ok'=>false]); exit; }
    }

    $out = ['ok'=>true,'hasData'=>false,'tiles'=>['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],'cap'=>null,'network'=>[]];
    $st = $db->prepare("SELECT id, agent_id, agent_total_sales_volume, agent_total_closed_deals, agent_residual_income_earned FROM tblre_transaction_agents WHERE agent_id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('s', $staffid); $st->execute();
        $res = $st->get_result();
        $me  = $res ? $res->fetch_assoc() : null;
        $st->close();
        if ($me) {
            $out['hasData'] = true;
            $out['tiles']['volume']      = (float)$me['agent_total_sales_volume'];
            $out['tiles']['closedDeals'] = (int)$me['agent_total_closed_deals'];
            $out['tiles']['residual']    = (float)$me['agent_residual_income_earned'];
            $myKey = ($me['agent_id'] !== null && $me['agent_id'] !== '') ? (string)$me['agent_id'] : (string)$me['id'];
            $dl = $db->prepare("SELECT t.agent_total_sales_volume, t.agent_total_closed_deals, t.agent_residual_income_earned, s.firstname, s.lastname FROM tblre_transaction_agents t LEFT JOIN tblstaff s ON s.staffid = t.agent_id WHERE t.recruit_source_agent_id = ? ORDER BY t.agent_total_sales_volume DESC");
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

// ---- Network tree (full hierarchy from a root agent) ----------------------
if ($action === 'network_tree') {
    $email = trim((string)($in['email'] ?? ''));
    if ($email === '') { echo json_encode(['ok'=>false,'error'=>'email required']); exit; }

    $su = $db->prepare("SELECT staffid FROM tblstaff WHERE email = ? LIMIT 1");
    if (!$su) { echo json_encode(['ok'=>false,'error'=>'lookup']); exit; }
    $su->bind_param('s', $email); $su->execute();
    $sr = $su->get_result()->fetch_assoc(); $su->close();
    if (!$sr) { echo json_encode(['ok'=>true,'tree'=>null,'totalCount'=>0]); exit; }
    $rootId = (string)$sr['staffid'];

    $res = $db->query(
        "SELECT t.agent_id, t.recruit_source_agent_id,
                t.agent_total_sales_volume, t.agent_total_closed_deals,
                t.agent_residual_income_earned,
                s.firstname, s.lastname, s.email as agent_email
         FROM tblre_transaction_agents t
         LEFT JOIN tblstaff s ON s.staffid = t.agent_id"
    );
    $nodes = []; $children = []; $parents = [];
    while ($row = $res->fetch_assoc()) {
        $id = (string)$row['agent_id'];
        $nodes[$id] = [
            'name'     => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'Agent',
            'email'    => $row['agent_email'] ?? '',
            'volume'   => (float)$row['agent_total_sales_volume'],
            'deals'    => (int)$row['agent_total_closed_deals'],
            'residual' => (float)$row['agent_residual_income_earned'],
        ];
        $parent = (string)($row['recruit_source_agent_id'] ?? '');
        if ($parent !== '' && $parent !== '0') { $children[$parent][] = $id; $parents[$id] = $parent; }
    }

    function buildTree(string $id, array &$nodes, array &$children, int $depth): ?array {
        if ($depth > 6 || !isset($nodes[$id])) return null;
        $node = $nodes[$id];
        $node['children'] = [];
        foreach ($children[$id] ?? [] as $childId) {
            $child = buildTree($childId, $nodes, $children, $depth + 1);
            if ($child !== null) $node['children'][] = $child;
        }
        usort($node['children'], fn($a,$b) => $b['volume'] <=> $a['volume']);
        return $node;
    }
    function countTree(array $node): int {
        $n = 1;
        foreach ($node['children'] as $c) $n += countTree($c);
        return $n;
    }

    $tree = buildTree($rootId, $nodes, $children, 0);
    $total = $tree ? countTree($tree) - 1 : 0;
    $sponsorId = $parents[$rootId] ?? '';
    $sponsor   = ($sponsorId !== '' && $sponsorId !== '0' && isset($nodes[$sponsorId])) ? $nodes[$sponsorId] : null;
    echo json_encode(['ok'=>true,'tree'=>$tree,'totalCount'=>$total,'sponsor'=>$sponsor]);
    exit;
}

// ---- Agent lookup by email -----------------------------------------------
if ($action === 'agent_lookup') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if ($email === '') { echo json_encode(['ok'=>false,'error'=>'email required']); exit; }
    $s = $db->prepare("SELECT staffid, email, firstname, lastname, profile_pic FROM tblstaff WHERE email = ? LIMIT 1");
    if (!$s) { echo json_encode(['ok'=>false,'error'=>'query']); exit; }
    $s->bind_param('s', $email); $s->execute();
    $u = $s->get_result()->fetch_assoc(); $s->close();
    if (!$u) { echo json_encode(['ok'=>false]); exit; }
    $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: $email;
    echo json_encode(['ok'=>true,'staffid'=>(int)$u['staffid'],'email'=>$u['email'],'name'=>$name,'photo'=>$u['profile_pic']??null]);
    exit;
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
