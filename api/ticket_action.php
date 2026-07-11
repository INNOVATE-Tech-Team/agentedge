<?php
// Ticket actions: reply, status, assign, cc_add, cc_remove.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

const TICKET_STATUSES = ['open', 'in_progress', 'answered', 'on_hold', 'closed'];

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$id     = (int)($in['id'] ?? 0);
$db     = local_db();

// Load ticket — agent can only touch their own; admin can touch any
$s = $db->prepare("SELECT * FROM support_tickets WHERE id=?");
$s->execute([$id]);
$tkt = $s->fetch(PDO::FETCH_ASSOC);
if (!$tkt) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
if (!is_admin() && $tkt['agent_email'] !== $me['email']) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

function log_ticket_event(PDO $db, int $id, string $type, string $detail, string $actor): void {
    $db->prepare("INSERT INTO support_ticket_events (ticket_id,event_type,detail,actor_email) VALUES (?,?,?,?)")
       ->execute([$id, $type, $detail, $actor]);
}

if ($action === 'reply') {
    $body = trim($in['body'] ?? '');
    if (!$body) { http_response_code(400); echo json_encode(['error'=>'body required']); exit; }
    $isStaff = is_admin() ? 1 : 0;
    $db->prepare("INSERT INTO support_ticket_messages (ticket_id,author,is_staff,body) VALUES (?,?,?,?)")
       ->execute([$id, $me['email'], $isStaff, $body]);

    // Staff replying moves the ticket to "answered" (agent's turn); the agent
    // replying moves it back to "open" (needs staff attention) — unless the
    // ticket is on hold or closed, which only an explicit status change lifts.
    $newStatus = $tkt['status'];
    if (!in_array($tkt['status'], ['on_hold', 'closed'], true)) {
        $newStatus = $isStaff ? 'answered' : 'open';
    }
    $db->prepare("UPDATE support_tickets SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$newStatus, $id]);
    if ($newStatus !== $tkt['status']) {
        log_ticket_event($db, $id, 'status_change', "{$tkt['status']} -> {$newStatus}", $me['email']);
    }

    echo json_encode(['ok'=>true]);
    notify_ticket_reply($id, $tkt['title'], $body, (bool)$isStaff, $tkt['dept_slug'] ?? '', $tkt['agent_email']);
    dispatch_notification_queue();
    exit;
}

if ($action === 'status') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $st = in_array($in['status'] ?? '', TICKET_STATUSES, true) ? $in['status'] : 'open';
    $db->prepare("UPDATE support_tickets SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$st, $id]);
    if ($st !== $tkt['status']) {
        log_ticket_event($db, $id, 'status_change', "{$tkt['status']} -> {$st}", $me['email']);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'assign') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $assignee = trim($in['assigned_to'] ?? '') ?: null;
    $db->prepare("UPDATE support_tickets SET assigned_to=?,updated_at=datetime('now') WHERE id=?")->execute([$assignee, $id]);
    log_ticket_event($db, $id, 'assigned', $assignee ?: '(unassigned)', $me['email']);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'cc_add') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $email = strtolower(trim($in['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error'=>'valid email required']); exit; }
    $exists = $db->prepare("SELECT 1 FROM support_ticket_cc WHERE ticket_id=? AND email=?");
    $exists->execute([$id, $email]);
    if (!$exists->fetchColumn()) {
        $db->prepare("INSERT INTO support_ticket_cc (ticket_id,email,added_by) VALUES (?,?,?)")->execute([$id, $email, $me['email']]);
        log_ticket_event($db, $id, 'cc_added', $email, $me['email']);
    }
    echo json_encode(['ok'=>true]);
    notify_ticket_cc_added($id, $tkt['title'], $email);
    dispatch_notification_queue();
    exit;
}

if ($action === 'cc_remove') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'admin only']); exit; }
    $email = strtolower(trim($in['email'] ?? ''));
    $db->prepare("DELETE FROM support_ticket_cc WHERE ticket_id=? AND email=?")->execute([$id, $email]);
    log_ticket_event($db, $id, 'cc_removed', $email, $me['email']);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
