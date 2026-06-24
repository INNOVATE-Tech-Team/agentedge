<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/local_db.php';
require_once dirname(__DIR__) . '/lib/s3.php';
header('Content-Type: application/json');
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'admin only']); exit; }

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$folderId = $body['folder_id'] ?? '';
if (!$folderId) { echo json_encode(['ok'=>false,'error'=>'folder_id required']); exit; }

$db = local_db();

// Recursively collect all descendant folder IDs.
function vault_collect_folder_ids(string $folderId): array {
    $db   = local_db();
    $ids  = [$folderId];
    $stmt = $db->prepare("SELECT id FROM vault_folders WHERE parent_id=?");
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $child) {
        $ids = array_merge($ids, vault_collect_folder_ids($child));
    }
    return $ids;
}

$allIds = vault_collect_folder_ids($folderId);

// Delete all files from S3 + DB.
foreach ($allIds as $fid) {
    $stmt = $db->prepare("SELECT storage_key FROM vault_files WHERE folder_id=?");
    $stmt->execute([$fid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        try { s3_delete($key); } catch (\Exception $e) {}
    }
    $db->prepare("DELETE FROM vault_files WHERE folder_id=?")->execute([$fid]);
}

// Delete all folder rows.
foreach ($allIds as $fid) {
    $db->prepare("DELETE FROM vault_folders WHERE id=?")->execute([$fid]);
}

echo json_encode(['ok'=>true]);
