<?php
// Vendorsgiving core backend (Checkpoint C). Pure-ish functions over an explicit
// PDO and an explicit $actor (see vg_actor()) so every rule is unit-testable.
// Endpoints: api/vg_action.php (internal, session + CSRF) and
// api/vg_vendor_rsvp.php (public, per-invitation token, no session).
//
// Parent record is mc_events (schema unchanged). Attendance truth is
// mc_event_rsvps (shared with the calendar RSVP, attendee panel and Company
// Email prefill); vg_* tables add settings, guests, potluck and vendors.
// Nothing in this file sends email or calls external services.
// Does NOT change any existing Market Center Event rule: mc_rsvp_act(),
// mc_events_action.php and event_rsvp_can_manage() are untouched.

require_once __DIR__ . '/vendorsgiving_schema.php';
require_once __DIR__ . '/mc_event_rsvp.php';
require_once __DIR__ . '/mc_event_access.php';

const VG_TZ = 'America/New_York';
const VG_DEFAULT_CATEGORIES = ['Main Dishes', 'Side Dishes', 'Desserts', 'Appetizers', 'Drinks', 'Supplies'];   // board order + colours (UI)
const VG_VENDOR_CATEGORIES = ['lender', 'title_attorney', 'home_inspector', 'insurance', 'home_warranty', 'moving_storage',
                              'photo_media', 'staging_design', 'contractor_trades', 'other'];
const VG_INVITE_TRANSITIONS = [              // organizer-driven status changes
    'suggested' => ['approved', 'rejected', 'cancelled'],
    'approved'  => ['cancelled'],             // → invited only via vg_record_sent (organizer confirms they sent it)
    'rejected'  => ['approved'],
    'invited'   => ['accepted', 'declined', 'cancelled'],
    'accepted'  => ['declined', 'cancelled'],
    'declined'  => ['accepted', 'cancelled'],
    'cancelled' => ['approved'],
];
const VG_LINK_STATUSES = ['approved', 'invited', 'accepted', 'declined'];   // a vendor link works only in these

// ── results ─────────────────────────────────────────────────────────────────
function vg_err(int $code, string $msg, array $extra = []): array { return ['ok' => false, 'code' => $code, 'error' => $msg] + $extra; }
function vg_ok(array $data = []): array { return ['ok' => true, 'code' => 200] + $data; }

function vg_installed(PDO $db): bool { return vg_schema_missing($db) === []; }

// ── settings (config.php 'vendorsgiving' => [...]) ──────────────────────────
// Defaults are the approved initial limits. max_guests is configurable by the
// server administrator (config.php) up to the DB safety ceiling (50); the
// approved default stays 10. mail_transport/dev_sink_dir exist only in the
// isolated development config — production has no transport at all.
function vg_settings(): array {
    $c = $GLOBALS['VG_SETTINGS_OVERRIDE'] ?? (function_exists('cfg') ? (cfg()['vendorsgiving'] ?? []) : []);
    return [
        'enabled'                       => !empty($c['enabled']),                 // internal pages + API
        'public_links_enabled'          => !empty($c['enabled']) && !empty($c['public_links_enabled']),   // public vendor RSVP page + endpoint
        'max_guests'                    => max(0, min(VG_GUEST_DB_CEILING, (int)($c['max_guests'] ?? 10))),
        'link_grace_days'               => max(1, min(90, (int)($c['link_grace_days'] ?? 14))),
        'vendors_count_toward_capacity' => !array_key_exists('vendors_count_toward_capacity', $c) || !empty($c['vendors_count_toward_capacity']),
        'mail_transport'                => ($c['mail_transport'] ?? '') === 'dev_sink' ? 'dev_sink' : '',   // the only value accepted
        'dev_sink_dir'                  => (string)($c['dev_sink_dir'] ?? ''),
        'pilot_markets'                 => vg_pilot_norm($c['pilot_markets'] ?? null),
    ];
}
function vg_enabled(): bool { return vg_settings()['enabled']; }

