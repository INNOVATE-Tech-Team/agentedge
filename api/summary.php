<?php
// Returns the signed-in agent's dashboard numbers as JSON, from the Perfex RE
// module (tblre_transaction_agents), joined to their tblstaff login via staffid.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

// Real numbers via the Perfex bridge (same endpoint as login). Reaches the
// Perfex RE module over HTTPS by staffid; cap stays null until Darwin.
$c = cfg();
$bridge = $c['auth_bridge_url'] ?? '';
$btoken = $c['auth_bridge_token'] ?? '';
if ($bridge !== '' && $btoken !== '') {
    $opts = ['http' => [
        'method'  => 'POST',
        'timeout' => 15,
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode(['token' => $btoken, 'action' => 'dashboard', 'staffid' => (int)$agent['id']]),
        'ignore_errors' => true,
    ]];
    $raw = @file_get_contents($bridge, false, stream_context_create($opts));
    $d = $raw === false ? null : json_decode($raw, true);
    if (is_array($d) && !empty($d['ok'])) {
        echo json_encode([
            'agent'   => ['id' => $agent['id'], 'name' => $agent['name']],
            'hasData' => !empty($d['hasData']),
            'tiles'   => $d['tiles'] ?? ['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],
            'cap'     => $d['cap'] ?? null,
            'network' => $d['network'] ?? [],
        ]);
        exit;
    }
    // If the bridge call fails, fall through to sample/local below.
}

// Sample dashboard numbers (until Perfex tx / Darwin data is wired in).
if (sample_dashboard()) {
    echo json_encode([
        'agent'   => ['id' => 1, 'name' => $agent['name']],
        'hasData' => true,
        'tiles'   => ['volume' => 12400000, 'closedDeals' => 31, 'residual' => 18500, 'recruits' => 4],
        'cap'     => ['amount' => 15000, 'paid' => 11250],
        'network' => [
            ['name' => 'Jordan Avery',  'volume' => 4200000, 'deals' => 11, 'residual' => 6300],
            ['name' => 'Sam Rivera',    'volume' => 2650000, 'deals' => 7,  'residual' => 3975],
            ['name' => 'Taylor Brooks', 'volume' => 1800000, 'deals' => 5,  'residual' => 2700],
            ['name' => 'Casey Lin',     'volume' => 950000,  'deals' => 3,  'residual' => 1425],
        ],
    ]);
    exit;
}

$staffid = (string)$agent['id'];
$f = fn($v) => $v === null ? 0 : (float)$v;

$out = [
    'agent'   => ['id' => $agent['id'], 'name' => $agent['name']],
    'hasData' => false,
    'tiles'   => ['volume' => 0, 'closedDeals' => 0, 'residual' => 0, 'recruits' => 0],
    'cap'     => null,            // wires in with Darwin
    'network' => [],
];

try {
    $me = db_one(
        "SELECT id, agent_id, agent_total_sales_volume, agent_total_closed_deals,
                agent_residual_income_earned, recruit_source_agent_id
         FROM tblre_transaction_agents WHERE staffid = ? LIMIT 1",
        [$staffid]
    );

    if ($me) {
        $out['hasData'] = true;
        $out['tiles']['volume']      = $f($me['agent_total_sales_volume']);
        $out['tiles']['closedDeals'] = (int)($me['agent_total_closed_deals'] ?? 0);
        $out['tiles']['residual']    = $f($me['agent_residual_income_earned']);

        $myKey = ($me['agent_id'] !== null && $me['agent_id'] !== '') ? (string)$me['agent_id'] : (string)$me['id'];
        $dl = db_query(
            "SELECT t.agent_id, t.agent_total_sales_volume, t.agent_total_closed_deals,
                    t.agent_residual_income_earned, s.firstname, s.lastname
             FROM tblre_transaction_agents t
             LEFT JOIN tblstaff s ON s.staffid = t.staffid
             WHERE t.recruit_source_agent_id = ?
             ORDER BY t.agent_total_sales_volume DESC",
            [$myKey]
        );
        foreach ($dl as $d) {
            $name = trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? '')) ?: 'Agent';
            $out['network'][] = [
                'name'     => $name,
                'volume'   => $f($d['agent_total_sales_volume']),
                'deals'    => (int)($d['agent_total_closed_deals'] ?? 0),
                'residual' => $f($d['agent_residual_income_earned']),
            ];
        }
        $out['tiles']['recruits'] = count($out['network']);
    }
} catch (Throwable $e) {
    $out['error'] = 'query failed: ' . $e->getMessage();
}

echo json_encode($out);
