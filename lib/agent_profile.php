<?php
// Single-agent data access for agent_profile.php. Deliberately a separate
// parameterized query from backoffice_agents.php's list query (not a shared
// refactor) so this new page can't regress that working list/detail tool.
if (defined('AGENTEDGE_AGENT_PROFILE_LIB_LOADED')) return;
define('AGENTEDGE_AGENT_PROFILE_LIB_LOADED', true);

// ════════════════════════════════════════════════════════════════════════════
// Profile completeness vs. intake submission — the ONE place these rules live.
//
// Two separate concepts (deliberately not the same thing):
//   • Intake submission — agent_intake.submitted / submitted_at. Means "a NEW
//     agent formally submitted their onboarding info". Only onboarding flows
//     set it; its meaning is unchanged for the Onboarding Queue, the intake
//     reminder cron and the public intake form.
//   • Profile completeness — computed live from the agent's current data,
//     never stored (a stored flag goes stale — that is how ~125 bulk-loaded
//     rows ended up stuck as "Draft").
//
// Track (also derived, never stored): an agent is "onboarding" while they
// have an ACTIVE onboard_queue row, otherwise "existing". The Queue already
// owns that lifecycle (active → completed/cancelled), so there is no second
// field to drift out of sync.
//
// Display labels:  onboarding → Draft / Submitted   (unchanged wording)
//                  existing   → Complete / Incomplete
// Every screen and save path calls these functions — do not re-declare field
// lists anywhere else.
// ════════════════════════════════════════════════════════════════════════════

// Core profile fields (13). Kept under its historical name because other
// code and the public intake form's duplicate-submission check rely on it.
const REQUIRED_INTAKE_FIELDS = [
    'full_name'       => 'Full Name',
    'phone'           => 'Phone Number',
    'license_number'  => 'License Number',
    'nar_number'      => 'NAR Number',
    'office_location' => 'Office Location',
    'birthday'        => 'Birthday',
    'address_line1'   => 'Address',
    'city'            => 'City',
    'state'           => 'State',
    'zip'             => 'Zip Code',
    'emergency_name'  => 'Emergency Contact Name',
    'emergency_phone' => 'Emergency Contact Phone',
    'bio'             => 'Bio',
];

// Bonus fields shown alongside the required ones on the completion form, but
// never enforced — the agent can leave these blank and still submit. Kept
// separate from REQUIRED_INTAKE_FIELDS because that list is all-or-nothing
// (api/complete_profile_action.php rejects the whole save if any of those
// come back blank); languages has no such gate.
const OPTIONAL_INTAKE_FIELDS = [
    'languages' => 'Languages Spoken',
    'mls_board' => 'MLS Board',
];

// New agents going through onboarding: UNCHANGED 14-field rule (core 13 +
// Referring Agent) — exactly what api/intake.php and api/intake_public.php
// enforced before this change.
const ONBOARDING_REQUIRED_FIELDS = REQUIRED_INTAKE_FIELDS + [
    'referring_agent' => 'Referring Agent',
];

// Existing/tenured agents: Bio and NAR # are asked for but never block
// completeness; Referring Agent is not asked at all.
const EXISTING_AGENT_RECOMMENDED_FIELDS = [
    'bio'        => 'Bio',
    'nar_number' => 'NAR Number',
];
const EXISTING_AGENT_REQUIRED_FIELDS = [
    'full_name'       => 'Full Name',
    'phone'           => 'Phone Number',
    'license_number'  => 'License Number',
    'office_location' => 'Office Location',
    'birthday'        => 'Birthday',
    'address_line1'   => 'Address',
    'city'            => 'City',
    'state'           => 'State',
    'zip'             => 'Zip Code',
    'emergency_name'  => 'Emergency Contact Name',
    'emergency_phone' => 'Emergency Contact Phone',
];

// Bulk imports stored "year unknown" birthdays as 1900-MM-DD. The stored
// value is preserved, but 1900 must never be read as a real birth year.
const BIRTH_YEAR_UNKNOWN_PLACEHOLDER = 1900;

const PROFILE_TRACK_ONBOARDING = 'onboarding';
const PROFILE_TRACK_EXISTING   = 'existing';

// ── Track ────────────────────────────────────────────────────────────────────

