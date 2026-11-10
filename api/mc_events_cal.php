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
require_once __DIR__ . '/../lib/mc_event_rsvp.php';
require_once __DIR__ . '/../lib/event_attendees.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$mcSlugs = array_values(array_unique(array_merge(my_own_mc_slugs(), my_mc_slugs())));
// RSVP-enabled events additionally reach agents by active ROSTER market center
// (most agents have no agent_roles row, so $mcSlugs above is empty for them).
// RSVP-off events keep the exact scoping above — nothing changes for them.
$db         = local_db();
$myEmail    = strtolower(trim($agent['email'] ?? ''));
$memberSlugs = mc_rsvp_member_slugs($db, $myEmail);
// Vendorsgiving: no generic admin Edit/Delete popup buttons for Vendorsgiving
// events (managed on the Vendorsgiving page; mc_events_action.php refuses them
// anyway). Every other event's feed entry is unchanged.
// Vendorsgiving events outside the live (pilot) markets, or with the feature off,
// are not shown to anyone but Super Admins.
$vgIds = []; $vgHidden = [];
if (is_file(__DIR__ . '/../lib/vendorsgiving.php')) { require_once __DIR__ . '/../lib/vendorsgiving.php'; $vgIds = vg_event_ids($db); $vgHidden = vg_hidden_event_ids($db, is_super_admin()); }
if (!$mcSlugs && !$memberSlugs) { echo json_encode(['events' => []]); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

// mc_events.mc_slugs is a CSV of every Market Center the event was posted to
// (an event can belong to more than one -- see api/mc_events_action.php) --
// matched here by exact comma-boundary membership in PHP rather than a SQL
// LIKE, which could false-positive on slug substrings (e.g. "wales" inside
// "north-wales").
$st = $db->prepare(
    "SELECT * FROM mc_events WHERE strftime('%Y-%m', start_date) = ? ORDER BY start_date, start_time"
);
$st->execute([$month]);

$events = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
    if ($vgHidden && in_array((int)$e['id'], $vgHidden, true)) continue;
    $eventSlugs = array_values(array_filter(array_map('trim', explode(',', $e['mc_slugs'] ?? ''))));
    $rsvpOn = (int)($e['rsvp_enabled'] ?? 0) === 1;
    if (!array_intersect($eventSlugs, $mcSlugs)) {
        // Not visible under the existing rule. Only an RSVP-enabled event can
        // still reach this viewer, and only through current membership.
        if (!($rsvpOn && array_intersect($eventSlugs, $memberSlugs))) continue;
    }
    $desc = $e['description'];
    if ($e['end_date'] !== '' && $e['end_date'] !== $e['start_date']) {
        $desc = 'Through ' . date('M j, Y', strtotime($e['end_date'])) . ($desc !== '' ? " — {$desc}" : '');
    }
    $item = [
        'date'        => $e['start_date'],
        'title'       => $e['name'],
        'scope'       => 'market-center',
        'description' => $desc,
        'location'    => $e['location'],
        'url'         => $e['url'],
        'time'        => $e['start_time'],
    ];
    if (($e['image_url'] ?? '') !== '') $item['image_url'] = $e['image_url'];
    // Admins / Super Admins can delete any MC event straight from the calendar
    // popup (api/mc_events_action.php already allows is_admin() for delete).
    if (is_admin() && !in_array((int)$e['id'], $vgIds, true)) {
        $item['mc_event_id']    = (int)$e['id'];
        $item['mc_slugs']       = $eventSlugs;
        $item['can_admin_edit'] = true;
    }
    // RSVP fields exist ONLY on RSVP-enabled events, so an RSVP-off event's
    // feed entry is identical to what it always was.
    if ($rsvpOn) {
        $ev = ['slugs' => $eventSlugs] + $e;
        $canManage = event_rsvp_can_manage($db, 'mc', (string)$e['id']);
        $closed    = mc_rsvp_is_past($e);
        $item['mc_event_id']      = (int)$e['id'];
        $item['rsvp_enabled']     = true;
        $item['rsvped']           = mc_rsvp_is_registered($db, (int)$e['id'], $myEmail);
        $item['can_rsvp']         = mc_rsvp_is_eligible($ev, $memberSlugs) && !$closed;
        $item['rsvp_closed']      = $closed;
        $item['can_manage_rsvps'] = $canManage;
        if ($canManage) $item['registered_count'] = mc_rsvp_registered_count($db, (int)$e['id']);
    }
    $events[] = $item;
}

echo json_encode(['events' => $events]);
