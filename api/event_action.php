<?php
// Admin-only: create / update / delete Company Calendar "Events" on their
// dedicated Google Calendar. Mirrors training_event_action.php exactly, but
// targets the events_* tables and gcal_events_calendar_id.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent)     { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']);    exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

$c        = cfg();
$key_file = $c['gcal_key_file']           ?? (__DIR__ . '/../agentedge-calendar-key.json');
$cal_id   = $c['gcal_events_calendar_id'] ?? '';

if ($cal_id === '') { http_response_code(500); echo json_encode(['error' => 'Events calendar not configured yet']); exit; }

$token = gcal_access_token($key_file);
if (!$token) { http_response_code(500); echo json_encode(['error' => 'calendar auth failed']); exit; }

// Short registration-link slug (register.php?s=<slug>) — lowercased,
// non-alphanumerics collapsed to single dashes.
function slugify_reg_link(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Slugs must be unique across BOTH training_events and events_calendar, since
// register.php resolves ?s=<slug> without knowing which scope it belongs to.
// $excludeEventId lets an update ignore the row being saved.
function reg_slug_taken(PDO $db, string $slug, string $excludeEventId = ''): bool {
    foreach (['training_events', 'events_calendar'] as $t) {
        $st = $db->prepare("SELECT 1 FROM {$t} WHERE reg_slug=? AND event_id != ? LIMIT 1");
        $st->execute([$slug, $excludeEventId]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

// Sanitizes the request's mc_slugs array down to real, enabled market_centers
// slugs, CSV-joined for storage (empty string = no restriction = everyone).
function sanitize_mc_slugs(PDO $db, array $requested): string {
    $requested = array_values(array_unique(array_filter(array_map('strval', $requested))));
    if (!$requested) return '';
    $placeholders = implode(',', array_fill(0, count($requested), '?'));
    $st = $db->prepare("SELECT slug FROM market_centers WHERE slug IN ($placeholders)");
    $st->execute($requested);
    return implode(',', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ── Create ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title      = trim($body['title']       ?? '');
    $date       = trim($body['date']        ?? '');
    $end_date   = trim($body['end_date']    ?? '');
    $start_time = trim($body['start_time']  ?? '');
    $end_time   = trim($body['end_time']    ?? '');
    $location   = trim($body['location']    ?? '');
    $description = trim($body['description'] ?? '');
    $capacity   = ($body['capacity'] ?? '') !== '' ? max(0, (int)$body['capacity']) : null;
    $regDesc    = trim($body['reg_description'] ?? '') !== '' ? trim($body['reg_description']) : null;
    $regSlug    = slugify_reg_link($body['reg_slug'] ?? '') ?: null;
    $mcSlugs    = sanitize_mc_slugs(local_db(), (array)($body['mc_slugs'] ?? []));

    if (!$title || !$date) { http_response_code(400); echo json_encode(['error' => 'title and date required']); exit; }
    if ($regSlug !== null && reg_slug_taken(local_db(), $regSlug)) {
        http_response_code(400); echo json_encode(['error' => 'That short link name is already taken.']); exit;
    }

    if ($start_time && $end_time) {
        $event = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['dateTime' => $date . 'T' . $start_time . ':00', 'timeZone' => 'America/New_York'],
            'end'   => ['dateTime' => ($end_date ?: $date) . 'T' . $end_time . ':00', 'timeZone' => 'America/New_York'],
        ];
    } else {
        $end = $end_date ?: date('Y-m-d', strtotime($date . ' +1 day'));
        $event = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['date' => $date],
            'end'   => ['date' => $end],
        ];
    }

    $result = gcal_create_event($cal_id, $token, $event);
    if (!$result) { http_response_code(500); echo json_encode(['error' => 'failed to create event — check calendar sharing permissions']); exit; }
    local_db()->prepare("INSERT INTO events_calendar (event_id, capacity, reg_description, reg_slug, mc_slugs) VALUES (?,?,?,?,?) ON CONFLICT(event_id) DO UPDATE SET capacity=excluded.capacity, reg_description=excluded.reg_description, reg_slug=excluded.reg_slug, mc_slugs=excluded.mc_slugs")
        ->execute([$result['id'], $capacity, $regDesc, $regSlug, $mcSlugs]);
    echo json_encode(['ok' => true, 'event_id' => $result['id']]);

// ── Update ────────────────────────────────────────────────────────────────────
} elseif ($action === 'update') {
    $event_id    = trim($body['event_id']    ?? '');
    $title       = trim($body['title']       ?? '');
    $date        = trim($body['date']        ?? '');
    $end_date    = trim($body['end_date']    ?? '');
    $start_time  = trim($body['start_time']  ?? '');
    $end_time    = trim($body['end_time']    ?? '');
    $location    = trim($body['location']    ?? '');
    $description = trim($body['description'] ?? '');
    $capacity    = ($body['capacity'] ?? '') !== '' ? max(0, (int)$body['capacity']) : null;
    $regDesc     = trim($body['reg_description'] ?? '') !== '' ? trim($body['reg_description']) : null;
    $regSlug     = slugify_reg_link($body['reg_slug'] ?? '') ?: null;
    $mcSlugs     = sanitize_mc_slugs(local_db(), (array)($body['mc_slugs'] ?? []));

    if (!$event_id || !$title || !$date) { http_response_code(400); echo json_encode(['error' => 'event_id, title, date required']); exit; }
    if ($regSlug !== null && reg_slug_taken(local_db(), $regSlug, $event_id)) {
        http_response_code(400); echo json_encode(['error' => 'That short link name is already taken.']); exit;
    }

    if ($start_time && $end_time) {
        $patch = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['dateTime' => $date . 'T' . $start_time . ':00', 'timeZone' => 'America/New_York'],
            'end'   => ['dateTime' => ($end_date ?: $date) . 'T' . $end_time . ':00', 'timeZone' => 'America/New_York'],
        ];
    } else {
        $end = $end_date ?: date('Y-m-d', strtotime($date . ' +1 day'));
        $patch = [
            'summary'     => $title,
            'location'    => $location,
            'description' => $description,
            'start' => ['date' => $date],
            'end'   => ['date' => $end],
        ];
    }

    $result = gcal_update_event($cal_id, $token, $event_id, $patch);
    if (!$result) { http_response_code(500); echo json_encode(['error' => 'failed to update event']); exit; }

    $db = local_db();
    $db->prepare("INSERT INTO events_calendar (event_id, capacity, reg_description, reg_slug, mc_slugs) VALUES (?,?,?,?,?) ON CONFLICT(event_id) DO UPDATE SET capacity=excluded.capacity, reg_description=excluded.reg_description, reg_slug=excluded.reg_slug, mc_slugs=excluded.mc_slugs")
       ->execute([$event_id, $capacity, $regDesc, $regSlug, $mcSlugs]);

    // Capacity may have gone up (or been removed) — promote waitlisted agents
    // into any now-open seats, oldest first.
    $regCountStmt = $db->prepare("SELECT COUNT(*) FROM events_rsvps WHERE event_id=? AND status='registered'");
    $regCountStmt->execute([$event_id]);
    $regCount = (int)$regCountStmt->fetchColumn();
    $open = $capacity === null ? PHP_INT_MAX : ($capacity - $regCount);

    if ($open > 0) {
        $wait = $db->prepare("SELECT id, agent_email FROM events_rsvps WHERE event_id=? AND status='waitlisted' ORDER BY rsvped_at LIMIT ?");
        $wait->bindValue(1, $event_id);
        $wait->bindValue(2, $open === PHP_INT_MAX ? 1000000 : $open, PDO::PARAM_INT);
        $wait->execute();
        foreach ($wait->fetchAll(PDO::FETCH_ASSOC) as $promoted) {
            $db->prepare("UPDATE events_rsvps SET status='registered' WHERE id=?")->execute([$promoted['id']]);
            try { gcal_add_attendee($cal_id, $token, $event_id, $promoted['agent_email']); } catch (\Throwable $e) {}
            queue_email_to([$promoted['agent_email']], "You're in: {$title}", implode("\n", [
                "A seat opened up — you've been moved from the waitlist to registered for:",
                "",
                $title,
                "Date: {$date}",
                "",
                "— AgentEdge",
            ]));
        }
    }

    echo json_encode(['ok' => true]);

// ── Delete ────────────────────────────────────────────────────────────────────
} elseif ($action === 'delete') {
    $event_id = trim($body['event_id'] ?? '');
    if (!$event_id) { http_response_code(400); echo json_encode(['error' => 'missing event_id']); exit; }

    gcal_delete_event($cal_id, $token, $event_id);
    local_db()->prepare("DELETE FROM events_rsvps WHERE event_id=?")->execute([$event_id]);
    local_db()->prepare("DELETE FROM events_calendar WHERE event_id=?")->execute([$event_id]);
    echo json_encode(['ok' => true]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
}
