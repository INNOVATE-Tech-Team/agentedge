<?php
// Uploads one agreement/contract document for an mls_integrations row to S3
// and records it in mls_agreements. Multipart form POST (not JSON) — mirrors
// vault_upload.php's shape, scoped to an MLS id instead of a vault folder.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/s3.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$mlsId = (int)($_POST['mls_id'] ?? 0);
if (!$mlsId) { echo json_encode(['ok' => false, 'error' => 'mls_id required']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['ok' => false, 'error' => 'no file']); exit; }

$db = local_db();
$mlsRow = $db->prepare("SELECT id FROM mls_integrations WHERE id=?");
$mlsRow->execute([$mlsId]);
if (!$mlsRow->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'MLS not found']); exit; }

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'upload error ' . $file['error']]); exit; }

$origName = basename($file['name']);
$mime     = $file['type'] ?: s3_mime_from_name($origName);
$size     = (int)$file['size'];
$id       = sprintf(
    '%08x-%04x-%04x-%04x-%012x',
    mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000, mt_rand() * mt_rand()
);
$storageKey = 'mls_agreements/' . $mlsId . '/' . $id . '/' . $origName;

try {
    s3_put_file($file['tmp_name'], $storageKey, $mime);
} catch (\Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}

$db->prepare(
    "INSERT INTO mls_agreements (id, mls_id, name, mime_type, size_bytes, storage_key, uploaded_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))"
)->execute([$id, $mlsId, $origName, $mime, $size, $storageKey, $agent['email']]);

echo json_encode(['ok' => true, 'id' => $id, 'name' => $origName]);
