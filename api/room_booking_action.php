<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/room_booking.php';

header('Content-Type: application/json');
$agent = require_login();

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

if ($action === 'create') {
    $roomId = (int)($in['room_id'] ?? 0);
    $date   = trim($in['booking_date'] ?? '');
    $start  = trim($in['start_time']   ?? '');
    $end    = trim($in['end_time']     ?? '');
    $purpose = trim($in['purpose']    ?? '');

    $room = $roomId ? room_booking_room($db, $roomId) : null;
    if (!$room) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }

    // Same scope as viewing: the agent's own market center, or -- for a room
    // with an explicit office allow-list -- any office on that list.
    if (!room_booking_can_view_room($db, $room)) {
        echo json_encode(['ok'=>false,'error'=>'You can only book rooms available to your office']); exit;
    }

    $err = room_booking_validate_window($room, $date, $start, $end);
    if ($err) { echo json_encode(['ok'=>false,'error'=>$err]); exit; }

    try {
        $db->exec('BEGIN IMMEDIATE');
        if (room_booking_overlaps($db, $roomId, $date, $start, $end)) {
            $db->exec('ROLLBACK');
            echo json_encode(['ok'=>false,'error'=>'That time was just reserved by someone else. Please pick another slot.']);
            exit;
        }
        $db->prepare(
            "INSERT INTO room_bookings (room_id, agent_email, agent_name, booking_date, start_time, end_time, purpose)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$roomId, $agent['email'], $agent['name'] ?? '', $date, $start, $end, $purpose]);
        $bookingId = (int)$db->lastInsertId();
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (\Throwable $e2) {}
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }

    $baseUrl = rtrim((string)(cfg()['app_base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com'))), '/');
    room_booking_notify_html(
        'Conference Room Booking Confirmed',
        room_booking_confirmation_content(
            $agent['name'] ?? '',
            $room['name'],
            room_booking_format_when($date, $start, $end),
            $purpose,
            $baseUrl . '/room_booking.php?booking=' . $bookingId,
            room_booking_gcal_link($room['name'], $date, $start, $end, $purpose)
        ),
        $agent['email']
    );
    room_booking_notify_watcher($room, $agent['name'] ?? '', $agent['email'], $date, $start, $end, $purpose);

    echo json_encode(['ok'=>true, 'booking_id'=>$bookingId]);
    exit;
}

if ($action === 'cancel') {
    $bookingId = (int)($in['booking_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM room_bookings WHERE id=? AND status='booked'");
    $s->execute([$bookingId]);
    $booking = $s->fetch(PDO::FETCH_ASSOC);
    if (!$booking) { echo json_encode(['ok'=>false,'error'=>'Booking not found']); exit; }

    $room = room_booking_room($db, (int)$booking['room_id']);
    if (!$room || !room_booking_can_manage($db, $booking, $room, $agent)) {
        echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
    }

    $db->prepare("UPDATE room_bookings SET status='canceled', canceled_at=datetime('now'), canceled_by=? WHERE id=?")
       ->execute([$agent['email'], $bookingId]);

    $canceledBySomeoneElse = strtolower($booking['agent_email']) !== strtolower($agent['email']);
    room_booking_notify(
        'Conference Room Booking Canceled',
        [
            "Hi {$booking['agent_name']},",
            "",
            "Your booking for {$room['name']} has been canceled:",
            room_booking_format_when($booking['booking_date'], $booking['start_time'], $booking['end_time']),
            $canceledBySomeoneElse ? "Canceled by {$agent['name']} ({$agent['email']})." : '',
            "",
            "-- AgentEdge",
        ],
        $booking['agent_email'],
        $booking['agent_name']
    );

    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
