<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
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

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
