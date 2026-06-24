<?php
// Toggle RSVP for a training event. POST {event_id, event_title, event_date}.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$event_id    = trim($body['event_id']    ?? '');
$event_title = trim($body['event_title'] ?? '');
$event_date  = trim($body['event_date']  ?? '');

if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email'] ?? ''));
$name  = trim(($agent['firstname'] ?? '') . ' ' . ($agent['lastname'] ?? ''));

$existing = $db->prepare("SELECT id FROM training_rsvps WHERE event_id=? AND agent_email=?");
$existing->execute([$event_id, $email]);

if ($existing->fetch()) {
    $db->prepare("DELETE FROM training_rsvps WHERE event_id=? AND agent_email=?")
       ->execute([$event_id, $email]);
    echo json_encode(['ok' => true, 'rsvped' => false]);
} else {
    $db->prepare("INSERT INTO training_rsvps (event_id, event_title, event_date, agent_email, agent_name) VALUES (?,?,?,?,?)")
       ->execute([$event_id, $event_title, $event_date, $email, $name]);
    echo json_encode(['ok' => true, 'rsvped' => true]);
}
