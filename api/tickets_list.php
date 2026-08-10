<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$db = local_db();
if (is_super_admin()) {
    $rows = $db->query("SELECT * FROM support_tickets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $s = $db->prepare("SELECT * FROM support_tickets WHERE agent_email=? ORDER BY updated_at DESC");
    $s->execute([$me['email']]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
}
// See tickets_detail.php for why JSON_INVALID_UTF8_SUBSTITUTE matters here —
// one ticket with malformed UTF-8 (e.g. from an inbound email reply) would
// otherwise silently break the entire list for every ticket, not just that one.
echo json_encode(['ok'=>true,'tickets'=>$rows], JSON_INVALID_UTF8_SUBSTITUTE);
