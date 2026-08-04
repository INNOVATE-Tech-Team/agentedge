<?php
// Public, unauthenticated event registration — used by register.php.
// No login required: anyone with the link registers with just a name + email.
// Writes into the SAME training_rsvps / events_rsvps tables the logged-in
// AgentEdge calendar uses, so capacity/waitlist/attendee-list stay unified
// no matter which door someone registered through.
//
// POST {action:'register', scope, event_id, event_title, event_date, name, email}
//   → idempotent: if this email already has a row for this event, returns its
//     existing status instead of erroring or duplicating.
// POST {action:'cancel', scope, event_id, email}
//   → removes the row; promotes the next waitlisted registrant if a confirmed
//     seat opened up (mirrors training_rsvp.php / event_rsvp.php).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/google_calendar.php';
require_once __DIR__ . '/../lib/event_notifications.php';
header('Content-Type: application/json');

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); jerr('POST only'); }

$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$scope    = ($body['scope'] ?? '') === 'events' ? 'events' : 'training';
$table    = $scope === 'events' ? 'events_rsvps'   : 'training_rsvps';
$capTable = $scope === 'events' ? 'events_calendar' : 'training_events';

$c        = cfg();
$key_file = $c['gcal_key_file'] ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $scope === 'events' ? ($c['gcal_events_calendar_id'] ?? '') : ($c['gcal_calendar_id'] ?? 'training@innovateonline.com');

// Best-effort: add/remove the registrant as a real Calendar attendee so they
// get a native invite. Never let a Calendar API hiccup block the RSVP itself
// — the local row is always the source of truth.
function public_gcal_sync(string $cal_id, string $key_file, string $event_id, string $email, bool $add): void {
    if ($cal_id === '') return;
    try {
        $token = gcal_access_token($key_file);
        if (!$token) return;
        $add ? gcal_add_attendee($cal_id, $token, $event_id, $email) : gcal_remove_attendee($cal_id, $token, $event_id, $email);
    } catch (\Throwable $e) {}
}

// Best-effort event details (when/location/Zoom/description) for the
// confirmation emails below. Never let a Calendar API hiccup block the RSVP
// itself — falls back to a bare title-only email if this fails.
function public_event_info(string $cal_id, string $key_file, string $scope, string $event_id): array {
    $empty = ['when' => '', 'location' => '', 'description' => '', 'start_ts' => null];
    if ($cal_id === '') return $empty;
    try {
        $token = gcal_access_token($key_file);
        if (!$token) return $empty;
        $event = gcal_get_event($cal_id, $token, $event_id);
        return $event ? event_display_info($event, $scope, $event_id) : $empty;
    } catch (\Throwable $e) {
        return $empty;
    }
}

$action   = $body['action'] ?? '';
$event_id = trim($body['event_id'] ?? '');
$email    = strtolower(trim($body['email'] ?? ''));

if (!$event_id) jerr('missing event_id');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jerr('a valid email is required');

$db = local_db();

if ($action === 'cancel') {
    $existing = $db->prepare("SELECT id, status FROM {$table} WHERE event_id=? AND agent_email=?");
    $existing->execute([$event_id, $email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) jok(['rsvped' => false, 'waitlisted' => false]);

    $db->prepare("DELETE FROM {$table} WHERE id=?")->execute([$row['id']]);

    if ($row['status'] === 'registered') {
        public_gcal_sync($cal_id, $key_file, $event_id, $email, false);

        $next = $db->prepare("SELECT id, agent_email, event_title, event_date FROM {$table} WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT 1");
        $next->execute([$event_id]);
        if ($promoted = $next->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare("UPDATE {$table} SET status='registered' WHERE id=?")->execute([$promoted['id']]);
            public_gcal_sync($cal_id, $key_file, $event_id, $promoted['agent_email'], true);
            $info = public_event_info($cal_id, $key_file, $scope, $event_id);
            queue_branded_email([$promoted['agent_email']], "You're in: {$promoted['event_title']}",
                '<p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 16px">A seat opened up - you\'ve been moved from the waitlist to registered for:</p>'
                . event_details_block_html($promoted['event_title'], $info)
            );
        }
    }
    jok(['rsvped' => false, 'waitlisted' => false]);
}

if ($action === 'register') {
    $name        = trim($body['name'] ?? '');
    $event_title = trim($body['event_title'] ?? '');
    $event_date  = trim($body['event_date'] ?? '');
    if ($name === '') jerr('name is required');

    $existing = $db->prepare("SELECT status FROM {$table} WHERE event_id=? AND agent_email=?");
    $existing->execute([$event_id, $email]);
    if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
        jok(['rsvped' => $row['status'] === 'registered', 'waitlisted' => $row['status'] === 'waitlisted', 'already' => true]);
    }

    $capStmt = $db->prepare("SELECT capacity FROM {$capTable} WHERE event_id=?");
    $capStmt->execute([$event_id]);
    $capacityRaw = $capStmt->fetchColumn();
    $capacity    = ($capacityRaw === false || $capacityRaw === null) ? null : (int)$capacityRaw;

    $status = 'registered';
    if ($capacity !== null) {
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id=? AND status='registered'");
        $cntStmt->execute([$event_id]);
        if ((int)$cntStmt->fetchColumn() >= $capacity) $status = 'waitlisted';
    }

    $db->prepare("INSERT INTO {$table} (event_id, event_title, event_date, agent_email, agent_name, status) VALUES (?,?,?,?,?,?)")
       ->execute([$event_id, $event_title, $event_date, $email, $name, $status]);

    if ($status === 'registered') public_gcal_sync($cal_id, $key_file, $event_id, $email, true);

    $info = public_event_info($cal_id, $key_file, $scope, $event_id);
    if ($status === 'waitlisted') {
        queue_branded_email([$email], "Waitlisted: {$event_title}",
            '<p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 16px">This event is currently full - you\'ve been added to the waitlist. We\'ll email you if a seat opens up.</p>'
            . event_details_block_html($event_title, $info)
        );
    } else {
        queue_branded_email([$email], "You're registered: {$event_title}",
            '<p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 16px">You\'re confirmed for:</p>'
            . event_details_block_html($event_title, $info)
        );
    }

    jok(['rsvped' => $status === 'registered', 'waitlisted' => $status === 'waitlisted']);
}

jerr('unknown action');
