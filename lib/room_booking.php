<?php
if (defined('AGENTEDGE_ROOM_BOOKING_LOADED')) return;
define('AGENTEDGE_ROOM_BOOKING_LOADED', true);
// Conference room booking — shared logic used by api/room_availability.php,
// api/room_booking_action.php, admin_conference_rooms.php/api, and
// cron/room_booking_reminders.php.
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/notifications.php';

const ROOM_BOOKING_OPEN_TIME  = '09:00';
const ROOM_BOOKING_CLOSE_TIME = '17:00';
const ROOM_BOOKING_GRID_MINUTES = 30;   // start-time grid granularity
const ROOM_BOOKING_MIN_MINUTES  = 15;
const ROOM_BOOKING_MAX_MINUTES  = 120;
const ROOM_BOOKING_STEP_MINUTES = 15;   // duration adjustment step
// All market centers on this roster are East Coast offices (SC/NC/PA/NJ/TN/etc.),
// so business hours are interpreted in Eastern time. Revisit if a MC outside
// this timezone is ever added.
const ROOM_BOOKING_TIMEZONE = 'America/New_York';

function room_booking_rooms_for_mc(PDO $db, string $mcSlug): array {
    $s = $db->prepare("SELECT * FROM conference_rooms WHERE mc_slug=? AND enabled=1 ORDER BY name, id");
    $s->execute([$mcSlug]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function room_booking_room(PDO $db, int $roomId): ?array {
    $s = $db->prepare("SELECT * FROM conference_rooms WHERE id=?");
    $s->execute([$roomId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Can the current agent view/book rooms belonging to $mcSlug? Booking is
// restricted to an agent's own market center (own_mc_slug); admins can act
// on any MC.
function room_booking_can_view_mc(string $mcSlug): bool {
    if ($mcSlug === '') return false;
    return is_admin() || my_own_mc_slug() === $mcSlug;
}

// Can the current agent cancel/edit a given booking? Same scope rule used
// throughout the app: the booker themself, an admin, or a leader (mc_leader/
// bic) whose mc_slugs cover this room's market center.
function room_booking_can_manage(array $booking, string $roomMcSlug, array $agent): bool {
    if (strtolower($booking['agent_email']) === strtolower($agent['email'] ?? '')) return true;
    if (is_admin()) return true;
    if ((is_mc_leader() || is_bic()) && $roomMcSlug !== '' && in_array($roomMcSlug, my_mc_slugs(), true)) return true;
    return false;
}

// Minutes since midnight for a "HH:MM" string.
function room_booking_minutes(string $hhmm): int {
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function room_booking_hhmm(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// Validates a proposed booking window against business hours/duration rules.
// Returns an error message, or null if the window is valid.
function room_booking_validate_window(string $date, string $start, string $end): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Invalid date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) return 'Invalid time.';

    $today = (new DateTime('now', new DateTimeZone(ROOM_BOOKING_TIMEZONE)))->format('Y-m-d');
    if ($date < $today) return 'Cannot book a date in the past.';

    $startMin = room_booking_minutes($start);
    $endMin   = room_booking_minutes($end);
    $openMin  = room_booking_minutes(ROOM_BOOKING_OPEN_TIME);
    $closeMin = room_booking_minutes(ROOM_BOOKING_CLOSE_TIME);

    if ($startMin % ROOM_BOOKING_GRID_MINUTES !== 0) return 'Start time must be on the half hour.';
    if ($startMin < $openMin || $startMin >= $closeMin) return 'Start time is outside business hours (9:00 AM-5:00 PM).';
    if ($endMin > $closeMin) return 'Booking cannot extend past 5:00 PM.';

    $duration = $endMin - $startMin;
    if ($duration < ROOM_BOOKING_MIN_MINUTES) return 'Minimum booking length is 15 minutes.';
    if ($duration > ROOM_BOOKING_MAX_MINUTES) return 'Maximum booking length is 2 hours.';
    if ($duration % ROOM_BOOKING_STEP_MINUTES !== 0) return 'Duration must be in 15-minute increments.';

    return null;
}

// Does [start,end) overlap any existing active booking for this room/date?
// $excludeBookingId lets a re-check during edit skip the booking being replaced.
function room_booking_overlaps(PDO $db, int $roomId, string $date, string $start, string $end, ?int $excludeBookingId = null): bool {
    $sql = "SELECT COUNT(*) FROM room_bookings
            WHERE room_id=? AND booking_date=? AND status='booked'
              AND start_time < ? AND end_time > ?";
    $params = [$roomId, $date, $end, $start];
    if ($excludeBookingId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeBookingId;
    }
    $s = $db->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn() > 0;
}

// The 9-5 slot grid for a room/date, each slot flagged available or not
// (booked slots carry the booking id so the UI can show "Reserved").
function room_booking_slot_grid(PDO $db, int $roomId, string $date): array {
    $s = $db->prepare(
        "SELECT id, start_time, end_time FROM room_bookings
         WHERE room_id=? AND booking_date=? AND status='booked'
         ORDER BY start_time"
    );
    $s->execute([$roomId, $date]);
    $bookings = $s->fetchAll(PDO::FETCH_ASSOC);

    $openMin  = room_booking_minutes(ROOM_BOOKING_OPEN_TIME);
    $closeMin = room_booking_minutes(ROOM_BOOKING_CLOSE_TIME);

    $slots = [];
    for ($m = $openMin; $m < $closeMin; $m += ROOM_BOOKING_GRID_MINUTES) {
        $slotStart = room_booking_hhmm($m);
        $slotEnd   = room_booking_hhmm($m + ROOM_BOOKING_GRID_MINUTES);
        $booking   = null;
        foreach ($bookings as $b) {
            if ($slotStart < $b['end_time'] && $slotEnd > $b['start_time']) { $booking = $b; break; }
        }
        $slots[] = [
            'start'      => $slotStart,
            'end'        => $slotEnd,
            'available'  => $booking === null,
            'booking_id' => $booking['id'] ?? null,
        ];
    }
    return $slots;
}

// Plain-text confirmation/cancellation notice, matching the codebase's
// existing implode("\n", [...]) email style (see lib/notifications.php).
function room_booking_notify(string $subject, array $lines, string $agentEmail, string $agentName): void {
    queue_email_to([$agentEmail], $subject, implode("\n", $lines), '', '');
}

function room_booking_format_when(string $date, string $start, string $end): string {
    $d = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    $s = DateTime::createFromFormat('H:i', $start, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    $e = DateTime::createFromFormat('H:i', $end, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    return $d->format('l, F j, Y') . ' from ' . $s->format('g:i A') . ' to ' . $e->format('g:i A');
}
