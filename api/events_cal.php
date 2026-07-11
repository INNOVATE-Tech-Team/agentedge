<?php
// Returns Company Calendar "Events" from a dedicated Google Calendar for the
// requested month. Mirrors training_cal.php's shape/behavior exactly, but reads
// a separate calendar and RSVP pool so Events and Training stay independent.
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
$key_file = $c['gcal_key_file']           ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $c['gcal_events_calendar_id'] ?? '';

if ($cal_id === '') { echo json_encode(['events' => []]); exit; }

$token = gcal_access_token($key_file);
if (!$token) { echo json_encode(['events' => []]); exit; }

// Zoom auto-fills the description with a big invite dump (join link, meeting ID,
// passcode, dial-in numbers, SIP info) whenever an event is scheduled as a Zoom
// meeting — the join link already shows via the location line, so drop this
// boilerplate rather than showing the raw dial-in text under every event.
function strip_zoom_invite_boilerplate(string $desc): string {
    if (preg_match('/is inviting you to a scheduled Zoom meeting/i', $desc)) return '';
    return $desc;
}

// Gmail/Google Calendar sometimes wrap a pasted link in a google.com/url click-tracking
// redirect — occasionally double-encoded — instead of storing the plain URL. Unwrap it
// back to the real link, preferring a clean Zoom join URL if one is embedded in there.
function unwrap_google_redirect(string $text): string {
    return preg_replace_callback('#https?://(?:www\.)?google\.com/url\?q=(\S+)#i', function ($m) {
        $decoded = $m[1];
        for ($i = 0; $i < 3; $i++) {
            $next = urldecode($decoded);
            if ($next === $decoded) break;
            $decoded = $next;
        }
        if (preg_match('#https?://[a-z0-9.-]*zoom\.us/j/\d+(?:\?pwd=[A-Za-z0-9._-]+)?#i', $decoded, $zm)) {
            return $zm[0];
        }
        return preg_match('#^https?://\S+#i', $decoded, $um) ? $um[0] : $m[0];
    }, $text);
}

$time_min = sprintf('%04d-%02d-01T00:00:00Z', $year, $mon);
$last_day = (int)date('t', mktime(0, 0, 0, $mon, 1, $year));
$time_max = sprintf('%04d-%02d-%02dT23:59:59Z', $year, $mon, $last_day);

$items = gcal_events($cal_id, $token, $time_min, $time_max);

// Load this agent's RSVP status, per-event registered counts, and capacities
// so the calendar can show Register / Registered / Waitlisted correctly.
$email = strtolower(trim($agent['email'] ?? ''));
$myStatus = [];
if ($email) {
    $stmt = local_db()->prepare("SELECT event_id, status FROM events_rsvps WHERE agent_email=?");
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $myStatus[$r['event_id']] = $r['status'];
}

$regCounts = [];
foreach (local_db()->query("SELECT event_id, COUNT(*) AS cnt FROM events_rsvps WHERE status='registered' GROUP BY event_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $regCounts[$r['event_id']] = (int)$r['cnt'];
}

$capacities = [];
foreach (local_db()->query("SELECT event_id, capacity FROM events_calendar")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $capacities[$r['event_id']] = $r['capacity'] !== null ? (int)$r['capacity'] : null;
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

    $location = unwrap_google_redirect(trim($item['location'] ?? ''));
    $description = isset($item['description'])
        ? unwrap_google_redirect(strip_zoom_invite_boilerplate(strip_tags($item['description'])))
        : '';
    // Avoid showing the same link twice — drop it from the description if the
    // clean location line already carries it.
    if ($location !== '' && str_ends_with(trim($description), $location)) {
        $description = trim(substr($description, 0, strrpos($description, $location)));
    }

    $events[] = [
        'date'        => $start,
        'title'       => $item['summary'] ?? 'Event',
        'scope'       => 'events',
        'description' => $description,
        'location'    => $location,
        'gcal_id'     => $gcal_id,
        'rsvped'      => ($myStatus[$gcal_id] ?? null) === 'registered',
        'waitlisted'  => ($myStatus[$gcal_id] ?? null) === 'waitlisted',
        'capacity'    => $capacities[$gcal_id] ?? null,
        'registered_count' => $regCounts[$gcal_id] ?? 0,
        'is_all_day'  => $is_all_day,
        'start_dt'    => $start_dt,
        'end_dt'      => $end_dt,
        'gcal_link'   => $item['htmlLink'] ?? '',
    ];
}

echo json_encode(['events' => $events]);
