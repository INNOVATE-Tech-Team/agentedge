<?php
// Staff Feature Access — toggle action for staff_feature_flags (see
// local_db.php / lib/feature_flags.php). Caller must be super_admin; target
// may be staff or super_admin. Every mutating action here is a plain UPSERT
// of one (user_email, feature_key) row — never a role/permission change,
// and never touches agent_roles.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/feature_flags.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_super_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'invalid csrf token']); exit;
}

$action = $in['action'] ?? '';
if ($action !== 'set_flag') {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']); exit;
}

$featureKey = (string)($in['feature_key'] ?? '');
if (!in_array($featureKey, STAFF_FEATURE_KEYS, true)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unsupported feature_key']); exit;
}

$targetEmail = strtolower(trim((string)($in['target_email'] ?? '')));
if ($targetEmail === '') {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'target_email is required']); exit;
}

$enabled = !empty($in['enabled']) ? 1 : 0;

try {
    $db = local_db();

    // Target must be an existing staff or super_admin row — never any other
    // role (recruiter, bic, mc_leader, plain agent, etc.) — this endpoint can
    // only ever grant or revoke a flag for an account that is admin-eligible.
    // super_admin is a valid target like any other: no automatic bypass, so
    // toggling one off is accepted and enforced, same as staff.
    $roleStmt = $db->prepare("SELECT role FROM agent_roles WHERE LOWER(email) = ?");
    $roleStmt->execute([$targetEmail]);
    $targetRole = $roleStmt->fetchColumn();

    if ($targetRole === false) {
        http_response_code(404); echo json_encode(['ok' => false, 'error' => 'target is not a staff or super_admin account']); exit;
    }
    if (!in_array($targetRole, ['staff', 'super_admin'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'target must be a staff or super_admin account']); exit;
    }

    $myEmail = strtolower(trim($me['email'] ?? ''));
    $now = gmdate('Y-m-d H:i:s');

    $db->prepare(
        "INSERT INTO staff_feature_flags (user_email, feature_key, enabled, updated_by_email, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_email, feature_key) DO UPDATE SET
             enabled = excluded.enabled,
             updated_by_email = excluded.updated_by_email,
             updated_at = excluded.updated_at"
    )->execute([$targetEmail, $featureKey, $enabled, $myEmail, $now, $now]);

    echo json_encode(['ok' => true, 'target_email' => $targetEmail, 'feature_key' => $featureKey, 'enabled' => (bool)$enabled]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save that change.']);
}
