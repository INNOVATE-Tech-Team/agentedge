<?php
// Admin impersonation — super_admin only.
// POST { action: 'start', email, name } — log in as another agent.
// POST { action: 'stop' }              — return to original admin session.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'POST only']); exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? 'start';

if ($action === 'stop') {
    stop_masquerade();
    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
    exit;
}

// Start masquerade: must be a real (non-masquerading) super_admin or admin.
// Deliberately checking the role directly rather than isSuperAdmin — the
// 'admin' role (split off 2026-10-08) keeps "Log In As" per Darren even
// though it doesn't see Finance or the handful of other things still
// reserved to 'super_admin' literally (Role Assignments, Teams, etc.).
if (is_masquerading()) {
    http_response_code(400);
    echo json_encode(['error' => 'Stop current masquerade before starting another.']);
    exit;
}

$perms = current_perms();
if (!in_array($perms['role'] ?? '', ['super_admin', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required.']);
    exit;
}

$email = trim($in['email'] ?? '');
if (!$email) {
    http_response_code(400); echo json_encode(['error' => 'email required']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(['error' => 'invalid email']); exit;
}

// Don't masquerade as yourself
if (strtolower($email) === strtolower($me['email'] ?? '')) {
    http_response_code(400); echo json_encode(['error' => 'Cannot masquerade as yourself.']); exit;
}

// Look up the agent via bridge to get real staffid
$c      = cfg();
$bridge = $c['auth_bridge_url'] ?? '';
$btoken = $c['auth_bridge_token'] ?? '';
$target = ['id' => 0, 'email' => $email, 'name' => $email, 'photo' => null];
if ($bridge && $btoken) {
    $opts = ['http' => [
        'method'        => 'POST',
        'timeout'       => 10,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => json_encode(['token' => $btoken, 'action' => 'agent_lookup', 'email' => $email]),
        'ignore_errors' => true,
    ]];
    $raw = @file_get_contents($bridge, false, stream_context_create($opts));
    $d   = $raw ? json_decode($raw, true) : null;
    if (is_array($d) && !empty($d['ok'])) {
        $target = [
            'id'    => (int)($d['staffid'] ?? 0),
            'email' => $d['email'] ?? $email,
            'name'  => $d['name'] ?? $email,
            'photo' => $d['photo'] ?? null,
        ];
    }
}

// The bridge only knows agents with a real Perfex (tblstaff) record — someone
// who exists only in our own roster (no Perfex staff row at all) falls through
// with name still equal to their email, showing as e.g. "Viewing as
// jane@gmail.com" instead of their real name. Fall back to the roster name
// here, same as attempt_login()'s local-password-override path already does,
// so impersonating them doesn't show a worse name than their own real login.
if ($target['name'] === $email) {
    try {
        $stmt = local_db()->prepare("SELECT agent_name FROM innovate_roster WHERE LOWER(TRIM(email))=? AND active=1 LIMIT 1");
        $stmt->execute([strtolower($email)]);
        $rname = $stmt->fetchColumn();
        if ($rname) $target['name'] = $rname;
    } catch (\Throwable $e) {}
}

start_masquerade($target);
echo json_encode(['ok' => true, 'redirect' => 'index.php']);
