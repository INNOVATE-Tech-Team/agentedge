<?php
// Admin-only: attendee list (registered + waitlisted) for a training event.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent)     { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']);    exit; }

$event_id = trim($_GET['event_id'] ?? '');
if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

$stmt = local_db()->prepare(
    "SELECT agent_name, agent_email, status, rsvped_at FROM training_rsvps WHERE event_id=? ORDER BY status, rsvped_at"
);
$stmt->execute([$event_id]);

echo json_encode(['ok' => true, 'attendees' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
