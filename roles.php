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
        'isAdmin'       => in_array($r, ['super_admin', 'staff'], true),
        'mc_slugs'      => [],
        'own_mc_slug'   => '',
        'bic_email'     => '',
        'name'          => '',
    ];
}

// Check AgentEdge's own agent_roles table first.
function fetch_perms_local(string $email): ?array {
    if (!function_exists('local_db')) return null;
    $stmt = local_db()->prepare("SELECT role, mc_slugs, own_mc_slug, bic_email, extra_roles_json FROM agent_roles WHERE email=?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $r   = canonical_role($row['role'] ?? 'agent');
    $mcs = json_decode($row['mc_slugs'] ?? '[]', true);
    if (!is_array($mcs)) $mcs = [];
    $perms = default_perms($r);
    $perms['mc_slugs']    = $mcs;
    $perms['own_mc_slug'] = $row['own_mc_slug'] ?? '';
    $perms['bic_email']   = $row['bic_email']   ?? '';
    // Merge the optional "extra role" (e.g. Team Leader alongside a BIC's
    // primary role) into the effective roles list so course role_filter
    // checks recognize it, not just the primary role.
    $extra = json_decode($row['extra_roles_json'] ?? '[]', true);
    $extraRole = (is_array($extra) && !empty($extra[0]['role'])) ? canonical_role($extra[0]['role']) : '';
    $perms['roles'] = array_values(array_unique(array_filter([$r, $extraRole])));
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
    $local = fetch_perms_local($email);
    return $local ?? fetch_perms_crm($email);
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
// The MC this agent belongs to (set by admin in agent_roles).
function my_own_mc_slug(): string { return current_perms()['own_mc_slug'] ?? ''; }
// The BIC email assigned to this agent.
function my_bic_email(): string   { return current_perms()['bic_email'] ?? ''; }

function is_super_admin(): bool    { return !empty(current_perms()['isSuperAdmin']); }
function is_admin(): bool          { return !empty(current_perms()['isAdmin']); }
function is_bic(): bool            { return (current_perms()['role'] ?? '') === 'bic'; }
function is_mc_leader(): bool      { return (current_perms()['role'] ?? '') === 'mc_leader'; }
function is_recruiter(): bool      { return (current_perms()['role'] ?? '') === 'recruiter'; }
// Can view "Leaders & Recruiters" visibility docs (super_admin, mc_leader, bic, recruiter)
function can_view_leader_docs(): bool { return is_super_admin() || is_mc_leader() || is_bic() || is_recruiter(); }
function is_leader(): bool         { return is_admin() || is_bic() || is_mc_leader(); }
// Can post announcements (any role except plain agent/recruiter)
function can_post_announcements(): bool { return is_admin() || is_mc_leader() || is_bic(); }
// Can send a Company Email — same tier as announcements: admin/staff (any audience),
// mc_leader/bic (only the Market Centers in their own mc_slugs), plus LAUNCH
// coaching staff (Launch Agents/Launch Coaches audiences only — see backoffice_email.php).
function can_send_company_email(): bool { return can_post_announcements() || is_launch_coach(); }
// LAUNCH coaching staff (coach or the director role above them).
function is_launch_coach(): bool { return in_array(my_role(), ['launch_coach', 'director_of_coaching'], true); }
// Can create/edit cohorts and reassign coaches (admin or coaching leadership).
function can_manage_cohorts(): bool { return is_admin() || is_launch_coach(); }
// Can search / view other agents' networks (super_admin, staff, recruiter)
function can_search_network(): bool { return is_admin() || is_recruiter(); }
function my_role(): string         { return current_perms()['role'] ?? 'agent'; }
// All effective roles for the current agent (primary + optional extra role) --
// use for role_filter-style checks so an extra role counts too.
function my_roles(): array         { return current_perms()['roles'] ?? [my_role()]; }

// Finance Accounting Checklists — scoped to specific named staff, not a role tier.
// Same hardcoded-allowlist shape as reconciliation.php, centralized here since
// both finance_checklists.php and its api/ counterpart need the same check.
const FINANCE_CHECKLIST_EMAILS = ['darren@innovateonline.com', 'michele@innovateonline.com', 'dominic@innovateonline.com'];
function can_access_finance_checklists(): bool {
    if (is_admin()) return true;
    $email = strtolower(trim(current_agent()['email'] ?? ''));
    return $email !== '' && in_array($email, FINANCE_CHECKLIST_EMAILS, true);
}

function require_admin_page(): void {
    if (!is_admin()) { header('Location: index.php'); exit; }
}
