<?php
// Communications tab on agent_profile.php — every Company Email an agent has
// received, in one place, instead of scattered across personal inboxes.
// GET ?email=... → admin only: log rows for that agent, newest first.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin only']); exit; }

$email = strtolower(trim($_GET['email'] ?? ''));
if ($email === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'email required']); exit; }

$st = local_db()->prepare(
    "SELECT sender_email, subject, snippet, sent_at FROM agent_comms_log WHERE agent_email=? ORDER BY sent_at DESC, id DESC LIMIT 100"
);
$st->execute([$email]);
echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