// Lower-cased set of every email with an ACTIVE onboarding-queue row. Cached
// per request; profile_track_cache_reset() exists for tests and for code that
// changes queue status mid-request.
function active_onboarding_email_set(): array {
    static $set = null;
    if (isset($GLOBALS['__profile_track_cache_reset'])) { $set = null; unset($GLOBALS['__profile_track_cache_reset']); }
    if ($set === null) {
        $set = [];
        foreach (local_db()->query("SELECT agent_email FROM onboard_queue WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $e = strtolower(trim((string)$e));
            if ($e !== '') $set[$e] = true;
        }
    }
    return $set;
}
function profile_track_cache_reset(): void {
    $GLOBALS['__profile_track_cache_reset'] = true;
    $GLOBALS['__profile_track_cache_reset_holds'] = true;
}

function is_actively_onboarding(string $email): bool {
    return isset(active_onboarding_email_set()[strtolower(trim($email))]);
}

function profile_track(string $email): string {
    return is_actively_onboarding($email) ? PROFILE_TRACK_ONBOARDING : PROFILE_TRACK_EXISTING;
}

function profile_required_fields_for_track(string $track): array {
    return $track === PROFILE_TRACK_ONBOARDING ? ONBOARDING_REQUIRED_FIELDS : EXISTING_AGENT_REQUIRED_FIELDS;
}

// ── Birthday helpers ─────────────────────────────────────────────────────────

// [year, month, day] for a YYYY-MM-DD value with a real month/day, else null.
function birthday_parts(?string $value): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string)$value), $m)) return null;
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    // Validate month/day against a leap year so Feb 29 is accepted for an
    // unknown (1900) year — 1900 itself was not a leap year.
    if (!checkdate($mo, $d, 2000)) return null;
    if ($y !== BIRTH_YEAR_UNKNOWN_PLACEHOLDER && !checkdate($mo, $d, $y)) return null;
    return [$y, $mo, $d];
}

function birthday_year_unknown(?string $value): bool {
    $p = birthday_parts($value);
    return $p !== null && $p[0] === BIRTH_YEAR_UNKNOWN_PLACEHOLDER;
}

// The ONLY sanctioned way to get a birth year (e.g. for any future age
// calculation): null when the year is the 1900 placeholder or the value is
// not a valid date. Nothing in AgentEdge computes an age today.
function agent_birth_year(?string $value): ?int {
    $p = birthday_parts($value);
    if ($p === null || $p[0] === BIRTH_YEAR_UNKNOWN_PLACEHOLDER) return null;
    return $p[0];
}

// ── Completeness / status ────────────────────────────────────────────────────

// Missing required fields for an already-loaded agent_intake row (or any
// array with the same keys). Shape: [['key'=>..., 'label'=>...], ...].
//   onboarding → presence check only, identical to the pre-existing rule.
//   existing   → presence check; the birthday must also be a real month/day
//                (a 1900 "unknown year" placeholder is accepted).
function profile_missing_fields_from_row(array $row, string $track): array {
    $missing = [];
    foreach (profile_required_fields_for_track($track) as $key => $label) {
        $val = trim((string)($row[$key] ?? ''));
        $ok  = $val !== '';
        if ($ok && $key === 'birthday' && $track === PROFILE_TRACK_EXISTING) $ok = birthday_parts($val) !== null;
        if (!$ok) $missing[] = ['key' => $key, 'label' => $label];
    }
    return $missing;
}

// Recommended-but-optional fields still blank (existing agents only).
function profile_recommended_blank_from_row(array $row, string $track): array {
    if ($track !== PROFILE_TRACK_EXISTING) return [];
    $blank = [];
    foreach (EXISTING_AGENT_RECOMMENDED_FIELDS as $key => $label) {
        if (trim((string)($row[$key] ?? '')) === '') $blank[] = ['key' => $key, 'label' => $label];
    }
    return $blank;
}

// Data-quality flags for cleanup (never block anything by themselves).
function profile_data_flags_from_row(array $row): array {
    $flags = [];
    if (birthday_year_unknown($row['birthday'] ?? '')) $flags[] = ['key' => 'birth_year_unknown', 'label' => 'Birth year unknown'];
    return $flags;
}

