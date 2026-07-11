<?php
// Event Planner — internal RSVPs (logged-in agents) and the organizer-facing
// attendee list. Public (non-logged-in) registration lives in ep_public_register.php.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email']));
$name  = $agent['name'] ?? '';

function ep_load_event(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
    $s->execute([$id]);
    $ev = $s->fetch(PDO::FETCH_ASSOC);
    return $ev ?: null;
}

function ep_can_manage_event(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}

function ep_visible_to_agent(array $ev): bool {
    if ($ev['mc_slug'] === '') return true;
    $slugs = array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
    return in_array($ev['mc_slug'], $slugs, true);
}

function ep_reg_total(PDO $db, int $eventId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(1 + guest_count),0) FROM ep_registrations WHERE event_id=? AND status='registered'");
    $s->execute([$eventId]);
    return (int)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error' => 'Missing event_id']); exit; }
    $ev = ep_load_event($db, $eventId);
    if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
    if (!ep_can_manage_event($ev, $email)) { http_response_code(403); echo json_encode(['error' => 'Not authorized']); exit; }

    $rows = $db->prepare("SELECT * FROM ep_registrations WHERE event_id=? ORDER BY registered_at");
    $rows->execute([$eventId]);
    echo json_encode(['registrations' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'register') {
    $eventId = (int)($body['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id']); exit; }
    $ev = ep_load_event($db, $eventId);
    if (!$ev) { echo json_encode(['ok' => false, 'error' => 'Event not found']); exit; }
    if ($ev['status'] !== 'published' || !ep_visible_to_agent($ev)) {
        echo json_encode(['ok' => false, 'error' => 'This event is not open for registration']); exit;
    }

    $guestCount = max(0, min(10, (int)($body['guest_count'] ?? 0)));

    if ($ev['capacity'] !== null) {
        $existing = $db->prepare("SELECT guest_count FROM ep_registrations WHERE event_id=? AND email=? AND status='registered'");
        $existing->execute([$eventId, $email]);
        $priorSeats = ($row = $existing->fetch(PDO::FETCH_ASSOC)) ? (1 + (int)$row['guest_count']) : 0;
        $total = ep_reg_total($db, $eventId) - $priorSeats + 1 + $guestCount;
        if ($total > (int)$ev['capacity']) {
            echo json_encode(['ok' => false, 'error' => 'This event is full']); exit;
        }
    }

    $db->prepare(
        "INSERT INTO ep_registrations (event_id, name, email, phone, guest_count, source, status)
         VALUES (?,?,?,?,?,'internal','registered')
         ON CONFLICT(event_id, email) DO UPDATE SET guest_count=excluded.guest_count, status='registered'"
    )->execute([$eventId, $name, $email, $agent['phone'] ?? '', $guestCount]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'cancel') {
    $eventId = (int)($body['event_id'] ?? 0);
    $regId   = (int)($body['registration_id'] ?? 0);

    if ($regId) {
        $s = $db->prepare("SELECT r.*, e.mc_slug, e.created_by FROM ep_registrations r JOIN ep_events e ON e.id=r.event_id WHERE r.id=?");
        $s->execute([$regId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'Registration not found']); exit; }
        $ev = ['mc_slug' => $row['mc_slug'], 'created_by' => $row['created_by']];
        if (strtolower($row['email']) !== $email && !ep_can_manage_event($ev, $email)) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit;
        }
        $db->prepare("UPDATE ep_registrations SET status='cancelled' WHERE id=?")->execute([$regId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id or registration_id']); exit; }
    $db->prepare("UPDATE ep_registrations SET status='cancelled' WHERE event_id=? AND email=?")->execute([$eventId, $email]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
