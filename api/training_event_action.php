<?php
// Admin-only: create / update / delete training events on Google Calendar.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';
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
    echo json_encode(['ok' => true]);

// ── Delete ────────────────────────────────────────────────────────────────────
} elseif ($action === 'delete') {
    $event_id = trim($body['event_id'] ?? '');
    if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

    gcal_delete_event($cal_id, $token, $event_id);
    local_db()->prepare("DELETE FROM training_rsvps WHERE event_id=?")->execute([$event_id]);
    echo json_encode(['ok' => true]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
}
