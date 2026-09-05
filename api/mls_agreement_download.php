<?php
// Presigned S3 GET URL for one uploaded MLS agreement document.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/s3.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$fileId = $_GET['id'] ?? '';
if (!$fileId) { echo json_encode(['error' => 'id required']); exit; }

$db = local_db();
$st = $db->prepare("SELECT storage_key FROM mls_agreements WHERE id=?");
$st->execute([$fileId]);
$file = $st->fetch(PDO::FETCH_ASSOC);
if (!$file) { echo json_encode(['error' => 'not found']); exit; }

try {
    $url = s3_presigned_url($file['storage_key'], 3600);
    echo json_encode(['url' => $url]);
} catch (\Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
