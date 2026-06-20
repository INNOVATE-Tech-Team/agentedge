<?php
// Ticket actions: reply, close, reopen, assign.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$id     = (int)($in['id'] ?? 0);
$db     = local_db();

// Load ticket — agent can only touch their own; admin can touch any
$tkt = $db->prepare("SELECT * FROM support_tickets WHERE id=?")->execute([$id]) ? null : null;
$s = $db->prepare("SELECT * FROM support_tickets WHERE id=?");
$s->execute([$id]);
$tkt = $s->fetch(PDO::FETCH_ASSOC);
if (!$tkt) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
if (!is_admin() && $tkt['agent_email'] !== $me['email']) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

if ($action === 'reply') {
    $body = trim($in['body'] ?? '');
    if (!$body) { http_response_code(400); echo json_encode(['error'=>'body required']); exit; }
    $isStaff = is_admin() ? 1 : 0;
    $db->prepare("INSERT INTO support_ticket_messages (ticket_id,author,is_staff,body) VALUES (?,?,?,?)")
       ->execute([$id, $me['email'], $isStaff, $body]);
    $db->prepare("UPDATE support_tickets SET updated_at=datetime('now') WHERE id=?")->execute([$id]);
    if ($isStaff && $tkt['status'] === 'open') {
        $db->prepare("UPDATE support_tickets SET status='in_progress' WHERE id=?")->execute([$id]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'status') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $st = in_array($in['status']??'', ['open','in_progress','closed']) ? $in['status'] : 'open';
    $db->prepare("UPDATE support_tickets SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$st,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'assign') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $assignee = trim($in['assigned_to'] ?? '') ?: null;
    $db->prepare("UPDATE support_tickets SET assigned_to=?,updated_at=datetime('now') WHERE id=?")->execute([$assignee,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
