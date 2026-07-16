<?php
// Called by the browser after a successful direct-to-S3 PUT via presigned URL.
// Writes the file record to the local DB so it appears in the vault listing.
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/local_db.php';
require_once dirname(__DIR__) . '/lib/s3.php';
header('Content-Type: application/json');

$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'admin only']); exit; }

$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$id         = $in['file_id']    ?? '';
$folderId   = $in['folder_id']  ?? '';
$origName   = basename($in['name'] ?? '');
$mime       = $in['mime_type']  ?? 'application/octet-stream';
$size       = (int)($in['size'] ?? 0);
$storageKey = $in['storage_key'] ?? '';

if (!$id || !$folderId || !$origName || !$storageKey) {
    echo json_encode(['ok'=>false,'error'=>'missing fields']); exit;
}

if (!$mime) $mime = s3_mime_from_name($origName);

$db = local_db();
$db->prepare("INSERT INTO vault_files (id,folder_id,name,mime_type,size_bytes,storage_key,uploaded_by,created_at)
              VALUES (?,?,?,?,?,?,?,datetime('now'))")
   ->execute([$id, $folderId, $origName, $mime, $size, $storageKey, $agent['email']]);

echo json_encode(['ok'=>true,'id'=>$id,'name'=>$origName]);
