<?php
// On-demand RSVP management for Company Calendar Events and Training sessions:
// who may see an event's attendee list, and the list itself (currently
// registered people only, enriched with phone + market center).
//
// scope is 'events' (events_rsvps / events_calendar), 'training'
// (training_rsvps / training_events) — event_id is the Google Calendar event id —
// or 'mc' (mc_event_rsvps / mc_events) — event_id is mc_events.id. An unknown
// scope is an error, never silently treated as another scope.
// Nothing here sends mail — emailing goes through the existing Company Email
// composer (see backoffice_email.php's event prefill).

function event_rsvp_norm_email($e): string { return strtolower(trim((string)$e)); }

const EVENT_RSVP_SCOPES = ['events', 'training', 'mc'];

function event_rsvp_scope_valid($scope): bool { return in_array($scope, EVENT_RSVP_SCOPES, true); }

// Per-scope table/column names: the RSVP table, the event's own table, the
// event's key column there, and its creator column.
function event_rsvp_cfg(string $scope): array {
    switch ($scope) {
        case 'events':   return ['rsvp' => 'events_rsvps',   'event' => 'events_calendar', 'key' => 'event_id', 'creator' => 'created_by_email'];
        case 'training': return ['rsvp' => 'training_rsvps', 'event' => 'training_events', 'key' => 'event_id', 'creator' => 'created_by_email'];
        case 'mc':       return ['rsvp' => 'mc_event_rsvps', 'event' => 'mc_events',        'key' => 'id',       'creator' => 'created_by'];
    }
    throw new InvalidArgumentException('unknown RSVP scope');
}

function event_rsvp_table(string $scope): string       { return event_rsvp_cfg($scope)['rsvp']; }
function event_rsvp_event_table(string $scope): string { return event_rsvp_cfg($scope)['event']; }

// Whether attendee management exists for this event at all. Events / Training
// always have RSVP; an MC Event only when its rsvp_enabled toggle is on (off =
// no attendee list, count, or composer prefill, even for admins — the
// registrations themselves are preserved untouched).
function event_rsvp_enabled(PDO $db, string $scope, string $eventId): bool {
    if ($scope !== 'mc') return true;
    $st = $db->prepare("SELECT rsvp_enabled FROM mc_events WHERE id=?");
    $st->execute([(int)$eventId]);
    return (int)$st->fetchColumn() === 1;
}

// The admin who created the event (lowercased email), or '' for events that
// pre-date creator tracking — those are never guessed at or back-filled.
function event_rsvp_creator(PDO $db, string $scope, string $eventId): string {
    $c  = event_rsvp_cfg($scope);
    $st = $db->prepare("SELECT {$c['creator']} FROM {$c['event']} WHERE {$c['key']}=?");
    $st->execute([$eventId]);
    return event_rsvp_norm_email($st->fetchColumn());
}

