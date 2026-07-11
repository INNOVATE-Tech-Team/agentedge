<?php
// Change the logged-in agent's own password — writes to AgentEdge's local
// agent_credentials table (see auth.php's local_credential_lookup()/
// backfill_local_credential()), replacing the old bridge-only implementation
// that depended on Perfex tblstaff being writable.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

$in      = json_decode(file_get_contents('php://input'), true) ?: [];
$current = (string)($in['current_password'] ?? '');
$new     = (string)($in['new_password'] ?? '');
$confirm = (string)($in['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    echo json_encode(['ok' => false, 'error' => 'Please fill in all fields.']); exit;
}
if ($new !== $confirm) {
    echo json_encode(['ok' => false, 'error' => "New passwords don't match."]); exit;
}
if (strlen($new) < 8) {
    echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']); exit;
}

$email = strtolower(trim($me['email']));

// Verify the current password the same way attempt_login() would: local
// credential first, falling back to the Perfex-backed path only if this
// agent has no local row yet.
$cred = local_credential_lookup($email);
if ($cred) {
    if (!password_verify($current, $cred['password_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }
} else {
    $reverify = attempt_login($email, $current);
    if (!$reverify) {
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }
    // attempt_login() already backfilled agent_credentials on that successful
    // verify above — re-fetch so the update below targets the real row.
    $cred = local_credential_lookup($email);
}

local_db()->prepare(
    "INSERT INTO agent_credentials (email, password_hash, staffid, name, photo, source, updated_at)
     VALUES (?, ?, ?, ?, ?, 'local', datetime('now'))
     ON CONFLICT(email) DO UPDATE SET password_hash=excluded.password_hash, updated_at=excluded.updated_at"
)->execute([
    $email,
    password_hash($new, PASSWORD_BCRYPT),
    (int)($cred['staffid'] ?? $me['id'] ?? 0),
    (string)($cred['name'] ?? $me['name'] ?? ''),
    $cred['photo'] ?? ($me['photo'] ?? null),
]);

echo json_encode(['ok' => true]);
