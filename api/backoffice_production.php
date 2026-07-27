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

    // Some agents use a different email with Darwin/AccountTECH than their
    // roster/login email (agent_extra.alt_email, set on the Agent Profile
    // page) — resolve Darwin's email back to that agent's canonical roster
    // email before matching against MC scope or building the response map,
    // so it still finds the right person even when Darwin has a different
    // address on file.
    $altToCanonical = [];
    foreach (local_db()->query("SELECT email, alt_email FROM agent_extra WHERE alt_email != ''")->fetchAll(PDO::FETCH_ASSOC) as $ae) {
        $altToCanonical[strtolower(trim($ae['alt_email']))] = strtolower(trim($ae['email']));
    }

    // mc_leader/bic only get their own Market Center's agents — scope by
    // roster email->MC (same email->mc_slugs pattern used in backoffice_agents.php),
    // not company-wide, since the raw response is otherwise visible via devtools.
    if (!$isAdmin) {
        $myMcSlugs = my_mc_slugs();
        $rosterMcSlugsByEmail = [];
        foreach (local_db()->query("SELECT email, market_center FROM innovate_roster WHERE active=1 AND email != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rosterMcSlugsByEmail[strtolower(trim($r['email']))][] = slugify_mc($r['market_center'] ?: '');
        }
        $rows = array_values(array_filter($rows, function($r) use ($rosterMcSlugsByEmail, $myMcSlugs, $altToCanonical) {
            $email     = strtolower(trim($r['agent_email'] ?? ''));
            $canonical = $altToCanonical[$email] ?? $email;
            $slugs = $rosterMcSlugsByEmail[$canonical] ?? [];
            return (bool)array_intersect($slugs, $myMcSlugs);
        }));
    }

    // Lowercase full-name → {volume, deals} map — same shape/key the front-end
    // (lookupProd in backoffice_roster.php) already expects, just a new source.
    // Keyed by the agent's canonical roster email (resolved above) with a
    // name-keyed map kept alongside as a fallback. Name-only matching is
    // fragile: Darwin's name field frequently differs from the roster's
    // legal name (nicknames, dropped middle names/initials — "Will Kelly" vs
    // "William Kelly", "Paul Mayer" vs "Paul F Mayer"), which can silently
    // miss an agent's production or, worse, match a different person with a
    // similar name.
    $agentMap       = [];
    $agentMapByName = [];
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

        $entry = ['volume' => $volume, 'deals' => $deals];
        $email     = strtolower(trim($a['agent_email'] ?? ''));
        $canonical = $altToCanonical[$email] ?? $email;
        $name      = strtolower(trim($a['agent_name'] ?? ''));
        if ($canonical !== '') $agentMap[$canonical] = $entry;
        if ($name      !== '') $agentMapByName[$name] = $entry;
    }

    echo json_encode([
        'ok'            => true,
        'total_volume'  => $totalVolume,
        'total_deals'   => $totalDeals,
        'crm_agents'    => $darwinAgentCount,
        'agents'        => $agentMapByName,
        'agentsByEmail' => $agentMap,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
