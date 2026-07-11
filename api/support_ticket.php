<?php
// Create a new support ticket (called by the support modal in global.js).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$title     = trim($in['title'] ?? '');
$body      = trim($in['body']  ?? '');
$deptSlug  = trim($in['departmentSlug'] ?? '');
$issueType = trim($in['issueType'] ?? '');
if (!$title) $title = $issueType; // agent-facing form derives the title from Issue Type instead of a manual field
if (!$title || !$body) { http_response_code(400); echo json_encode(['error'=>'title and body required']); exit; }

$db = local_db();

// Validate dept slug
$dept     = null;
$deptName = '';
if ($deptSlug) {
    $ds = $db->prepare("SELECT slug, name FROM support_departments WHERE slug=?");
    $ds->execute([$deptSlug]);
    $row = $ds->fetch(PDO::FETCH_ASSOC);
    if ($row) { $dept = $row['slug']; $deptName = $row['name']; }
}

$s = $db->prepare(
    "INSERT INTO support_tickets (title,body,dept_slug,agent_email,agent_name,issue_type) VALUES (?,?,?,?,?,?)"
);
$s->execute([$title, $body, $dept, $me['email'], $me['name'] ?? '', $issueType]);
$ticketId = (int)$db->lastInsertId();

// First message = the original body
$m = $db->prepare(
    "INSERT INTO support_ticket_messages (ticket_id,author,is_staff,body) VALUES (?,?,0,?)"
);
$m->execute([$ticketId, $me['email'], $body]);

$db->prepare("INSERT INTO support_ticket_events (ticket_id,event_type,detail,actor_email) VALUES (?,?,?,?)")
   ->execute([$ticketId, 'created', $deptName ?: 'General', $me['email']]);

notify_ticket_created($ticketId, $title, $body, $dept ?? '', $deptName, $me['name'] ?? $me['email'], $me['email']);

echo json_encode(['ok'=>true,'id'=>$ticketId]);
dispatch_notification_queue();
