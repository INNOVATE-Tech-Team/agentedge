<?php
// Staff-only ticket helpers: predefined replies + knowledge base links, used
// by the reply toolbar in backoffice_tickets.php.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$db = local_db();
$replies = $db->query("SELECT id, title, body FROM support_canned_replies ORDER BY sort_ord, title")->fetchAll(PDO::FETCH_ASSOC);
$links   = $db->query("SELECT id, title, url FROM support_kb_links ORDER BY sort_ord, title")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'cannedReplies' => $replies, 'kbLinks' => $links]);
