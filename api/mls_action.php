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

// Credential fields are only ever sent to super admins; every other leader gets
// nulls for these columns even though they can see the rest of the row. Mirrors
// the same masking already used in api/mls_memberships_action.php.
const CRED_FIELDS = ['login_username', 'login_password', 'billing_username', 'billing_password', 'api_username', 'api_secret', 'api_key'];

if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM mls_integrations ORDER BY
        CASE status WHEN 'active' THEN 0 WHEN 'approved' THEN 1 WHEN 'applied' THEN 2
                    WHEN 'researching' THEN 3 WHEN 'paused' THEN 4 ELSE 5 END,
        mls_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
        $name = trim($body['mls_name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'mls_name required']); exit; }
        // go_live_date is intentionally not part of this insert/update anymore
        // (dropped from the UI) — any value already on a row is preserved by
        // simply never touching the column here, rather than writing null.
        $cols = ['mls_name','mls_code','region','feed_type','feed_bbo','feed_idx','status','monthly_fee','products',
             'application_date','approval_date','agreement_url',
             'contact_name','contact_org','contact_email','contact_phone',
             'api_base_url','api_username','api_secret','api_key','notes',
             'membership_type','office_id','broker_of_record','login_username','login_password',
             'office_fees','broker_fees','admin_fees',
             'address','phone','login_link','billing_site','billing_frequency','billing_username','billing_password','board_or_mls',
             'created_by'];
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $s = $db->prepare("INSERT INTO mls_integrations (" . implode(',', $cols) . ") VALUES ($placeholders)");
        $s->execute([
            $name,
            trim($body['mls_code'] ?? ''),
            trim($body['region']   ?? ''),
            trim($body['feed_type']?? 'RETS'),
            !empty($body['feed_bbo']) ? 1 : 0,
            !empty($body['feed_idx']) ? 1 : 0,
            trim($body['status']   ?? 'researching'),
            (float)($body['monthly_fee'] ?? 0),
            trim($body['products'] ?? ''),
            $body['application_date'] ?: null,
            $body['approval_date']    ?: null,
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
            trim($body['membership_type']  ?? ''),
            trim($body['office_id']        ?? ''),
            trim($body['broker_of_record'] ?? ''),
            trim($body['login_username']   ?? ''),
            $body['login_password'] ?? '',
            trim($body['office_fees'] ?? ''),
            trim($body['broker_fees'] ?? ''),
            trim($body['admin_fees']  ?? ''),
            trim($body['address'] ?? ''),
            trim($body['phone']   ?? ''),
            trim($body['login_link'] ?? ''),
            trim($body['billing_site']      ?? ''),
            trim($body['billing_frequency'] ?? ''),
            trim($body['billing_username']  ?? ''),
            $body['billing_password'] ?? '',
            trim($body['board_or_mls'] ?? ''),
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
        $setCols = ['mls_name','mls_code','region','feed_type','feed_bbo','feed_idx','status','monthly_fee','products',
             'application_date','approval_date','agreement_url',
             'contact_name','contact_org','contact_email','contact_phone',
             'api_base_url','api_username','api_secret','api_key','notes',
             'membership_type','office_id','broker_of_record','login_username','login_password',
             'office_fees','broker_fees','admin_fees',
             'address','phone','login_link','billing_site','billing_frequency','billing_username','billing_password','board_or_mls'];
        $setClause = implode(',', array_map(fn($c) => "$c=?", $setCols));
        $s = $db->prepare("UPDATE mls_integrations SET $setClause, updated_at=datetime('now') WHERE id=?");
        $s->execute([
            $name,
            trim($body['mls_code'] ?? ''),
            trim($body['region']   ?? ''),
            trim($body['feed_type']?? 'RETS'),
            !empty($body['feed_bbo']) ? 1 : 0,
            !empty($body['feed_idx']) ? 1 : 0,
            trim($body['status']   ?? 'researching'),
            (float)($body['monthly_fee'] ?? 0),
            trim($body['products'] ?? ''),
            $body['application_date'] ?: null,
            $body['approval_date']    ?: null,
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
            trim($body['membership_type']  ?? ''),
            trim($body['office_id']        ?? ''),
            trim($body['broker_of_record'] ?? ''),
            trim($body['login_username']   ?? ''),
            $body['login_password'] ?? '',
            trim($body['office_fees'] ?? ''),
            trim($body['broker_fees'] ?? ''),
            trim($body['admin_fees']  ?? ''),
            trim($body['address'] ?? ''),
            trim($body['phone']   ?? ''),
            trim($body['login_link'] ?? ''),
            trim($body['billing_site']      ?? ''),
            trim($body['billing_frequency'] ?? ''),
            trim($body['billing_username']  ?? ''),
            $body['billing_password'] ?? '',
            trim($body['board_or_mls'] ?? ''),
            $id,
        ]);
        echo json_encode(['ok'=>true]);
        break;
    }

    case 'update_notes': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("UPDATE mls_integrations SET notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['notes'] ?? ''), $id]);
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
