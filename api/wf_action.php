<?php
// Workflow board/stage/item CRUD + card moves.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }

$db     = local_db();
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

// ── Boards ────────────────────────────────────────────────────────────────────
if ($action === 'list_boards') {
    $rows = $db->query("SELECT * FROM wf_boards ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'boards'=>$rows]); exit;
}
if ($action === 'create_board') {
    $name = trim($in['name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'name required']); exit; }
    $db->prepare("INSERT INTO wf_boards (name,description,created_by) VALUES (?,?,?)")
       ->execute([$name, $in['description']??null, $me['email']]);
    $bid = (int)$db->lastInsertId();
    // Seed default stages
    $si = $db->prepare("INSERT INTO wf_stages (board_id,name,sort_ord,color) VALUES (?,?,?,?)");
    foreach ([['To Do',10,'#e0e0e0'],['In Progress',20,'#fff4e0'],['Review',30,'#e8f0ff'],['Done',40,'#e8f5e9']] as [$n,$o,$c]) {
        $si->execute([$bid,$n,$o,$c]);
    }
    echo json_encode(['ok'=>true,'id'=>$bid]); exit;
}
if ($action === 'delete_board') {
    $id = (int)($in['id'] ?? 0);
    $db->prepare("DELETE FROM wf_items WHERE board_id=?")->execute([$id]);
    $db->prepare("DELETE FROM wf_stages WHERE board_id=?")->execute([$id]);
    $db->prepare("DELETE FROM wf_boards WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Board detail (stages + items) ─────────────────────────────────────────────
if ($action === 'get_board') {
    $id = (int)($in['id'] ?? 0);
    $board = $db->prepare("SELECT * FROM wf_boards WHERE id=?");
    $board->execute([$id]); $b = $board->fetch(PDO::FETCH_ASSOC);
    if (!$b) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    $stages = $db->prepare("SELECT * FROM wf_stages WHERE board_id=? ORDER BY sort_ord");
    $stages->execute([$id]);
    $items = $db->prepare("SELECT * FROM wf_items WHERE board_id=? ORDER BY sort_ord,id");
    $items->execute([$id]);
    $allItems = $items->fetchAll(PDO::FETCH_ASSOC);
    $stageRows = $stages->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stageRows as &$st) {
        $st['items'] = array_values(array_filter($allItems, fn($i) => (int)$i['stage_id'] === (int)$st['id']));
    }
    echo json_encode(['ok'=>true,'board'=>$b,'stages'=>$stageRows]); exit;
}

// ── Items ─────────────────────────────────────────────────────────────────────
if ($action === 'create_item') {
    $title   = trim($in['title'] ?? '');
    $stageId = (int)($in['stage_id'] ?? 0);
    $boardId = (int)($in['board_id'] ?? 0);
    if (!$title || !$stageId || !$boardId) { http_response_code(400); echo json_encode(['error'=>'title, stage_id and board_id required']); exit; }
    $maxOrd = $db->prepare("SELECT COALESCE(MAX(sort_ord),0)+10 FROM wf_items WHERE stage_id=?");
    $maxOrd->execute([$stageId]); $ord = (int)$maxOrd->fetchColumn();
    $db->prepare("INSERT INTO wf_items (stage_id,board_id,title,description,assigned_to,due_date,sort_ord,created_by) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$stageId,$boardId,$title,$in['description']??null,$in['assigned_to']??null,$in['due_date']??null,$ord,$me['email']]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_item') {
    $id = (int)($in['id'] ?? 0);
    $db->prepare("UPDATE wf_items SET title=?,description=?,assigned_to=?,due_date=? WHERE id=?")
       ->execute([trim($in['title']??''),$in['description']??null,$in['assigned_to']??null,$in['due_date']??null,$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'move_item') {
    $id       = (int)($in['id'] ?? 0);
    $stageId  = (int)($in['stage_id'] ?? 0);
    $db->prepare("UPDATE wf_items SET stage_id=? WHERE id=?")->execute([$stageId,$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_item') {
    $db->prepare("DELETE FROM wf_items WHERE id=?")->execute([(int)($in['id']??0)]);
    echo json_encode(['ok'=>true]); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
