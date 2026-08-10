<?php
// Run every 5 minutes via cron (docker exec agentedge php cron/room_booking_reminders.php).
// Sends a "1 day before" and a "30 minutes before" reminder per booking,
// each gated by its own *_sent flag so a 5-min cadence never double-sends.
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/room_booking.php';

$db  = local_db();
$now = new DateTime('now', new DateTimeZone(ROOM_BOOKING_TIMEZONE));

$rows = $db->query(
    "SELECT b.*, r.name AS room_name, r.mc_slug
     FROM room_bookings b JOIN conference_rooms r ON r.id = b.room_id
     WHERE b.status='booked' AND (b.reminder_day_sent=0 OR b.reminder_30m_sent=0)
       AND b.booking_date >= date('now')"
)->fetchAll(PDO::FETCH_ASSOC);

$dayCount = 0;
$mCount   = 0;

foreach ($rows as $b) {
    $start = DateTime::createFromFormat(
        'Y-m-d H:i',
        $b['booking_date'] . ' ' . $b['start_time'],
        new DateTimeZone(ROOM_BOOKING_TIMEZONE)
    );
    if (!$start) continue;
    $minutesUntil = ($start->getTimestamp() - $now->getTimestamp()) / 60;

    if (!$b['reminder_day_sent'] && $minutesUntil <= (24 * 60) && $minutesUntil > (23 * 60)) {
        room_booking_notify(
            'Reminder: Conference Room Booking Tomorrow',
            [
                "Hi {$b['agent_name']},",
                "",
                "This is a reminder that you have {$b['room_name']} booked tomorrow:",
                room_booking_format_when($b['booking_date'], $b['start_time'], $b['end_time']),
                "",
                "-- AgentEdge",
            ],
            $b['agent_email'],
            $b['agent_name']
        );
        $db->prepare("UPDATE room_bookings SET reminder_day_sent=1 WHERE id=?")->execute([$b['id']]);
        $dayCount++;
    }

    if (!$b['reminder_30m_sent'] && $minutesUntil <= 30 && $minutesUntil > 0) {
        room_booking_notify(
            'Reminder: Conference Room Booking in 30 Minutes',
            [
                "Hi {$b['agent_name']},",
                "",
                "This is a reminder that you have {$b['room_name']} booked shortly:",
                room_booking_format_when($b['booking_date'], $b['start_time'], $b['end_time']),
                "",
                "-- AgentEdge",
            ],
            $b['agent_email'],
            $b['agent_name']
        );
        $db->prepare("UPDATE room_bookings SET reminder_30m_sent=1 WHERE id=?")->execute([$b['id']]);
        $mCount++;
    }
}

echo "Room booking reminders: {$dayCount} day-before, {$mCount} 30-min sent.\n";
