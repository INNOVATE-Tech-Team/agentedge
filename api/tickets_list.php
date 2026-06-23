<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$db = local_db();
if (is_admin()) {
    $rows = $db->query("SELECT * FROM support_tickets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $s = $db->prepare("SELECT * FROM support_tickets WHERE agent_email=? ORDER BY updated_at DESC");
    $s->execute([$me['email']]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode(['ok'=>true,'tickets'=>$rows]);
