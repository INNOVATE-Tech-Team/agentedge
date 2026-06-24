<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$db = local_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM mls_integrations ORDER BY
        CASE status WHEN 'active' THEN 0 WHEN 'approved' THEN 1 WHEN 'applied' THEN 2
                    WHEN 'researching' THEN 3 WHEN 'paused' THEN 4 ELSE 5 END,
        mls_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

// Mutations require super_admin
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'add': {
        $name = trim($body['mls_name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'mls_name required']); exit; }
        $s = $db->prepare("INSERT INTO mls_integrations
            (mls_name,mls_code,region,feed_type,status,monthly_fee,products,
             application_date,approval_date,go_live_date,agreement_url,
             contact_name,contact_org,contact_email,contact_phone,
             api_base_url,api_username,api_secret,api_key,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            $name,
            trim($body['mls_code'] ?? ''),
            trim($body['region']   ?? ''),
            trim($body['feed_type']?? 'RETS'),
            trim($body['status']   ?? 'researching'),
            (float)($body['monthly_fee'] ?? 0),
            trim($body['products'] ?? ''),
            $body['application_date'] ?: null,
            $body['approval_date']    ?: null,
            $body['go_live_date']     ?: null,
            $body['agreement_url']    ?: null,
            trim($body['contact_name']  ?? ''),
            trim($body['contact_org']   ?? ''),
            trim($body['contact_email'] ?? ''),
            trim($body['contact_phone'] ?? ''),
            $body['api_base_url'] ?: null,
            trim($body['api_username'] ?? ''),
            trim($body['api_secret']   ?? ''),
            trim($body['api_key']      ?? ''),
            trim($body['notes']        ?? ''),
            $agent['email'] ?? '',
        ]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;
    }

    case 'update': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $name = trim($body['mls_name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'mls_name required']); exit; }
        $s = $db->prepare("UPDATE mls_integrations SET
            mls_name=?,mls_code=?,region=?,feed_type=?,status=?,monthly_fee=?,products=?,
            application_date=?,approval_date=?,go_live_date=?,agreement_url=?,
            contact_name=?,contact_org=?,contact_email=?,contact_phone=?,
            api_base_url=?,api_username=?,api_secret=?,api_key=?,notes=?,
            updated_at=datetime('now')
            WHERE id=?");
        $s->execute([
            $name,
            trim($body['mls_code'] ?? ''),
            trim($body['region']   ?? ''),
            trim($body['feed_type']?? 'RETS'),
            trim($body['status']   ?? 'researching'),
            (float)($body['monthly_fee'] ?? 0),
            trim($body['products'] ?? ''),
            $body['application_date'] ?: null,
            $body['approval_date']    ?: null,
            $body['go_live_date']     ?: null,
            $body['agreement_url']    ?: null,
            trim($body['contact_name']  ?? ''),
            trim($body['contact_org']   ?? ''),
            trim($body['contact_email'] ?? ''),
            trim($body['contact_phone'] ?? ''),
            $body['api_base_url'] ?: null,
            trim($body['api_username'] ?? ''),
            trim($body['api_secret']   ?? ''),
            trim($body['api_key']      ?? ''),
            trim($body['notes']        ?? ''),
            $id,
        ]);
        echo json_encode(['ok'=>true]);
        break;
    }

    case 'delete': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM mls_integrations WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;
    }

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
