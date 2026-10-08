<?php
if (defined('AGENTEDGE_ROLES_LOADED')) return;
define('AGENTEDGE_ROLES_LOADED', true);
// Role model — AgentEdge is now the source of truth.
// Roles are stored in the local SQLite agent_roles table (set via admin_roles.php).
// Falls back to the CRM /public/whoami when no local row exists.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const ROLE_LABELS = [
    'super_admin'          => 'Super Admin',
    'admin'                => 'Admin',
    'staff'                => 'Staff',
    'recruiter'            => 'Recruiter',
    'bic'                  => 'Broker in Charge',
    'mc_leader'            => 'Market Center Leader',
    'team_leader'          => 'Team Leader',
    'launch_coach'         => 'Launch Coach',
    'director_of_coaching' => 'Director of Coaching',
    'launch_facilitator'   => 'Launch Facilitator',
    'launch_agent'         => 'Launch Agent',
    'agent'                => 'Agent',
];

// Legacy CRM role names → canonical AgentEdge role names.
const ROLE_ALIASES = [
    'retention_admin'  => 'staff',
    'broker_in_charge' => 'bic',
];

function role_label(string $role): string {
    return ROLE_LABELS[$role] ?? ROLE_LABELS[ROLE_ALIASES[$role] ?? ''] ?? 'Agent';
}

function canonical_role(string $role): string {
    return ROLE_ALIASES[$role] ?? (isset(ROLE_LABELS[$role]) ? $role : 'agent');
}

function slugify_mc(string $s): string {
    return preg_replace('/^-|-$/', '', preg_replace('/[^a-z0-9]+/', '-', strtolower($s)));
}

function default_perms(string $role = 'agent'): array {
    $r = canonical_role($role);
    return [
        'role'          => $r,
        'roles'         => [$r],
        'isSuperAdmin'  => $r === 'super_admin',
        'isAdmin'       => in_array($r, ['super_admin', 'admin', 'staff'], true),
        'mc_slugs'      => [],
        'own_mc_slug'   => '',
        'own_mc_slugs'  => [],
        'bic_email'     => '',
        'name'          => '',
        'restricted_to' => '',
    ];
}

// Check AgentEdge's own agent_roles table first.
function fetch_perms_local(string $email): ?array {
    if (!function_exists('local_db')) return null;
    // Case-insensitive on purpose — the session email comes from tblstaff's
    // stored casing (whatever Perfex has on file), which doesn't always match
    // how the email was typed when the agent_roles row was created, and
    // SQLite's default TEXT comparison is case-sensitive. A mismatch here
    // silently drops the local role assignment and falls through to the CRM
    // lookup instead (see fetch_perms()) — confirmed causing a super_admin's
    // role to not load when their tblstaff email had different casing.
    $stmt = local_db()->prepare("SELECT role, mc_slugs, own_mc_slug, own_mc_slugs, bic_email, extra_roles_json, restricted_to FROM agent_roles WHERE LOWER(email)=LOWER(?)");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $r   = canonical_role($row['role'] ?? 'agent');
    $mcs = json_decode($row['mc_slugs'] ?? '[]', true);
    if (!is_array($mcs)) $mcs = [];
    $ownMcs = json_decode($row['own_mc_slugs'] ?? '[]', true);
    if (!is_array($ownMcs)) $ownMcs = [];
    $perms = default_perms($r);
    $perms['mc_slugs']     = $mcs;
    $perms['own_mc_slug']  = $row['own_mc_slug'] ?? '';
    $perms['own_mc_slugs'] = $ownMcs;
    $perms['bic_email']    = $row['bic_email']   ?? '';
    $perms['restricted_to'] = $row['restricted_to'] ?? '';
    // Merge the optional extra roles (e.g. Team Leader and/or Launch Coach
    // alongside a BIC's primary role — up to two, see admin_roles.php) into
    // the effective roles list so course role_filter checks recognize them,
    // not just the primary role.
    $extra = json_decode($row['extra_roles_json'] ?? '[]', true);
    $extraRoles = [];
    if (is_array($extra)) {
        foreach ($extra as $er) {
            if (!empty($er['role'])) $extraRoles[] = canonical_role($er['role']);
        }
    }
    $perms['roles'] = array_values(array_unique(array_filter(array_merge([$r], $extraRoles))));
    // Re-derive isAdmin from the effective role set, not just the primary
    // role default_perms() computed it from above — 'staff' can be granted as
    // an additional role (see admin_roles.php's extraRoleOptions) alongside a
    // BIC/mc_leader/etc. primary role, and without this the admin/staff-tier
    // gates (is_admin(), can_send_company_email(), nav's Back Office admin
    // filtering, and the few call sites that read $perms['isAdmin'] directly)
    // would silently ignore that grant. 'super_admin' can't be assigned as an
    // extra role (excluded from extraRoleOptions), so it never needs this.
    if (in_array('staff', $perms['roles'], true)) $perms['isAdmin'] = true;
    return $perms;
}

