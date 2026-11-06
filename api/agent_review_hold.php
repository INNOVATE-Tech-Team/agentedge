<?php
// Needs Review holds — admin-only. Holds are stored data (agent_review_holds)
// so they survive anything the agent does to their own profile; only an admin
// can place or resolve one, and resolving requires a note. Nothing here
// changes any agent record, merges duplicates or sends email.
// GET  ?email=x                          → {ok, holds:[active], history:[resolved]}
// POST {action:'place', email, reason_code, note}
// POST {action:'resolve', hold_id, note}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only']); exit; }
$db = local_db();
if (!review_holds_schema_ready($db)) { echo json_encode(['ok' => false, 'error' => PROFILE_STATUS_MIGRATION_PENDING, 'migration_pending' => true]); exit; }
$actor = strtolower(trim($me['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'valid email required']); exit; }
    $st = $db->prepare("SELECT * FROM agent_review_holds WHERE email = ? ORDER BY id DESC");
    $st->execute([$email]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true,
        'holds'   => array_values(array_filter($all, fn($h) => $h['resolved_at'] === null)),
        'history' => array_values(array_filter($all, fn($h) => $h['resolved_at'] !== null))]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'place') {
    $email  = strtolower(trim($body['email'] ?? ''));
    $reason = (string)($body['reason_code'] ?? '');
    $note   = trim((string)($body['note'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'valid email required']); exit; }
    if (!isset(REVIEW_HOLD_REASONS[$reason])) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Pick a reason']); exit; }
    if ($reason === 'other' && $note === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'A note is required for "Other"']); exit; }
    if (active_review_holds($email)) { echo json_encode(['ok' => false, 'error' => 'This agent already has an active review hold.']); exit; }
    try {
        $db->prepare("INSERT INTO agent_review_holds (email, reason_code, reason_note, source, placed_by) VALUES (?, ?, ?, 'staff', ?)")
           ->execute([$email, $reason, $note, $actor]);
    } catch (PDOException $e) {
        // The unique index allows one active hold per email; a simultaneous second click lands here.
        if (stripos($e->getMessage(), 'UNIQUE') !== false) { echo json_encode(['ok' => false, 'error' => 'This agent already has an active review hold.']); exit; }
        throw $e;
    }
    echo json_encode(['ok' => true, 'hold_id' => (int)$db->lastInsertId()]);
    exit;
}

if ($action === 'resolve') {
    $id   = (int)($body['hold_id'] ?? 0);
    $note = trim((string)($body['note'] ?? ''));
    if ($note === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Say how this was resolved (a note is required).']); exit; }
    $st = $db->prepare("UPDATE agent_review_holds SET resolved_at = datetime('now'), resolved_by = ?, resolution_note = ? WHERE id = ? AND resolved_at IS NULL");
    $st->execute([$actor, $note, $id]);
    if ($st->rowCount() !== 1) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Active hold not found']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
