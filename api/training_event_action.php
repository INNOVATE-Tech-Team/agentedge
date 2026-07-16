<?php
// Admin-only: create / update / delete training events on Google Calendar.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent)     { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']);    exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

$c        = cfg();
$key_file = $c['gcal_key_file']    ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $c['gcal_calendar_id'] ?? 'training@innovateonline.com';

$token = gcal_access_token($key_file);
if (!$token) { http_response_code(500); echo json_encode(['error' => 'calendar auth failed']); exit; }

// ── Create ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title      = trim($body['title']       ?? '');
    $date       = trim($body['date']        ?? '');
    $end_date   = trim($body['end_date']    ?? '');
    $start_time = trim($body['start_time']  ?? '');
    $end_time   = trim($body['end_time']    ?? '');
    $location   = trim($body['location']    ?? '');
    $description = trim($body['description'] ?? '');
    $capacity   = ($body['capacity'] ?? '') !== '' ? max(0, (int)$body['capacity']) : null;

    if (!$title || !$date) { http_response_code(400); echo json_encode(['error' => 'title and date required']); exit; }

    if ($start_time && $end_time) {
        $event = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['dateTime' => $date . 'T' . $start_time . ':00', 'timeZone' => 'America/New_York'],
            'end'   => ['dateTime' => ($end_date ?: $date) . 'T' . $end_time . ':00', 'timeZone' => 'America/New_York'],
        ];
    } else {
        $end = $end_date ?: date('Y-m-d', strtotime($date . ' +1 day'));
        $event = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['date' => $date],
            'end'   => ['date' => $end],
        ];
    }

    $result = gcal_create_event($cal_id, $token, $event);
    if (!$result) { http_response_code(500); echo json_encode(['error' => 'failed to create event — check calendar sharing permissions']); exit; }
    local_db()->prepare("INSERT INTO training_events (event_id, capacity) VALUES (?,?) ON CONFLICT(event_id) DO UPDATE SET capacity=excluded.capacity")
        ->execute([$result['id'], $capacity]);
    echo json_encode(['ok' => true, 'event_id' => $result['id']]);

// ── Update ────────────────────────────────────────────────────────────────────
} elseif ($action === 'update') {
    $event_id    = trim($body['event_id']    ?? '');
    $title       = trim($body['title']       ?? '');
    $date        = trim($body['date']        ?? '');
    $end_date    = trim($body['end_date']    ?? '');
    $start_time  = trim($body['start_time']  ?? '');
    $end_time    = trim($body['end_time']    ?? '');
    $location    = trim($body['location']    ?? '');
    $description = trim($body['description'] ?? '');
    $capacity    = ($body['capacity'] ?? '') !== '' ? max(0, (int)$body['capacity']) : null;

    if (!$event_id || !$title || !$date) { http_response_code(400); echo json_encode(['error' => 'event_id, title, date required']); exit; }

    if ($start_time && $end_time) {
        $patch = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['dateTime' => $date . 'T' . $start_time . ':00', 'timeZone' => 'America/New_York'],
            'end'   => ['dateTime' => ($end_date ?: $date) . 'T' . $end_time . ':00', 'timeZone' => 'America/New_York'],
        ];
    } else {
        $end = $end_date ?: date('Y-m-d', strtotime($date . ' +1 day'));
        $patch = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['date' => $date],
            'end'   => ['date' => $end],
        ];
    }

    $result = gcal_update_event($cal_id, $token, $event_id, $patch);
    if (!$result) { http_response_code(500); echo json_encode(['error' => 'failed to update event']); exit; }

    $db = local_db();
    $db->prepare("INSERT INTO training_events (event_id, capacity) VALUES (?,?) ON CONFLICT(event_id) DO UPDATE SET capacity=excluded.capacity")
       ->execute([$event_id, $capacity]);

    // Capacity may have gone up (or been removed) — promote waitlisted agents
    // into any now-open seats, oldest first.
    $regCountStmt = $db->prepare("SELECT COUNT(*) FROM training_rsvps WHERE event_id=? AND status='registered'");
    $regCountStmt->execute([$event_id]);
    $regCount = (int)$regCountStmt->fetchColumn();
    $open = $capacity === null ? PHP_INT_MAX : ($capacity - $regCount);

    if ($open > 0) {
        $wait = $db->prepare("SELECT id, agent_email FROM training_rsvps WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT ?");
        $wait->bindValue(1, $event_id);
        $wait->bindValue(2, $open === PHP_INT_MAX ? 1000000 : $open, PDO::PARAM_INT);
        $wait->execute();
        foreach ($wait->fetchAll(PDO::FETCH_ASSOC) as $promoted) {
            $db->prepare("UPDATE training_rsvps SET status='registered' WHERE id=?")->execute([$promoted['id']]);
            try { gcal_add_attendee($cal_id, $token, $event_id, $promoted['agent_email']); } catch (\Throwable $e) {}
            queue_email_to([$promoted['agent_email']], "You're in: {$title}", implode("\n", [
                "A seat opened up — you've been moved from the waitlist to registered for:",
                "",
                $title,
                "Date: {$date}",
                "",
                "— AgentEdge Training",
            ]));
        }
    }

    echo json_encode(['ok' => true]);

// ── Delete ────────────────────────────────────────────────────────────────────
} elseif ($action === 'delete') {
    $event_id = trim($body['event_id'] ?? '');
    if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

    gcal_delete_event($cal_id, $token, $event_id);
    local_db()->prepare("DELETE FROM training_rsvps WHERE event_id=?")->execute([$event_id]);
    local_db()->prepare("DELETE FROM training_events WHERE event_id=?")->execute([$event_id]);
    echo json_encode(['ok' => true]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
}
