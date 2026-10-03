<?php
// GET /api/darwin_production_export.php?token=TOKEN
// Returns YTD Darwin production stats for all active Advantage-linked roster agents.
// Called nightly by Advantage's _sync_agentedge_darwin_production scheduler job.
//
// Response: { ok: true, agents: [{ canonical_agent_id, email, ytd_gci, ytd_volume,
//             ytd_list_volume, ytd_sell_volume, ytd_sides }] }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$c     = cfg();
$token = $c['crm_token'] ?? '';
$given = trim($_GET['token'] ?? $_SERVER['HTTP_X_AGENTEDGE_TOKEN'] ?? '');
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// GROUP BY handles the darwin duplicate-person bug: some agents have 2 active
// darwin_cap_progress rows (old + new person_id after Darwin replaces a record).
// SUM(cap_earned) also correctly accumulates alt-email team partners.
// MAX(ytd_sales_volume) picks the higher (more complete) volume row.
$rows = local_db()->query("
    SELECT
        ir.canonical_agent_id,
        ir.email,
        COALESCE(SUM(CAST(dcp.cap_earned           AS REAL)), 0.0) AS ytd_gci,
        COALESCE(MAX(CAST(dsv.ytd_sales_volume      AS REAL)), 0.0) AS ytd_volume,
        COALESCE(MAX(CAST(dsv.ytd_list_volume       AS REAL)), 0.0) AS ytd_list_volume,
        COALESCE(MAX(CAST(dsv.ytd_sell_volume       AS REAL)), 0.0) AS ytd_sell_volume,
        COALESCE(MAX(CAST(dsv.ytd_transaction_count AS INTEGER)), 0) AS ytd_sides
    FROM innovate_roster ir
    LEFT JOIN darwin_cap_progress dcp
           ON LOWER(TRIM(dcp.agent_email)) = LOWER(TRIM(ir.email))
    LEFT JOIN darwin_sales_volume dsv
           ON dsv.agent_person_id = dcp.agent_person_id
    WHERE ir.active = 1
      AND ir.canonical_agent_id IS NOT NULL
      AND ir.canonical_agent_id != ''
    GROUP BY ir.canonical_agent_id, ir.email
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['ytd_gci']         = (float) $r['ytd_gci'];
    $r['ytd_volume']      = (float) $r['ytd_volume'];
    $r['ytd_list_volume'] = (float) $r['ytd_list_volume'];
    $r['ytd_sell_volume'] = (float) $r['ytd_sell_volume'];
    $r['ytd_sides']       = (int)   $r['ytd_sides'];
}
unset($r);

echo json_encode(['ok' => true, 'agents' => $rows]);