// Fall back: ask the CRM which role this email has.
function fetch_perms_crm(string $email): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    if ($token === '' || trim($email) === '') return default_perms('agent');
    $url = $base . '/public/whoami?token=' . urlencode($token) . '&email=' . urlencode($email);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return default_perms('agent');
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['role'])) return default_perms('agent');
    $r    = canonical_role($d['role']);
    $perms = default_perms($r);
    $perms['name'] = $d['name'] ?? '';
    return $perms;
}

function fetch_perms(string $email): array {
    $perms = fetch_perms_local($email) ?? fetch_perms_crm($email);
    // Team leader status is resolved independently of role/agent_roles — a bic
    // or mc_leader can also lead a team, and a team spans multiple MCs, so it
    // can't live in agent_roles.mc_slugs. The teams/team_leaders/team_members
    // tables are the sole source of truth, checked live on every request (same
    // as mc_slugs). A team can have more than one leader (team_leaders), so
    // this reads that join table, not teams.leader_email directly.
    $emailLower = strtolower(trim($email));
    if (function_exists('local_db') && $emailLower !== '') {
        try {
            $stmt = local_db()->prepare(
                "SELECT t.id FROM team_leaders tl JOIN teams t ON t.id = tl.team_id
                  WHERE tl.agent_email=? AND t.enabled=1 LIMIT 1"
            );
            $stmt->execute([$emailLower]);
            $teamId = $stmt->fetchColumn();
            if ($teamId !== false) {
                $perms['team_id'] = (int)$teamId;
                if (!in_array('team_leader', $perms['roles'], true)) $perms['roles'][] = 'team_leader';
            }
            $stmt = local_db()->prepare("SELECT team_id FROM team_members WHERE agent_email=?");
            $stmt->execute([$emailLower]);
            $ownTeamId = $stmt->fetchColumn();
            if ($ownTeamId !== false) $perms['own_team_id'] = (int)$ownTeamId;
        } catch (\Throwable $e) {}
    }
    return $perms;
}

// The logged-in agent's permissions. Memoized per-request only (a static, not
// $_SESSION) — role/mc_slug edits in agent_roles take effect on the agent's
// very next request instead of needing a fresh login to bust a session cache.
// In demo mode, ?role=<key> lets you preview any role.
function current_perms(): array {
    static $cached = null;
    $c = cfg();
    if (!empty($c['demo']) && isset($_GET['role'])) {
        $r = preg_replace('/[^a-z_]/', '', $_GET['role']);
        if (!isset(ROLE_LABELS[$r])) $r = 'agent';
        return default_perms($r);
    }
    if ($cached === null) {
        $a = current_agent();
        $cached = $a ? fetch_perms($a['email'] ?? '') : default_perms('agent');
    }
    return $cached;
}

