<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$id = (int)($_GET['id'] ?? 0);
$db = local_db();
$s = $db->prepare("SELECT * FROM support_tickets WHERE id=?");
$s->execute([$id]);
$tkt = $s->fetch(PDO::FETCH_ASSOC);
if (!$tkt) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
if (!is_admin() && $tkt['agent_email'] !== $me['email']) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$m = $db->prepare("SELECT * FROM support_ticket_messages WHERE ticket_id=? ORDER BY created_at");
$m->execute([$id]);
$messages = $m->fetchAll(PDO::FETCH_ASSOC);

$cc = $db->prepare("SELECT email, added_by, created_at FROM support_ticket_cc WHERE ticket_id=? ORDER BY created_at");
$cc->execute([$id]);

$ev = $db->prepare("SELECT event_type, detail, actor_email, created_at FROM support_ticket_events WHERE ticket_id=? ORDER BY created_at");
$ev->execute([$id]);

$fl = $db->prepare("SELECT id, message_id, orig_name, mime_type, size_bytes FROM support_ticket_files WHERE ticket_id=? ORDER BY created_at");
$fl->execute([$id]);
$filesByMsg = [];
foreach ($fl->fetchAll(PDO::FETCH_ASSOC) as $file) {
    $filesByMsg[(int)$file['message_id']][] = $file;
}
foreach ($messages as &$msg) {
    $msg['files'] = $filesByMsg[(int)$msg['id']] ?? [];
}
unset($msg);

echo json_encode([
    'ok'       => true,
    'ticket'   => $tkt,
    'messages' => $messages,
    'cc'       => $cc->fetchAll(PDO::FETCH_ASSOC),
    'events'   => $ev->fetchAll(PDO::FETCH_ASSOC),
]);