// ── Needs Review ─────────────────────────────────────────────────────────────
// Review HOLDS are stored data (table agent_review_holds), placed and resolved
// only by an admin, never by code paths an agent can reach — so an agent can
// not lose a hold by editing their profile, and there is no hard-coded list
// of agents anywhere in the application. Review SIGNALS (possible duplicate,
// questionable data, identity mismatch) are computed live and only SUGGEST a
// hold to staff; they never change an agent's status on their own.
const REVIEW_HOLD_REASONS = [
    'duplicate'         => 'Possible duplicate record',
    'questionable_data' => 'Questionable profile information',
    'identity'          => 'Unresolved identity / email issue',
    'other'             => 'Other (see note)',
];

// email => [active hold rows]. Cached per request.
function review_holds_map(): array {
    static $map = null;
    if (isset($GLOBALS['__profile_track_cache_reset_holds'])) { $map = null; unset($GLOBALS['__profile_track_cache_reset_holds']); }
    if ($map === null) {
        $map = [];
        $db = local_db();
        if (review_holds_schema_ready($db)) {
            foreach ($db->query("SELECT id, email, reason_code, reason_note, placed_by, placed_at, source FROM agent_review_holds WHERE resolved_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $map[strtolower(trim($h['email']))][] = $h;
            }
        }
    }
    return $map;
}
function active_review_holds(string $email): array { return review_holds_map()[strtolower(trim($email))] ?? []; }

// Live review signals for a set of agent_intake rows (needs the whole set to
// spot duplicates). Returns email => [['key'=>..,'label'=>..], ...].
// $rosterEmails / $rosterNames: active innovate_roster emails and names.
function review_signals_for_rows(array $rows, array $rosterEmails, array $rosterNamesToEmails): array {
    $digits = fn($s) => substr(preg_replace('/\D/', '', (string)$s), -10);
    $byPhone = []; $byName = [];
    foreach ($rows as $r) {
        $e = strtolower(trim((string)($r['email'] ?? '')));
        if ($e === '') continue;
        $p = $digits($r['phone'] ?? ''); if (strlen($p) === 10) $byPhone[$p][$e] = true;
        $n = strtolower(preg_replace('/\s+/', ' ', trim((string)($r['full_name'] ?? '')))); if ($n !== '') $byName[$n][$e] = true;
    }
    $out = [];
    foreach ($rows as $r) {
        $e = strtolower(trim((string)($r['email'] ?? '')));
        if ($e === '') continue;
        $sig = [];
        $p = $digits($r['phone'] ?? '');
        $n = strtolower(preg_replace('/\s+/', ' ', trim((string)($r['full_name'] ?? ''))));
        if (strlen($p) === 10 && count($byPhone[$p]) > 1) $sig[] = ['key' => 'shared_phone', 'label' => 'Phone shared with another profile'];
        if ($n !== '' && count($byName[$n]) > 1) $sig[] = ['key' => 'duplicate_name', 'label' => 'Same name on another profile'];
        if (!isset($rosterEmails[$e])) {
            $other = array_diff($rosterNamesToEmails[$n] ?? [], [$e]);
            $sig[] = ['key' => 'not_on_roster', 'label' => $other ? 'Roster lists this name under a different email' : 'Email not on the active roster'];
        }
        $ep = $digits($r['emergency_phone'] ?? '');
        $en = strtolower(trim((string)($r['emergency_name'] ?? '')));
        if (($en !== '' && $en === $n) || ($ep !== '' && $ep === $p)) $sig[] = ['key' => 'emergency_is_self', 'label' => 'Emergency contact is the agent'];
        $bp = birthday_parts($r['birthday'] ?? '');
        if ($bp && $bp[0] !== BIRTH_YEAR_UNKNOWN_PLACEHOLDER) {
            $age = (int)date('Y') - $bp[0];
            if ($age < 18 || $age > 95) $sig[] = ['key' => 'birthday_implausible', 'label' => 'Birthday looks wrong'];
        }
        if ($sig) $out[$e] = $sig;
    }
    return $out;
}

// One status object used by every screen.
//   track:  onboarding | existing
//   status: draft | submitted   (onboarding — from the stored submitted flag)
//           complete | incomplete (existing — computed live)
function profile_status_from_row(array $row, ?bool $onboarding = null): array {
    $email = strtolower(trim((string)($row['email'] ?? '')));
    if ($onboarding === null) $onboarding = $email !== '' && is_actively_onboarding($email);
    $track   = $onboarding ? PROFILE_TRACK_ONBOARDING : PROFILE_TRACK_EXISTING;
    $missing = profile_missing_fields_from_row($row, $track);
    if ($onboarding) {
        $status = !empty($row['submitted']) ? 'submitted' : 'draft';
        $label  = $status === 'submitted' ? 'Submitted' : 'Draft';
    } else {
        $status = $missing ? 'incomplete' : 'complete';
        $label  = $status === 'complete' ? 'Complete' : 'Incomplete';
    }
    // Needs Review is a separate indicator layered on top: completeness and
    // onboarding status are still computed and returned unchanged, but the
    // Agents page shows Needs Review first while an active hold exists.
    $holds = $email !== '' ? active_review_holds($email) : [];
    return [
        'track'               => $track,
        'track_label'         => $onboarding ? 'Onboarding' : 'Existing agent',
        'status'              => $status,
        'label'               => $label,
        'missing'             => $missing,
        'recommended_missing' => profile_recommended_blank_from_row($row, $track),
        'flags'               => profile_data_flags_from_row($row),
        'needs_review'        => (bool)$holds,
        'review_holds'        => $holds,
        'display_status'      => $holds ? 'needs_review' : $status,
        'display_label'       => $holds ? 'Needs Review' : $label,
        // Historical intake submission — preserved and shown as-is; for an
        // existing agent it is history, not their current completeness.
        'intake_submitted'    => !empty($row['submitted']),
        'intake_submitted_at' => $row['submitted_at'] ?? null,
    ];
}

function profile_status(string $email): array {
    $row = agent_intake_row($email);
    $row['email'] = strtolower(trim($email));
    return profile_status_from_row($row);
}

// Returns [['key'=>'phone','label'=>'Phone Number'], ...] for every field
// still required for THIS agent (track-aware) — empty array means nothing
// missing (an agent with no agent_intake row at all is missing everything).
// Used by the login popup, completion links and the send-link endpoint.
function get_missing_required_fields(string $email): array {
    return profile_missing_fields_from_row(agent_intake_row($email), profile_track($email));
}

// Same shape, for the non-enforced fields offered on the completion form:
// the general optional fields plus (existing agents) the recommended Bio/NAR.
// Only returns ones still blank, so nothing on file is asked for again.
function get_blank_optional_fields(string $email): array {
    $row = agent_intake_row($email);
    $blank = profile_recommended_blank_from_row($row, profile_track($email));
    foreach (OPTIONAL_INTAKE_FIELDS as $key => $label) {
        if (trim($row[$key] ?? '') === '') $blank[] = ['key' => $key, 'label' => $label];
    }
    return $blank;
}

// True when the stored record has all 13 core fields — the public intake
// form's "already submitted" duplicate check. Kept on the 13 core fields so
// that check behaves exactly as before.
function intake_record_has_core_fields(string $email): bool {
    $row = agent_intake_row($email);
    foreach (array_keys(REQUIRED_INTAKE_FIELDS) as $key) {
        if (trim((string)($row[$key] ?? '')) === '') return false;
    }
    return true;
}


// ── Shared status line (detail panel + profile page) ─────────────────────────
// Needs Review first, then the onboarding status or profile completeness,
// then historical intake submission (kept separate for existing agents).
function profile_status_line_html(array $ps, string $updated): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $parts = [];
    if ($ps['needs_review']) {
        $reasons = array_map(fn($h) => REVIEW_HOLD_REASONS[$h['reason_code']] ?? $h['reason_code'], $ps['review_holds']);
        $parts[] = '<strong style="color:#8a5a00">Needs Review</strong> (' . $e(implode('; ', $reasons)) . ')';
    }
    if ($ps['track'] === 'onboarding') {
        $parts[] = 'Onboarding · ' . $e($ps['label']) . ($ps['status'] === 'submitted' && $ps['intake_submitted_at'] ? ' ' . $e(fmt_dt_et($ps['intake_submitted_at'], 'M j, Y')) : '');
    } else {
        $parts[] = $e($ps['label']) . ($ps['missing'] ? ' — missing ' . $e(implode(', ', array_column($ps['missing'], 'label'))) : '');
        $parts[] = $ps['intake_submitted']
            ? 'Intake submitted' . ($ps['intake_submitted_at'] ? ' ' . $e(fmt_dt_et($ps['intake_submitted_at'], 'M j, Y')) : '') . ' (historical)'
            : 'Intake never submitted';
    }
    if ($ps['flags']) $parts[] = $e(implode(', ', array_column($ps['flags'], 'label')));
    $parts[] = 'Last updated ' . $e($updated);
    return '<span class="ps-line" style="font-size:11px;color:var(--faint)">' . implode(' · ', $parts) . '</span>';
}

