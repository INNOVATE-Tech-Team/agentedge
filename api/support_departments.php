<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$rows = local_db()->query("SELECT slug, name FROM support_departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['departments' => $rows]);
