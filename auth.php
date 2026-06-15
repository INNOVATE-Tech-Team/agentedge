<?php
// Session + auth helpers. Agents sign in with their existing Perfex (tblstaff)
// email + password (bcrypt), so there's no new account to manage.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_agent(): ?array {
    return $_SESSION['agent'] ?? null;
}

function require_login(): array {
    $a = current_agent();
    if (!$a) {
        header('Location: login.php');
        exit;
    }
    return $a;
}

// Verify email + password against tblstaff. Returns the agent array or null.
function attempt_login(string $email, string $password): ?array {
    $c = cfg();
    // Preferred: the Perfex login bridge — verifies the agent's existing
    // password over HTTPS on innovateonline.com (works from Lightsail, where
    // we can't reach the Perfex database directly).
    if (!empty($c['auth_bridge_url'])) {
        return attempt_login_bridge(trim($email), $password, $c);
    }
    // Sample-data mode: any non-empty login works (no database, no real auth).
    if (!empty($c['demo'])) {
        if (trim($email) === '' || $password === '') return null;
        return ['id' => 1, 'email' => trim($email), 'name' => 'Demo Agent', 'photo' => null];
    }
    $u = db_one(
        "SELECT staffid, email, firstname, lastname, password, profile_image, active, role
         FROM tblstaff WHERE email = ? LIMIT 1",
        [trim($email)]
    );
    if (!$u) return null;
    if (!empty($c['require_active']) && (int)$u['active'] !== 1) return null;
    if (!empty($c['agent_role_ids']) && !in_array((int)$u['role'], array_map('intval', $c['agent_role_ids']), true)) return null;
    if (!password_verify($password, (string)$u['password'])) return null;
    return [
        'id'    => (int)$u['staffid'],
        'email' => $u['email'],
        'name'  => trim($u['firstname'] . ' ' . $u['lastname']),
        'photo' => $u['profile_image'] ?: null,
    ];
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
