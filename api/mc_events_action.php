<?php
// Market-Center events — self-service for MC Leaders/BICs (their own MC(s)
// only) and admins (any MC). Distinct from Industry Events (custom_events),
// which is one shared company-wide list.
//
// GET  ?mc=<slug>            → list events for that MC (must be admin or lead that MC)
// POST action=save           → create/update an event for a target_mc_slug you're allowed to manage
// POST action=delete         → delete one of that MC's events
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!can_post_announcements()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$myEmail  = strtolower(trim($agent['email'] ?? ''));
$isAdmin  = is_admin();
$myMcSlugs = my_mc_slugs();

function mc_events_can_manage(string $slug, bool $isAdmin, array $myMcSlugs): bool {
    return $isAdmin || in_array($slug, $myMcSlugs, true);
}

$db = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = trim($_GET['mc'] ?? '');
    if ($slug === '') { echo json_encode(['error' => 'mc is required']); exit; }
    if (!mc_events_can_manage($slug, $isAdmin, $myMcSlugs)) {
        http_response_code(403); echo json_encode(['error' => 'Not a leader of this Market Center']); exit;
    }
    $st = $db->prepare("SELECT * FROM mc_events WHERE mc_slug=? ORDER BY start_date, start_time");
    $st->execute([$slug]);
    echo json_encode(['ok' => true, 'events' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

if ($action === 'save') {
    $id        = (int)($in['id'] ?? 0);
    $mcSlug    = trim($in['mc_slug'] ?? '');
    $name      = trim($in['name'] ?? '');
    $startDate = trim($in['start_date'] ?? '');
    $endDate   = trim($in['end_date'] ?? '');
    $startTime = trim($in['start_time'] ?? '');
    $endTime   = trim($in['end_time'] ?? '');
    $location  = trim($in['location'] ?? '');
    $url       = trim($in['url'] ?? '');
    $desc      = trim($in['description'] ?? '');

    if (!mc_events_can_manage($mcSlug, $isAdmin, $myMcSlugs)) {
        http_response_code(403); echo json_encode(['error' => 'Not a leader of this Market Center']); exit;
    }
    if ($name === '' || $startDate === '') {
        http_response_code(400); echo json_encode(['error' => 'Event name and start date are required']); exit;
    }

    if ($id > 0) {
        $own = $db->prepare("SELECT mc_slug FROM mc_events WHERE id=?");
        $own->execute([$id]);
        $existingSlug = $own->fetchColumn();
        if ($existingSlug === false) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        if (!mc_events_can_manage($existingSlug, $isAdmin, $myMcSlugs)) {
            http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
        }
        $db->prepare(
            "UPDATE mc_events SET mc_slug=?, name=?, start_date=?, end_date=?, start_time=?, end_time=?, location=?, url=?, description=? WHERE id=?"
        )->execute([$mcSlug, $name, $startDate, $endDate, $startTime, $endTime, $location, $url, $desc, $id]);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    $db->prepare(
        "INSERT INTO mc_events (mc_slug, name, start_date, end_date, start_time, end_time, location, url, description, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([$mcSlug, $name, $startDate, $endDate, $startTime, $endTime, $location, $url, $desc, $myEmail]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    $own = $db->prepare("SELECT mc_slug FROM mc_events WHERE id=?");
    $own->execute([$id]);
    $existingSlug = $own->fetchColumn();
    if ($existingSlug === false) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
    if (!mc_events_can_manage($existingSlug, $isAdmin, $myMcSlugs)) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }
    $db->prepare("DELETE FROM mc_events WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400); echo json_encode(['error' => 'Unknown action']);
