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
$super = is_super_admin();

// Credential fields are only ever sent to super admins; every other leader gets nulls
// for these columns even though they can see the rest of the membership record.
const CRED_FIELDS = ['username', 'password', 'billing_username', 'billing_password'];

if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM mls_memberships ORDER BY state ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$super) {
        foreach ($rows as &$r) {
            foreach (CRED_FIELDS as $f) { $r[$f] = null; }
        }
        unset($r);
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

// Mutations require super_admin
if (!$super) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'add': {
        $state = trim($body['state'] ?? '');
        $name  = trim($body['name'] ?? '');
        if (!$state || !$name) { echo json_encode(['ok'=>false,'error'=>'state and name required']); exit; }
        $s = $db->prepare("INSERT INTO mls_memberships
            (state,board_or_mls,name,membership_type,address,phone,office_id,broker_of_record,
             username,password,login_link,notes,billing_site,billing_frequency,billing_username,
             billing_password,office_fees,broker_fees,admin_fees,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            $state,
            trim($body['board_or_mls'] ?? ''),
            $name,
            trim($body['membership_type'] ?? ''),
            trim($body['address'] ?? ''),
            trim($body['phone'] ?? ''),
            trim($body['office_id'] ?? ''),
            trim($body['broker_of_record'] ?? ''),
            trim($body['username'] ?? ''),
            $body['password'] ?? '',
            trim($body['login_link'] ?? ''),
            trim($body['notes'] ?? ''),
            trim($body['billing_site'] ?? ''),
            trim($body['billing_frequency'] ?? ''),
            trim($body['billing_username'] ?? ''),
            $body['billing_password'] ?? '',
            trim($body['office_fees'] ?? ''),
            trim($body['broker_fees'] ?? ''),
            trim($body['admin_fees'] ?? ''),
            $agent['email'] ?? '',
        ]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;
    }

    case 'update': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $state = trim($body['state'] ?? '');
        $name  = trim($body['name'] ?? '');
        if (!$state || !$name) { echo json_encode(['ok'=>false,'error'=>'state and name required']); exit; }
        $s = $db->prepare("UPDATE mls_memberships SET
            state=?,board_or_mls=?,name=?,membership_type=?,address=?,phone=?,office_id=?,broker_of_record=?,
            username=?,password=?,login_link=?,notes=?,billing_site=?,billing_frequency=?,billing_username=?,
            billing_password=?,office_fees=?,broker_fees=?,admin_fees=?,
            updated_at=datetime('now')
            WHERE id=?");
        $s->execute([
            $state,
            trim($body['board_or_mls'] ?? ''),
            $name,
            trim($body['membership_type'] ?? ''),
            trim($body['address'] ?? ''),
            trim($body['phone'] ?? ''),
            trim($body['office_id'] ?? ''),
            trim($body['broker_of_record'] ?? ''),
            trim($body['username'] ?? ''),
            $body['password'] ?? '',
            trim($body['login_link'] ?? ''),
            trim($body['notes'] ?? ''),
            trim($body['billing_site'] ?? ''),
            trim($body['billing_frequency'] ?? ''),
            trim($body['billing_username'] ?? ''),
            $body['billing_password'] ?? '',
            trim($body['office_fees'] ?? ''),
            trim($body['broker_fees'] ?? ''),
            trim($body['admin_fees'] ?? ''),
            $id,
        ]);
        echo json_encode(['ok'=>true]);
        break;
    }

    case 'delete': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM mls_memberships WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;
    }

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
