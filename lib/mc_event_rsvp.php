<?php
// Optional RSVP for Market Center Events (mc_events.rsvp_enabled). Pure
// functions over an explicit PDO so the rules are testable; the endpoints
// (api/mc_event_rsvp.php, api/mc_events_action.php, api/mc_events_cal.php) are
// thin callers. Attendee list / permissions live in lib/event_attendees.php
// (scope 'mc'). Nothing here sends mail.

function mc_rsvp_slugs(string $csv): array {
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)))));
}

function mc_rsvp_event(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM mc_events WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['slugs'] = mc_rsvp_slugs((string)$r['mc_slugs']);
    return $r;
}

// Every market center this person belongs to or leads, by email: the same union
// the announcements feed uses — agent_roles.mc_slugs (leads) + own_mc_slugs
// (assigned) + active innovate_roster.market_center (slugified). The roster leg
// matters because most active agents have no agent_roles row at all.
function mc_rsvp_member_slugs(PDO $db, string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    $slugs = [];
    $st = $db->prepare("SELECT mc_slugs, own_mc_slugs FROM agent_roles WHERE LOWER(email)=?");
    $st->execute([$email]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        foreach (['mc_slugs', 'own_mc_slugs'] as $col) {
            $arr = json_decode((string)$row[$col], true);
            if (is_array($arr)) foreach ($arr as $s) if (is_string($s) && $s !== '') $slugs[] = $s;
        }
    }
    $st = $db->prepare("SELECT market_center FROM innovate_roster WHERE LOWER(email)=? AND active=1");
    $st->execute([$email]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mc) {
        if ($mc !== '' && function_exists('slugify_mc')) $slugs[] = slugify_mc($mc);
    }
    return array_values(array_unique($slugs));
}

// Eligible = in at least one of the event's CURRENT market centers. Never
// snapshotted: adding/removing an MC on the event changes this immediately.
function mc_rsvp_is_eligible(array $event, array $memberSlugs): bool {
    return (bool)array_intersect($event['slugs'], $memberSlugs);
}

// "Past" for RSVP/cancel purposes (America/New_York): a timed event closes at
// its start_date + start_time; an untimed (all-day style) event stays open
// through the end of its last day (end_date, else start_date).
function mc_rsvp_is_past(array $event, ?int $now = null): bool {
    $now = $now ?? time();
    $tz  = new DateTimeZone('America/New_York');
    $start = trim((string)$event['start_time']);
    if (preg_match('/^\d{1,2}:\d{2}$/', $start)) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['start_date'] . ' ' . str_pad($start, 5, '0', STR_PAD_LEFT), $tz);
        return $dt ? $now >= $dt->getTimestamp() : false;
    }
    $lastDay = trim((string)$event['end_date']) !== '' ? $event['end_date'] : $event['start_date'];
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastDay . ' 23:59:59', $tz);
    return $dt ? $now > $dt->getTimestamp() : false;
}

function mc_rsvp_registered_count(PDO $db, int $eventId): int {
    $st = $db->prepare("SELECT COUNT(*) FROM mc_event_rsvps WHERE event_id=? AND status='registered'");
    $st->execute([$eventId]);
    return (int)$st->fetchColumn();
}

function mc_rsvp_is_registered(PDO $db, int $eventId, string $email): bool {
    $st = $db->prepare("SELECT 1 FROM mc_event_rsvps WHERE event_id=? AND agent_email=? AND status='registered'");
    $st->execute([$eventId, strtolower(trim($email))]);
    return (bool)$st->fetchColumn();
}

// RSVP or cancel the CALLER'S OWN registration. $email/$name come from the
// session, never from the request. Checks, in order: event exists → RSVP is
// enabled → caller is eligible for a current MC of the event → event hasn't
// passed. Returns ['ok', 'code' (HTTP), 'error', 'rsvped'].
function mc_rsvp_act(PDO $db, string $action, int $eventId, string $email, string $name, array $memberSlugs, ?int $now = null): array {
    $fail = fn(int $code, string $msg) => ['ok' => false, 'code' => $code, 'error' => $msg, 'rsvped' => false];
    $email = strtolower(trim($email));
    if ($email === '')                              return $fail(401, 'not signed in');
    if (!in_array($action, ['rsvp', 'cancel'], true)) return $fail(400, 'unknown action');
    $ev = mc_rsvp_event($db, $eventId);
    if (!$ev)                                       return $fail(404, 'Event not found');
    if ((int)$ev['rsvp_enabled'] !== 1)             return $fail(400, 'RSVP is not enabled for this event');
    if (!mc_rsvp_is_eligible($ev, $memberSlugs))    return $fail(403, "This event isn't open to your Market Center");
    if (mc_rsvp_is_past($ev, $now))                 return $fail(409, 'RSVP is closed — this event has already started or passed');

    if ($action === 'rsvp') {
        // Duplicate RSVP is a no-op that keeps the original timestamp; a previously
        // cancelled row is revived.
        $db->prepare(
            "INSERT INTO mc_event_rsvps (event_id, agent_email, agent_name, status) VALUES (?,?,?, 'registered')
             ON CONFLICT(event_id, agent_email) DO UPDATE SET
               agent_name = excluded.agent_name,
               rsvped_at  = CASE WHEN mc_event_rsvps.status = 'registered' THEN mc_event_rsvps.rsvped_at ELSE datetime('now') END,
               status     = 'registered'"
        )->execute([$eventId, $email, $name !== '' ? $name : $email]);
        return ['ok' => true, 'code' => 200, 'error' => '', 'rsvped' => true];
    }
    $db->prepare("UPDATE mc_event_rsvps SET status='cancelled' WHERE event_id=? AND agent_email=? AND status='registered'")
       ->execute([$eventId, $email]);
    return ['ok' => true, 'code' => 200, 'error' => '', 'rsvped' => false];
}

// Only a Super Admin may change which market centers an EXISTING MC Event
// belongs to (add or remove). An unchanged set is always fine for whoever may
// already edit the event. Order and duplicates don't count as a change.
function mc_events_slug_change_allowed(array $oldSlugs, array $newSlugs, bool $isSuperAdmin): bool {
    $a = array_values(array_unique($oldSlugs)); sort($a);
    $b = array_values(array_unique($newSlugs)); sort($b);
    return $a === $b || $isSuperAdmin;
}

// Deleting an MC Event removes its registrations too (no orphans).
function mc_rsvp_delete_for_event(PDO $db, int $eventId): void {
    $db->prepare("DELETE FROM mc_event_rsvps WHERE event_id=?")->execute([$eventId]);
}
