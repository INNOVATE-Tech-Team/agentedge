<?php
// Admin-initiated password reset for another agent — Staff-Managed section
// on agent_profile.php. Writes to the same agent_passwords table that
// change_password.php (self-service) and attempt_login() use.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($in['email'] ?? ''));
$new   = (string)($in['new_password'] ?? '');

if ($email === '') { http_response_code(400); echo json_encode(['error' => 'email required']); exit; }
if ($new === '') { echo json_encode(['ok' => false, 'error' => 'Please enter a new password.']); exit; }
if (strlen($new) < 8) { echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']); exit; }

$pdo = local_db();
$pdo->prepare(
    "INSERT INTO agent_passwords (email, password_hash, updated_at)
     VALUES (?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET password_hash=excluded.password_hash, updated_at=excluded.updated_at"
)->execute([$email, password_hash($new, PASSWORD_BCRYPT)]);

$adminEmail = strtolower(trim($agent['email'] ?? ''));
$pdo->prepare(
    "INSERT INTO agent_notes (email, note, created_by, created_at) VALUES (?, ?, ?, datetime('now'))"
)->execute([$email, "Password reset by admin ({$adminEmail}).", $adminEmail]);

echo json_encode(['ok' => true]);
