<?php
// Event Planner — event CRUD. Market Center leaders/BICs manage their own MC's
// events; admins (super_admin/staff) manage or oversee everything, including
// company-wide conferences (mc_slug = '').
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$db    = local_db();
$email = strtolower(trim($agent['email']));

function ep_my_mc_slugs(): array {
    return array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
}

function ep_can_manage(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}

function ep_reg_count(PDO $db, int $eventId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(1 + guest_count),0) FROM ep_registrations WHERE event_id=? AND status='registered'");
    $s->execute([$eventId]);
    return (int)$s->fetchColumn();
}

// ── Hero image helpers ──────────────────────────────────────────────────────
function ep_img_dir(): string {
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
    $dir     = $dataDir . '/ep_event_images';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function save_ep_event_image(): string {
    if (empty($_FILES['image']['tmp_name'])) return '';
    $file    = $_FILES['image'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return '';
    if ($file['size'] > 8 * 1024 * 1024) return '';
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime] ?? 'jpg';
    $key = bin2hex(random_bytes(16)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], ep_img_dir() . '/' . $key);
    return $key;
}

function delete_ep_event_image(string $key): void {
    if ($key === '') return;
    $path = ep_img_dir() . '/' . $key;
    if (file_exists($path)) @unlink($path);
}

function ep_validate(array $d): ?string {
    if (trim($d['title'] ?? '') === '') return 'Title is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start_date'] ?? '')) return 'Start date must be YYYY-MM-DD.';
    if (!empty($d['end_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end_date'])) return 'End date must be YYYY-MM-DD.';
    if (isset($d['capacity']) && $d['capacity'] !== '' && $d['capacity'] !== null) {
        if (!ctype_digit((string)$d['capacity']) || (int)$d['capacity'] < 1) return 'Capacity must be a positive number.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $visibleSlugs = ep_my_mc_slugs();

    if ($id) {
        $s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
        $s->execute([$id]);
        $ev = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
        $manage = ep_can_manage($ev, $email);
        $visible = is_admin() || $manage
            || ($ev['status'] === 'published' && ($ev['mc_slug'] === '' || in_array($ev['mc_slug'], $visibleSlugs, true)));
        if (!$visible) { http_response_code(403); echo json_encode(['error' => 'Not authorized']); exit; }
        $ev['capacity']       = $ev['capacity'] !== null ? (int)$ev['capacity'] : null;
        $ev['registered']     = ep_reg_count($db, $id);
        $ev['can_manage']     = $manage;
        $ev['image_url']      = ep_image_url($ev);
        echo json_encode(['event' => $ev]);
        exit;
    }

    $rows = $db->query("SELECT * FROM ep_events ORDER BY start_date, id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $ev) {
        $manage = ep_can_manage($ev, $email);
        $visible = is_admin() || $manage
            || ($ev['status'] === 'published' && ($ev['mc_slug'] === '' || in_array($ev['mc_slug'], $visibleSlugs, true)));
        if (!$visible) continue;
        $ev['capacity']   = $ev['capacity'] !== null ? (int)$ev['capacity'] : null;
        $ev['registered'] = ep_reg_count($db, (int)$ev['id']);
        $ev['can_manage'] = $manage;
        $ev['image_url']  = ep_image_url($ev);
        $out[] = $ev;
    }
    echo json_encode(['events' => $out]);
    exit;
}

$isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$body = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
$action = $body['action'] ?? '';

function ep_image_url(array $ev): ?string {
    return $ev['image_key'] ? ('api/ep_event_image.php?key=' . urlencode($ev['image_key'])) : null;
}

