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
    // Sample-data mode: any non-empty login works (no database).
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