// Who can see an event's registration list and use its attendee tools:
// Staff / Super Admin (is_admin()) or the event's recorded creator. A plain
// agent never qualifies just because they RSVP'd. Legacy events (no creator on
// record) are therefore admin/staff only. For an MC Event, anyone with
// mc_slugs covering AT LEAST ONE of the event's CURRENT market centers
// qualifies too (any-of) — mc_slugs assignment is the actual gate, not the
// mc_leader/bic role literally, matching mc_events_cal.php's own viewer-side
// scoping; this is what lets a restricted partner seat (agent_roles.mc_slugs
// set, role left as plain 'agent') manage RSVPs for their own offices without
// being a leader for anything else. Editing/deleting the event itself is a
// separate, stricter check in api/mc_events_action.php, so attendee access
// never implies edit rights.
// $isAdmin / $email / $mcLead are overridable for tests; callers normally omit
// them (current session). $mcLead = ['slugs' => string[]].
function event_rsvp_can_manage(PDO $db, string $scope, string $eventId, ?bool $isAdmin = null, ?string $email = null, ?array $mcLead = null): bool {
    if ($isAdmin === null) $isAdmin = function_exists('is_admin') && is_admin();
    if ($isAdmin) return true;
    if ($email === null) $email = current_agent()['email'] ?? '';
    $email = event_rsvp_norm_email($email);
    if ($email === '') return false;
    $creator = event_rsvp_creator($db, $scope, $eventId);
    if ($creator !== '' && $creator === $email) return true;
    // Market Center Leaders / BICs may always manage Events and Training
    // RSVPs — unlike an MC Event (always tied to specific office(s), checked
    // below), these two have no meaningful per-office scoping to intersect
    // against: events_cal.php's own mc_slugs restriction already gates
    // whether a leader sees the event at all, and Training events have no
    // restriction concept at all. Confirmed live 2026-10-22 per a Tech
    // Support ticket (Kris Fuller) asking why she couldn't see RSVPs on an
    // event she didn't personally create.
    if (($scope === 'events' || $scope === 'training')
        && ((function_exists('is_mc_leader') && is_mc_leader()) || (function_exists('is_bic') && is_bic()))) {
        return true;
    }
    if ($scope === 'mc') {
        if ($mcLead === null) $mcLead = ['slugs' => function_exists('my_event_mc_slugs') ? my_event_mc_slugs() : my_mc_slugs()];  // incl. secondary-role MCs
        if (!empty($mcLead['slugs'])) {
            $st = $db->prepare("SELECT mc_slugs FROM mc_events WHERE id=?");
            $st->execute([(int)$eventId]);
            $evSlugs = array_values(array_filter(array_map('trim', explode(',', (string)$st->fetchColumn()))));
            if (array_intersect($evSlugs, $mcLead['slugs'])) return true;
        }
    }
    return false;
}

// 10 (or 11 with a leading 1) digits -> (267) 664-9757; anything else is shown as entered.
function event_rsvp_format_phone(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : $p;
}

// Currently-registered attendees only (status='registered'; waitlisted and
// cancelled excluded), sorted by name. Phone: innovate_roster, then
// agent_intake. Market center: innovate_roster. An email on several active
// roster rows resolves to the lowest row id so the list is deterministic and
// never shows the same person twice. Anything unavailable (e.g. a public
// registrant who isn't an agent) is '—'.
function event_rsvp_attendees(PDO $db, string $scope, string $eventId): array {
    $t  = event_rsvp_table($scope);
    $st = $db->prepare("SELECT agent_name, agent_email FROM {$t} WHERE event_id=? AND status='registered' ORDER BY lower(agent_name), rsvped_at, id");
    $st->execute([$eventId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $emails = array_values(array_unique(array_map(fn($r) => event_rsvp_norm_email($r['agent_email']), $rows)));
    $ph = implode(',', array_fill(0, count($emails), '?'));

    $roster = [];
    $q = $db->prepare(
        "SELECT lower(r.email) e, r.agent_name, r.phone, r.market_center FROM innovate_roster r
          WHERE r.active = 1 AND lower(r.email) IN ($ph)
            AND r.id = (SELECT MIN(r2.id) FROM innovate_roster r2 WHERE r2.active = 1 AND lower(r2.email) = lower(r.email))"
    );
    $q->execute($emails);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $roster[$r['e']] = $r;

    $intake = [];
    $q = $db->prepare("SELECT lower(email) e, phone FROM agent_intake WHERE lower(email) IN ($ph)");
    $q->execute($emails);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $intake[$r['e']] = trim((string)$r['phone']);

    $out = [];
    foreach ($rows as $r) {
        $e  = event_rsvp_norm_email($r['agent_email']);
        $ro = $roster[$e] ?? null;
        $phone = trim((string)($ro['phone'] ?? ''));
        if ($phone === '') $phone = $intake[$e] ?? '';
        $mc   = trim((string)($ro['market_center'] ?? ''));
        $name = trim((string)$r['agent_name']);
        if ($name === '' && $ro) $name = trim((string)$ro['agent_name']);
        $out[] = [
            'name'  => $name !== '' ? $name : $e,
            'email' => $r['agent_email'],
            'phone' => $phone !== '' ? event_rsvp_format_phone($phone) : '—',
            'mc'    => $mc !== '' ? $mc : '—',
        ];
    }
    return $out;
}