if ($action === 'create') {
    if (!is_leader()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized to create events']); exit; }
    if ($err = ep_validate($body)) { echo json_encode(['ok' => false, 'error' => $err]); exit; }

    $mcSlug = trim($body['mc_slug'] ?? '');
    if (is_admin()) {
        $eventType = $mcSlug === '' ? 'conference' : 'mc_award';
    } else {
        if ($mcSlug === '' || !in_array($mcSlug, my_mc_slugs(), true)) {
            echo json_encode(['ok' => false, 'error' => 'You can only create events for a Market Center you lead.']);
            exit;
        }
        $eventType = 'mc_award';
    }

    $capacity = (isset($body['capacity']) && $body['capacity'] !== '' && $body['capacity'] !== null) ? (int)$body['capacity'] : null;
    $token = bin2hex(random_bytes(16));
    $imageKey = save_ep_event_image();

    $db->prepare(
        "INSERT INTO ep_events (title, event_type, mc_slug, description, location, start_date, end_date, start_time, capacity, public_token, created_by, image_key)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        trim($body['title']), $eventType, $mcSlug, trim($body['description'] ?? ''), trim($body['location'] ?? ''),
        trim($body['start_date']), trim($body['end_date'] ?? ''), trim($body['start_time'] ?? ''),
        $capacity, $token, $email, $imageKey,
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

// All remaining actions operate on an existing event — load + authorize once.
$id = (int)($body['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id.']); exit; }
$s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
$s->execute([$id]);
$ev = $s->fetch(PDO::FETCH_ASSOC);
if (!$ev) { echo json_encode(['ok' => false, 'error' => 'Event not found.']); exit; }
if (!ep_can_manage($ev, $email)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

if ($action === 'update') {
    if ($err = ep_validate($body)) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
    $capacity = (isset($body['capacity']) && $body['capacity'] !== '' && $body['capacity'] !== null) ? (int)$body['capacity'] : null;
    // mc_slug/event_type are fixed at creation — an organizer cannot move an event to another MC or make it company-wide.
    $db->prepare(
        "UPDATE ep_events SET title=?, description=?, location=?, start_date=?, end_date=?, start_time=?, capacity=?,
                              room_block_hotel=?, room_block_rate=?, room_block_code=?, room_block_url=?, room_block_cutoff=?,
                              updated_at=datetime('now')
         WHERE id=?"
    )->execute([
        trim($body['title']), trim($body['description'] ?? ''), trim($body['location'] ?? ''),
        trim($body['start_date']), trim($body['end_date'] ?? ''), trim($body['start_time'] ?? ''),
        $capacity,
        trim($body['room_block_hotel'] ?? ''), trim($body['room_block_rate'] ?? ''), trim($body['room_block_code'] ?? ''),
        trim($body['room_block_url'] ?? ''), trim($body['room_block_cutoff'] ?? ''),
        $id,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'clone') {
    $newToken = bin2hex(random_bytes(16));
    $newImageKey = '';
    if ($ev['image_key'] !== '') {
        $srcPath = ep_img_dir() . '/' . $ev['image_key'];
        if (file_exists($srcPath)) {
            $ext = pathinfo($ev['image_key'], PATHINFO_EXTENSION);
            $newImageKey = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
            @copy($srcPath, ep_img_dir() . '/' . $newImageKey);
        }
    }

    $db->prepare(
        "INSERT INTO ep_events (title, event_type, mc_slug, description, location, start_date, end_date, start_time, capacity, public_token, created_by, image_key,
                                 room_block_hotel, room_block_rate, room_block_code, room_block_url, room_block_cutoff)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $ev['title'] . ' (Copy)', $ev['event_type'], $ev['mc_slug'], $ev['description'], $ev['location'],
        $ev['start_date'], $ev['end_date'], $ev['start_time'], $ev['capacity'], $newToken, $email, $newImageKey,
        $ev['room_block_hotel'], $ev['room_block_rate'], $ev['room_block_code'], $ev['room_block_url'], $ev['room_block_cutoff'],
    ]);
    $newId = (int)$db->lastInsertId();

    $sessRows = $db->prepare("SELECT * FROM ep_sessions WHERE event_id=? ORDER BY session_date, start_time, end_time, sort_ord, id");
    $sessRows->execute([$id]);
    $insSess = $db->prepare(
        "INSERT INTO ep_sessions (event_id, title, description, session_date, start_time, end_time, room, speaker, track, sort_ord)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($sessRows->fetchAll(PDO::FETCH_ASSOC) as $sess) {
        $insSess->execute([
            $newId, $sess['title'], $sess['description'], $sess['session_date'],
            $sess['start_time'], $sess['end_time'], $sess['room'], $sess['speaker'], $sess['track'], $sess['sort_ord'],
        ]);
    }

    $recRows = $db->prepare("SELECT * FROM ep_recommendations WHERE event_id=? ORDER BY sort_ord, id");
    $recRows->execute([$id]);
    $insRec = $db->prepare(
        "INSERT INTO ep_recommendations (event_id, name, category, description, url, sort_ord) VALUES (?,?,?,?,?,?)"
    );
    foreach ($recRows->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        $insRec->execute([$newId, $rec['name'], $rec['category'], $rec['description'], $rec['url'], $rec['sort_ord']]);
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

if ($action === 'update_image') {
    if (!empty($body['remove_image'])) {
        delete_ep_event_image($ev['image_key']);
        $db->prepare("UPDATE ep_events SET image_key='', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true, 'image_url' => null]);
        exit;
    }
    $newKey = save_ep_event_image();
    if ($newKey === '') { echo json_encode(['ok' => false, 'error' => 'No valid image uploaded (jpg/png/gif/webp, max 8MB).']); exit; }
    delete_ep_event_image($ev['image_key']);
    $db->prepare("UPDATE ep_events SET image_key=?, updated_at=datetime('now') WHERE id=?")->execute([$newKey, $id]);
    echo json_encode(['ok' => true, 'image_url' => ep_image_url(['image_key' => $newKey])]);
    exit;
}

if ($action === 'publish') {
    $db->prepare("UPDATE ep_events SET status='published', updated_at=datetime('now') WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'cancel') {
    $db->prepare("UPDATE ep_events SET status='cancelled', updated_at=datetime('now') WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $cnt = $db->prepare("SELECT COUNT(*) FROM ep_registrations WHERE event_id=? AND status='registered'");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'error' => 'Cannot delete: event has registered attendees. Cancel it instead.']);
        exit;
    }
    delete_ep_event_image($ev['image_key']);
    $db->prepare("DELETE FROM ep_registrations WHERE event_id=?")->execute([$id]);
    $db->prepare("DELETE FROM ep_events WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
