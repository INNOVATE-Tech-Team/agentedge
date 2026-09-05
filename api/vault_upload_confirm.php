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

$admin = !empty($perms['isAdmin']);



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



$db = local_db();



// Admins may upload anywhere. A non-admin may only upload into their own

// personal folder (self-serve "My Documents").

if (!$admin) {

    $chk = $db->prepare("SELECT visibility, owner_email FROM vault_folders WHERE id=?");

    $chk->execute([$folderId]);

    $fld = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$fld || $fld['visibility'] !== 'personal' || ($fld['owner_email'] ?? '') !== $agent['email']) {

        echo json_encode(['ok'=>false,'error'=>'access denied']); exit;

    }

}



if (!$mime) $mime = s3_mime_from_name($origName);



$db->prepare("INSERT INTO vault_files (id,folder_id,name,mime_type,size_bytes,storage_key,uploaded_by,created_at)

              VALUES (?,?,?,?,?,?,?,datetime('now'))")

   ->execute([$id, $folderId, $origName, $mime, $size, $storageKey, $agent['email']]);



echo json_encode(['ok'=>true,'id'=>$id,'name'=>$origName]);

