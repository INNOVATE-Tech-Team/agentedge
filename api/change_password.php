<?php
// Change the logged-in agent's own password (Perfex tblstaff), via the login
// bridge — Lightsail can't reach the Perfex DB directly, so this can't be a
// direct UPDATE the way db.php's read-only queries are. See bridge/verify.php.
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

$c = cfg();
if (empty($c['auth_bridge_url'])) {
    echo json_encode(['ok' => false, 'error' => "Password changes aren't available in preview mode."]);
    exit;
}

$res = bridge_request('change_password', [
    'email'            => $me['email'],
    'current_password' => $current,
    'new_password'     => $new,
]);

if (is_array($res) && !empty($res['ok'])) {
    echo json_encode(['ok' => true]);
} else {
    $err = is_array($res) ? ($res['error'] ?? 'Password change failed.') : 'Could not reach the login server.';
    echo json_encode(['ok' => false, 'error' => $err]);
}
