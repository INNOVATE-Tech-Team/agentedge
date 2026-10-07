<?php
// Presigned S3 GET URL for one bill's uploaded receipt.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/s3.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); exit; }
if (!can_access_mlsap()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$billId = (int) ($_GET['bill_id'] ?? 0);
if (!$billId) { echo json_encode(['error' => 'bill_id required']); exit; }

$db = local_db();
$st = $db->prepare("SELECT receipt_key FROM mlsap_bills WHERE id=?");
$st->execute([$billId]);
$key = $st->fetchColumn();
if (!$key) { echo json_encode(['error' => 'No receipt on this bill']); exit; }

try {
    $url = s3_presigned_url($key, 3600);
    echo json_encode(['url' => $url]);
} catch (\Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