// ── Completion links ─────────────────────────────────────────────────────────

const PROFILE_LINK_TTL_DAYS       = 30;
const PROFILE_LINK_COOLDOWN_DAYS  = 7;

// The application NEVER alters the database. The columns/table below are
// added only by the explicit, reviewed migration
// scripts/migrations/20261105_profile_status.php. Until it has been run, the
// code degrades safely: existing links keep working exactly as before, new
// links cannot be issued (clear error), and there are no review holds.
const PROFILE_LINK_MIGRATION_COLUMNS = ['expires_at', 'revoked_at', 'revoked_reason', 'cooldown_override'];
const PROFILE_STATUS_MIGRATION_PENDING = 'This feature is waiting for a database update (migration 20261105_profile_status) to be applied.';

// For tests / long-running scripts that apply the migration mid-process.
function profile_schema_cache_reset(): void { $GLOBALS['__profile_schema_cache_reset'] = true; }

function profile_table_columns(PDO $db, string $table): array {
    return array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
}
function profile_link_schema_ready(PDO $db): bool {
    static $ready = null;
    if ($ready === null || isset($GLOBALS['__profile_schema_cache_reset'])) {
        $ready = !array_diff(PROFILE_LINK_MIGRATION_COLUMNS, profile_table_columns($db, 'profile_completion_tokens'));
    }
    return $ready;
}
function review_holds_schema_ready(PDO $db): bool {
    static $ready = null;
    if ($ready === null || isset($GLOBALS['__profile_schema_cache_reset'])) {
        $ready = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='agent_review_holds'")->fetchColumn();
    }
    return $ready;
}

