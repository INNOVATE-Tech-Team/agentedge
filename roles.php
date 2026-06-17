<?php
// Role model — AgentEdge is now the source of truth.
// Roles are stored in the local SQLite agent_roles table (set via admin_roles.php).
// Falls back to the CRM /public/whoami when no local row exists.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const ROLE_LABELS = [
    'super_admin' => 'Super Admin',
    'staff'       => 'Staff',
    'bic'         => 'Broker in Charge',
    'mc_leader'   => 'Market Center Leader',
    'agent'       => 'Agent',
];

// Legacy CRM role names → canonical AgentEdge role names.
const ROLE_ALIASES = [
    'retention_admin'  => 'staff',
    'broker_in_charge' => 'bic',
    'recruiter'        => 'staff',
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
        'name'          => '',
    ];
}

// Check AgentEdge's own agent_roles table first.
function fetch_perms_local(string $email): ?array {
    if (!function_exists('local_db')) return null;
    $stmt = local_db()->prepare("SELECT role, mc_slugs FROM agent_roles WHERE email=?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $r   = canonical_role($row['role'] ?? 'agent');
    $mcs = json_decode($row['mc_slugs'] ?? '[]', true);
    if (!is_array($mcs)) $mcs = [];
    $perms = default_perms($r);
    $perms['mc_slugs'] = $mcs;
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

// The logged-in agent's permissions, cached in the session.
// In demo mode, ?role=<key> lets you preview any role.
function current_perms(): array {
    $c = cfg();
    if (!empty($c['demo']) && isset($_GET['role'])) {
        $r = preg_replace('/[^a-z_]/', '', $_GET['role']);
        if (!isset(ROLE_LABELS[$r])) $r = 'agent';
        $_SESSION['perms'] = default_perms($r);
        return $_SESSION['perms'];
    }
    if (!isset($_SESSION['perms'])) {
        $a = current_agent();
        $_SESSION['perms'] = $a ? fetch_perms($a['email'] ?? '') : default_perms('agent');
    }
    return $_SESSION['perms'];
}

// The MC slugs this user is allowed to post to (bic / mc_leader).
function my_mc_slugs(): array {
    return current_perms()['mc_slugs'] ?? [];
}

function is_super_admin(): bool { return !empty(current_perms()['isSuperAdmin']); }
function is_admin(): bool       { return !empty(current_perms()['isAdmin']); }
function is_bic(): bool         { return (current_perms()['role'] ?? '') === 'bic'; }
function is_mc_leader(): bool   { return (current_perms()['role'] ?? '') === 'mc_leader'; }
function is_leader(): bool      { return is_admin() || is_bic() || is_mc_leader(); }
function my_role(): string      { return current_perms()['role'] ?? 'agent'; }

function require_admin_page(): void {
    if (!is_admin()) { header('Location: index.php'); exit; }
}
