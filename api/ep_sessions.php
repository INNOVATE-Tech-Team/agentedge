<?php
// Event Planner — agenda/session CRUD for a single event. Same visibility rule
// as the parent event: GET is allowed for anyone who can see the event, writes
// are organizer/admin only.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email']));

function ep_sess_load_event(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
    $s->execute([$id]);
    $ev = $s->fetch(PDO::FETCH_ASSOC);
    return $ev ?: null;
}

function ep_sess_can_manage(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}

function ep_sess_visible(array $ev): bool {
    if ($ev['mc_slug'] === '') return true;
    $slugs = array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
    return in_array($ev['mc_slug'], $slugs, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error' => 'Missing event_id']); exit; }
    $ev = ep_sess_load_event($db, $eventId);
    if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
    $manage = ep_sess_can_manage($ev, $email);
    if (!$manage && !($ev['status'] === 'published' && ep_sess_visible($ev))) {
        http_response_code(403); echo json_encode(['error' => 'Not authorized']); exit;
    }
    $rows = $db->prepare("SELECT * FROM ep_sessions WHERE event_id=? ORDER BY session_date, start_time, end_time, sort_ord, id");
    $rows->execute([$eventId]);
    echo json_encode(['sessions' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'create') {
    $eventId = (int)($body['event_id'] ?? 0);
    $title   = trim($body['title'] ?? '');
    if (!$eventId || $title === '') { echo json_encode(['ok' => false, 'error' => 'event_id and title are required']); exit; }
    $ev = ep_sess_load_event($db, $eventId);
    if (!$ev) { echo json_encode(['ok' => false, 'error' => 'Event not found']); exit; }
    if (!ep_sess_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

    $cnt = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM ep_sessions WHERE event_id=?");
    $cnt->execute([$eventId]);
    $sortOrd = (int)$cnt->fetchColumn() + 10;

    $db->prepare(
        "INSERT INTO ep_sessions (event_id, title, description, session_date, start_time, end_time, room, speaker, track, sort_ord)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $eventId, $title, trim($body['description'] ?? ''),
        trim($body['session_date'] ?? '') ?: $ev['start_date'],
        trim($body['start_time'] ?? ''), trim($body['end_time'] ?? ''),
        trim($body['room'] ?? ''), trim($body['speaker'] ?? ''), trim($body['track'] ?? ''), $sortOrd,
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

// update/delete operate on an existing session — load its parent event to authorize.
$sessId = (int)($body['id'] ?? 0);
if (!$sessId) { echo json_encode(['ok' => false, 'error' => 'Missing id.']); exit; }
$sRow = $db->prepare("SELECT * FROM ep_sessions WHERE id=?");
$sRow->execute([$sessId]);
$sess = $sRow->fetch(PDO::FETCH_ASSOC);
if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session not found.']); exit; }
$ev = ep_sess_load_event($db, (int)$sess['event_id']);
if (!$ev || !ep_sess_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

if ($action === 'update') {
    $title = trim($body['title'] ?? '');
    if ($title === '') { echo json_encode(['ok' => false, 'error' => 'Title is required']); exit; }
    $db->prepare(
        "UPDATE ep_sessions SET title=?, description=?, session_date=?, start_time=?, end_time=?, room=?, speaker=?, track=? WHERE id=?"
    )->execute([
        $title, trim($body['description'] ?? ''), trim($body['session_date'] ?? '') ?: $ev['start_date'],
        trim($body['start_time'] ?? ''), trim($body['end_time'] ?? ''),
        trim($body['room'] ?? ''), trim($body['speaker'] ?? ''), trim($body['track'] ?? ''), $sessId,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $db->prepare("DELETE FROM ep_sessions WHERE id=?")->execute([$sessId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