// Looks a token up and says whether it can still be used.
// Returns ['email'=>..., 'state'=>'ok'|'invalid'|'expired'|'revoked'].
function profile_link_lookup(PDO $db, string $token): array {
    if ($token === '') return ['email' => null, 'state' => 'invalid'];
    if (!profile_link_schema_ready($db)) {
        // Pre-migration: legacy behaviour (a known token is usable).
        $st = $db->prepare("SELECT email FROM profile_completion_tokens WHERE token = ?");
        $st->execute([$token]);
        $email = $st->fetchColumn();
        return $email ? ['email' => $email, 'state' => 'ok'] : ['email' => null, 'state' => 'invalid'];
    }
    $st = $db->prepare("SELECT email, expires_at, revoked_at FROM profile_completion_tokens WHERE token = ?");
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['email' => null, 'state' => 'invalid'];
    if (!empty($r['revoked_at'])) return ['email' => $r['email'], 'state' => 'revoked'];
    if (!empty($r['expires_at']) && strtotime($r['expires_at'] . ' UTC') <= time()) return ['email' => $r['email'], 'state' => 'expired'];
    return ['email' => $r['email'], 'state' => 'ok'];
}

// Most recent link sent to this agent (UTC 'Y-m-d H:i:s') or null.
function profile_link_last_sent(PDO $db, string $email): ?string {
    $st = $db->prepare("SELECT MAX(created_at) FROM profile_completion_tokens WHERE email = ?");
    $st->execute([strtolower(trim($email))]);
    return $st->fetchColumn() ?: null;
}

function profile_link_in_cooldown(PDO $db, string $email): bool {
    $last = profile_link_last_sent($db, $email);
    return $last !== null && strtotime($last . ' UTC') > time() - PROFILE_LINK_COOLDOWN_DAYS * 86400;
}

