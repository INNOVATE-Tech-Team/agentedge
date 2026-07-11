<?php
// Change the logged-in agent's own password — writes to AgentEdge's local
// agent_passwords table (checked first by attempt_login() in auth.php),
// replacing the old bridge-only implementation that depended on Perfex
// tblstaff being writable (its DB user is read-only, so that path was
// likely already broken).
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

// Re-verify the current password through the exact same precedence
// attempt_login() already uses (agent_passwords first, then the Perfex
// bridge/direct-DB fallback) rather than duplicating that logic here.
if (!attempt_login($email, $current)) {
    echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
    exit;
}

local_db()->prepare(
    "INSERT INTO agent_passwords (email, password_hash, updated_at)
     VALUES (?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET password_hash=excluded.password_hash, updated_at=excluded.updated_at"
)->execute([$email, password_hash($new, PASSWORD_BCRYPT)]);

echo json_encode(['ok' => true]);
