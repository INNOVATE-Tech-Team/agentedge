<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms    = current_perms();
$isAdmin  = !empty($perms['isAdmin']);
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try {
    // YTD production, sourced from Darwin's darwin_sales_volume (synced nightly by
    // cron/sync_darwin.php from AccountTECH's customAPI_InnovateSalesVolume) —
    // real finance-system numbers, joined to darwin_cap_progress to scope to
    // active agents only. Previously sourced from Advantage's retention-roster
    // (trailing-12mo volume12mo/deals12mo); Darwin's figures are YTD, not
    // trailing-12mo — a real metric-definition change, not just a source swap.
    $rows = local_db()->query(
        "SELECT sv.agent_name, sv.ytd_sales_volume, sv.ytd_transaction_count, cp.agent_email
           FROM darwin_sales_volume sv
           JOIN darwin_cap_progress cp ON cp.agent_person_id = sv.agent_person_id
          WHERE cp.is_active_agent = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    // mc_leader/bic only get their own Market Center's agents — scope by
    // roster email->MC (same email->mc_slugs pattern used in backoffice_agents.php),
    // not company-wide, since the raw response is otherwise visible via devtools.
    if (!$isAdmin) {
        $myMcSlugs = my_mc_slugs();
        $rosterMcSlugsByEmail = [];
        foreach (local_db()->query("SELECT email, market_center FROM innovate_roster WHERE active=1 AND email != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rosterMcSlugsByEmail[strtolower(trim($r['email']))][] = slugify_mc($r['market_center'] ?: '');
        }
        $rows = array_values(array_filter($rows, function($r) use ($rosterMcSlugsByEmail, $myMcSlugs) {
            $email = strtolower(trim($r['agent_email'] ?? ''));
            $slugs = $rosterMcSlugsByEmail[$email] ?? [];
            return (bool)array_intersect($slugs, $myMcSlugs);
        }));
    }

    // Lowercase full-name → {volume, deals} map — same shape/key the front-end
    // (lookupProd in backoffice_roster.php) already expects, just a new source.
    $agentMap       = [];
    $totalVolume    = 0.0;
    $totalDeals     = 0;
    $darwinAgentCount = 0;

    foreach ($rows as $a) {
        $volume = (float)($a['ytd_sales_volume'] ?? 0);
        $deals  = (int)($a['ytd_transaction_count'] ?? 0);
        if ($volume <= 0 && $deals <= 0) continue;

        $totalVolume += $volume;
        $totalDeals  += $deals;
        $darwinAgentCount++;

        $name = strtolower(trim($a['agent_name'] ?? ''));
        if ($name === '') continue;
        $agentMap[$name] = ['volume' => $volume, 'deals' => $deals];
    }

    echo json_encode([
        'ok'           => true,
        'total_volume' => $totalVolume,
        'total_deals'  => $totalDeals,
        'crm_agents'   => $darwinAgentCount,
        'agents'       => $agentMap,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
