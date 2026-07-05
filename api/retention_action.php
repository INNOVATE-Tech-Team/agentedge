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

$validStatuses = ['secure', 'watch', 'at_risk'];

if ($action === 'update') {
    $id     = (int)($body['id']               ?? 0);
    $status = trim($body['retention_status']  ?? '');
    $notes  = trim($body['retention_notes']    ?? '');
    if (!$id || !in_array($status, $validStatuses)) {
        echo json_encode(['ok'=>false,'error'=>'id and valid retention_status required']); exit;
    }

    $s = $db->prepare("SELECT * FROM innovate_roster WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $db->prepare("UPDATE innovate_roster SET retention_status=?, retention_notes=? WHERE id=?")
       ->execute([$status, $notes, $id]);

    $db->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
       ->execute([$row['agent_name'], $row['state_code'], $row['market_center'], $row['license_exp'], 'retention:' . $status, $by]);

    echo json_encode(['ok'=>true, 'id'=>$id, 'retention_status'=>$status, 'retention_notes'=>$notes]);
    exit;
}

if ($action === 'log_contact') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }

    $db->prepare("UPDATE innovate_roster SET last_contact_at=datetime('now') WHERE id=?")
       ->execute([$id]);

    $s = $db->prepare("SELECT last_contact_at FROM innovate_roster WHERE id=?");
    $s->execute([$id]);
    echo json_encode(['ok'=>true, 'last_contact_at'=>$s->fetchColumn()]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
