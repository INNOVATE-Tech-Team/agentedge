<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$code   = strtoupper(trim($body['state_code'] ?? ''));
$status = $body['status'] ?? '';
$notes  = trim($body['notes'] ?? '');

$valid_codes   = ['FL','VA','DE','RI','NH','OH','NC','GA','PA','SC','MD','TN','NJ','MA'];
$valid_statuses = ['pending','in_progress','active'];

if (!in_array($code, $valid_codes) || !in_array($status, $valid_statuses)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid state_code or status']); exit;
}

$email = $agent['email'] ?? '';
$s = local_db()->prepare(
    "INSERT INTO state_roster_status (state_code,status,notes,updated_by,updated_at)
     VALUES (?,?,?,?,datetime('now'))
     ON CONFLICT(state_code) DO UPDATE SET
       status=excluded.status,
       notes=excluded.notes,
       updated_by=excluded.updated_by,
       updated_at=excluded.updated_at"
);
$s->execute([$code, $status, $notes, $email]);

echo json_encode(['ok'=>true]);
