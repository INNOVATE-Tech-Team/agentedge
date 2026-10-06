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

// Preset purpose options shown in the booking dropdown, in display order.
// "Other" is always last and pairs with a free-text description field.
const ROOM_BOOKING_PURPOSES = [
    'Agent on Duty',
    'Client Meeting',
    'Deed Package Signing',
    'Office Party',
    'Settlement Meeting',
    'Team Meeting',
    'Training Session',
    'Vendor Meeting',
    'Wednesday Workshop',
    'Other',
];

// Rooms visible from market center $mcSlug: rooms whose home mc_slug is
// $mcSlug, plus any room with an explicit office allow-list (see
// room_allowed_offices) that includes $mcSlug -- e.g. "NMB Agent on Duty"
// shows up for every office on its allow-list, not just one home office.
function room_booking_rooms_for_mc(PDO $db, string $mcSlug): array {
    $s = $db->prepare(
        "SELECT DISTINCT r.* FROM conference_rooms r
         LEFT JOIN room_allowed_offices rao ON rao.room_id = r.id
         WHERE r.enabled=1 AND (r.mc_slug=? OR rao.mc_slug=?)
         ORDER BY r.name, r.id"
    );
    $s->execute([$mcSlug, $mcSlug]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function room_booking_room(PDO $db, int $roomId): ?array {
    $s = $db->prepare("SELECT * FROM conference_rooms WHERE id=?");
    $s->execute([$roomId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// The explicit office allow-list for a room (empty if it has none, meaning
// it uses the original single mc_slug match instead).
function room_booking_allowed_offices(PDO $db, int $roomId): array {
    $s = $db->prepare("SELECT mc_slug FROM room_allowed_offices WHERE room_id=? ORDER BY mc_slug");
    $s->execute([$roomId]);
    return array_column($s->fetchAll(PDO::FETCH_ASSOC), 'mc_slug');
}

// Every office the current agent belongs to: their home MC plus any
// secondary MCs (an agent can be licensed/working across bordering offices).
function room_booking_my_offices(): array {
    return array_values(array_unique(array_filter(array_merge([my_own_mc_slug()], my_own_mc_slugs()))));
}

// Can the current agent view/book rooms belonging to $mcSlug? Booking is
// restricted to an agent's own market center (own_mc_slug); admins can act
// on any MC.
function room_booking_can_view_mc(string $mcSlug): bool {
    if ($mcSlug === '') return false;
    return is_admin() || my_own_mc_slug() === $mcSlug;
}

// Can the current agent view/book this specific room? Rooms with an
// explicit office allow-list check membership against any office the agent
// belongs to; rooms without one fall back to the original single mc_slug
// match against the agent's home office.
function room_booking_can_view_room(PDO $db, array $room): bool {
    if (is_admin()) return true;
    $allowed = room_booking_allowed_offices($db, (int)$room['id']);
    if ($allowed) return (bool)array_intersect($allowed, room_booking_my_offices());
    return room_booking_can_view_mc($room['mc_slug']);
}

// Can the current agent cancel/edit a given booking? Same scope rule used
// throughout the app: the booker themself, an admin, or a leader (mc_leader/
// bic) whose mc_slugs cover this room's market center (or, for an
// allow-listed room, any office on its allow-list).
function room_booking_can_manage(PDO $db, array $booking, array $room, array $agent): bool {
    if (strtolower($booking['agent_email']) === strtolower($agent['email'] ?? '')) return true;
    if (is_admin()) return true;
    if (is_mc_leader() || is_bic()) {
        $allowed = room_booking_allowed_offices($db, (int)$room['id']);
        $scopeSlugs = $allowed ?: array_filter([$room['mc_slug']]);
        if (array_intersect($scopeSlugs, my_mc_slugs())) return true;
    }
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

// Fixed 4-hour windows for a schedule_type='fixed_4hr' room (e.g. NMB Agent
// on Duty): Mon-Fri 9:00-1:00/1:00-5:00, Sat-Sun 10:00-2:00. Not a
// configurable per-room schedule -- this grid is hardcoded for this one
// booking mode, unlike the default flexible 9-5/adjustable-duration grid.
function room_booking_fixed_windows_for_date(string $date): array {
    $dow = (int)(new DateTime($date))->format('N'); // 1=Mon .. 7=Sun
    if ($dow >= 6) return [['10:00', '14:00']];
    return [['09:00', '13:00'], ['13:00', '17:00']];
}

// Validates a proposed booking window against business hours/duration rules.
// Returns an error message, or null if the window is valid.
function room_booking_validate_window(array $room, string $date, string $start, string $end): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Invalid date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) return 'Invalid time.';

    $today = (new DateTime('now', new DateTimeZone(ROOM_BOOKING_TIMEZONE)))->format('Y-m-d');
    if ($date < $today) return 'Cannot book a date in the past.';

    if (($room['schedule_type'] ?? 'flexible') === 'fixed_4hr') {
        foreach (room_booking_fixed_windows_for_date($date) as [$ws, $we]) {
            if ($start === $ws && $end === $we) return null;
        }
        return 'That is not one of this room\'s fixed 4-hour slots.';
    }

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

// The slot grid for a room/date, each slot flagged available or not (booked
// slots carry the booking id so the UI can show "Reserved"). Rooms with
// schedule_type='fixed_4hr' get the hardcoded 4-hour windows; every other
// room gets the default 9-5/30-min grid.
function room_booking_slot_grid(PDO $db, array $room, string $date): array {
    $roomId = (int)$room['id'];
    $s = $db->prepare(
        "SELECT id, start_time, end_time, agent_name, agent_email FROM room_bookings
         WHERE room_id=? AND booking_date=? AND status='booked'
         ORDER BY start_time"
    );
    $s->execute([$roomId, $date]);
    $bookings = $s->fetchAll(PDO::FETCH_ASSOC);

    $windows = ($room['schedule_type'] ?? 'flexible') === 'fixed_4hr'
        ? room_booking_fixed_windows_for_date($date)
        : null;
    if ($windows === null) {
        $windows = [];
        $openMin  = room_booking_minutes(ROOM_BOOKING_OPEN_TIME);
        $closeMin = room_booking_minutes(ROOM_BOOKING_CLOSE_TIME);
        for ($m = $openMin; $m < $closeMin; $m += ROOM_BOOKING_GRID_MINUTES) {
            $windows[] = [room_booking_hhmm($m), room_booking_hhmm($m + ROOM_BOOKING_GRID_MINUTES)];
        }
    }

    $slots = [];
    foreach ($windows as [$slotStart, $slotEnd]) {
        $booking = null;
        foreach ($bookings as $b) {
            if ($slotStart < $b['end_time'] && $slotEnd > $b['start_time']) { $booking = $b; break; }
        }
        $slots[] = [
            'start'       => $slotStart,
            'end'         => $slotEnd,
            'available'   => $booking === null,
            'booking_id'  => $booking['id'] ?? null,
            'agent_name'  => $booking ? ($booking['agent_name'] ?: $booking['agent_email']) : null,
        ];
    }
    return $slots;
}

// Plain-text confirmation/cancellation notice, matching the codebase's
// existing implode("\n", [...]) email style (see lib/notifications.php).
function room_booking_notify(string $subject, array $lines, string $agentEmail, string $agentName): void {
    queue_email_to([$agentEmail], $subject, implode("\n", $lines), '', '');
}

// Notifies a room's configured watcher (conference_rooms.notify_email) of a
// newly created booking, e.g. an office manager who wants visibility into
// every booking on a room, not just their own. No-op if unset, or if it's
// the same address as the booker's (they already got the confirmation email).
function room_booking_notify_watcher(array $room, string $agentName, string $agentEmail, string $date, string $start, string $end, string $purpose): void {
    $watcher = trim($room['notify_email'] ?? '');
    if ($watcher === '' || strtolower($watcher) === strtolower($agentEmail)) return;
    queue_email_to([$watcher], 'New Conference Room Booking: ' . $room['name'], implode("\n", [
        "{$agentName} ({$agentEmail}) booked {$room['name']}:",
        room_booking_format_when($date, $start, $end),
        $purpose !== '' ? "Purpose: {$purpose}" : "",
        "",
        "-- AgentEdge",
    ]), '', '');
}

// HTML reminder notice, styled like the branded emails in
// api/password_reset.php (green banner shell via notification_email_html()).
function room_booking_notify_html(string $subject, string $contentHtml, string $agentEmail): void {
    queue_email_to([$agentEmail], $subject, notification_email_html($contentHtml), '', '', '', true);
}

// Body content (without the outer branded shell) for a "your booking is
// coming up" reminder, with Cancel/Reschedule buttons that both land on
// $manageUrl -- the booking page itself is where the agent actually
// cancels or picks a new time, since there is no separate reschedule action.
function room_booking_reminder_content(string $agentName, string $roomName, string $when, string $manageUrl, string $timeframeLabel): string {
    $manageHref = htmlspecialchars($manageUrl, ENT_QUOTES);
    return '
        <p style="margin:0 0 6px 0;font-size:12px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#82C112;">Conference room</p>
        <h1 style="margin:0 0 18px 0;font-size:22px;line-height:1.3;color:#1a1a1a;">Your room is booked ' . htmlspecialchars($timeframeLabel, ENT_QUOTES) . '</h1>
        <p style="margin:0 0 6px 0;font-size:15px;line-height:1.6;color:#3a3a3a;">
            Hi ' . htmlspecialchars($agentName, ENT_QUOTES) . ', this is a reminder that you have <strong>' . htmlspecialchars($roomName, ENT_QUOTES) . '</strong> booked ' . htmlspecialchars($timeframeLabel, ENT_QUOTES) . ':
        </p>
        <p style="margin:0 0 28px 0;font-size:15px;line-height:1.6;color:#1a1a1a;font-weight:700;">' . htmlspecialchars($when, ENT_QUOTES) . '</p>
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td style="border-radius:7px;background:#ffffff;border:1px solid #82C112;">
                <a href="' . $manageHref . '" style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:700;color:#3a7a00;text-decoration:none;">Cancel booking</a>
            </td>
            <td style="width:10px;">&nbsp;</td>
            <td style="border-radius:7px;background:#82C112;">
                <a href="' . $manageHref . '" style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:700;color:#1a1a1a;text-decoration:none;">Reschedule &rarr;</a>
            </td>
        </tr></table>
        <p style="margin:28px 0 0 0;font-size:13px;line-height:1.6;color:#767676;">
            Need to make a change? Both buttons take you to your booking page, where you can cancel this booking or pick a new time.
        </p>
    ';
}

// Google Calendar "quick add" link -- no auth or attendee lookup required,
// just a URL the agent's browser opens to a pre-filled event they save
// themselves. Times are converted from ROOM_BOOKING_TIMEZONE to UTC, which
// is what the template endpoint expects.
function room_booking_gcal_link(string $roomName, string $date, string $start, string $end, string $purpose): string {
    $tz = new DateTimeZone(ROOM_BOOKING_TIMEZONE);
    $s = DateTime::createFromFormat('Y-m-d H:i', "$date $start", $tz)->setTimezone(new DateTimeZone('UTC'));
    $e = DateTime::createFromFormat('Y-m-d H:i', "$date $end", $tz)->setTimezone(new DateTimeZone('UTC'));
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => $roomName,
        'dates'   => $s->format('Ymd\THis\Z') . '/' . $e->format('Ymd\THis\Z'),
        'details' => $purpose !== '' ? "Purpose: {$purpose}" : '',
    ]);
}

// Body content for the booking confirmation email, with an "Add to Google
// Calendar" button alongside the existing plain-text-style details.
function room_booking_confirmation_content(string $agentName, string $roomName, string $when, string $purpose, string $manageUrl, string $gcalLink): string {
    $manageHref  = htmlspecialchars($manageUrl, ENT_QUOTES);
    $gcalHref    = htmlspecialchars($gcalLink, ENT_QUOTES);
    $purposeLine = $purpose !== ''
        ? '<p style="margin:0 0 20px 0;font-size:14px;line-height:1.6;color:#3a3a3a;">Purpose: ' . htmlspecialchars($purpose, ENT_QUOTES) . '</p>'
        : '';
    return '
        <p style="margin:0 0 6px 0;font-size:12px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#82C112;">Conference room</p>
        <h1 style="margin:0 0 18px 0;font-size:22px;line-height:1.3;color:#1a1a1a;">Booking confirmed</h1>
        <p style="margin:0 0 6px 0;font-size:15px;line-height:1.6;color:#3a3a3a;">
            Hi ' . htmlspecialchars($agentName, ENT_QUOTES) . ', your booking for <strong>' . htmlspecialchars($roomName, ENT_QUOTES) . '</strong> is confirmed:
        </p>
        <p style="margin:0 0 6px 0;font-size:15px;line-height:1.6;color:#1a1a1a;font-weight:700;">' . htmlspecialchars($when, ENT_QUOTES) . '</p>
        ' . $purposeLine . '
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td style="border-radius:7px;background:#82C112;">
                <a href="' . $gcalHref . '" target="_blank" rel="noopener" style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:700;color:#1a1a1a;text-decoration:none;">Add to Google Calendar</a>
            </td>
        </tr></table>
        <p style="margin:28px 0 0 0;font-size:13px;line-height:1.6;color:#767676;">
            Need to cancel? Visit your <a href="' . $manageHref . '" style="color:#3a7a00;">booking page</a>.
        </p>
    ';
}

function room_booking_format_when(string $date, string $start, string $end): string {
    $d = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    $s = DateTime::createFromFormat('H:i', $start, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    $e = DateTime::createFromFormat('H:i', $end, new DateTimeZone(ROOM_BOOKING_TIMEZONE));
    return $d->format('l, F j, Y') . ' from ' . $s->format('g:i A') . ' to ' . $e->format('g:i A');
}