// ── pilot markets (F1) ──────────────────────────────────────────────────────
// config 'vendorsgiving' => ['pilot_markets' => ['north-myrtle-beach']]  → only these markets
//                                              '*'                        → every market
//                                              absent / [] / anything else → NO market
// Non-Super-Admins see and act on Vendorsgiving events only in live markets
// (enforced in vg_actor/vg_can_* below, so every endpoint inherits it). Super
// Admins always see every market ("preview"); events in a non-live market stay
// invisible to everyone else, including in the shared calendar / MC Events lists.
function vg_pilot_norm($v) {
    if ($v === '*') return '*';
    if (!is_array($v)) return [];
    $out = array_values(array_unique(array_filter(array_map(fn($s) => is_string($s) ? strtolower(trim($s)) : '', $v), fn($s) => $s !== '' && $s !== '*')));
    sort($out);
    return $out;
}
function vg_market_live(string $slug): bool {
    $s = vg_settings();
    if (!$s['enabled'] || $slug === '') return false;
    return $s['pilot_markets'] === '*' || in_array($slug, $s['pilot_markets'], true);
}
function vg_any_market_live(): bool { $s = vg_settings(); return $s['enabled'] && ($s['pilot_markets'] === '*' || $s['pilot_markets'] !== []); }
// Vendorsgiving events a non-Super-Admin must not see anywhere (feature off, or
// market not in the pilot). Used by the guards in the shared calendar / MC Events
// endpoints and Back Office list. Super Admins: none hidden.
function vg_hidden_event_ids(PDO $db, bool $isSuper): array {
    if ($isSuper || !in_array('vg_events', vg_schema_present($db), true)) return [];
    $out = [];
    foreach ($db->query("SELECT mc_event_id, mc_slug FROM vg_events")->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (!vg_market_live((string)$r['mc_slug'])) $out[] = (int)$r['mc_event_id'];
    return $out;
}
// Sidebar link: Super Admins whenever the feature is enabled; others only when
// they can attend or organize in at least one live market.
function vg_nav_visible(PDO $db, string $email, array $perms): bool {
    if (!vg_enabled() || !vg_installed($db)) return false;
    if (!empty($perms['isSuperAdmin'])) return true;
    if (!vg_any_market_live()) return false;
    $a = vg_actor($db, $email, $perms);
    return $a['organizer_slugs'] !== [] || $a['member_slugs'] !== [] || $a['is_staff'];
}
// Ids of all Vendorsgiving events (for the guards in existing calendar/MC-event endpoints).
function vg_event_ids(PDO $db): array {
    if (!in_array('vg_events', vg_schema_present($db), true)) return [];
    return array_map('intval', $db->query("SELECT mc_event_id FROM vg_events")->fetchAll(PDO::FETCH_COLUMN));
}
function vg_is_vg_event(PDO $db, int $id): bool {
    if (!in_array('vg_events', vg_schema_present($db), true)) return false;   // guard works whenever the event sidecar exists
    $st = $db->prepare("SELECT 1 FROM vg_events WHERE mc_event_id=?"); $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

// ── actor & authorization ───────────────────────────────────────────────────
// $perms is roles.php's current_perms()/fetch_perms_local() shape. Organizer
// markets = super admin: every enabled market; otherwise ONLY if the person holds
// a BIC or MC Leader role (primary OR secondary): the markets of those roles plus
// any market a Super Admin granted via mc_event_grants. Staff/admin status alone,
// a "partner seat" (role agent + mc_slugs) or a creator email never organizes.
function vg_actor(PDO $db, string $email, array $perms): array {
    $email   = strtolower(trim($email));
    $role    = (string)($perms['role'] ?? 'agent');
    $roles   = array_values(array_unique(array_merge([$role], (array)($perms['roles'] ?? []))));
    $isSuper = !empty($perms['isSuperAdmin']);
    $isAdmin = !empty($perms['isAdmin']);
    $leaderPrimary = in_array($role, ['bic', 'mc_leader'], true);
    $org = [];
    if ($leaderPrimary) $org = array_merge($org, (array)($perms['mc_slugs'] ?? []));
    $org = array_merge($org, (array)($perms['secondary_mc_slugs'] ?? []));   // secondary bic/mc_leader roles
    $isLeader = $leaderPrimary || !empty($perms['secondary_mc_slugs']);
    if ($isLeader && $email !== '') $org = array_merge($org, mc_access_granted_slugs($db, $email));
    if ($isSuper) $org = $db->query("SELECT slug FROM market_centers WHERE enabled=1")->fetchAll(PDO::FETCH_COLUMN);
    $org = array_values(array_unique(array_filter(array_map('strval', $org))));
    sort($org);
    $member = $email !== '' ? mc_rsvp_member_slugs($db, $email) : [];
    // F1 pilot: outside the live markets a non-Super-Admin has no Vendorsgiving
    // role at all. The pre-pilot lists are kept only to explain "not available yet".
    $live = fn(array $l) => array_values(array_filter($l, 'vg_market_live'));
    return [
        'email'           => $email,
        'name'            => trim((string)($perms['name'] ?? '')),
        'is_super'        => $isSuper,
        'is_staff'        => $isAdmin,          // staff / admin / super admin tier: view + RSVP any LIVE market
        'organizer_slugs' => $isSuper ? $org : $live($org),
        'member_slugs'    => $isSuper ? $member : $live($member),
        'inactive_slugs'  => $isSuper ? [] : array_values(array_diff(array_unique(array_merge($org, $member)), $live(array_merge($org, $member)))),
    ];
}
function vg_can_manage(array $a, string $slug): bool { return $a['email'] !== '' && ($a['is_super'] || (in_array($slug, $a['organizer_slugs'], true) && vg_market_live($slug))); }
function vg_can_attend(array $a, string $slug): bool {
    if ($a['email'] === '') return false;
    if (vg_can_manage($a, $slug)) return true;
    return vg_market_live($slug) && ($a['is_staff'] || in_array($slug, $a['member_slugs'], true));
}

// ── normalization helpers ───────────────────────────────────────────────────
function vg_clean(?string $s, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s)), 0, $max); }
function vg_email_norm(string $e): string { return strtolower(trim($e)); }
function vg_phone_norm(string $p): string { $d = preg_replace('/\D+/', '', $p); return strlen($d) > 10 ? substr($d, -10) : $d; }
function vg_company_norm(string $c): string {
    $c = strtolower(str_replace('&', ' and ', $c));
    $c = preg_replace('/[^a-z0-9 ]+/', ' ', $c);
    $c = preg_replace('/\b(the|llc|l l c|inc|incorporated|co|corp|corporation|company|group|ltd|pllc|pc|pa)\b/', ' ', $c);
    return trim(preg_replace('/\s+/', ' ', $c));
}
function vg_item_norm(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\b(homemade|home made|my|famous|the|a|an|some|of|and|with|fresh)\b/', ' ', $s);
    $w = array_map(fn($x) => strlen($x) > 3 ? preg_replace('/(ies)$/', 'y', preg_replace('/(?<!s)s$/', '', $x)) : $x, preg_split('/\s+/', trim($s)) ?: []);
    return trim(implode(' ', array_filter($w)));
}

// ── time ────────────────────────────────────────────────────────────────────
function vg_ts(string $ymd, string $hm): ?int {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$ymd $hm", new DateTimeZone(VG_TZ));
    return ($dt && $dt->format('Y-m-d H:i') === "$ymd $hm") ? $dt->getTimestamp() : null;
}
function vg_valid_date(string $d): bool { $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }
function vg_valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

// ── audit ───────────────────────────────────────────────────────────────────
// Never put tokens, token hashes or full vendor contact details into detail.
function vg_audit(PDO $db, string $actor, string $actorType, string $action, ?int $eventId, string $targetType = '', ?int $targetId = null, array $detail = []): void {
    $db->prepare("INSERT INTO vg_audit (actor, actor_type, action, mc_event_id, target_type, target_id, detail_json) VALUES (?,?,?,?,?,?,?)")
       ->execute([$actor, $actorType, $action, $eventId, $targetType, $targetId, json_encode($detail, JSON_UNESCAPED_SLASHES)]);
}

// ── events ──────────────────────────────────────────────────────────────────
// Joined event row + integrity: 'ok' | 'parent_missing' | 'market_mismatch'.
function vg_load_event(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT v.*, m.id AS m_id, m.name, m.mc_slugs, m.start_date, m.end_date, m.start_time, m.end_time,
                               m.location, m.description, m.rsvp_enabled, m.created_by AS mc_created_by
                          FROM vg_events v LEFT JOIN mc_events m ON m.id = v.mc_event_id WHERE v.mc_event_id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['integrity'] = $r['m_id'] === null ? 'parent_missing' : (trim((string)$r['mc_slugs']) === $r['mc_slug'] ? 'ok' : 'market_mismatch');
    $r['market_name'] = (string)($db->query("SELECT name FROM market_centers WHERE slug=" . $db->quote($r['mc_slug']))->fetchColumn() ?: $r['mc_slug']);
    return $r;
}
function vg_event_start_ts(array $ev): ?int { return vg_ts((string)$ev['start_date'], (string)($ev['start_time'] ?: '00:00')); }

// RSVP window: closed when RSVP is off, event cancelled, deadline passed, or the event has started.
function vg_rsvp_closed_reason(array $ev, int $now): ?string {
    if ($ev['status'] !== 'active') return 'This event has been cancelled';
    if ((int)$ev['rsvp_enabled'] !== 1) return 'RSVP is not enabled for this event';
    if ($ev['rsvp_deadline']) { [$d, $t] = explode(' ', $ev['rsvp_deadline']) + [1 => '23:59']; $dl = vg_ts($d, $t); if ($dl !== null && $now > $dl) return 'The RSVP deadline has passed'; }
    $start = vg_event_start_ts($ev);
    if ($start !== null && $now >= $start) return 'This event has already started';
    return null;
}

