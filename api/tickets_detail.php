<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
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
echo json_encode(['ok'=>true,'ticket'=>$tkt,'messages'=>$m->fetchAll(PDO::FETCH_ASSOC)]);
