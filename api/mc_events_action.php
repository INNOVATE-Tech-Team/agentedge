<?php
// Market-Center events — self-service for MC Leaders/BICs (their own MC(s)
// only) and admins (any MC). Distinct from Industry Events (custom_events),
// which is one shared company-wide list. An event can belong to MULTIPLE
// market centers at once (e.g. a BIC leading 5 PA offices posting one
// training session that applies to all of them) — mc_events.mc_slugs is a
// CSV of every MC it's posted to, not a single slug. Confirmed 2026-09-24:
// Melanie Henderson (BIC, 5 PA offices) needed exactly this and the
// original single-mc_slug design had no way to do it short of creating the
// same event 5 separate times.
//
// GET  ?mc=<slug1,slug2,...>  → list events belonging to ANY of those MCs (must be admin or lead every one requested)
// POST action=save            → create/update an event for a set of mc_slugs you're allowed to manage (every slug, not just one)
// POST action=delete          → delete an event (must be able to manage every MC it's posted to)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/mc_event_rsvp.php';
require_once __DIR__ . '/../lib/event_attendees.php';
require_once __DIR__ . '/../lib/mc_event_access.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!can_post_announcements()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$myEmail   = strtolower(trim($agent['email'] ?? ''));
$isAdmin   = is_admin();
// Own MCs plus any a Super Admin approved via mc_events.php's access request.
$myMcSlugs = mc_access_effective_slugs(local_db(), $myEmail, my_event_mc_slugs());

function mc_events_slugs_array(string $csv): array {
    return array_values(array_filter(array_map('trim', explode(',', $csv))));
}

// True only if the caller is allowed to manage EVERY slug in $slugs — a
// shared multi-MC event should never be editable/deletable by someone who
// only leads a subset of the MCs it's posted to.
function mc_events_can_manage_all(array $slugs, bool $isAdmin, array $myMcSlugs): bool {
    if ($isAdmin) return true;
    if (!$slugs) return false;
    foreach ($slugs as $s) {
        if (!in_array($s, $myMcSlugs, true)) return false;
    }
    return true;
}

// Validates the requested slugs against real enabled market_centers AND
// (for non-admins) rejects the whole request if any slug is outside the
// caller's own leadership, rather than silently dropping it — a BIC should
// see a clear error, not a quietly-shrunk event.
function mc_events_sanitize_slugs(PDO $db, array $requested, bool $isAdmin, array $myMcSlugs): array {
    $requested = array_values(array_unique(array_filter(array_map('strval', $requested))));
    if (!$requested) return ['error' => 'Select at least one Market Center'];
    if (!$isAdmin) {
        foreach ($requested as $s) {
            if (!in_array($s, $myMcSlugs, true)) return ['error' => 'You can only post events for Market Centers you lead'];
        }
    }
    $ph = implode(',', array_fill(0, count($requested), '?'));
    $st = $db->prepare("SELECT slug FROM market_centers WHERE enabled=1 AND slug IN ($ph)");
    $st->execute($requested);
    $valid = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($valid) !== count($requested)) return ['error' => 'One or more selected Market Centers are invalid'];
    return ['slugs' => $valid];
}

$db = local_db();

// Vendorsgiving guard: events with a vg_events row are managed only through the
// Vendorsgiving organizer workflow (one market, settings, potluck, vendors). The
// generic save/delete below refuses them; ordinary events are unaffected.
$vgIds = []; $vgHidden = [];
if (is_file(__DIR__ . '/../lib/vendorsgiving.php')) {
    require_once __DIR__ . '/../lib/vendorsgiving.php';
    $vgIds = vg_event_ids($db);   // whenever vg_events exists (not only when every Vendorsgiving table is installed)
    $vgHidden = vg_hidden_event_ids($db, is_super_admin());   // non-pilot markets / feature off: listed for Super Admins only
}
function mc_events_vg_refuse(int $id): void {
    http_response_code(409);
    echo json_encode(['error' => 'This is a Vendorsgiving event — edit or cancel it from the Vendorsgiving page.', 'vendorsgiving_url' => 'vendorsgiving.php?id=' . $id]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requested = mc_events_slugs_array(trim($_GET['mc'] ?? ''));
    if (!$requested) { echo json_encode(['error' => 'mc is required']); exit; }
    foreach ($requested as $s) {
        if (!mc_events_can_manage_all([$s], $isAdmin, $myMcSlugs)) {
            http_response_code(403); echo json_encode(['error' => 'Not a leader of this Market Center']); exit;
        }
    }
    // Fetch every event whose mc_slugs intersects the requested set, then
    // do the precise (comma-boundary-safe) membership check in PHP.
    $rows = $db->query("SELECT * FROM mc_events ORDER BY start_date, start_time")->fetchAll(PDO::FETCH_ASSOC);
    $events = [];
    foreach ($rows as $r) {
        if ($vgHidden && in_array((int)$r['id'], $vgHidden, true)) continue;
        $eventSlugs = mc_events_slugs_array($r['mc_slugs'] ?? '');
        if (array_intersect($eventSlugs, $requested)) {
            $r['mc_slugs'] = $eventSlugs;
            // RSVP info for the management page. can_manage_rsvps is about the
            // attendee list only (creator / Staff / Super Admin / a leader of any
            // of this event's MCs) — it is NOT edit permission.
            $r['rsvp_count'] = mc_rsvp_registered_count($db, (int)$r['id']);
            $r['can_manage_rsvps'] = (int)$r['rsvp_enabled'] === 1 && event_rsvp_can_manage($db, 'mc', (string)$r['id']);
            // Added ONLY for Vendorsgiving events, so ordinary rows are byte-identical.
            if (in_array((int)$r['id'], $vgIds, true)) { $r['vendorsgiving'] = true; $r['vendorsgiving_url'] = 'vendorsgiving.php?id=' . (int)$r['id']; }
            $events[] = $r;
        }
    }
    echo json_encode(['ok' => true, 'events' => $events]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

// Optional event image: multipart POST, returns the URL to send back with the
// save. The file is stored under a random key; an unused upload is harmless.
if (($_POST['action'] ?? '') === 'upload_image') {
    $f = $_FILES['image'] ?? null;
    if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        http_response_code(400); echo json_encode(['error' => 'No image received (it may be over the size limit)']); exit;
    }
    $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $mime  = mime_content_type($f['tmp_name']);
    if (!isset($types[$mime])) { http_response_code(400); echo json_encode(['error' => 'Image must be a JPG, PNG, GIF or WebP']); exit; }
    if ($f['size'] > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'Image must be 8MB or smaller']); exit; }
    $cfgDir = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dir = ($cfgDir ?: (__DIR__ . '/../data')) . '/mc_event_images';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $key = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $key)) {
        http_response_code(500); echo json_encode(['error' => 'Could not store the image']); exit;
    }
    echo json_encode(['ok' => true, 'image_url' => 'api/mc_event_image.php?key=' . $key]);
    exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