// Issues a new 30-day link and revokes this agent's earlier still-usable
// links (only when a replacement is issued — never as a bulk expiry).
function profile_link_issue(PDO $db, string $email, string $createdBy, bool $cooldownOverride = false): string {
    if (!profile_link_schema_ready($db)) throw new \RuntimeException(PROFILE_STATUS_MIGRATION_PENDING);
    $email = strtolower(trim($email));
    $token = bin2hex(random_bytes(24));
    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE profile_completion_tokens SET revoked_at = datetime('now'), revoked_reason = 'superseded'
             WHERE email = ? AND revoked_at IS NULL"
        )->execute([$email]);
        $db->prepare(
            "INSERT INTO profile_completion_tokens (token, email, created_by, expires_at, cooldown_override)
             VALUES (?, ?, ?, datetime('now', '+" . PROFILE_LINK_TTL_DAYS . " days'), ?)"
        )->execute([$token, $email, $createdBy, $cooldownOverride ? 1 : 0]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $token;
}

function agent_intake_row(string $email): array {
    $st = local_db()->prepare("SELECT * FROM agent_intake WHERE email = ?");
    $st->execute([strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function load_agent_profile(string $email): ?array {
    $st = local_db()->prepare(
        "SELECT i.email, i.full_name, i.phone, i.license_number, i.license_state,
                i.license_exp, i.nar_number, i.mls_board, i.mls_id, i.office_location,
                i.birthday, i.mailing_address, i.spouse_name, i.emergency_name, i.emergency_phone,
                i.bio, i.tshirt_size, i.is_military, i.first_responder, i.is_teacher,
                i.phone_last4, i.referring_agent, i.languages,
                i.personal_email, i.commissions_email,
                i.address_line1, i.address_line2, i.city, i.state, i.zip, i.country,
                i.drivers_license, i.gender,
                i.website, i.additional_websites, i.facebook, i.linkedin, i.skype, i.instagram,
                i.specialty, i.career_start, i.prior_occupation, i.prior_affiliation,
                i.full_time, i.show_on_internet,
                i.corporation_start, i.corporation_end,
                i.personal_tax_id_enc, i.corporate_tax_id_enc,
                i.submitted, i.submitted_at, i.updated_at,
                e.hire_date, e.license_renewal, e.alt_email, e.dotloop_alt_email, e.is_team_leader_tag,
                ar.role,
                aa.tax_1099_type, aa.gets_1099, aa.terminated_date, aa.agent_team, aa.coached_by, aa.managed_by,
                aa.recruit_source_email
         FROM agent_intake i
         LEFT JOIN agent_extra e ON e.email = i.email
         LEFT JOIN agent_roles ar ON ar.email = i.email
         LEFT JOIN agent_admin aa ON aa.email = i.email
         WHERE i.email = ?"
    );
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function load_agent_additional_licenses(string $email): array {
    $st = local_db()->prepare(
        "SELECT license_number, license_state, license_exp FROM agent_intake_licenses WHERE agent_email=? ORDER BY id"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_agent_headshot_count(string $email): int {
    $st = local_db()->prepare("SELECT COUNT(*) FROM agent_intake_files WHERE agent_email=?");
    $st->execute([$email]);
    return (int)$st->fetchColumn();
}

// Most recently uploaded headshot, used as the displayed profile photo.
function load_agent_latest_headshot(string $email): ?string {
    $st = local_db()->prepare(
        "SELECT file_key FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $key = $st->fetchColumn();
    return $key ?: null;
}

// Full headshot list (file_key + orig_name), used to render inline thumbnails
// instead of linking out to the intake form.
function load_agent_headshots(string $email): array {
    $st = local_db()->prepare(
        "SELECT file_key, orig_name FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_agent_documents(string $email): array {
    $st = local_db()->prepare(
        "SELECT id, name, source, category, mime_type, size_bytes, storage_key, uploaded_by, created_at
         FROM agent_documents WHERE email=? ORDER BY created_at DESC"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Most recent onboarding/offboarding queue row for this agent, if any —
// backs the Task Checklist tab's lightweight status line + deep link.
function load_agent_queue_status(string $email): array {
    $ob = local_db()->prepare(
        "SELECT id, status, added_at FROM onboard_queue WHERE LOWER(agent_email)=? ORDER BY added_at DESC LIMIT 1"
    );
    $ob->execute([$email]);
    $offb = local_db()->prepare(
        "SELECT id, status, added_at FROM offboard_queue WHERE LOWER(agent_email)=? ORDER BY added_at DESC LIMIT 1"
    );
    $offb->execute([$email]);
    return [
        'onboarding'  => $ob->fetch(PDO::FETCH_ASSOC) ?: null,
        'offboarding' => $offb->fetch(PDO::FETCH_ASSOC) ?: null,
    ];
}
