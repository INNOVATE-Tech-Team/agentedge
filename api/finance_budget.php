<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$email  = $agent['email'] ?? '';
$db     = local_db();

switch ($action) {

    case 'create_period':
        $name  = trim($body['name']        ?? '');
        $type  = trim($body['period_type'] ?? 'annual');
        $start = trim($body['start_date']  ?? '');
        $end   = trim($body['end_date']    ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        $valid_types = ['annual','quarterly','monthly','custom'];
        if (!in_array($type, $valid_types)) $type = 'custom';
        $s = $db->prepare("INSERT INTO budget_periods (name,period_type,start_date,end_date,created_by) VALUES (?,?,?,?,?)");
        $s->execute([$name, $type, $start, $end, $email]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;

    case 'delete_period':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM budget_lines WHERE period_id=?")->execute([$id]);
        $db->prepare("DELETE FROM budget_periods WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'add_line':
        $pid    = (int)($body['period_id']    ?? 0);
        $dept   = trim($body['department']    ?? 'Operations');
        $cat    = trim($body['category']      ?? '');
        $desc   = trim($body['description']   ?? '');
        $budget = (float)($body['budgeted_amt'] ?? 0);
        $actual = (float)($body['actual_amt']   ?? 0);
        $notes  = trim($body['notes']          ?? '');
        if (!$pid || !$cat) { echo json_encode(['ok'=>false,'error'=>'period_id and category required']); exit; }
        // Confirm period exists
        $chk = $db->prepare("SELECT id FROM budget_periods WHERE id=?");
        $chk->execute([$pid]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'invalid period']); exit; }
        $s = $db->prepare("INSERT INTO budget_lines
            (period_id,department,category,description,budgeted_amt,actual_amt,notes,created_by,updated_at)
            VALUES (?,?,?,?,?,?,?,?,datetime('now'))");
        $s->execute([$pid, $dept, $cat, $desc, $budget, $actual, $notes, $email]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;

    case 'update_line':
        $id     = (int)($body['id']            ?? 0);
        $dept   = trim($body['department']     ?? 'Operations');
        $cat    = trim($body['category']       ?? '');
        $desc   = trim($body['description']    ?? '');
        $budget = (float)($body['budgeted_amt'] ?? 0);
        $actual = (float)($body['actual_amt']   ?? 0);
        $notes  = trim($body['notes']           ?? '');
        if (!$id || !$cat) { echo json_encode(['ok'=>false,'error'=>'id and category required']); exit; }
        $s = $db->prepare("UPDATE budget_lines
            SET department=?, category=?, description=?, budgeted_amt=?, actual_amt=?, notes=?, updated_at=datetime('now')
            WHERE id=?");
        $s->execute([$dept, $cat, $desc, $budget, $actual, $notes, $id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'delete_line':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM budget_lines WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'list_periods':
        $rows = $db->query("SELECT * FROM budget_periods ORDER BY start_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'periods'=>$rows]);
        break;

    case 'list_lines':
        $pid = (int)($body['period_id'] ?? 0);
        if (!$pid) { echo json_encode(['ok'=>false,'error'=>'period_id required']); exit; }
        $s = $db->prepare("SELECT * FROM budget_lines WHERE period_id=? ORDER BY department, category, id");
        $s->execute([$pid]);
        echo json_encode(['ok'=>true, 'lines'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
