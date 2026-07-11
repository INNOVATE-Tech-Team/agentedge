<?php
// Toggle RSVP for a Company Calendar "Event". POST {event_id, event_title, event_date}.
// Mirrors training_rsvp.php exactly, but against the events_* tables so this
// RSVP pool is independent from Training's.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
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

$existing = $db->prepare("SELECT id, status FROM events_rsvps WHERE event_id=? AND agent_email=?");
$existing->execute([$event_id, $email]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // Cancelling. If this freed a confirmed seat, promote the longest-waiting
    // agent on the waitlist into it.
    $db->prepare("DELETE FROM events_rsvps WHERE id=?")->execute([$row['id']]);

    if ($row['status'] === 'registered') {
        $next = $db->prepare(
            "SELECT id, agent_email FROM events_rsvps WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT 1"
        );
        $next->execute([$event_id]);
        if ($promoted = $next->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare("UPDATE events_rsvps SET status='registered' WHERE id=?")->execute([$promoted['id']]);
            queue_email_to([$promoted['agent_email']], "You're in: {$event_title}", implode("\n", [
                "A seat opened up — you've been moved from the waitlist to registered for:",
                "",
                $event_title,
                "Date: {$event_date}",
                "",
                "— AgentEdge",
            ]));
        }
    }

    echo json_encode(['ok' => true, 'rsvped' => false, 'waitlisted' => false]);
    exit;
}

// Registering — check capacity.
$capStmt = $db->prepare("SELECT capacity FROM events_calendar WHERE event_id=?");
$capStmt->execute([$event_id]);
$capacityRaw = $capStmt->fetchColumn();
$capacity    = ($capacityRaw === false || $capacityRaw === null) ? null : (int)$capacityRaw;

$status = 'registered';
if ($capacity !== null) {
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM events_rsvps WHERE event_id=? AND status='registered'");
    $cntStmt->execute([$event_id]);
    if ((int)$cntStmt->fetchColumn() >= $capacity) $status = 'waitlisted';
}

$db->prepare(
    "INSERT INTO events_rsvps (event_id, event_title, event_date, agent_email, agent_name, status) VALUES (?,?,?,?,?,?)"
)->execute([$event_id, $event_title, $event_date, $email, $name, $status]);

if ($email) {
    if ($status === 'waitlisted') {
        queue_email_to([$email], "Waitlisted: {$event_title}", implode("\n", [
            "This event is currently full. You've been added to the waitlist for:",
            "",
            $event_title,
            "Date: {$event_date}",
            "",
            "We'll email you if a seat opens up.",
            "",
            "— AgentEdge",
        ]));
    } else {
        queue_email_to([$email], "You're registered: {$event_title}", implode("\n", [
            "You're confirmed for:",
            "",
            $event_title,
            "Date: {$event_date}",
            "",
            "— AgentEdge",
        ]));
    }
}

echo json_encode(['ok' => true, 'rsvped' => $status === 'registered', 'waitlisted' => $status === 'waitlisted']);
