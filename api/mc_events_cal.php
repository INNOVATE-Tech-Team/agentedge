<?php
// Company Calendar feed for the signed-in agent's Market Center(s) events
// (mc_events, managed by their MC Leader/BIC via mc_events.php). Read-only.
// Scoped to every MC the agent belongs to (own_mc_slug(s)) OR leads
// (mc_slugs) — a leader isn't always also enrolled as a roster member of
// the MC(s) they lead (confirmed live: 8 of 18 mc_leader/bic rows have an
// empty own_mc_slug, including one leading 5 MCs whose own_mc_slug points
// at just their primary office), so membership-only scoping silently hid
// the calendar from most leaders, not just edge cases. Mirrors the same
// own_mc_slugs+mc_slugs union announcements.php already uses. Mirrors
// training_cal.php/events_cal.php's event shape so calendar.js can merge it
// into the existing "Market Center" (scope=market-center) bucket.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$mcSlugs = array_values(array_unique(array_merge(my_own_mc_slugs(), my_mc_slugs())));
if (!$mcSlugs) { echo json_encode(['events' => []]); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

$placeholders = implode(',', array_fill(0, count($mcSlugs), '?'));
$st = local_db()->prepare(
    "SELECT * FROM mc_events
     WHERE mc_slug IN ($placeholders)
       AND strftime('%Y-%m', start_date) = ?
     ORDER BY start_date, start_time"
);
$st->execute([...$mcSlugs, $month]);

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