// Validates create/update input. One market only: accepts mc_slug (string) or
// mc_slugs (array of exactly one). Returns ['data'=>...] or vg_err().
function vg_validate_event(PDO $db, array $in): array {
    if (array_key_exists('mc_slugs', $in)) {
        $arr = array_values(array_unique(array_filter(array_map('strval', (array)$in['mc_slugs']))));
        if (count($arr) !== 1) return vg_err(400, 'A Vendorsgiving event must belong to exactly one market');
        $slug = $arr[0];
    } else {
        $slug = trim((string)($in['mc_slug'] ?? ''));
        if (str_contains($slug, ',')) return vg_err(400, 'A Vendorsgiving event must belong to exactly one market');
    }
    if ($slug === '') return vg_err(400, 'Market is required');
    $st = $db->prepare("SELECT 1 FROM market_centers WHERE slug=? AND enabled=1"); $st->execute([$slug]);
    if (!$st->fetchColumn()) return vg_err(400, 'Unknown or disabled market');
    $d = [
        'mc_slug'     => $slug,
        'title'       => vg_clean($in['title'] ?? '', 150),
        'date'        => trim((string)($in['date'] ?? '')),
        'start_time'  => trim((string)($in['start_time'] ?? '')),
        'end_time'    => trim((string)($in['end_time'] ?? '')),
        'location'    => vg_clean($in['location'] ?? '', 200),
        'description' => vg_clean($in['description'] ?? '', 5000),
        'rsvp_enabled'           => !array_key_exists('rsvp_enabled', $in) || !empty($in['rsvp_enabled']) ? 1 : 0,
        'potluck_enabled'        => !array_key_exists('potluck_enabled', $in) || !empty($in['potluck_enabled']) ? 1 : 0,
        'vendor_invites_enabled' => !array_key_exists('vendor_invites_enabled', $in) || !empty($in['vendor_invites_enabled']) ? 1 : 0,
        'capacity'      => null,
        'rsvp_deadline' => null,
    ];
    if ($d['title'] === '')    return vg_err(400, 'Event title is required');
    if ($d['location'] === '') return vg_err(400, 'Location is required');
    if (!vg_valid_date($d['date'])) return vg_err(400, 'Date must be YYYY-MM-DD');
    if (!vg_valid_time($d['start_time']) || !vg_valid_time($d['end_time'])) return vg_err(400, 'Start and end time are required (HH:MM)');
    if ($d['end_time'] <= $d['start_time']) return vg_err(400, 'End time must be after start time');
    if (isset($in['capacity']) && $in['capacity'] !== '' && $in['capacity'] !== null) {
        if (!is_numeric($in['capacity']) || (int)$in['capacity'] != $in['capacity'] || (int)$in['capacity'] < 1 || (int)$in['capacity'] > 5000) return vg_err(400, 'Capacity must be a whole number from 1 to 5000');
        $d['capacity'] = (int)$in['capacity'];
    }
    if (isset($in['rsvp_deadline']) && trim((string)$in['rsvp_deadline']) !== '') {
        $dl = trim((string)$in['rsvp_deadline']);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $dl, $m) || vg_ts($m[1], $m[2]) === null) return vg_err(400, 'RSVP deadline must be YYYY-MM-DD HH:MM');
        if (vg_ts($m[1], $m[2]) > vg_ts($d['date'], $d['start_time'])) return vg_err(400, 'RSVP deadline must be before the event starts');
        $d['rsvp_deadline'] = $dl;
    }
    return ['data' => $d];
}

function vg_create_event(PDO $db, array $actor, array $in): array {
    $v = vg_validate_event($db, $in);
    if (isset($v['error'])) return $v;
    $d = $v['data'];
    if (!vg_can_manage($actor, $d['mc_slug'])) return vg_err(403, 'You can only create Vendorsgiving events for markets you lead');
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Same columns the existing Market Center Events save writes — the event
        // shows in the calendar / mc_events.php exactly like any other MC event.
        $db->prepare("INSERT INTO mc_events (mc_slugs, name, start_date, end_date, start_time, end_time, location, url, description, created_by, rsvp_enabled, image_url)
                      VALUES (?,?,?,'',?,?,?,'',?,?,?, '')")
           ->execute([$d['mc_slug'], $d['title'], $d['date'], $d['start_time'], $d['end_time'], $d['location'], $d['description'], $actor['email'], $d['rsvp_enabled']]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO vg_events (mc_event_id, mc_slug, capacity, rsvp_deadline, potluck_enabled, vendor_invites_enabled, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$id, $d['mc_slug'], $d['capacity'], $d['rsvp_deadline'], $d['potluck_enabled'], $d['vendor_invites_enabled'], $actor['email'], $actor['email']]);
        $cat = $db->prepare("INSERT INTO vg_potluck_categories (mc_event_id, name, sort_ord) VALUES (?,?,?)");
        foreach (VG_DEFAULT_CATEGORIES as $i => $c) $cat->execute([$id, $c, $i]);
        vg_audit($db, $actor['email'], 'user', 'event.create', $id, 'event', $id, ['market' => $d['mc_slug'], 'title' => $d['title']]);
        $db->exec('COMMIT');
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    return vg_ok(['id' => $id]);
}

function vg_update_event(PDO $db, array $actor, int $id, array $in): array {
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    if (!vg_can_manage($actor, $ev['mc_slug'])) return vg_err(403, 'Only organizers for this market can edit this event');
    if ($ev['integrity'] !== 'ok') return vg_err(409, 'This event was changed outside Vendorsgiving and needs a Super Admin to review it');
    $v = vg_validate_event($db, $in + ['mc_slug' => $ev['mc_slug']]);
    if (isset($v['error'])) return $v;
    $d = $v['data'];
    if ($d['mc_slug'] !== $ev['mc_slug'] && !$actor['is_super']) return vg_err(403, "Only a Super Admin can change an event's market");
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare("UPDATE mc_events SET mc_slugs=?, name=?, start_date=?, start_time=?, end_time=?, location=?, description=?, rsvp_enabled=? WHERE id=?")
           ->execute([$d['mc_slug'], $d['title'], $d['date'], $d['start_time'], $d['end_time'], $d['location'], $d['description'], $d['rsvp_enabled'], $id]);
        $db->prepare("UPDATE vg_events SET mc_slug=?, capacity=?, rsvp_deadline=?, potluck_enabled=?, vendor_invites_enabled=?, updated_by=?, updated_at=datetime('now') WHERE mc_event_id=?")
           ->execute([$d['mc_slug'], $d['capacity'], $d['rsvp_deadline'], $d['potluck_enabled'], $d['vendor_invites_enabled'], $actor['email'], $id]);
        $changed = [];
        foreach (['title' => 'name', 'date' => 'start_date', 'start_time' => 'start_time', 'end_time' => 'end_time', 'location' => 'location', 'rsvp_enabled' => 'rsvp_enabled', 'mc_slug' => 'mc_slug', 'capacity' => 'capacity', 'rsvp_deadline' => 'rsvp_deadline', 'potluck_enabled' => 'potluck_enabled', 'vendor_invites_enabled' => 'vendor_invites_enabled'] as $k => $col) {
            if ((string)$d[$k] !== (string)$ev[$col]) $changed[$k] = ['from' => $ev[$col], 'to' => $d[$k]];
        }
        vg_audit($db, $actor['email'], 'user', 'event.update', $id, 'event', $id, $changed);
        $db->exec('COMMIT');
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    return vg_ok(['id' => $id]);
}

function vg_set_event_status(PDO $db, array $actor, int $id, string $status): array {
    if (!in_array($status, ['active', 'cancelled'], true)) return vg_err(400, 'Unknown status');
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    if (!vg_can_manage($actor, $ev['mc_slug'])) return vg_err(403, 'Only organizers for this market can change this event');
    $db->prepare("UPDATE vg_events SET status=?, updated_by=?, updated_at=datetime('now') WHERE mc_event_id=?")->execute([$status, $actor['email'], $id]);
    vg_audit($db, $actor['email'], 'user', 'event.status', $id, 'event', $id, ['from' => $ev['status'], 'to' => $status]);
    return vg_ok();
}

// ── attendance ──────────────────────────────────────────────────────────────
// attending = mc_event_rsvps.status='registered' (shared truth). Guests only
// count while attending. response 'no' = explicit decline.
function vg_my_rsvp(PDO $db, int $id, string $email): array {
    $email = strtolower(trim($email));
    $reg = mc_rsvp_is_registered($db, $id, $email);
    $st = $db->prepare("SELECT response, guest_count FROM vg_attendees WHERE mc_event_id=? AND email=?"); $st->execute([$id, $email]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return ['attending' => $reg, 'response' => $reg ? 'yes' : (($a['response'] ?? null) === 'no' ? 'no' : null), 'guest_count' => $reg ? (int)($a['guest_count'] ?? 0) : 0];
}

// Total expected people: registered attendees + their guests + accepted vendor reps (1 + vendor guests).
function vg_headcount(PDO $db, int $id): array {
    $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(COALESCE(a.guest_count,0)),0) g FROM mc_event_rsvps r
                         LEFT JOIN vg_attendees a ON a.mc_event_id=r.event_id AND a.email=r.agent_email
                         WHERE r.event_id=? AND r.status='registered'");
    $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(response_guest_count),0) g FROM vg_invitations WHERE mc_event_id=? AND status='accepted'");
    $st->execute([$id]); $v = $st->fetch(PDO::FETCH_ASSOC);
    $att = (int)$r['n']; $gst = (int)$r['g']; $ven = (int)$v['n'] + (int)$v['g'];
    return ['attendees' => $att, 'guests' => $gst, 'vendor_people' => $ven,
            'total' => $att + $gst + (vg_settings()['vendors_count_toward_capacity'] ? $ven : 0)];
}

