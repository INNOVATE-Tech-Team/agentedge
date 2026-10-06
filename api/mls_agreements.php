<?php
// Agreement/contract documents attached to an mls_integrations row.
// GET  ?mls_id=...        → list agreement files for one MLS row, newest first
// POST {action:'delete'}  → remove one (from S3 + the DB row)
// Uploads go through mls_agreement_upload.php (multipart, not JSON) and
// downloads through mls_agreement_download.php (presigned S3 URL) — same
// three-way split used by the vault_* endpoints.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/s3.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$db = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mlsId = (int)($_GET['mls_id'] ?? 0);
    if (!$mlsId) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
    $st = $db->prepare(
        "SELECT id, name, mime_type, size_bytes, uploaded_by, created_at
         FROM mls_agreements WHERE mls_id=? ORDER BY created_at DESC, id DESC"
    );
    $st->execute([$mlsId]);
    echo json_encode(['ok' => true, 'files' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action !== 'delete') { echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit; }

$id = $body['id'] ?? '';
if (!$id) { echo json_encode(['ok' => false, 'error' => 'id required']); exit; }

$st = $db->prepare("SELECT storage_key FROM mls_agreements WHERE id=?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

try { s3_delete($row['storage_key']); } catch (\Exception $e) {}
$db->prepare("DELETE FROM mls_agreements WHERE id=?")->execute([$id]);

echo json_encode(['ok' => true]);
