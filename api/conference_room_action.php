<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');
$agent = require_login();
if (!is_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

if ($action === 'add') {
    $mcSlug = trim($in['mc_slug'] ?? '');
    $name   = trim($in['name']    ?? '');
    if (!$mcSlug || !$name) { echo json_encode(['ok'=>false,'error'=>'Market center and room name are required']); exit; }

    $mc = $db->prepare("SELECT slug FROM market_centers WHERE slug=?");
    $mc->execute([$mcSlug]);
    if (!$mc->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Unknown market center']); exit; }

    $db->prepare("INSERT INTO conference_rooms (mc_slug, name) VALUES (?, ?)")->execute([$mcSlug, $name]);
    echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
    exit;
}

if ($action === 'rename') {
    $id   = (int)($in['id']   ?? 0);
    $name = trim($in['name']  ?? '');
    if (!$id || !$name) { echo json_encode(['ok'=>false,'error'=>'Room and name are required']); exit; }
    $db->prepare("UPDATE conference_rooms SET name=? WHERE id=?")->execute([$name, $id]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'toggle') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Room required']); exit; }
    $db->prepare("UPDATE conference_rooms SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
    $s = $db->prepare("SELECT enabled FROM conference_rooms WHERE id=?");
    $s->execute([$id]);
    echo json_encode(['ok'=>true, 'enabled'=>(int)$s->fetchColumn()]);
    exit;
}

if ($action === 'set_notify_email') {
    $id    = (int)($in['id'] ?? 0);
    $email = trim($in['notify_email'] ?? '');
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Room required']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid email address']); exit;
    }
    $db->prepare("UPDATE conference_rooms SET notify_email=? WHERE id=?")->execute([$email, $id]);
    echo json_encode(['ok'=>true, 'notify_email'=>$email]);
    exit;
}

if ($action === 'set_allowed_offices') {
    $id      = (int)($in['id'] ?? 0);
    $mcSlugs = is_array($in['mc_slugs'] ?? null) ? array_values(array_unique(array_map('trim', $in['mc_slugs']))) : [];
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Room required']); exit; }

    $room = $db->prepare("SELECT id FROM conference_rooms WHERE id=?");
    $room->execute([$id]);
    if (!$room->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }

    if ($mcSlugs) {
        $ph = implode(',', array_fill(0, count($mcSlugs), '?'));
        $known = $db->prepare("SELECT slug FROM market_centers WHERE slug IN ($ph)");
        $known->execute($mcSlugs);
        $knownSlugs = $known->fetchAll(PDO::FETCH_COLUMN);
        if (count($knownSlugs) !== count($mcSlugs)) {
            echo json_encode(['ok'=>false,'error'=>'Unknown market center in list']); exit;
        }
    }

    $db->exec('BEGIN');
    $db->prepare("DELETE FROM room_allowed_offices WHERE room_id=?")->execute([$id]);
    $insAllowed = $db->prepare("INSERT INTO room_allowed_offices (room_id, mc_slug) VALUES (?, ?)");
    foreach ($mcSlugs as $slug) { $insAllowed->execute([$id, $slug]); }
    $db->exec('COMMIT');

    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Room required']); exit; }
    $hasBookings = $db->prepare("SELECT COUNT(*) FROM room_bookings WHERE room_id=? AND status='booked'");
    $hasBookings->execute([$id]);
    if ((int)$hasBookings->fetchColumn() > 0) {
        echo json_encode(['ok'=>false,'error'=>'Cannot delete a room with active bookings -- disable it instead']);
        exit;
    }
    $db->prepare("DELETE FROM room_allowed_offices WHERE room_id=?")->execute([$id]);
    $db->prepare("DELETE FROM conference_rooms WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
