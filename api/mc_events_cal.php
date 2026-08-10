<?php
// Company Calendar feed for the signed-in agent's own Market Center's events
// (mc_events, managed by their MC Leader/BIC via mc_events.php). Read-only,
// no MC-leadership required — any agent sees their own MC's events. Mirrors
// training_cal.php/events_cal.php's event shape so calendar.js can merge it
// into the existing "Market Center" (scope=market-center) bucket.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$mcSlug = my_own_mc_slug();
if ($mcSlug === '') { echo json_encode(['events' => []]); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

$st = local_db()->prepare(
    "SELECT * FROM mc_events
     WHERE mc_slug = ?
       AND strftime('%Y-%m', start_date) = ?
     ORDER BY start_date, start_time"
);
$st->execute([$mcSlug, $month]);

$events = array_map(function (array $e): array {
    $desc = $e['description'];
    if ($e['end_date'] !== '' && $e['end_date'] !== $e['start_date']) {
        $desc = 'Through ' . date('M j, Y', strtotime($e['end_date'])) . ($desc !== '' ? " — {$desc}" : '');
    }
    return [
        'date'        => $e['start_date'],
        'title'       => $e['name'],
        'scope'       => 'market-center',
        'description' => $desc,
        'location'    => $e['location'],
        'url'         => $e['url'],
        'time'        => $e['start_time'],
    ];
}, $st->fetchAll(PDO::FETCH_ASSOC));

echo json_encode(['events' => $events]);
