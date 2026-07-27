<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }

$isAdmin = is_admin();
$teamId  = $isAdmin ? (int)($_GET['team_id'] ?? 0) : my_team_id();
if (!$isAdmin && $teamId === null) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
if (!$teamId) { echo json_encode(['ok'=>false,'error'=>'team_id required']); exit; }

try {
    // Same Darwin YTD source as backoffice_production.php, just filtered by
    // team_members instead of MC-slug intersection — a direct membership
    // lookup, no innovate_roster/slugify_mc join needed since a team is a
    // plain list of emails, not a name-matched MC bucket.
    $rows = local_db()->query(
        "SELECT sv.agent_name, sv.ytd_sales_volume, sv.ytd_transaction_count, cp.agent_email
           FROM darwin_sales_volume sv
           JOIN darwin_cap_progress cp ON cp.agent_person_id = sv.agent_person_id
          WHERE cp.is_active_agent = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $memberStmt = local_db()->prepare("SELECT agent_email FROM team_members WHERE team_id=?");
    $memberStmt->execute([$teamId]);
    $emailSet = array_flip(array_map('strtolower', $memberStmt->fetchAll(PDO::FETCH_COLUMN)));

    // Some agents use a different email with Darwin/AccountTECH than their
    // roster/login email (agent_extra.alt_email, set on the Agent Profile
    // page) — resolve Darwin's email back to that agent's canonical roster
    // email before matching, so a team member still shows up even when
    // Darwin has a different address on file.
    $altToCanonical = [];
    foreach (local_db()->query("SELECT email, alt_email FROM agent_extra WHERE alt_email != ''")->fetchAll(PDO::FETCH_ASSOC) as $ae) {
        $altToCanonical[strtolower(trim($ae['alt_email']))] = strtolower(trim($ae['email']));
    }

    $rows = array_values(array_filter($rows, function($r) use ($emailSet, $altToCanonical) {
        $email = strtolower(trim($r['agent_email'] ?? ''));
        $canonical = $altToCanonical[$email] ?? $email;
        return isset($emailSet[$canonical]);
    }));

    // Keyed by the agent's canonical roster email (resolved above) — this is
    // exactly the email we just filtered team_members against, so it's
    // guaranteed to be the right person, whether or not Darwin's own record
    // uses that same address. A name-keyed map is kept alongside as a
    // fallback for any caller that only has a name to go on — name-only
    // matching is fragile (Darwin's own name field frequently differs from
    // the roster's legal name: nicknames, dropped middle names/initials),
    // so it can silently miss an agent or match a different person entirely.
    $agentMap       = [];
    $agentMapByName = [];
    $totalVolume    = 0.0;
    $totalDeals     = 0;
    foreach ($rows as $a) {
        $volume = (float)($a['ytd_sales_volume'] ?? 0);
        $deals  = (int)($a['ytd_transaction_count'] ?? 0);
        if ($volume <= 0 && $deals <= 0) continue;
        $totalVolume += $volume;
        $totalDeals  += $deals;
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
        'agents'        => $agentMapByName,
        'agentsByEmail' => $agentMap,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
