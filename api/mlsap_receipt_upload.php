<?php
// Uploads one receipt for an mlsap_bills row to S3 and records it directly on
// that row (receipt_key/receipt_name) — mirrors mls_agreement_upload.php's
// shape, scoped to a bill instead of a separate agreements table since a
// bill only ever has one receipt.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/s3.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_access_mlsap()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$billId = (int) ($_POST['bill_id'] ?? 0);
if (!$billId) { echo json_encode(['ok' => false, 'error' => 'bill_id required']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['ok' => false, 'error' => 'no file']); exit; }

$db = local_db();
$bill = $db->prepare("SELECT id FROM mlsap_bills WHERE id=?");
$bill->execute([$billId]);
if (!$bill->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'Bill not found']); exit; }

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'upload error ' . $file['error']]); exit; }

$origName = basename($file['name']);
$mime = $file['type'] ?: s3_mime_from_name($origName);
$uuid = sprintf(
    '%08x-%04x-%04x-%04x-%012x',
    mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000, mt_rand() * mt_rand()
);
$storageKey = 'mlsap_receipts/' . $billId . '/' . $uuid . '/' . $origName;

try {
    s3_put_file($file['tmp_name'], $storageKey, $mime);
} catch (\Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}

$db->prepare("UPDATE mlsap_bills SET receipt_key = ?, receipt_name = ?, updated_at = datetime('now') WHERE id = ?")
    ->execute([$storageKey, $origName, $billId]);

echo json_encode(['ok' => true, 'name' => $origName]);
