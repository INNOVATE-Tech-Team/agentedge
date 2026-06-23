<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db     = local_db();
$by     = $agent['email'] ?? '';

$validStates = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

if ($action === 'add') {
    $name  = trim($body['agent_name']    ?? '');
    $state = strtoupper(trim($body['state_code']    ?? ''));
    $mc    = trim($body['market_center'] ?? '');
    $exp   = trim($body['license_exp']   ?? '');
    if (!$name || !in_array($state, $validStates)) {
        echo json_encode(['ok'=>false,'error'=>'Name and valid state required']); exit;
    }
    if ($exp && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = '';

    $stmt = $db->prepare(
        "INSERT INTO innovate_roster (agent_name,state_code,market_center,license_exp,active,added_at,added_by)
         VALUES (?,?,?,?,1,datetime('now'),?)"
    );
    $stmt->execute([$name, $state, $mc, $exp, $by]);
    $id = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
       ->execute([$name, $state, $mc, $exp, 'added', $by]);

    echo json_encode(['ok'=>true, 'id'=>$id, 'agent_name'=>$name, 'state_code'=>$state, 'market_center'=>$mc, 'license_exp'=>$exp]);
    exit;
}

if ($action === 'remove') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $s = $db->prepare("SELECT * FROM innovate_roster WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $db->prepare("UPDATE innovate_roster SET active=0, removed_at=datetime('now'), removed_by=? WHERE id=?")
       ->execute([$by, $id]);

    $db->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
       ->execute([$row['agent_name'], $row['state_code'], $row['market_center'], $row['license_exp'], 'removed', $by]);

    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'restore') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $s = $db->prepare("SELECT * FROM innovate_roster WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $db->prepare("UPDATE innovate_roster SET active=1, removed_at='', removed_by='' WHERE id=?")
       ->execute([$id]);

    $db->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
       ->execute([$row['agent_name'], $row['state_code'], $row['market_center'], $row['license_exp'], 'restored', $by]);

    echo json_encode(['ok'=>true]);
    exit;
}

// Return market centers for a given state (used to populate the add-agent form datalist)
if ($action === 'mcs_for_state') {
    $state = strtoupper(trim($body['state_code'] ?? ''));
    if (!in_array($state, $validStates)) { echo json_encode(['ok'=>false]); exit; }
    $mcs = $db->prepare("SELECT DISTINCT market_center FROM innovate_roster WHERE state_code=? AND market_center != '' AND active=1 ORDER BY market_center");
    $mcs->execute([$state]);
    echo json_encode(['ok'=>true, 'mcs' => $mcs->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}

// Rename a market center — updates display name everywhere, slug stays stable.
if ($action === 'rename_mc') {
    if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    $oldName = trim($body['old_name']   ?? '');
    $newName = trim($body['new_name']   ?? '');
    $state   = strtoupper(trim($body['state_code'] ?? ''));
    if (!$oldName || !$newName) { echo json_encode(['ok'=>false,'error'=>'old_name and new_name required']); exit; }

    // Update roster rows for this state
    $stmt = $db->prepare("UPDATE innovate_roster SET market_center=? WHERE market_center=? AND state_code=?");
    $stmt->execute([$newName, $oldName, $state]);
    $count = (int)$stmt->rowCount();

    // Update the display name in market_centers without touching the slug
    $db->prepare("UPDATE market_centers SET name=? WHERE name=?")
       ->execute([$newName, $oldName]);

    echo json_encode(['ok'=>true, 'count'=>$count]);
    exit;
}

// Move a single roster row to a different MC (updates innovate_roster.market_center by id)
if ($action === 'move_mc') {
    $id     = (int)($body['id']      ?? 0);
    $mcName = trim($body['mc_name']  ?? '');
    if (!$id || $mcName === '') { echo json_encode(['ok'=>false,'error'=>'id and mc_name required']); exit; }
    $db->prepare("UPDATE innovate_roster SET market_center=? WHERE id=?")->execute([$mcName, $id]);
    echo json_encode(['ok'=>true, 'mc_name'=>$mcName]);
    exit;
}

// Bulk assign agents to an MC + BIC by matching names against the CRM roster
if ($action === 'bulk_assign') {
    if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    $names      = $body['agent_names'] ?? [];
    $mcSlug     = preg_replace('/[^a-z0-9\-]/', '', $body['mc_slug']   ?? '');
    $bicEmail   = strtolower(trim($body['bic_email'] ?? ''));
    $stateCode  = strtoupper(preg_replace('/[^A-Za-z]/', '', $body['state_code'] ?? ''));
    if (!is_array($names) || empty($names)) { echo json_encode(['ok'=>false,'error'=>'No agents selected']); exit; }
    if (!$mcSlug) { echo json_encode(['ok'=>false,'error'=>'mc_slug required']); exit; }

    // Look up the MC display name so we can update the roster text field
    $mcRow = $db->prepare("SELECT name FROM market_centers WHERE slug=?");
    $mcRow->execute([$mcSlug]);
    $mcName = $mcRow->fetchColumn() ?: '';

    // Fetch CRM roster to build normalised name → email map
    $c      = cfg();
    $base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token  = $c['crm_token'] ?? '';
    $url    = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx    = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $raw    = @file_get_contents($url, false, $ctx);
    $roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

    $nameMap = [];
    foreach ($roster as $a) {
        $email = strtolower(trim($a['email'] ?? ''));
        $name  = trim($a['name'] ?? $a['fullName'] ?? '');
        if ($email && $name) $nameMap[strtolower($name)] = $email;
    }

    $assigned  = 0;
    $unmatched = [];
    $upsert = $db->prepare(
        "INSERT INTO agent_roles (email, role, mc_slugs, own_mc_slug, bic_email, updated_by, updated_at)
         VALUES (?, 'agent', '[]', ?, ?, ?, datetime('now'))
         ON CONFLICT(email) DO UPDATE SET
           own_mc_slug = excluded.own_mc_slug,
           bic_email   = excluded.bic_email,
           updated_by  = excluded.updated_by,
           updated_at  = excluded.updated_at"
    );
    // Also update the roster display grouping when we have a valid MC name and state
    $rosterUpd = $mcName && $stateCode
        ? $db->prepare("UPDATE innovate_roster SET market_center=? WHERE agent_name=? AND state_code=?")
        : null;

    foreach ($names as $name) {
        $email = $nameMap[strtolower(trim($name))] ?? null;
        if (!$email) { $unmatched[] = $name; continue; }
        $upsert->execute([$email, $mcSlug, $bicEmail, $by]);
        if ($rosterUpd) $rosterUpd->execute([$mcName, trim($name), $stateCode]);
        $assigned++;
    }

    // For unmatched names we still update the roster grouping if we have state context
    if ($rosterUpd && $stateCode) {
        foreach ($unmatched as $name) {
            $rosterUpd->execute([$mcName, trim($name), $stateCode]);
        }
    }

    echo json_encode(['ok'=>true, 'assigned'=>$assigned, 'unmatched'=>$unmatched, 'mc_name'=>$mcName]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
