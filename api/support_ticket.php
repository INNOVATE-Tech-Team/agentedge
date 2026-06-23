<?php
// Create a new support ticket (called by the support modal in global.js).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$title    = trim($in['title'] ?? '');
$body     = trim($in['body']  ?? '');
$deptSlug = trim($in['departmentSlug'] ?? '');
if (!$title || !$body) { http_response_code(400); echo json_encode(['error'=>'title and body required']); exit; }

$db = local_db();

// Validate dept slug
$dept = null;
if ($deptSlug) {
    $ds = $db->prepare("SELECT slug FROM support_departments WHERE slug=?");
    $ds->execute([$deptSlug]);
    $dept = $ds->fetchColumn();
}

$s = $db->prepare(
    "INSERT INTO support_tickets (title,body,dept_slug,agent_email,agent_name) VALUES (?,?,?,?,?)"
);
$s->execute([$title, $body, $dept ?: null, $me['email'], $me['name'] ?? '']);
$ticketId = $db->lastInsertId();

// First message = the original body
$m = $db->prepare(
    "INSERT INTO support_ticket_messages (ticket_id,author,is_staff,body) VALUES (?,?,0,?)"
);
$m->execute([$ticketId, $me['email'], $body]);

echo json_encode(['ok'=>true,'id'=>(int)$ticketId]);
