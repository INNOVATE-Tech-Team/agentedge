<?php
// Toggle RSVP for a training event. POST {event_id, event_title, event_date}.
// Honors an optional capacity set in training_events — once full, new RSVPs
// are waitlisted, and cancelling a confirmed seat promotes the longest-waiting
// waitlisted agent. Confirmation/promotion emails are queued for the cron
// mailer in cron/process_email_queue.php.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/google_calendar.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

// Best-effort: add/remove the agent as a real Calendar attendee so their own
// Google Calendar gets a native invite. Never let a Calendar API hiccup block
// the RSVP itself — the local row is always the source of truth.
$c        = cfg();
$key_file = $c['gcal_key_file']    ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $c['gcal_calendar_id'] ?? 'training@innovateonline.com';
function training_gcal_sync(string $event_id, string $email, bool $add): void {
    global $key_file, $cal_id;
    try {
        $token = gcal_access_token($key_file);
        if (!$token) return;
        $add ? gcal_add_attendee($cal_id, $token, $event_id, $email) : gcal_remove_attendee($cal_id, $token, $event_id, $email);
    } catch (\Throwable $e) {}
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$event_id    = trim($body['event_id']    ?? '');
$event_title = trim($body['event_title'] ?? '');
$event_date  = trim($body['event_date']  ?? '');

if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email'] ?? ''));
$name  = trim(($agent['firstname'] ?? '') . ' ' . ($agent['lastname'] ?? ''));

$existing = $db->prepare("SELECT id, status FROM training_rsvps WHERE event_id=? AND agent_email=?");
$existing->execute([$event_id, $email]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // Cancelling. If this freed a confirmed seat, promote the longest-waiting
    // agent on the waitlist into it.
    $db->prepare("DELETE FROM training_rsvps WHERE id=?")->execute([$row['id']]);

    if ($row['status'] === 'registered') {
        training_gcal_sync($event_id, $email, false);

        $next = $db->prepare(
            "SELECT id, agent_email FROM training_rsvps WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT 1"
        );
        $next->execute([$event_id]);
        if ($promoted = $next->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare("UPDATE training_rsvps SET status='registered' WHERE id=?")->execute([$promoted['id']]);
            training_gcal_sync($event_id, $promoted['agent_email'], true);
            queue_email_to([$promoted['agent_email']], "You're in: {$event_title}", implode("\n", [
                "A seat opened up — you've been moved from the waitlist to registered for:",
                "",
                $event_title,
                "Date: {$event_date}",
                "",
                "— AgentEdge Training",
            ]));
        }
    }

    echo json_encode(['ok' => true, 'rsvped' => false, 'waitlisted' => false]);
    exit;
}

// Registering — check capacity.
$capStmt = $db->prepare("SELECT capacity FROM training_events WHERE event_id=?");
$capStmt->execute([$event_id]);
$capacityRaw = $capStmt->fetchColumn();
$capacity    = ($capacityRaw === false || $capacityRaw === null) ? null : (int)$capacityRaw;

$status = 'registered';
if ($capacity !== null) {
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM training_rsvps WHERE event_id=? AND status='registered'");
    $cntStmt->execute([$event_id]);
    if ((int)$cntStmt->fetchColumn() >= $capacity) $status = 'waitlisted';
}

$db->prepare(
    "INSERT INTO training_rsvps (event_id, event_title, event_date, agent_email, agent_name, status) VALUES (?,?,?,?,?,?)"
)->execute([$event_id, $event_title, $event_date, $email, $name, $status]);

if ($status === 'registered' && $email) {
    training_gcal_sync($event_id, $email, true);
}

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
            "— AgentEdge Training",
        ]));
    } else {
        queue_email_to([$email], "You're registered: {$event_title}", implode("\n", [
            "You're confirmed for:",
            "",
            $event_title,
            "Date: {$event_date}",
            "",
            "— AgentEdge Training",
        ]));
    }
}

echo json_encode(['ok' => true, 'rsvped' => $status === 'registered', 'waitlisted' => $status === 'waitlisted']);
