<?php
// Returns training events from Google Calendar for the requested month.
// Each event includes rsvped:true/false for the signed-in agent.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
[$year, $mon] = array_map('intval', explode('-', $month));

$c        = cfg();
$key_file = $c['gcal_key_file']    ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $c['gcal_calendar_id'] ?? 'training@innovateonline.com';

$token = gcal_access_token($key_file);
if (!$token) { echo json_encode(['events' => []]); exit; }

$time_min = sprintf('%04d-%02d-01T00:00:00Z', $year, $mon);
$last_day = (int)date('t', mktime(0, 0, 0, $mon, 1, $year));
$time_max = sprintf('%04d-%02d-%02dT23:59:59Z', $year, $mon, $last_day);

$items = gcal_events($cal_id, $token, $time_min, $time_max);

// Load this agent's RSVPs so we can mark each event
$email = strtolower(trim($agent['email'] ?? ''));
$rsvped = [];
if ($email) {
    $stmt = local_db()->prepare("SELECT event_id FROM training_rsvps WHERE agent_email=?");
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $rsvped[$id] = true;
}

$events = [];
foreach ($items as $item) {
    if (($item['status'] ?? '') === 'cancelled') continue;
    $start = $item['start']['date'] ?? substr($item['start']['dateTime'] ?? '', 0, 10);
    if (!$start) continue;

    $gcal_id    = $item['id'] ?? '';
    $is_all_day = isset($item['start']['date']);
    $start_dt   = $item['start']['date'] ?? ($item['start']['dateTime'] ?? '');
    $end_raw    = $item['end']['date']   ?? ($item['end']['dateTime']   ?? '');
    // All-day end from Google is exclusive next day; normalize to last day for display
    $end_dt = ($is_all_day && $end_raw)
        ? date('Y-m-d', strtotime($end_raw . ' -1 day'))
        : $end_raw;

    $events[] = [
        'date'        => $start,
        'title'       => $item['summary'] ?? 'Training Event',
        'scope'       => 'training',
        'description' => isset($item['description']) ? strip_tags($item['description']) : '',
        'location'    => $item['location'] ?? '',
        'gcal_id'     => $gcal_id,
        'rsvped'      => isset($rsvped[$gcal_id]),
        'is_all_day'  => $is_all_day,
        'start_dt'    => $start_dt,
        'end_dt'      => $end_dt,
        'gcal_link'   => $item['htmlLink'] ?? '',
    ];
}

echo json_encode(['events' => $events]);
