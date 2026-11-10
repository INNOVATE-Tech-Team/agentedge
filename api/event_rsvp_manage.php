<?php
// On-demand attendee list for the Events / Training RSVP area on the Company
// Calendar. Allowed for Staff / Super Admin and the event's recorded creator
// (event_rsvp_can_manage) — not for someone who merely RSVP'd.
// GET ?scope=events|training|mc&event_id=<gcal id, or mc_events.id for mc>
//   → {ok, count, attendees:[{name,email,phone,mc}], can_email, email_url}
// Registered only (no waitlist). Read-only; sends nothing.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/event_attendees.php';
header('Content-Type: application/json');

if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$scope = $_GET['scope'] ?? '';
if (!event_rsvp_scope_valid($scope)) { http_response_code(400); echo json_encode(['error' => 'unknown scope']); exit; }   // never falls through to another scope
$eid   = trim($_GET['event_id'] ?? '');
if ($eid === '') { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

$db = local_db();
if (!event_rsvp_can_manage($db, $scope, $eid)) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
// MC Events: attendee management only exists while RSVP is switched on.
if (!event_rsvp_enabled($db, $scope, $eid)) { http_response_code(403); echo json_encode(['error' => 'RSVP is not enabled for this event']); exit; }

$attendees = event_rsvp_attendees($db, $scope, $eid);
echo json_encode([
    'ok'        => true,
    'count'     => count($attendees),
    'attendees' => $attendees,
    // The compose screen is the existing Company Email page, which has its own
    // access gate; only offer the button to people who can actually open it.
    'can_email' => can_send_company_email(),
    'email_url' => 'backoffice_email.php?event_scope=' . $scope . '&event_id=' . rawurlencode($eid),
]);
