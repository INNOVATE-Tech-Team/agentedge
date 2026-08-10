<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/room_booking.php';

header('Content-Type: application/json');
$agent = require_login();

$db     = local_db();
$roomId = (int)($_GET['room_id'] ?? 0);
$date   = trim($_GET['date'] ?? '');

$room = $roomId ? room_booking_room($db, $roomId) : null;
if (!$room) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }
if (!room_booking_can_view_mc($room['mc_slug'])) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok'=>false,'error'=>'Invalid date']); exit; }

echo json_encode([
    'ok'    => true,
    'room'  => ['id' => (int)$room['id'], 'name' => $room['name'], 'mc_slug' => $room['mc_slug']],
    'date'  => $date,
    'slots' => room_booking_slot_grid($db, $roomId, $date),
]);
