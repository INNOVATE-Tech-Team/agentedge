<?php
// Admin-only: remove a registrant/waitlistee from a training or company "events"
// RSVP, from the aggregated Event RSVPs dashboard. Mirrors the self-service
// cancel path in event_rsvp.php/training_rsvp.php, including waitlist promotion.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent)     { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']);    exit; }

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$scope = trim($body['scope'] ?? '');
$id    = (int)($body['id'] ?? 0);

if (!in_array($scope, ['training', 'events'], true) || !$id) {
    http_response_code(400); echo json_encode(['error' => 'missing scope or id']); exit;
}

$table = $scope === 'training' ? 'training_rsvps' : 'events_rsvps';
$db    = local_db();

$stmt = $db->prepare("SELECT event_id, event_title, event_date, status FROM $table WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

$db->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);

if ($row['status'] === 'registered') {
    $next = $db->prepare(
        "SELECT id, agent_email FROM $table WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT 1"
    );
    $next->execute([$row['event_id']]);
    if ($promoted = $next->fetch(PDO::FETCH_ASSOC)) {
        $db->prepare("UPDATE $table SET status='registered' WHERE id=?")->execute([$promoted['id']]);
        queue_email_to([$promoted['agent_email']], "You're in: {$row['event_title']}", implode("\n", [
            "A seat opened up — you've been moved from the waitlist to registered for:",
            "",
            $row['event_title'],
            "Date: {$row['event_date']}",
            "",
            "— AgentEdge",
        ]));
    }
}

echo json_encode(['ok' => true]);
