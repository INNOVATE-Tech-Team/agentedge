<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try {
    // Company-wide aggregate totals (all time — tblre_transaction_agents is a running-total table)
    $totals = db_one(
        "SELECT COALESCE(SUM(agent_total_sales_volume),0) as total_volume,
                COALESCE(SUM(agent_total_closed_deals),0)  as total_deals,
                COUNT(*) as crm_agent_count
         FROM tblre_transaction_agents
         WHERE (agent_total_sales_volume > 0 OR agent_total_closed_deals > 0)",
        []
    );

    // Per-agent name → {volume, deals} map for roster enrichment.
    // Join tblstaff to get displayable names; use staffid for reliable join.
    $agentRows = db_query(
        "SELECT TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) as full_name,
                t.agent_total_sales_volume as volume,
                t.agent_total_closed_deals  as deals
         FROM tblre_transaction_agents t
         LEFT JOIN tblstaff s ON s.staffid = t.staffid
         WHERE t.agent_total_sales_volume > 0 OR t.agent_total_closed_deals > 0",
        []
    );

    // Build a lowercase-name keyed map for front-end fuzzy matching.
    $agentMap = [];
    foreach ($agentRows as $r) {
        $name = trim($r['full_name'] ?? '');
        if ($name === '' || $name === ' ') continue;
        $key = strtolower($name);
        $agentMap[$key] = [
            'volume' => (float)$r['volume'],
            'deals'  => (int)$r['deals'],
        ];
    }

    echo json_encode([
        'ok'           => true,
        'total_volume' => (float)($totals['total_volume'] ?? 0),
        'total_deals'  => (int)($totals['total_deals']  ?? 0),
        'crm_agents'   => (int)($totals['crm_agent_count'] ?? 0),
        'agents'       => $agentMap,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
