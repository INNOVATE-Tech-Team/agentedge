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

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Room required']); exit; }
    $hasBookings = $db->prepare("SELECT COUNT(*) FROM room_bookings WHERE room_id=? AND status='booked'");
    $hasBookings->execute([$id]);
    if ((int)$hasBookings->fetchColumn() > 0) {
        echo json_encode(['ok'=>false,'error'=>'Cannot delete a room with active bookings -- disable it instead']);
        exit;
    }
    $db->prepare("DELETE FROM conference_rooms WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
