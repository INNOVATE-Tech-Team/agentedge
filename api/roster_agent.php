<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/roster.php';
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

    $result = add_or_reactivate_roster_agent($db, $name, $state, $mc, $exp, null, $by);

    echo json_encode(['ok'=>true, 'id'=>$result['id'], 'agent_name'=>$name, 'state_code'=>$state, 'market_center'=>$mc, 'license_exp'=>$exp]);
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

    // Every roster removal starts offboarding — deprovisioning steps get tracked
    // in the queue instead of the agent just vanishing from the roster.
    $obId = null;
    try {
        require_once __DIR__ . '/../offboard_tools.php';
        $obEmail = $row['email'] ?? '';
        $existing = $obEmail !== ''
            ? $db->prepare("SELECT id FROM offboard_queue WHERE agent_email=? AND status='active' LIMIT 1")
            : $db->prepare("SELECT id FROM offboard_queue WHERE agent_email='' AND agent_name=? AND status='active' LIMIT 1");
        $existing->execute([$obEmail !== '' ? $obEmail : $row['agent_name']]);
        $obId = $existing->fetchColumn();

        if (!$obId) {
            $now = date('Y-m-d H:i:s');
            $db->prepare(
                "INSERT INTO offboard_queue (agent_email, agent_name, market_center, reason_notes, added_by, added_at)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$obEmail, $row['agent_name'], $row['market_center'], 'Started automatically when removed from the roster.', $by, $now]);
            $obId = (int)$db->lastInsertId();

            $stepIns = $db->prepare(
                "INSERT OR IGNORE INTO offboard_steps (queue_id, tool_key, tool_label, is_auto, status)
                 VALUES (?,?,?,?,?)"
            );
            foreach (offboard_tools() as $t) {
                $stepIns->execute([$obId, $t['key'], $t['label'], $t['is_auto'] ? 1 : 0, 'pending']);
            }

            require_once __DIR__ . '/../lib/notifications.php';
            notify_offboard_added($row['agent_name'], $obEmail, $row['market_center'] ?? '', '', 'voluntary', 'Started automatically when removed from the roster.', $by);
            dispatch_notification_queue();
        }
    } catch (\Throwable $e) {}

    echo json_encode(['ok'=>true, 'offboard_id'=>$obId]);
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

    // Close out any in-flight offboarding for this agent too.
    try {
        $obEmail = $row['email'] ?? '';
        $obq = $obEmail !== ''
            ? $db->prepare("SELECT id FROM offboard_queue WHERE agent_email=? AND status='active' LIMIT 1")
            : $db->prepare("SELECT id FROM offboard_queue WHERE agent_email='' AND agent_name=? AND status='active' LIMIT 1");
        $obq->execute([$obEmail !== '' ? $obEmail : $row['agent_name']]);
        $obqId = $obq->fetchColumn();
        if ($obqId) {
            $db->prepare("UPDATE offboard_queue SET status='cancelled' WHERE id=?")->execute([$obqId]);
        }
    } catch (\Throwable $e) {}

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

// Edit a single agent's contact info and details
if ($action === 'edit') {
    $id    = (int)($body['id']           ?? 0);
    $name  = trim($body['agent_name']    ?? '');
    $email = strtolower(trim($body['email']  ?? ''));
    $phone = trim($body['phone']         ?? '');
    $exp   = trim($body['license_exp']   ?? '');
    $mc    = trim($body['market_center'] ?? '');
    $state = strtoupper(trim($body['state_code'] ?? ''));
    if (!$id || !$name) { echo json_encode(['ok'=>false,'error'=>'id and agent_name required']); exit; }
    if ($exp && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = '';

    $prevStmt = $db->prepare("SELECT agent_name, canonical_agent_id FROM innovate_roster WHERE id=?");
    $prevStmt->execute([$id]);
    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prev) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $fields = ['agent_name'=>$name, 'email'=>$email, 'phone'=>$phone, 'license_exp'=>$exp];
    if ($mc !== '')    $fields['market_center'] = $mc;
    if ($state !== '') $fields['state_code']    = $state;
    $sets   = implode(', ', array_map(fn($k) => "$k=?", array_keys($fields)));
    $vals   = array_values($fields);
    $vals[] = $id;
    $db->prepare("UPDATE innovate_roster SET $sets WHERE id=?")->execute($vals);

    // Push the name/email/phone change out to this agent's other-state listings too.
    sync_roster_identity($db, $id, $prev['agent_name'], $prev['canonical_agent_id'], $name, $email, $phone, $by);

    echo json_encode(['ok'=>true]);
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