// The MC slugs this user leads (mc_leader / bic).
function my_mc_slugs(): array    { return current_perms()['mc_slugs'] ?? []; }
// The MC this agent belongs to (set by admin in agent_roles). Kept as a
// scalar "first MC" mirror for callers that only need one value for display.
function my_own_mc_slug(): string { return current_perms()['own_mc_slug'] ?? ''; }
// Every MC this agent belongs to (an agent can be in more than one, e.g.
// licensed/working in bordering states).
function my_own_mc_slugs(): array { return current_perms()['own_mc_slugs'] ?? []; }
// The MC(s) this agent is actually rostered under (innovate_roster.market_center,
// slugified) — most rank-and-file agents have no agent_roles row at all, so
// mc_slugs/own_mc_slugs above (leader/staff-only, manually assigned) are empty
// for them. Notification delivery already matches recipients this way (see
// resolve_notification_recipients()'s 'mc' case); this lets in-app targeting
// (e.g. the announcements feed) reach the same people, not just leaders/staff.
function my_roster_mc_slugs(): array {
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    if ($email === '' || !function_exists('local_db')) return [];
    $stmt = local_db()->prepare("SELECT market_center FROM innovate_roster WHERE LOWER(email)=? AND active=1");
    $stmt->execute([$email]);
    $slugs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mc) {
        if ($mc !== '') $slugs[] = slugify_mc($mc);
    }
    return array_values(array_unique($slugs));
}
// The BIC email assigned to this agent.
function my_bic_email(): string   { return current_perms()['bic_email'] ?? ''; }
// Non-empty only for a restricted seat (e.g. 'calendar') — enforced in
// auth.php's require_login() (redirects anywhere else back to the one
// allowed page) and nav.php's render_sidebar() (shows a minimal sidebar).
function my_restricted_to(): string { return current_perms()['restricted_to'] ?? ''; }
// The team this user leads (one team per leader, resolved live from the teams
// table — see fetch_perms()). Null if they don't lead a team.
function my_team_id(): ?int       { $t = current_perms()['team_id'] ?? null; return $t !== null ? (int)$t : null; }
// The team this agent is a member of (not leading it) — via team_members.
function my_own_team_id(): ?int   { $t = current_perms()['own_team_id'] ?? null; return $t !== null ? (int)$t : null; }

// Every gate below takes an optional $perms override (a default_perms()-shaped
// array) on top of its normal current_perms()-based check. Every existing
// zero-arg call site is unaffected (falls through to current_perms() exactly
// as before) — the override exists solely so role_permission_matrix() below
// can run these SAME functions against a synthetic per-role perms array
// instead of duplicating their logic into a second, driftable copy.
function is_super_admin(?array $perms = null): bool    { return !empty(($perms ?? current_perms())['isSuperAdmin']); }
function is_admin(?array $perms = null): bool          { return !empty(($perms ?? current_perms())['isAdmin']); }
// Editing University master templates (On-Demand course scaffolding) is
// intentionally a tighter gate than regular course editing (is_admin()) --
// templates propagate structure into every course instantiated from them.
function can_edit_uni_templates(?array $perms = null): bool { return is_super_admin($perms); }
function is_bic(?array $perms = null): bool            { return (($perms ?? current_perms())['role'] ?? '') === 'bic'; }
function is_mc_leader(?array $perms = null): bool      { return (($perms ?? current_perms())['role'] ?? '') === 'mc_leader'; }
// NOTE: deliberately not folded into is_leader() below — that gate is checked
// by unrelated features (announcements, company email audiences) not part of
// this build. Team-specific pages check is_team_leader() explicitly instead.
// Not $perms-overridable: team leadership comes from a live teams/team_leaders
// table lookup, not anything storable in a role-only perms array.
function is_team_leader(): bool   { return my_team_id() !== null; }
function is_recruiter(?array $perms = null): bool      { return (($perms ?? current_perms())['role'] ?? '') === 'recruiter'; }
// Can view "Leaders & Recruiters" visibility docs (super_admin, mc_leader, bic, recruiter)
function can_view_leader_docs(?array $perms = null): bool { return is_super_admin($perms) || is_mc_leader($perms) || is_bic($perms) || is_recruiter($perms); }
function is_leader(?array $perms = null): bool         { return is_admin($perms) || is_bic($perms) || is_mc_leader($perms); }
// Can post announcements (any role except plain agent/recruiter)
function can_post_announcements(?array $perms = null): bool { return is_admin($perms) || is_mc_leader($perms) || is_bic($perms); }
// Can manage Market Center events (mc_events.php: create/edit/delete, see
// RSVP counts) — the normal mc_leader/bic/admin roles, OR anyone with
// mc_slugs manually assigned regardless of role (e.g. an outside partner
// restricted to just this page for specific offices, via agent_roles'
// restricted_to — doesn't make them a leader for anything else, like the
// Birthdays/Anniversaries calendar tabs, which stay is_leader()-gated).
function can_manage_mc_events(?array $perms = null): bool { return can_post_announcements($perms) || !empty(($perms ?? current_perms())['mc_slugs'] ?? []); }
// Buy Back Your Time (Eliminate/Automate/Delegate agents): every producing
// agent's own book of business. Unlike can_send_hot_deals() there's no
// per-use paid third-party data API here (just Claude + the agent's own
// FUB contacts/MLS comps), so this isn't admin/BIC-gated -- excluded only
// for roles that don't have their own client book (recruiting/coaching
// staff), not allowlisted to producing-role names, so it stays correct as
// new roles get added.
function can_use_buyback(?array $perms = null): bool {
    return !in_array(my_role($perms), ['staff', 'recruiter', 'launch_coach', 'director_of_coaching', 'launch_facilitator'], true);
}
// Can send a Company Email — same tier as announcements: admin/staff (any audience),
// mc_leader/bic (only the Market Centers in their own mc_slugs), plus LAUNCH
// coaching staff (Launch Agents/Launch Coaches audiences only — see backoffice_email.php).
function can_send_company_email(?array $perms = null): bool { return can_post_announcements($perms) || is_launch_coach($perms); }
// LAUNCH coaching staff (coach or the director role above them). Checks the
// effective role set (my_roles(), primary + the one optional extra role from
// agent_roles.extra_roles_json) rather than just the primary role, so
// someone whose primary role is 'agent' with Launch Coach assigned only as
// an additional role (the safe way to grant it without disturbing their
// existing agent placement/bic_email) is still recognized as a coach.
function is_launch_coach(?array $perms = null): bool { return array_intersect(my_roles($perms), ['launch_coach', 'director_of_coaching']) !== []; }

