<?php
// Reminders attached to an mls_integrations row.
// GET  ?mls_id=...            → list all reminders for one MLS row, by date
// POST {action:'add'}         → body: { mls_id, remind_at, note? }
// POST {action:'dismiss'}     → body: { id }
// POST {action:'delete'}      → body: { id }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$db = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mlsId = (int)($_GET['mls_id'] ?? 0);
    if (!$mlsId) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
    $st = $db->prepare("SELECT id, mls_id, remind_at, note, created_by, created_at, dismissed_at FROM mls_reminders WHERE mls_id=? ORDER BY dismissed_at IS NOT NULL ASC, remind_at ASC, id ASC");
    $st->execute([$mlsId]);
    echo json_encode(['ok' => true, 'reminders' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'add') {
    $mlsId   = (int)($body['mls_id'] ?? 0);
    $remindAt = trim($body['remind_at'] ?? '');
    if (!$mlsId)    { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
    if (!$remindAt) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'remind_at required']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $remindAt)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid date format']); exit; }
    $note = trim($body['note'] ?? '');
    $by   = strtolower(trim($agent['email'] ?? ''));
    $db->prepare("INSERT INTO mls_reminders (mls_id, remind_at, note, created_by) VALUES (?,?,?,?)")
       ->execute([$mlsId, $remindAt, $note, $by]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

if ($action === 'dismiss') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
    $db->prepare("UPDATE mls_reminders SET dismissed_at=datetime('now') WHERE id=? AND dismissed_at IS NULL")
       ->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
    $db->prepare("DELETE FROM mls_reminders WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
