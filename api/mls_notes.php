<?php
// Activity/notes thread for a single mls_integrations row — separate from
// that record's own single `notes` field (see backoffice_mls.php's Edit
// modal). Lets any leader post a running update and optionally tag a
// staff/super_admin teammate, which queues them an email.
// GET  ?mls_id=...  → list notes for one MLS row, newest first
// POST              → add a note for body.mls_id, optionally tagging body.tagged_email
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$db = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mlsId = (int)($_GET['mls_id'] ?? 0);
    if (!$mlsId) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
    $st = $db->prepare("SELECT id, note, tagged_email, created_by, created_at FROM mls_notes WHERE mls_id=? ORDER BY created_at DESC, id DESC");
    $st->execute([$mlsId]);
    echo json_encode(['ok' => true, 'notes' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?: [];
$mlsId       = (int)($body['mls_id'] ?? 0);
$note        = trim($body['note'] ?? '');
$taggedEmail = strtolower(trim($body['tagged_email'] ?? ''));
if (!$mlsId) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
if ($note === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'note required']); exit; }
if ($taggedEmail !== '' && !filter_var($taggedEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'tagged_email is not a valid email']); exit;
}

$mlsRow = $db->prepare("SELECT mls_name FROM mls_integrations WHERE id=?");
$mlsRow->execute([$mlsId]);
$mlsName = $mlsRow->fetchColumn();
if ($mlsName === false) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'MLS not found']); exit; }

$createdBy = strtolower(trim($agent['email'] ?? ''));
$db->prepare(
    "INSERT INTO mls_notes (mls_id, note, tagged_email, created_by, created_at) VALUES (?, ?, ?, ?, datetime('now'))"
)->execute([$mlsId, $note, $taggedEmail, $createdBy]);
$noteId = (int)$db->lastInsertId();

if ($taggedEmail !== '' && $taggedEmail !== $createdBy) {
    $displayFrom = $agent['name'] ?? $createdBy;
    queue_email_to(
        [$taggedEmail],
        "You were tagged on {$mlsName} — MLS Integrations",
        "{$displayFrom} tagged you on \"{$mlsName}\" in MLS Integrations:\n\n\"{$note}\"\n\nView it: https://agentedge.innovateonline.com/backoffice_mls.php\n\n— AgentEdge",
        $createdBy,
        $displayFrom
    );
}

echo json_encode(['ok' => true, 'id' => $noteId, 'created_by' => $createdBy]);
