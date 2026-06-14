<?php
// Role model — mirrors bold360.vip. The agent's role is resolved from the CRM
// by their login email (leaders/admins keep their role; everyone else = agent).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const ROLE_LABELS = [
    'super_admin'      => 'Super Admin',
    'retention_admin'  => 'Retention Admin',
    'recruiter'        => 'Recruiter',
    'broker_in_charge' => 'Broker in Charge',
    'mc_leader'        => 'Market Center Leader',
    'agent'            => 'Agent',
];

function role_label(string $role): string {
    return ROLE_LABELS[$role] ?? 'Agent';
}

function default_perms(string $role = 'agent'): array {
    return [
        'role'         => $role,
        'roles'        => [$role],
        'isSuperAdmin' => $role === 'super_admin',
        'isAdmin'      => in_array($role, ['super_admin', 'retention_admin'], true),
        'name'         => '',
    ];
}

// Ask the CRM which role this email has.
function fetch_perms(string $email): array {
    $c = cfg();
    $base = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    if ($token === '' || trim($email) === '') return default_perms('agent');
    $url = $base . '/public/whoami?token=' . urlencode($token) . '&email=' . urlencode($email);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return default_perms('agent');
    $d = json_decode($raw, true);
    return is_array($d) && !empty($d['role']) ? $d : default_perms('agent');
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

function is_admin(): bool   { return !empty(current_perms()['isAdmin']); }
function my_role(): string  { return current_perms()['role'] ?? 'agent'; }

// Gate a page to admins only (super_admin / retention_admin).
function require_admin_page(): void {
    if (!is_admin()) { header('Location: index.php'); exit; }
}
