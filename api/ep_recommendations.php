<?php
// Event Planner — "things to do nearby" cards for a single event. Same
// visibility/authorization shape as api/ep_sessions.php.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email']));

const EP_REC_CATEGORIES = ['food', 'attraction', 'nightlife', 'shopping', 'other'];

function ep_rec_load_event(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
    $s->execute([$id]);
    $ev = $s->fetch(PDO::FETCH_ASSOC);
    return $ev ?: null;
}

function ep_rec_can_manage(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}

function ep_rec_visible(array $ev): bool {
    if ($ev['mc_slug'] === '') return true;
    $slugs = array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
    return in_array($ev['mc_slug'], $slugs, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error' => 'Missing event_id']); exit; }
    $ev = ep_rec_load_event($db, $eventId);
    if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
    $manage = ep_rec_can_manage($ev, $email);
    if (!$manage && !($ev['status'] === 'published' && ep_rec_visible($ev))) {
        http_response_code(403); echo json_encode(['error' => 'Not authorized']); exit;
    }
    $rows = $db->prepare("SELECT * FROM ep_recommendations WHERE event_id=? ORDER BY sort_ord, id");
    $rows->execute([$eventId]);
    echo json_encode(['recommendations' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

function ep_rec_category(array $body): string {
    $c = $body['category'] ?? '';
    return in_array($c, EP_REC_CATEGORIES, true) ? $c : 'other';
}

if ($action === 'create') {
    $eventId = (int)($body['event_id'] ?? 0);
    $name    = trim($body['name'] ?? '');
    if (!$eventId || $name === '') { echo json_encode(['ok' => false, 'error' => 'event_id and name are required']); exit; }
    $ev = ep_rec_load_event($db, $eventId);
    if (!$ev) { echo json_encode(['ok' => false, 'error' => 'Event not found']); exit; }
    if (!ep_rec_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

    $cnt = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM ep_recommendations WHERE event_id=?");
    $cnt->execute([$eventId]);
    $sortOrd = (int)$cnt->fetchColumn() + 10;

    $db->prepare(
        "INSERT INTO ep_recommendations (event_id, name, category, description, url, sort_ord) VALUES (?,?,?,?,?,?)"
    )->execute([$eventId, $name, ep_rec_category($body), trim($body['description'] ?? ''), trim($body['url'] ?? ''), $sortOrd]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

$recId = (int)($body['id'] ?? 0);
if (!$recId) { echo json_encode(['ok' => false, 'error' => 'Missing id.']); exit; }
$rRow = $db->prepare("SELECT * FROM ep_recommendations WHERE id=?");
$rRow->execute([$recId]);
$rec = $rRow->fetch(PDO::FETCH_ASSOC);
if (!$rec) { echo json_encode(['ok' => false, 'error' => 'Recommendation not found.']); exit; }
$ev = ep_rec_load_event($db, (int)$rec['event_id']);
if (!$ev || !ep_rec_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

if ($action === 'update') {
    $name = trim($body['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Name is required']); exit; }
    $db->prepare(
        "UPDATE ep_recommendations SET name=?, category=?, description=?, url=? WHERE id=?"
    )->execute([$name, ep_rec_category($body), trim($body['description'] ?? ''), trim($body['url'] ?? ''), $recId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $db->prepare("DELETE FROM ep_recommendations WHERE id=?")->execute([$recId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
