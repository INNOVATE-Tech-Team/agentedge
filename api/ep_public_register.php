<?php
// Event Planner — PUBLIC registration endpoint. No login required.
// Reachable only via the unguessable per-event public_token; only ever exposes
// or accepts registrations for events that are currently 'published'.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$db = local_db();

function ep_pub_reg_total(PDO $db, int $eventId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(1 + guest_count),0) FROM ep_registrations WHERE event_id=? AND status='registered'");
    $s->execute([$eventId]);
    return (int)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['t'] ?? '');
    if ($token === '') { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $s = $db->prepare("SELECT * FROM ep_events WHERE public_token=?");
    $s->execute([$token]);
    $ev = $s->fetch(PDO::FETCH_ASSOC);
    if (!$ev || $ev['status'] === 'draft') { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $sessRows = $db->prepare("SELECT title, description, session_date, start_time, end_time, room, speaker, track FROM ep_sessions WHERE event_id=? ORDER BY session_date, start_time, end_time, sort_ord, id");
    $sessRows->execute([$ev['id']]);

    $recRows = $db->prepare("SELECT name, category, description, url FROM ep_recommendations WHERE event_id=? ORDER BY sort_ord, id");
    $recRows->execute([$ev['id']]);

    echo json_encode(['event' => [
        'title'       => $ev['title'],
        'description' => $ev['description'],
        'location'    => $ev['location'],
        'start_date'  => $ev['start_date'],
        'end_date'    => $ev['end_date'],
        'start_time'  => $ev['start_time'],
        'status'      => $ev['status'],
        'capacity'    => $ev['capacity'] !== null ? (int)$ev['capacity'] : null,
        'registered'  => ep_pub_reg_total($db, (int)$ev['id']),
        'image_url'   => $ev['image_key'] ? ('api/ep_event_image.php?key=' . urlencode($ev['image_key'])) : null,
        'sessions'    => $sessRows->fetchAll(PDO::FETCH_ASSOC),
        'recommendations' => $recRows->fetchAll(PDO::FETCH_ASSOC),
        'room_block'  => $ev['room_block_hotel'] ? [
            'hotel'  => $ev['room_block_hotel'],
            'rate'   => $ev['room_block_rate'],
            'code'   => $ev['room_block_code'],
            'url'    => $ev['room_block_url'],
            'cutoff' => $ev['room_block_cutoff'],
        ] : null,
    ]]);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($body['token'] ?? '');

if ($token === '') { echo json_encode(['ok' => false, 'error' => 'Missing token']); exit; }

$s = $db->prepare("SELECT * FROM ep_events WHERE public_token=?");
$s->execute([$token]);
$ev = $s->fetch(PDO::FETCH_ASSOC);
if (!$ev || $ev['status'] !== 'published') {
    echo json_encode(['ok' => false, 'error' => 'This event is not open for registration']); exit;
}

// Honeypot — a real visitor never fills this hidden field. Report success without
// writing anything so a bot can't tell its submission was dropped.
if (trim($body['hp'] ?? '') !== '') { echo json_encode(['ok' => true]); exit; }

$name  = trim(mb_substr($body['name'] ?? '', 0, 200));
$email = strtolower(trim(mb_substr($body['email'] ?? '', 0, 200)));
$phone = trim(mb_substr($body['phone'] ?? '', 0, 40));
$guestCount = max(0, min(10, (int)($body['guest_count'] ?? 0)));

if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Name is required.']); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'error' => 'A valid email is required.']); exit; }

if ($ev['capacity'] !== null) {
    $existing = $db->prepare("SELECT guest_count FROM ep_registrations WHERE event_id=? AND email=? AND status='registered'");
    $existing->execute([$ev['id'], $email]);
    $priorSeats = ($row = $existing->fetch(PDO::FETCH_ASSOC)) ? (1 + (int)$row['guest_count']) : 0;
    $total = ep_pub_reg_total($db, (int)$ev['id']) - $priorSeats + 1 + $guestCount;
    if ($total > (int)$ev['capacity']) {
        echo json_encode(['ok' => false, 'error' => 'Sorry, this event is full.']); exit;
    }
}

$db->prepare(
    "INSERT INTO ep_registrations (event_id, name, email, phone, guest_count, source, status)
     VALUES (?,?,?,?,?,'public','registered')
     ON CONFLICT(event_id, email) DO UPDATE SET name=excluded.name, phone=excluded.phone, guest_count=excluded.guest_count, status='registered'"
)->execute([$ev['id'], $name, $email, $phone, $guestCount]);

echo json_encode(['ok' => true]);