// Every agent whose EFFECTIVE role (primary agent_roles.role, or the one
// optional additional role in extra_roles_json) is Launch Coach or Director
// of Coaching — same "effective role" definition is_launch_coach() uses for
// the signed-in agent, applied across every agent_roles row instead of just
// the current session. Single source for "Coached By" dropdown eligibility
// (api/agent_detail.php, backoffice_agents.php) so those two call sites
// don't grow their own, potentially drifting, copies of this JSON parsing.
// Exact role match only — never fuzzy/name-based.
function list_launch_coaches(\PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT ar.email, ar.role, ar.extra_roles_json, COALESCE(i.full_name, ar.email) AS full_name
         FROM agent_roles ar
         LEFT JOIN agent_intake i ON i.email = ar.email"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $coaches = [];
    foreach ($rows as $r) {
        $effectiveRoles = [$r['role']];
        $extra = json_decode($r['extra_roles_json'] ?? '[]', true);
        if (is_array($extra)) {
            foreach ($extra as $er) {
                if (!empty($er['role'])) $effectiveRoles[] = $er['role'];
            }
        }
        if (array_intersect($effectiveRoles, ['launch_coach', 'director_of_coaching'])) {
            $coaches[] = ['email' => $r['email'], 'full_name' => $r['full_name']];
        }
    }
    usort($coaches, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
    return $coaches;
}

// Coach Dashboard access scoping for Launch Coaches (super_admin is never
// scoped -- see is_super_admin() checks at each call site, kept separate
// from this rather than folded in here). Source of truth is exactly one
// column: agent_admin.coached_by = the coach's email, exact match only,
// never fuzzy/name-based. Deliberately NOT cohort_members (a different,
// unrelated coaching-pilot table) and no new mapping table.

// Every active roster agent currently assigned to $coachEmail. Returns full
// innovate_roster rows so callers (coach_dashboard.php's roster/list,
// api/coach_production_summary.php's agents[] filter) can use whatever
// columns they need without a second query.
function coach_assigned_agents(\PDO $pdo, string $coachEmail): array {
    $coachEmail = strtolower(trim($coachEmail));
    if ($coachEmail === '') return [];
    $stmt = $pdo->prepare(
        "SELECT r.*
         FROM agent_admin aa
         JOIN innovate_roster r ON LOWER(TRIM(r.email)) = LOWER(TRIM(aa.email))
         WHERE LOWER(TRIM(aa.coached_by)) = ? AND r.active = 1
         ORDER BY r.agent_name"
    );
    $stmt->execute([$coachEmail]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

// Whether $coachEmail is authorized (via agent_admin.coached_by) to view
// $agentEmail's production/profile data. Used to re-check a single agent
// server-side even when the caller already has coach_assigned_agents()'s
// list, so a request can't bypass this by supplying a roster_id/email the
// client-side list simply wasn't rendered with.
function coach_can_access_agent(\PDO $pdo, string $coachEmail, string $agentEmail): bool {
    $coachEmail = strtolower(trim($coachEmail));
    $agentEmail = strtolower(trim($agentEmail));
    if ($coachEmail === '' || $agentEmail === '') return false;
    $stmt = $pdo->prepare("SELECT 1 FROM agent_admin WHERE LOWER(TRIM(email))=? AND LOWER(TRIM(coached_by))=? LIMIT 1");
    $stmt->execute([$agentEmail, $coachEmail]);
    return (bool)$stmt->fetchColumn();
}

// Can create/edit cohorts and reassign coaches (admin or coaching leadership).
function can_manage_cohorts(?array $perms = null): bool { return is_admin($perms) || is_launch_coach($perms); }
// Can view (and edit) the LAUNCH Curriculum reference content — coaching
// staff only, not agents. launch_facilitator is a separate role from
// launch_coach/director_of_coaching (is_launch_coach()) since a facilitator
// may run sessions without carrying an active coaching caseload.
function can_view_launch_curriculum(?array $perms = null): bool { return is_admin($perms) || is_launch_coach($perms) || my_role($perms) === 'launch_facilitator'; }
// Hot Deals: find best-value listings matching a spec, email FUB leads
// searching for that type of property. Phase 1 scope is admin/BIC only —
// widening to all agents is gated on a prepaid-credit wallet / per-use
// billing existing first (see the coastline-server hot-deal-alerts Phase 2
// plan) so open access can't run up real AirROI/data costs unrecovered.
function can_send_hot_deals(?array $perms = null): bool { return is_admin($perms) || is_bic($perms); }
// Can manage the Launch Schedule / Launch Coaching roster (admin or coaching leadership).
function can_manage_launch_roster(?array $perms = null): bool { return is_admin($perms) || is_launch_coach($perms); }
// True if this agent has an active cohort_members row in a LAUNCH-program
// cohort right now — the same "active member" definition api/leaderboard.php
// itself uses to decide who appears on the board, not the static launch_agent
// role (which is a manually-assigned tag in agent_roles and can go stale
// after an agent graduates/drops instead of tracking live enrollment).
function is_active_launch_agent(): bool {
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    if ($email === '' || !function_exists('local_db')) return false;
    $stmt = local_db()->prepare(
        "SELECT 1 FROM cohort_members cm JOIN cohorts c ON c.id = cm.cohort_id
          WHERE LOWER(cm.agent_email)=? AND cm.status='active' AND c.program='launch' LIMIT 1"
    );
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}
// LAUNCH Leaderboard: super_admin, launch coaching staff, or an agent
// currently active in a LAUNCH cohort -- not agents generally.
function can_view_launch_leaderboard(): bool {
    return is_super_admin() || is_launch_coach() || is_active_launch_agent();
}
// Can search / view other agents' networks (super_admin, staff, recruiter)
function can_search_network(?array $perms = null): bool { return is_admin($perms) || is_recruiter($perms); }
function my_role(?array $perms = null): string         { return ($perms ?? current_perms())['role'] ?? 'agent'; }
// All effective roles for the current agent (primary + optional extra role) --
// use for role_filter-style checks so an extra role counts too.
function my_roles(?array $perms = null): array         { return ($perms ?? current_perms())['roles'] ?? [my_role($perms)]; }

// Finance Accounting Checklists — scoped to specific named staff, not a role tier.
// Same hardcoded-allowlist shape as reconciliation.php, centralized here since
// both finance_checklists.php and its api/ counterpart need the same check.
const FINANCE_CHECKLIST_EMAILS = ['darren@innovateonline.com', 'michele@innovateonline.com', 'dominic@innovateonline.com'];
function can_access_finance_checklists(): bool {
    // Was is_admin() (any admin-tier role) until the 'admin' role was split
    // off from 'super_admin' 2026-10-08 specifically so the new tier does
    // NOT see Finance — narrowed to is_super_admin() to match every other
    // Finance gate in this file, rather than leaving this one wider.
    if (is_super_admin()) return true;
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    return $email !== '' && in_array($email, FINANCE_CHECKLIST_EMAILS, true);
}

function require_admin_page(): void {
    if (!is_admin()) { header('Location: index.php'); exit; }
}

// State Annual Reports — Super Admins plus the two named staff who actually
// run state filings. Same hardcoded-allowlist shape as
// FINANCE_CHECKLIST_EMAILS above, but gated on is_super_admin() rather than
// is_admin() per how the module was scoped when it was installed.
const ANNUAL_REPORTS_EMAILS = ['darren@innovateonline.com', 'michele@innovateonline.com'];
function can_access_annual_reports(): bool {
    if (is_super_admin()) return true;
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    return $email !== '' && in_array($email, ANNUAL_REPORTS_EMAILS, true);
}

// MLS & Board Cost Audit — same scope as State Annual Reports (Super Admins
// plus Michele and Darren); built from the same Finance receipts work.
const MLS_COSTS_EMAILS = ['darren@innovateonline.com', 'michele@innovateonline.com'];
function can_access_mls_costs(): bool {
    if (is_super_admin()) return true;
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    return $email !== '' && in_array($email, MLS_COSTS_EMAILS, true);
}

// MLS & Boards Payables — same scope as MLS & Board Cost Audit above, which
// this module supersedes for day-to-day use (Super Admins plus Michele and
// Darren only; not every admin, and never agents/other staff).
const MLSAP_EMAILS = ['darren@innovateonline.com', 'michele@innovateonline.com'];
function can_access_mlsap(): bool {
    if (is_super_admin()) return true;
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    return $email !== '' && in_array($email, MLSAP_EMAILS, true);
}

// Capability label => gate function name, for the Role Assignments page's
// "What can each role do?" reference (see role_permission_matrix() below).
// Only gates whose result depends purely on role/roles (nothing from a live
// per-agent DB lookup) are listed here, since those are the only ones a
// synthetic default_perms($role) array can answer correctly. Left out on
// purpose: is_team_leader() (teams/team_leaders table membership, not a
// role field), is_active_launch_agent() and can_view_launch_leaderboard()
// (live cohort_members enrollment), and can_access_finance_checklists()
// (a named-email allowlist, not a role tier) — role_permission_matrix()
// documents these separately instead of trying to compute them.
const ROLE_PERMISSION_CAPABILITIES = [
    'Full Super Admin access'                      => 'is_super_admin',
    'Admin access (Super Admin or Staff)'          => 'is_admin',
    'Leader-gated pages (MLS, Docs, Press Releases, Workflows, State Reports, Event creation, Agent Stats)' => 'is_leader',
    "View Leaders & Recruiters' visibility-restricted docs" => 'can_view_leader_docs',
    'Post Company Announcements'                   => 'can_post_announcements',
    'Send Company Email'                           => 'can_send_company_email',
    "Search / view other agents' networks"         => 'can_search_network',
    'Send Hot Deals'                               => 'can_send_hot_deals',
    'Manage LAUNCH Cohorts'                        => 'can_manage_cohorts',
    'Manage LAUNCH Schedule / Coaching roster'     => 'can_manage_launch_roster',
    'View/edit LAUNCH Curriculum reference'        => 'can_view_launch_curriculum',
    'Buy Back Your Time tool'                      => 'can_use_buyback',
    'Edit University master templates'             => 'can_edit_uni_templates',
];

// Builds the full role -> capability matrix by actually calling each gate
// function above against a synthetic default_perms() array per role — not a
// hand-maintained copy of the logic — so this can never silently drift out
// of sync with the real access-control checks every page in the app uses.
function role_permission_matrix(): array {
    $matrix = [];
    foreach (ROLE_LABELS as $roleKey => $roleLabel) {
        $perms = default_perms($roleKey);
        $caps  = [];
        foreach (ROLE_PERMISSION_CAPABILITIES as $label => $fn) {
            $caps[$label] = (bool)$fn($perms);
        }
        $matrix[$roleKey] = ['label' => $roleLabel, 'capabilities' => $caps];
    }
    return $matrix;
}