// Cancelling ('no') while the person still has potluck items requires
// $confirmRemoval=true; otherwise 409 + the items, so the UI can explain and confirm.
function vg_rsvp(PDO $db, array $actor, int $id, string $response, int $guests, ?int $now = null, bool $confirmRemoval = false): array {
    $now = $now ?? time();
    $maxG = vg_settings()['max_guests'];
    if (!in_array($response, ['yes', 'no'], true)) return vg_err(400, 'Response must be yes or no');
    if ($guests < 0 || $guests > $maxG) return vg_err(400, "Guests must be between 0 and $maxG");
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    if ($ev['integrity'] !== 'ok') return vg_err(409, 'This event is temporarily unavailable');
    if ($why = vg_rsvp_closed_reason($ev, $now)) return vg_err(409, $why);
    $email = $actor['email']; $name = $actor['name'] !== '' ? $actor['name'] : $email;
    if ($response === 'no') {
        $guests = 0;
        $st = $db->prepare("SELECT id, item_name FROM vg_contributions WHERE mc_event_id=? AND contributor_email=? AND status='active' ORDER BY id");
        $st->execute([$id, $email]); $mine = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($mine && !$confirmRemoval) return vg_err(409, 'Cancelling will remove the items you signed up to bring', ['confirm_required' => true, 'items' => array_column($mine, 'item_name')]);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $before = vg_my_rsvp($db, $id, $email);
        if ($response === 'yes' && $ev['capacity'] !== null) {
            $hc = vg_headcount($db, $id);
            $after = $hc['total'] - ($before['attending'] ? 1 + $before['guest_count'] : 0) + 1 + $guests;
            if ($after > (int)$ev['capacity']) { $db->exec('ROLLBACK'); return vg_err(409, 'Sorry, this event is at capacity', ['remaining' => max(0, (int)$ev['capacity'] - ($hc['total'] - ($before['attending'] ? 1 + $before['guest_count'] : 0)))]); }
        }
        if ($response === 'yes') {
            // identical upsert semantics to mc_rsvp_act(): keep original timestamp if already registered
            $db->prepare("INSERT INTO mc_event_rsvps (event_id, agent_email, agent_name, status) VALUES (?,?,?,'registered')
                          ON CONFLICT(event_id, agent_email) DO UPDATE SET agent_name=excluded.agent_name,
                            rsvped_at = CASE WHEN mc_event_rsvps.status='registered' THEN mc_event_rsvps.rsvped_at ELSE datetime('now') END,
                            status='registered'")->execute([$id, $email, $name]);
        } else {
            $db->prepare("UPDATE mc_event_rsvps SET status='cancelled' WHERE event_id=? AND agent_email=? AND status='registered'")->execute([$id, $email]);
        }
        $db->prepare("INSERT INTO vg_attendees (mc_event_id, email, name, response, guest_count) VALUES (?,?,?,?,?)
                      ON CONFLICT(mc_event_id, email) DO UPDATE SET name=excluded.name, response=excluded.response, guest_count=excluded.guest_count, updated_at=datetime('now')")
           ->execute([$id, $email, $name, $response, $guests]);
        $removed = 0; $removedItems = [];
        if ($response === 'no') {
            $st = $db->prepare("SELECT item_name FROM vg_contributions WHERE mc_event_id=? AND contributor_email=? AND status='active'");
            $st->execute([$id, $email]); $removedItems = $st->fetchAll(PDO::FETCH_COLUMN);
            $st = $db->prepare("UPDATE vg_contributions SET status='removed', updated_by=?, updated_at=datetime('now') WHERE mc_event_id=? AND contributor_email=? AND status='active'");
            $st->execute([$email, $id, $email]); $removed = $st->rowCount();
        }
        vg_audit($db, $email, 'user', 'rsvp.' . $response, $id, 'attendee', null, ['guests' => $guests, 'was' => $before['response'], 'contributions_removed' => $removed] + ($removedItems ? ['removed_items' => $removedItems] : []));
        $db->exec('COMMIT');
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    return vg_ok(['rsvp' => vg_my_rsvp($db, $id, $email), 'contributions_removed' => $removed]);
}

// ── potluck ─────────────────────────────────────────────────────────────────
function vg_dup_warnings(PDO $db, int $id, string $norm, int $excludeId = 0): array {
    if ($norm === '') return [];
    $st = $db->prepare("SELECT c.id, c.item_name, c.item_norm, c.contributor_name, k.name category FROM vg_contributions c JOIN vg_potluck_categories k ON k.id=c.category_id
                         WHERE c.mc_event_id=? AND c.status='active' AND c.id<>?");
    $st->execute([$id, $excludeId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = $r['item_norm'];
        if ($o === $norm || (strlen($norm) >= 4 && strlen($o) >= 4 && (str_contains($o, $norm) || str_contains($norm, $o)))) {
            $out[] = ['item' => $r['item_name'], 'by' => $r['contributor_name'], 'category' => $r['category']];
        }
    }
    return $out;
}

function vg_contribution_guard(PDO $db, array $actor, int $id, int $now): array {
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    if ($ev['integrity'] !== 'ok') return vg_err(409, 'This event is temporarily unavailable');
    if ((int)$ev['potluck_enabled'] !== 1) return vg_err(400, 'Potluck sign-up is not enabled for this event');
    if ($ev['status'] !== 'active') return vg_err(409, 'This event has been cancelled');
    $start = vg_event_start_ts($ev);
    if ($start !== null && $now >= $start) return vg_err(409, 'This event has already started');
    return ['ev' => $ev];
}

function vg_add_contribution(PDO $db, array $actor, int $id, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $g = vg_contribution_guard($db, $actor, $id, $now); if (isset($g['error'])) return $g;
    if (!vg_my_rsvp($db, $id, $actor['email'])['attending']) return vg_err(409, 'RSVP as attending before signing up to bring something');
    $item = vg_clean($in['item_name'] ?? '', 120); $notes = vg_clean($in['notes'] ?? '', 300);
    $qty = (int)($in['qty'] ?? 1); $cat = (int)($in['category_id'] ?? 0);
    if ($item === '') return vg_err(400, 'Item name is required');
    if ($qty < 1 || $qty > 100) return vg_err(400, 'Quantity must be between 1 and 100');
    $st = $db->prepare("SELECT 1 FROM vg_potluck_categories WHERE id=? AND mc_event_id=?"); $st->execute([$cat, $id]);
    $known = (bool)$st->fetchColumn(); $st->closeCursor();   // F1: release the read snapshot before writing
    if (!$known) return vg_err(400, 'Unknown category for this event');
    $norm = vg_item_norm($item);
    $warn = vg_dup_warnings($db, $id, $norm);
    $db->prepare("INSERT INTO vg_contributions (mc_event_id, category_id, contributor_email, contributor_name, item_name, item_norm, qty, notes, updated_by) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$id, $cat, $actor['email'], $actor['name'] !== '' ? $actor['name'] : $actor['email'], $item, $norm, $qty, $notes, $actor['email']]);
    $cid = (int)$db->lastInsertId();
    vg_audit($db, $actor['email'], 'user', 'contribution.add', $id, 'contribution', $cid, ['item' => $item, 'qty' => $qty, 'dup_warnings' => count($warn)]);
    return vg_ok(['id' => $cid, 'duplicate_warnings' => $warn]);
}

function vg_load_contribution(PDO $db, int $cid): ?array {
    $st = $db->prepare("SELECT * FROM vg_contributions WHERE id=?"); $st->execute([$cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Owner may edit/remove their own; organizers for the market may manage any.
function vg_update_contribution(PDO $db, array $actor, int $cid, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $c = vg_load_contribution($db, $cid); if (!$c || $c['status'] !== 'active') return vg_err(404, 'Contribution not found');
    $g = vg_contribution_guard($db, $actor, (int)$c['mc_event_id'], $now); if (isset($g['error'])) return $g;
    $isOwner = $c['contributor_email'] === $actor['email']; $isOrg = vg_can_manage($actor, $g['ev']['mc_slug']);
    if (!$isOwner && !$isOrg) return vg_err(403, 'You can only change your own items');
    $item = array_key_exists('item_name', $in) ? vg_clean($in['item_name'], 120) : $c['item_name'];
    $qty = array_key_exists('qty', $in) ? (int)$in['qty'] : (int)$c['qty'];
    $notes = array_key_exists('notes', $in) ? vg_clean($in['notes'], 300) : $c['notes'];
    $cat = array_key_exists('category_id', $in) ? (int)$in['category_id'] : (int)$c['category_id'];
    if ($item === '') return vg_err(400, 'Item name is required');
    if ($qty < 1 || $qty > 100) return vg_err(400, 'Quantity must be between 1 and 100');
    $st = $db->prepare("SELECT 1 FROM vg_potluck_categories WHERE id=? AND mc_event_id=?"); $st->execute([$cat, $c['mc_event_id']]);
    $known = (bool)$st->fetchColumn(); $st->closeCursor();   // F1: release the read snapshot before writing
    if (!$known) return vg_err(400, 'Unknown category for this event');
    $norm = vg_item_norm($item);
    $db->prepare("UPDATE vg_contributions SET item_name=?, item_norm=?, qty=?, notes=?, category_id=?, updated_by=?, updated_at=datetime('now') WHERE id=?")
       ->execute([$item, $norm, $qty, $notes, $cat, $actor['email'], $cid]);
    vg_audit($db, $actor['email'], 'user', $isOwner ? 'contribution.update' : 'contribution.update_by_organizer', (int)$c['mc_event_id'], 'contribution', $cid, ['item' => $item, 'qty' => $qty]);
    return vg_ok(['duplicate_warnings' => vg_dup_warnings($db, (int)$c['mc_event_id'], $norm, $cid)]);
}

function vg_remove_contribution(PDO $db, array $actor, int $cid, ?int $now = null): array {
    $now = $now ?? time();
    $c = vg_load_contribution($db, $cid); if (!$c || $c['status'] !== 'active') return vg_err(404, 'Contribution not found');
    $g = vg_contribution_guard($db, $actor, (int)$c['mc_event_id'], $now); if (isset($g['error'])) return $g;
    $isOwner = $c['contributor_email'] === $actor['email']; $isOrg = vg_can_manage($actor, $g['ev']['mc_slug']);
    if (!$isOwner && !$isOrg) return vg_err(403, 'You can only remove your own items');
    $db->prepare("UPDATE vg_contributions SET status='removed', updated_by=?, updated_at=datetime('now') WHERE id=?")->execute([$actor['email'], $cid]);
    vg_audit($db, $actor['email'], 'user', $isOwner ? 'contribution.remove' : 'contribution.remove_by_organizer', (int)$c['mc_event_id'], 'contribution', $cid, ['item' => $c['item_name']]);
    return vg_ok();
}

// Board: visible to everyone eligible for the event (Checkpoint D: "view what
// everyone is bringing"); adding still requires an attending RSVP. Names only —
// never emails — except organizers also get contributor_email for management.
function vg_potluck_board(PDO $db, array $actor, int $id): array {
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    $isOrg = vg_can_manage($actor, $ev['mc_slug']);
    $cats = $db->prepare("SELECT id, name FROM vg_potluck_categories WHERE mc_event_id=? ORDER BY sort_ord, id"); $cats->execute([$id]);
    $board = []; foreach ($cats->fetchAll(PDO::FETCH_ASSOC) as $c) $board[(int)$c['id']] = ['id' => (int)$c['id'], 'name' => $c['name'], 'items' => [], 'item_count' => 0, 'qty' => 0];
    $st = $db->prepare("SELECT id, category_id, contributor_email, contributor_name, item_name, item_norm, qty, notes FROM vg_contributions WHERE mc_event_id=? AND status='active' ORDER BY id");
    $st->execute([$id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $similar = fn(string $a, string $b) => $a !== '' && ($a === $b || (strlen($a) >= 4 && strlen($b) >= 4 && (str_contains($a, $b) || str_contains($b, $a))));
    $dupIds = [];
    foreach ($rows as $i => $x) foreach ($rows as $j => $y) if ($i < $j && $similar($x['item_norm'], $y['item_norm'])) { $dupIds[$x['id']] = 1; $dupIds[$y['id']] = 1; }
    $people = [];
    foreach ($rows as $r) {
        $k = (int)$r['category_id']; if (!isset($board[$k])) continue;
        $it = ['id' => (int)$r['id'], 'item' => $r['item_name'], 'qty' => (int)$r['qty'], 'notes' => $r['notes'], 'by' => $r['contributor_name'],
               'mine' => $r['contributor_email'] === $actor['email'], 'can_edit' => $r['contributor_email'] === $actor['email'] || $isOrg,
               'possible_duplicate' => isset($dupIds[$r['id']])];
        if ($isOrg) $it['contributor_email'] = $r['contributor_email'];
        $board[$k]['items'][] = $it; $board[$k]['item_count']++; $board[$k]['qty'] += (int)$r['qty']; $people[$r['contributor_email']] = 1;
    }
    // Vendor contributions: shown inside their chosen category card (separate list,
    // so attendee item counts are unchanged); uncategorised ones under "From our vendors".
    $vend = $db->prepare("SELECT k.name company_name, i.response_bringing, i.response_contribution, i.response_category_id FROM vg_invitations i JOIN vendor_companies k ON k.id=i.company_id
                           WHERE i.mc_event_id=? AND i.status='accepted' AND i.response_bringing<>'none' ORDER BY k.name");
    $vend->execute([$id]);
    $loose = [];
    foreach ($board as &$b) $b['vendor_items'] = []; unset($b);
    foreach ($vend->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $k = (int)$v['response_category_id'];
        if ($k && isset($board[$k])) $board[$k]['vendor_items'][] = ['item' => $v['response_contribution'], 'by' => $v['company_name'], 'bringing' => $v['response_bringing']];
        else $loose[] = ['company_name' => $v['company_name'], 'response_bringing' => $v['response_bringing'], 'response_contribution' => $v['response_contribution']];
    }
    return vg_ok(['categories' => array_values($board), 'vendor_contributions' => $loose,
                  'summary' => ['items' => count($rows), 'qty' => array_sum(array_column($rows, 'qty')), 'contributors' => count($people)]]);
}

// ── directory ───────────────────────────────────────────────────────────────
// Staff / admin / Super Admin: every Vendorsgiving event. Everyone else: events
// in markets they can attend (member) or organize.
function vg_directory(PDO $db, array $actor, bool $includePast = false, ?int $now = null): array {
    $now = $now ?? time();
    $rows = $db->query("SELECT mc_event_id FROM vg_events ORDER BY mc_event_id")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $id) {
        $ev = vg_load_event($db, (int)$id);
        if (!$ev || $ev['integrity'] === 'parent_missing' || !vg_can_attend($actor, $ev['mc_slug'])) continue;
        $start = vg_event_start_ts($ev);
        if (!$includePast && $start !== null && $start < $now - 86400) continue;
        $out[] = ['id' => (int)$id, 'title' => $ev['name'], 'market' => $ev['mc_slug'], 'market_name' => $ev['market_name'], 'date' => $ev['start_date'],
                  'start_time' => $ev['start_time'], 'end_time' => $ev['end_time'], 'location' => $ev['location'], 'status' => $ev['status'],
                  'integrity' => $ev['integrity'], 'can_manage' => vg_can_manage($actor, $ev['mc_slug']), 'my_rsvp' => vg_my_rsvp($db, (int)$id, $actor['email']),
                  'rsvp_open' => vg_rsvp_closed_reason($ev, $now) === null, 'headcount' => vg_headcount($db, (int)$id)['total'], 'capacity' => $ev['capacity'] !== null ? (int)$ev['capacity'] : null,
                  'market_live' => vg_market_live($ev['mc_slug'])];   // false only ever reaches a Super Admin (preview)
    }
    usort($out, fn($a, $b) => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);
    return vg_ok(['events' => $out, 'inactive_markets' => $actor['inactive_slugs'] ?? [], 'any_market_live' => vg_any_market_live()]);
}

function vg_event_detail(PDO $db, array $actor, int $id, ?int $now = null): array {
    $now = $now ?? time();
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    $isOrg = vg_can_manage($actor, $ev['mc_slug']);
    $cats = $db->prepare("SELECT id, name FROM vg_potluck_categories WHERE mc_event_id=? ORDER BY sort_ord, id"); $cats->execute([$id]);
    $out = ['id' => $id, 'title' => $ev['name'], 'market' => $ev['mc_slug'], 'market_name' => $ev['market_name'], 'date' => $ev['start_date'],
            'start_time' => $ev['start_time'], 'end_time' => $ev['end_time'], 'location' => $ev['location'], 'description' => $ev['description'],
            'rsvp_enabled' => (int)$ev['rsvp_enabled'] === 1, 'capacity' => $ev['capacity'] !== null ? (int)$ev['capacity'] : null, 'rsvp_deadline' => $ev['rsvp_deadline'],
            'potluck_enabled' => (int)$ev['potluck_enabled'] === 1, 'vendor_invites_enabled' => (int)$ev['vendor_invites_enabled'] === 1,
            'status' => $ev['status'], 'integrity' => $ev['integrity'], 'can_manage' => $isOrg, 'closed_reason' => vg_rsvp_closed_reason($ev, $now),
            'my_rsvp' => vg_my_rsvp($db, $id, $actor['email']), 'headcount' => vg_headcount($db, $id), 'categories' => $cats->fetchAll(PDO::FETCH_ASSOC),
            'max_guests' => vg_settings()['max_guests'], 'can_change_market' => $actor['is_super'], 'market_live' => vg_market_live($ev['mc_slug'])];
    if ($isOrg) {
        $c = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
        $hc = $out['headcount'];
        $out['counts'] = ['attendees' => $hc['attendees'], 'guests' => $hc['guests'], 'vendor_people' => $hc['vendor_people'], 'headcount' => $hc['total'],
            'capacity' => $out['capacity'], 'contributions' => $c("SELECT COUNT(*) FROM vg_contributions WHERE mc_event_id=$id AND status='active'"),
            'vendors_total' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND status NOT IN ('rejected','cancelled')"),
            'vendors_confirmed' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND status='accepted'"),
            'vendors_invited' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND status IN ('invited','accepted','declined')"),
            'vendors_prepared' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND delivery_status='prepared' AND status IN ('approved','invited')"),
            'vendors_recorded_sent' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND delivery_status='recorded_sent'"),
            'vendors_attended' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND attendance='attended'"),
            'suggestions_pending' => $c("SELECT COUNT(*) FROM vg_invitations WHERE mc_event_id=$id AND status='suggested'"),
            'declined' => $c("SELECT COUNT(*) FROM vg_attendees WHERE mc_event_id=$id AND response='no' AND email NOT IN (SELECT agent_email FROM mc_event_rsvps WHERE event_id=$id AND status='registered')")];
        $st = $db->prepare("SELECT r.agent_name name, r.agent_email email, COALESCE(a.guest_count,0) guest_count, r.rsvped_at FROM mc_event_rsvps r
                             LEFT JOIN vg_attendees a ON a.mc_event_id=r.event_id AND a.email=r.agent_email WHERE r.event_id=? AND r.status='registered' ORDER BY lower(r.agent_name)");
        $st->execute([$id]); $out['attendees'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT email, name, updated_at FROM vg_attendees WHERE mc_event_id=? AND response='no' AND email NOT IN (SELECT agent_email FROM mc_event_rsvps WHERE event_id=? AND status='registered')");
        $st->execute([$id, $id]); $out['declined'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return vg_ok(['event' => $out]);
}

// ── rate limiting (public endpoint) ─────────────────────────────────────────
// Fixed windows; every bucket uses a window of at most VG_RATE_MAX_WINDOW seconds.
// F1 cleanup: rows whose window ended more than VG_RATE_RETENTION ago are deleted
// in small bounded batches during ordinary public requests (no scheduler, no cron).
// A row still counted by any check is at most VG_RATE_MAX_WINDOW old, far inside
// the retention period, so cleanup can never remove an active entry.
const VG_RATE_MAX_WINDOW  = 600;
const VG_RATE_RETENTION   = 86400;   // kept 24 h for abuse review
const VG_RATE_PRUNE_EVERY = 20;      // ~1 in 20 hits prunes …
const VG_RATE_PRUNE_BATCH = 500;     // … at most 500 rows (≫ the 1 row a hit can add)
function vg_rate_hit(PDO $db, string $bucket, string $subject, int $limit, int $window, ?int $now = null, ?bool $prune = null): bool {
    if ($window < 1 || $window > VG_RATE_MAX_WINDOW) throw new InvalidArgumentException('rate window out of range');
    $now = $now ?? time(); $ws = $now - ($now % $window); $subject = hash('sha256', $subject);
    $db->prepare("INSERT INTO vg_rate_limits (bucket, subject, window_start, count) VALUES (?,?,?,1)
                  ON CONFLICT(bucket, subject, window_start) DO UPDATE SET count=count+1")->execute([$bucket, $subject, $ws]);
    $st = $db->prepare("SELECT count FROM vg_rate_limits WHERE bucket=? AND subject=? AND window_start=?"); $st->execute([$bucket, $subject, $ws]);
    $count = (int)$st->fetchColumn(); $st->closeCursor();   // F1: release the read snapshot before the cleanup DELETE
    if ($prune ?? (random_int(1, VG_RATE_PRUNE_EVERY) === 1)) vg_rate_prune($db, $now);
    return $count <= $limit;
}
// Deletes up to $batch expired rows (oldest first). Returns the number deleted.
function vg_rate_prune(PDO $db, ?int $now = null, int $batch = VG_RATE_PRUNE_BATCH): int {
    $now = $now ?? time();
    $st = $db->prepare("DELETE FROM vg_rate_limits WHERE rowid IN (SELECT rowid FROM vg_rate_limits WHERE window_start < ? ORDER BY window_start LIMIT ?)");
    $st->execute([$now - VG_RATE_RETENTION, max(1, min(5000, $batch))]);
    return $st->rowCount();
}

// ── CSRF (internal endpoints; session must already be started by auth.php) ──
function vg_csrf_token(): string {
    if (empty($_SESSION['vg_csrf']) || !is_string($_SESSION['vg_csrf'])) $_SESSION['vg_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['vg_csrf'];
}
function vg_csrf_valid(?string $provided): bool {
    $t = $_SESSION['vg_csrf'] ?? '';
    return is_string($t) && $t !== '' && is_string($provided) && $provided !== '' && hash_equals($t, $provided);
}

// ── calendar RSVP guard (api/mc_event_rsvp.php, Vendorsgiving events only) ──
// The existing calendar RSVP button must not bypass Vendorsgiving rules. For a
// VG event it is routed here: same eligibility, deadline, capacity and
// duplicate handling as the Vendorsgiving page, keeping any guest count already
// on file. Cancelling from the calendar is refused when the person has potluck
// items (they must confirm the removal on the Vendorsgiving page).
// Returns the existing endpoint's shape: ['ok','code','error','rsvped'] (+ url).
function vg_calendar_rsvp(PDO $db, array $actor, int $id, string $action, ?int $now = null): array {
    $url = 'vendorsgiving.php?id=' . $id;
    if (!in_array($action, ['rsvp', 'cancel'], true)) return ['ok' => false, 'code' => 400, 'error' => 'unknown action', 'rsvped' => false];
    if ($action === 'rsvp') {
        $cur = vg_my_rsvp($db, $id, $actor['email']);
        $r = vg_rsvp($db, $actor, $id, 'yes', $cur['guest_count'], $now);
    } else {
        $r = vg_rsvp($db, $actor, $id, 'no', 0, $now, false);
        if (!$r['ok'] && !empty($r['confirm_required'])) $r['error'] = 'You signed up to bring food for this Vendorsgiving event. Cancel from the Vendorsgiving page so you can confirm removing your items.';
    }
    if (!$r['ok']) return ['ok' => false, 'code' => $r['code'], 'error' => $r['error'], 'rsvped' => vg_my_rsvp($db, $id, $actor['email'])['attending'], 'vendorsgiving_url' => $url];
    return ['ok' => true, 'code' => 200, 'error' => '', 'rsvped' => $r['rsvp']['attending'], 'vendorsgiving_url' => $url];
}

// ── CSV exports (F1, organizers only) ───────────────────────────────────────
// Each export is built from the SAME organizer views the page uses
// (vg_event_detail / vg_list_invitations / vg_potluck_board), so it can never
// contain more than the organizer already sees on screen: one event, one market,
// phones only where vg_contact_access() allows. Every export is audited (kind +
// row count only, never the data).
const VG_EXPORT_KINDS = ['attendees', 'vendors', 'potluck'];
// Same wording the organizer page shows (assets/vendorsgiving.js), so a spreadsheet reads like the screen.
const VG_EXPORT_LABELS = [
    'category'   => ['lender' => 'Lender', 'title_attorney' => 'Title / closing attorney', 'home_inspector' => 'Home inspector', 'insurance' => 'Insurance',
                     'home_warranty' => 'Home warranty', 'moving_storage' => 'Moving & storage', 'photo_media' => 'Photography & media',
                     'staging_design' => 'Staging & design', 'contractor_trades' => 'Contractor / trades', 'other' => 'Other'],
    'status'     => ['suggested' => 'Awaiting review', 'approved' => 'Approved', 'rejected' => 'Not invited', 'invited' => 'Invited',
                     'accepted' => 'Attending', 'declined' => 'Declined', 'cancelled' => 'Cancelled'],
    'delivery'   => ['none' => 'Not prepared', 'prepared' => 'Prepared', 'recorded_sent' => 'Sent (recorded manually)', 'queued' => 'Queued',
                     'delivered' => 'Delivered (confirmed by provider)', 'failed' => 'Delivery failed'],
    'bringing'   => ['none' => 'Nothing', 'food' => 'Food', 'supplies' => 'Supplies', 'both' => 'Food & supplies'],
    'attendance' => ['unknown' => 'Not recorded', 'attended' => 'Attended', 'no_show' => 'Did not attend'],
];
function vg_export_label(string $kind, string $v): string { return VG_EXPORT_LABELS[$kind][$v] ?? $v; }
// Spreadsheet formula injection: a text cell starting with = + - @ TAB CR (or
// their full-width forms) is prefixed with an apostrophe so it is shown as text.
function vg_csv_cell($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_int($v)) return (string)$v;
    $s = str_replace("\0", '', (string)$v);
    if ($s !== '' && preg_match('/^[=+\-@\t\r\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $s)) $s = "'" . $s;
    return $s;
}
function vg_csv(array $rows): string {
    $f = fopen('php://temp', 'r+');
    foreach ($rows as $r) fputcsv($f, array_map('vg_csv_cell', $r), ',', '"', '');
    rewind($f); $csv = stream_get_contents($f); fclose($f);
    return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);   // UTF-8 BOM so Excel shows accents correctly; CRLF per RFC 4180
}
function vg_export(PDO $db, array $actor, int $id, string $kind): array {
    if (!in_array($kind, VG_EXPORT_KINDS, true)) return vg_err(400, 'Unknown export');
    $ev = vg_load_event($db, $id);
    if (!$ev || !vg_can_attend($actor, $ev['mc_slug'])) return vg_err(404, 'Event not found');
    if (!vg_can_manage($actor, $ev['mc_slug'])) return vg_err(403, 'Only organizers for this market can export event information');
    $label = $ev['name'] . ' (' . $ev['market_name'] . ', ' . $ev['start_date'] . ')';
    $rows = [];
    if ($kind === 'attendees') {
        $d = vg_event_detail($db, $actor, $id)['event'];
        $rows[] = ['Event', 'Name', 'RSVP status', 'Guests', 'Party size (attendee + guests)', 'RSVP date'];
        foreach ($d['attendees'] as $a) $rows[] = [$label, $a['name'] ?: $a['email'], 'Attending', (int)$a['guest_count'], 1 + (int)$a['guest_count'], substr((string)$a['rsvped_at'], 0, 16)];
        foreach ($d['declined'] as $a) $rows[] = [$label, $a['name'] ?: $a['email'], 'Not attending', 0, 0, substr((string)$a['updated_at'], 0, 16)];
        $n = count($rows) - 1; $hc = $d['headcount'];
        $rows[] = [];
        $rows[] = ['Totals', 'Attendees: ' . $hc['attendees'], '', $hc['guests'], $hc['attendees'] + $hc['guests'], ''];
        $rows[] = ['Totals', 'Accepted vendor people (incl. vendor guests)', '', '', $hc['vendor_people'], ''];
        $rows[] = ['Totals', 'Total expected headcount' . (vg_settings()['vendors_count_toward_capacity'] ? '' : ' (vendors not counted)'), '', '', $hc['total'], $d['capacity'] !== null ? 'Capacity ' . $d['capacity'] : 'No capacity limit'];
    } elseif ($kind === 'vendors') {
        $list = vg_list_invitations($db, $actor, $id)['invitations'];
        $rows[] = ['Event', 'Company', 'First name', 'Last name', 'Email', 'Phone', 'Category', 'Invitation status', 'Delivery', 'Response', 'Vendor guests', 'Bringing', 'Potluck category', 'Item', 'Attendance', 'Opted out of vendor email', 'Source'];
        $resp = ['accepted' => 'Attending', 'declined' => 'Declined'];
        foreach ($list as $i) {
            $phone = $i['phone'] !== '' ? $i['phone'] : ($i['phone_hidden'] ? 'Not shared with your market' : '');
            $rows[] = [$label, $i['company_name'], $i['first_name'], $i['last_name'], $i['email'], $phone, vg_export_label('category', (string)$i['category']),
                       vg_export_label('status', (string)$i['status']), vg_export_label('delivery', (string)$i['delivery_status']),
                       $resp[$i['status']] ?? 'No response', $i['status'] === 'accepted' ? (int)$i['response_guest_count'] : 0,
                       $i['status'] === 'accepted' ? vg_export_label('bringing', (string)$i['response_bringing']) : '', $i['status'] === 'accepted' ? (string)$i['response_category'] : '',
                       $i['status'] === 'accepted' ? (string)$i['response_contribution'] : '', vg_export_label('attendance', (string)$i['attendance']), (bool)$i['suppressed'],
                       $i['source'] === 'suggestion' ? 'Agent suggestion' : 'Added by organizer'];
        }
        $n = count($rows) - 1;
    } else {
        $b = vg_potluck_board($db, $actor, $id);
        $rows[] = ['Event', 'Category', 'Item', 'Quantity', 'Notes', 'Contributor', 'Contributor type'];
        foreach ($b['categories'] as $c) {
            foreach ($c['items'] as $it) $rows[] = [$label, $c['name'], $it['item'], (int)$it['qty'], $it['notes'], $it['by'], 'Attendee'];
            foreach ($c['vendor_items'] as $it) $rows[] = [$label, $c['name'], $it['item'], '', '', $it['by'], 'Vendor'];
        }
        foreach ($b['vendor_contributions'] as $it) $rows[] = [$label, 'From our vendors', $it['response_contribution'], '', '', $it['company_name'], 'Vendor'];
        $n = count($rows) - 1;
    }
    vg_audit($db, $actor['email'], 'user', 'export.' . $kind, $id, 'event', $id, ['rows' => $n]);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ev['name'])), '-') ?: 'event';
    return vg_ok(['filename' => 'vendorsgiving-' . substr($slug, 0, 40) . '-' . $kind . '-' . gmdate('Ymd') . '.csv', 'csv' => vg_csv($rows), 'rows' => $n]);
}

// ── organizer: correct vendor contact details ───────────────────────────────

// Vendors, invitations, public links, templates and the dev email sink.
require_once __DIR__ . '/vendorsgiving_vendors.php';
function vg_update_vendor(PDO $db, array $actor, int $iid, array $in): array { return vg_update_contact($db, $actor, $iid, $in); }   // Checkpoint C/D action name
