<?php
if (defined('AGENTEDGE_AUTH_LOADED')) return;
define('AGENTEDGE_AUTH_LOADED', true);
// Session + auth helpers. Login now checks AgentEdge's own local
// agent_credentials table first (see attempt_login()); Perfex tblstaff via
// the bridge/direct-DB path is a fallback for agents not yet migrated there,
// kept during the transition off Perfex — see docs/plans on the auth
// decoupling effort for the retirement criteria.
require_once __DIR__ . '/local_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_agent(): ?array {
    return $_SESSION['agent'] ?? null;
}

// ── Masquerade (super_admin Log in as agent) ───────────────────────────────

function is_masquerading(): bool {
    return isset($_SESSION['original_admin']);
}

function original_admin(): ?array {
    return $_SESSION['original_admin'] ?? null;
}

function start_masquerade(array $target): void {
    $_SESSION['original_admin'] = $_SESSION['agent'];
    $_SESSION['agent']  = $target;
    unset($_SESSION['perms']); // force permission re-fetch for new identity
}

function stop_masquerade(): void {
    if (isset($_SESSION['original_admin'])) {
        $_SESSION['agent'] = $_SESSION['original_admin'];
        unset($_SESSION['original_admin']);
        unset($_SESSION['perms']); // restore admin permissions
    }
}

function require_login(): array {
    $a = current_agent();
    if (!$a) {
        header('Location: login.php');
        exit;
    }
    return $a;
}

// AgentEdge's own local credential lookup — the replacement for Perfex
// tblstaff auth. Keyed by lowercased email, same convention as every other
// agent-keyed table in local_db.php.
function local_credential_lookup(string $email): ?array {
    $st = local_db()->prepare("SELECT * FROM agent_credentials WHERE email=?");
    $st->execute([strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function credential_to_agent(array $cred): array {
    return [
        'id'    => (int)$cred['staffid'],
        'email' => $cred['email'],
        'name'  => $cred['name'],
        'photo' => $cred['photo'] ?: null,
    ];
}

// Called after a successful Perfex-backed login (bridge or direct-DB) for an
// agent with no local credential row yet — captures a fresh local bcrypt hash
// of the password they just proved they know, plus their identity, so every
// subsequent login for this agent skips Perfex entirely. Never overwrites a
// 'migrated' row with stale identity data on every login — only inserts once
// or refreshes if the underlying Perfex identity actually changed.
function backfill_local_credential(array $agent, string $password): void {
    $email = strtolower(trim($agent['email'] ?? ''));
    if ($email === '') return;
    try {
        local_db()->prepare(
            "INSERT INTO agent_credentials (email, password_hash, staffid, name, photo, source, updated_at)
             VALUES (?, ?, ?, ?, ?, 'local', datetime('now'))
             ON CONFLICT(email) DO UPDATE SET
                password_hash=excluded.password_hash, staffid=excluded.staffid,
                name=excluded.name, photo=excluded.photo, updated_at=excluded.updated_at"
        )->execute([
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            (int)($agent['id'] ?? 0),
            (string)($agent['name'] ?? ''),
            $agent['photo'] ?? null,
        ]);
    } catch (\Throwable $e) {}
}

// Verify email + password. Local-first: if this agent already has a row in
// agent_credentials (from the bulk migration or a prior backfill), verify
// against it directly — zero Perfex/bridge calls. Otherwise fall back to the
// pre-existing Perfex-backed paths and backfill on success, so the fallback
// path organically empties out as agents log in. See the auth-decoupling
// plan for the criteria to eventually remove the fallback entirely.
function attempt_login(string $email, string $password): ?array {
    $email = trim($email);
    if ($email === '' || $password === '') return null;

    $cred = local_credential_lookup($email);
    if ($cred) {
        if (!password_verify($password, $cred['password_hash'])) return null;
        return credential_to_agent($cred);
    }

    $c = cfg();
    $agent = null;

    // Preferred fallback: the Perfex login bridge — verifies the agent's
    // existing password over HTTPS on innovateonline.com (works from
    // Lightsail, where we can't reach the Perfex database directly).
    if (!empty($c['auth_bridge_url'])) {
        $agent = attempt_login_bridge($email, $password, $c);
    } elseif (!empty($c['demo'])) {
        // Sample-data mode: any non-empty login works (no database, no real
        // auth, and nothing worth persisting to agent_credentials).
        return ['id' => 1, 'email' => $email, 'name' => 'Demo Agent', 'photo' => null];
    } else {
        $u = db_one(
            "SELECT staffid, email, firstname, lastname, password, profile_image, active, role
             FROM tblstaff WHERE email = ? LIMIT 1",
            [$email]
        );
        if ($u
            && (empty($c['require_active']) || (int)$u['active'] === 1)
            && (empty($c['agent_role_ids']) || in_array((int)$u['role'], array_map('intval', $c['agent_role_ids']), true))
            && password_verify($password, (string)$u['password'])
        ) {
            $agent = [
                'id'    => (int)$u['staffid'],
                'email' => $u['email'],
                'name'  => trim($u['firstname'] . ' ' . $u['lastname']),
                'photo' => $u['profile_image'] ?: null,
            ];
        }
    }

    if ($agent) backfill_local_credential($agent, $password);
    return $agent;
}

// Generic authenticated call to the Perfex login bridge (bridge/verify.php),
// for actions beyond login (e.g. change_password). Returns the decoded JSON
// response, or null if the bridge isn't configured or unreachable.
function bridge_request(string $action, array $payload = []): ?array {
    $c = cfg();
    if (empty($c['auth_bridge_url'])) return null;
    $body = json_encode(array_merge(
        ['token' => $c['auth_bridge_token'] ?? '', 'action' => $action],
        $payload
    ));
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'timeout' => 12,
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $body,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($c['auth_bridge_url'], false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// Verify credentials through the Perfex login bridge (a small endpoint on
// innovateonline.com that checks tblstaff bcrypt and returns the agent).
function attempt_login_bridge(string $email, string $password, array $c): ?array {
    if ($email === '' || $password === '') return null;
    $payload = json_encode([
        'token'    => $c['auth_bridge_token'] ?? '',
        'email'    => $email,
        'password' => $password,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'timeout' => 12,
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($c['auth_bridge_url'], false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['ok'])) return null;
    return [
        'id'    => (int)($d['staffid'] ?? 0),
        'email' => $d['email'] ?? $email,
        'name'  => $d['name'] ?? $email,
        'photo' => $d['photo'] ?? null,
    ];
}