if ($action === 'save') {
    $id        = (int)($in['id'] ?? 0);
    if ($id > 0 && in_array($id, $vgIds, true)) mc_events_vg_refuse($id);   // before any other validation
    $name      = trim($in['name'] ?? '');
    $startDate = trim($in['start_date'] ?? '');
    $endDate   = trim($in['end_date'] ?? '');
    $startTime = trim($in['start_time'] ?? '');
    $endTime   = trim($in['end_time'] ?? '');
    $location  = trim($in['location'] ?? '');
    $url       = trim($in['url'] ?? '');
    $desc      = trim($in['description'] ?? '');
    // Optional RSVP. Absent key (older page / API caller) leaves an existing
    // event's setting alone; new events default to off.
    $hasRsvpKey  = array_key_exists('rsvp_enabled', $in);
    $rsvpEnabled = !empty($in['rsvp_enabled']) ? 1 : 0;

    // Optional image. Absent key leaves an existing event's image alone; ''
    // removes it. Only URLs minted by the upload action above are accepted.
    $hasImageKey = array_key_exists('image_url', $in);
    $imageUrl    = trim((string)($in['image_url'] ?? ''));
    if ($imageUrl !== '' && !preg_match('/^api\/mc_event_image\.php\?key=[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $imageUrl)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid image']); exit;
    }

    $sanitized = mc_events_sanitize_slugs($db, (array)($in['mc_slugs'] ?? []), $isAdmin, $myMcSlugs);
    if (isset($sanitized['error'])) { http_response_code(400); echo json_encode(['error' => $sanitized['error']]); exit; }
    $mcSlugsCsv = implode(',', $sanitized['slugs']);

    if ($name === '' || $startDate === '') {
        http_response_code(400); echo json_encode(['error' => 'Event name and start date are required']); exit;
    }

    if ($id > 0) {
        $own = $db->prepare("SELECT mc_slugs FROM mc_events WHERE id=?");
        $own->execute([$id]);
        $existingCsv = $own->fetchColumn();
        if ($existingCsv === false) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        if (!mc_events_can_manage_all(mc_events_slugs_array($existingCsv), $isAdmin, $myMcSlugs)) {
            http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
        }
        // Adding or removing market centers on an EXISTING event is Super Admin
        // only (leaders, BICs, Staff and the creator can edit everything else).
        // Enforced here, not just by disabling the checkboxes in the UI.
        if (!mc_events_slug_change_allowed(mc_events_slugs_array($existingCsv), $sanitized['slugs'], is_super_admin())) {
            http_response_code(403);
            echo json_encode(['error' => "Only a Super Admin can change an existing event's Market Centers."]);
            exit;
        }
        $db->prepare(
            "UPDATE mc_events SET mc_slugs=?, name=?, start_date=?, end_date=?, start_time=?, end_time=?, location=?, url=?, description=?"
            . ($hasRsvpKey ? ", rsvp_enabled=?" : "") . ($hasImageKey ? ", image_url=?" : "") . " WHERE id=?"
        )->execute(array_merge([$mcSlugsCsv, $name, $startDate, $endDate, $startTime, $endTime, $location, $url, $desc], $hasRsvpKey ? [$rsvpEnabled] : [], $hasImageKey ? [$imageUrl] : [], [$id]));
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    $db->prepare(
        "INSERT INTO mc_events (mc_slugs, name, start_date, end_date, start_time, end_time, location, url, description, created_by, rsvp_enabled, image_url)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$mcSlugsCsv, $name, $startDate, $endDate, $startTime, $endTime, $location, $url, $desc, $myEmail, $rsvpEnabled, $imageUrl]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (in_array($id, $vgIds, true)) mc_events_vg_refuse($id);
    $own = $db->prepare("SELECT mc_slugs FROM mc_events WHERE id=?");
    $own->execute([$id]);
    $existingCsv = $own->fetchColumn();
    if ($existingCsv === false) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
    if (!mc_events_can_manage_all(mc_events_slugs_array($existingCsv), $isAdmin, $myMcSlugs)) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }
    $db->prepare("DELETE FROM mc_events WHERE id=?")->execute([$id]);
    mc_rsvp_delete_for_event($db, $id);   // no orphaned registrations
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400); echo json_encode(['error' => 'Unknown action']);
