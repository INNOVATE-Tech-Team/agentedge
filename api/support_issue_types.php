<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$rows = local_db()->query("SELECT id, name FROM support_issue_types ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['issueTypes' => $rows]);
