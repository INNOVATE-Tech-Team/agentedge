<?php
// Returns the signed-in agent's dashboard numbers as JSON, from the Perfex RE
// module (tblre_transaction_agents), joined to their tblstaff login via agent_id.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/darwin.php';
header('Content-Type: application/json');
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$staffid = (string)$agent['id'];
$f = fn($v) => $v === null ? 0 : (float)$v;

$out = [
    'agent'   => ['id' => $agent['id'], 'name' => $agent['name']],
    'hasData' => false,
    'tiles'   => ['volume' => 0, 'closedDeals' => 0, 'residual' => 0, 'recruits' => 0],
    'cap'     => null,
    'network' => [],
];

// Cap progress — from Darwin sync (lib/darwin.php / cron/sync_darwin.php), matched
// by email since AgentEdge doesn't otherwise track the agent's Darwin person id.
try {
    $capRow = local_db()->prepare("SELECT cap_amount, cap_earned FROM darwin_cap_progress WHERE lower(agent_email)=lower(?) AND is_active_agent=1 LIMIT 1");
    $capRow->execute([$agent['email'] ?? '']);
    $cap = $capRow->fetch(PDO::FETCH_ASSOC);
    if ($cap) {
        $out['cap'] = ['amount' => (float)$cap['cap_amount'], 'paid' => (float)$cap['cap_earned']];
    }
} catch (\Throwable $e) {}

try {
    $me = db_one(
        "SELECT id, agent_id, agent_total_sales_volume, agent_total_closed_deals,
                agent_residual_income_earned, recruit_source_agent_id
         FROM tblre_transaction_agents WHERE agent_id = ? LIMIT 1",
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
             LEFT JOIN tblstaff s ON s.staffid = t.agent_id
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
        // "In Growth Network" — everyone in the agent's downline within 5 levels,
        // using the same recruit_source-override + terminated-pruning rules as
        // api/network_tree.php so this tile agrees with the Network page.
        $rows2 = db_query(
            "SELECT t.agent_id, t.recruit_source_agent_id, s.email AS agent_email
             FROM tblre_transaction_agents t
             LEFT JOIN tblstaff s ON s.staffid = t.agent_id"
        );
        $childrenMap = []; $emailToId = [];
        foreach ($rows2 as $row) {
            $id = (string)$row['agent_id'];
            if (!empty($row['agent_email'])) $emailToId[strtolower($row['agent_email'])] = $id;
            $parent = (string)($row['recruit_source_agent_id'] ?? '');
            if ($parent !== '' && $parent !== '0') $childrenMap[$parent][] = $id;
        }
        try {
            $overrides = local_db()->query(
                "SELECT email, recruit_source_email FROM agent_admin WHERE recruit_source_email <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($overrides as $o) {
                $childId  = $emailToId[strtolower(trim($o['email']))] ?? null;
                $sourceId = $emailToId[strtolower(trim($o['recruit_source_email']))] ?? null;
                if ($childId === null || $sourceId === null || $childId === $sourceId) continue;
                foreach ($childrenMap as $p => $kids) {
                    $childrenMap[$p] = array_values(array_diff($kids, [$childId]));
                }
                $childrenMap[$sourceId][] = $childId;
            }
        } catch (\Throwable $e) {}
        $terminated = [];
        try {
            $termRows = local_db()->query(
                "SELECT email FROM agent_admin WHERE terminated_date <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($termRows as $r) {
                $tid = $emailToId[strtolower(trim($r['email']))] ?? null;
                if ($tid !== null) $terminated[$tid] = true;
            }
        } catch (\Throwable $e) {}

        $growthNetwork = 0;
        $queue = [[$myKey, 0]];
        $visited = [$myKey => true];
        while ($queue) {
            [$id, $depth] = array_shift($queue);
            if ($depth >= 5) continue;
            foreach ($childrenMap[$id] ?? [] as $childId) {
                if (!empty($visited[$childId])) continue;
                $visited[$childId] = true;
                if (empty($terminated[$childId])) $growthNetwork++;
                $queue[] = [$childId, $depth + 1];
            }
        }
        $out['tiles']['recruits'] = $growthNetwork;
    }
} catch (Throwable $e) {
    $out['error'] = 'query failed: ' . $e->getMessage();
}

// Closed deals + residual income + network — from Darwin sync, when this agent has a
// Darwin person id (looked up via darwin_cap_progress, which covers the full roster
// including $0-cap agents). "Residual income" = sum of this agent's Revenue Share
// override vouchers as a recruiter (Darwin's own term for this is "override" — see
// customAPI_InnovateRevenueShare). Overrides the Perfex-derived values above when this
// agent is found in Darwin; falls back to the Perfex figures otherwise.
try {
    $idRow = local_db()->prepare("SELECT agent_person_id FROM darwin_cap_progress WHERE lower(agent_email)=lower(?) AND is_active_agent=1 LIMIT 1");
    $idRow->execute([$agent['email'] ?? '']);
    $darwinPersonId = $idRow->fetchColumn();

    if ($darwinPersonId) {
        $svStmt = local_db()->prepare("SELECT ytd_sales_volume, ytd_transaction_count FROM darwin_sales_volume WHERE agent_person_id=?");
        $svStmt->execute([$darwinPersonId]);
        $sv = $svStmt->fetch(PDO::FETCH_ASSOC);
        if ($sv) {
            $out['tiles']['volume']      = (float)$sv['ytd_sales_volume'];
            $out['tiles']['closedDeals'] = (int)$sv['ytd_transaction_count'];
        }

        $rsStmt = local_db()->prepare(
            "SELECT rs.agent_name, rs.ytd_amount, sv.ytd_sales_volume, sv.ytd_transaction_count
             FROM darwin_revenue_share rs
             LEFT JOIN darwin_sales_volume sv ON sv.agent_person_id = rs.agent_person_id
             WHERE rs.recruiter_person_id = ?
             ORDER BY sv.ytd_sales_volume DESC"
        );
        $rsStmt->execute([$darwinPersonId]);
        $overrides = $rsStmt->fetchAll(PDO::FETCH_ASSOC);

        $out['tiles']['residual'] = array_sum(array_map(fn($r) => (float)$r['ytd_amount'], $overrides));
        $out['network'] = array_map(fn($r) => [
            'name'   => $r['agent_name'] ?: 'Agent',
            'volume' => (float)($r['ytd_sales_volume'] ?? 0),
            'deals'  => (int)($r['ytd_transaction_count'] ?? 0),
        ], $overrides);
        $out['hasData'] = true;
    }
} catch (\Throwable $e) {}

echo json_encode($out);
