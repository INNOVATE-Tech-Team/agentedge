<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!can_access_finance_checklists()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$email  = $agent['email'] ?? '';
$db     = local_db();

$valid_recurrences = ['weekly', 'monthly', 'quarterly', 'annual', 'one_time'];

switch ($action) {

    case 'create_template':
        $name = trim($body['name'] ?? '');
        $desc = trim($body['description'] ?? '');
        $rec  = trim($body['recurrence'] ?? 'monthly');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        if (!in_array($rec, $valid_recurrences, true)) $rec = 'monthly';
        $s = $db->prepare("INSERT INTO finance_checklist_templates (name,description,recurrence,created_by) VALUES (?,?,?,?)");
        $s->execute([$name, $desc, $rec, $email]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;

    case 'update_template':
        $id   = (int)($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $desc = trim($body['description'] ?? '');
        $rec  = trim($body['recurrence'] ?? 'monthly');
        if (!$id || !$name) { echo json_encode(['ok'=>false,'error'=>'id and name required']); exit; }
        if (!in_array($rec, $valid_recurrences, true)) $rec = 'monthly';
        $s = $db->prepare("UPDATE finance_checklist_templates SET name=?, description=?, recurrence=? WHERE id=?");
        $s->execute([$name, $desc, $rec, $id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'archive_template':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("UPDATE finance_checklist_templates SET active=0 WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'unarchive_template':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("UPDATE finance_checklist_templates SET active=1 WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'delete_template':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $chk = $db->prepare("SELECT COUNT(*) FROM finance_checklist_runs WHERE template_id=?");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['ok'=>false,'error'=>'Template has runs — archive it instead of deleting, to keep the history.']);
            exit;
        }
        $db->prepare("DELETE FROM finance_checklist_template_items WHERE template_id=?")->execute([$id]);
        $db->prepare("DELETE FROM finance_checklist_templates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'add_template_item':
        $tid  = (int)($body['template_id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        $asg   = trim($body['default_assignee_email'] ?? '');
        $sort  = (int)($body['sort_ord'] ?? 0);
        if (!$tid || !$title) { echo json_encode(['ok'=>false,'error'=>'template_id and title required']); exit; }
        $s = $db->prepare("INSERT INTO finance_checklist_template_items (template_id,title,description,default_assignee_email,sort_ord) VALUES (?,?,?,?,?)");
        $s->execute([$tid, $title, $desc, $asg, $sort]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;

    case 'update_template_item':
        $id    = (int)($body['id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        $asg   = trim($body['default_assignee_email'] ?? '');
        if (!$id || !$title) { echo json_encode(['ok'=>false,'error'=>'id and title required']); exit; }
        $s = $db->prepare("UPDATE finance_checklist_template_items SET title=?, description=?, default_assignee_email=? WHERE id=?");
        $s->execute([$title, $desc, $asg, $id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'delete_template_item':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM finance_checklist_template_items WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'start_run':
        $tid    = (int)($body['template_id'] ?? 0);
        $period = trim($body['period_label'] ?? '');
        if (!$tid || !$period) { echo json_encode(['ok'=>false,'error'=>'template_id and period_label required']); exit; }
        $chk = $db->prepare("SELECT id FROM finance_checklist_templates WHERE id=?");
        $chk->execute([$tid]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'invalid template']); exit; }
        $db->prepare("INSERT INTO finance_checklist_runs (template_id,period_label,created_by) VALUES (?,?,?)")->execute([$tid, $period, $email]);
        $runId = (int)$db->lastInsertId();
        $items = $db->prepare("SELECT * FROM finance_checklist_template_items WHERE template_id=? ORDER BY sort_ord, id");
        $items->execute([$tid]);
        $ins = $db->prepare("INSERT INTO finance_checklist_run_items
            (run_id,title,description,assigned_to_email,sort_ord,updated_at)
            VALUES (?,?,?,?,?,datetime('now'))");
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $ins->execute([$runId, $it['title'], $it['description'], $it['default_assignee_email'], $it['sort_ord']]);
        }
        echo json_encode(['ok'=>true, 'id'=>$runId]);
        break;

    case 'delete_run':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM finance_checklist_run_items WHERE run_id=?")->execute([$id]);
        $db->prepare("DELETE FROM finance_checklist_runs WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'add_run_item':
        $rid   = (int)($body['run_id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        $asg   = trim($body['assigned_to_email'] ?? '');
        $due   = trim($body['due_date'] ?? '');
        $notes = trim($body['notes'] ?? '');
        if (!$rid || !$title) { echo json_encode(['ok'=>false,'error'=>'run_id and title required']); exit; }
        $chk = $db->prepare("SELECT id FROM finance_checklist_runs WHERE id=?");
        $chk->execute([$rid]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'invalid run']); exit; }
        $s = $db->prepare("INSERT INTO finance_checklist_run_items
            (run_id,title,description,assigned_to_email,due_date,notes,updated_at)
            VALUES (?,?,?,?,?,?,datetime('now'))");
        $s->execute([$rid, $title, $desc, $asg, $due, $notes]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        break;

    case 'update_run_item':
        $id    = (int)($body['id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        $asg   = trim($body['assigned_to_email'] ?? '');
        $due   = trim($body['due_date'] ?? '');
        $notes = trim($body['notes'] ?? '');
        if (!$id || !$title) { echo json_encode(['ok'=>false,'error'=>'id and title required']); exit; }
        $s = $db->prepare("UPDATE finance_checklist_run_items
            SET title=?, description=?, assigned_to_email=?, due_date=?, notes=?, updated_at=datetime('now')
            WHERE id=?");
        $s->execute([$title, $desc, $asg, $due, $notes, $id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'toggle_run_item':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $chk = $db->prepare("SELECT status FROM finance_checklist_run_items WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        if ($row['status'] === 'done') {
            $db->prepare("UPDATE finance_checklist_run_items SET status='todo', completed_at='', completed_by='', updated_at=datetime('now') WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true, 'status'=>'todo']);
        } else {
            $db->prepare("UPDATE finance_checklist_run_items SET status='done', completed_at=datetime('now'), completed_by=?, updated_at=datetime('now') WHERE id=?")->execute([$email, $id]);
            echo json_encode(['ok'=>true, 'status'=>'done']);
        }
        break;

    case 'delete_run_item':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM finance_checklist_run_items WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'list_templates':
        $rows = $db->query("SELECT * FROM finance_checklist_templates ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'templates'=>$rows]);
        break;

    case 'list_template_items':
        $tid = (int)($body['template_id'] ?? 0);
        if (!$tid) { echo json_encode(['ok'=>false,'error'=>'template_id required']); exit; }
        $s = $db->prepare("SELECT * FROM finance_checklist_template_items WHERE template_id=? ORDER BY sort_ord, id");
        $s->execute([$tid]);
        echo json_encode(['ok'=>true, 'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'list_runs':
        $rows = $db->query("SELECT r.*, t.name AS template_name, t.recurrence AS template_recurrence
            FROM finance_checklist_runs r
            JOIN finance_checklist_templates t ON t.id = r.template_id
            ORDER BY r.created_at DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'runs'=>$rows]);
        break;

    case 'list_run_items':
        $rid = (int)($body['run_id'] ?? 0);
        if (!$rid) { echo json_encode(['ok'=>false,'error'=>'run_id required']); exit; }
        $s = $db->prepare("SELECT * FROM finance_checklist_run_items WHERE run_id=? ORDER BY sort_ord, id");
        $s->execute([$rid]);
        echo json_encode(['ok'=>true, 'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
