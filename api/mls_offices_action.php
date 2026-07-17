<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$db = local_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM mls_offices ORDER BY state ASC, branch_office ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

// Mutations require super_admin
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'add': {
        $state = trim($body['state'] ?? '');
        if (!$state) { echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $s = $db->prepare("INSERT INTO mls_offices
            (state,branch_office,entity_name,dba,office_type,office_license_number,license_expiration,
             firm_type,designated_broker,market_leader,broker_license_number,broker_expiration,
             fub_phone,address,lease_payee,notes,mls_integration_id,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            strtoupper($state),
            trim($body['branch_office'] ?? ''),
            trim($body['entity_name']   ?? ''),
            trim($body['dba']           ?? ''),
            trim($body['office_type']   ?? ''),
            trim($body['office_license_number'] ?? ''),
            $body['license_expiration'] ?: null,
            trim($body['firm_type'] ?? 'Residential'),
            trim($body['designated_broker'] ?? ''),
            trim($body['market_leader']     ?? ''),
            trim($body['broker_license_number'] ?? ''),
            $body['broker_expiration'] ?: null,
            trim($body['fub_phone'] ?? ''),
            trim($body['address']   ?? ''),
            trim($body['lease_payee'] ?? ''),
            trim($body['notes'] ?? ''),
            $body['mls_integration_id'] ?: null,
            $agent['email'] ?? '',
        ]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;
    }

    case 'update': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $state = trim($body['state'] ?? '');
        if (!$state) { echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $s = $db->prepare("UPDATE mls_offices SET
            state=?,branch_office=?,entity_name=?,dba=?,office_type=?,office_license_number=?,license_expiration=?,
            firm_type=?,designated_broker=?,market_leader=?,broker_license_number=?,broker_expiration=?,
            fub_phone=?,address=?,lease_payee=?,notes=?,mls_integration_id=?,
            updated_at=datetime('now')
            WHERE id=?");
        $s->execute([
            strtoupper($state),
            trim($body['branch_office'] ?? ''),
            trim($body['entity_name']   ?? ''),
            trim($body['dba']           ?? ''),
            trim($body['office_type']   ?? ''),
            trim($body['office_license_number'] ?? ''),
            $body['license_expiration'] ?: null,
            trim($body['firm_type'] ?? 'Residential'),
            trim($body['designated_broker'] ?? ''),
            trim($body['market_leader']     ?? ''),
            trim($body['broker_license_number'] ?? ''),
            $body['broker_expiration'] ?: null,
            trim($body['fub_phone'] ?? ''),
            trim($body['address']   ?? ''),
            trim($body['lease_payee'] ?? ''),
            trim($body['notes'] ?? ''),
            $body['mls_integration_id'] ?: null,
            $id,
        ]);
        echo json_encode(['ok'=>true]);
        break;
    }

    case 'delete': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM mls_offices WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;
    }

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
