<?php

// Returns a presigned S3 PUT URL so the browser can upload directly to S3.

// Does NOT write to the DB — call vault_upload_confirm.php after the PUT succeeds.

require_once dirname(__DIR__) . '/db.php';

require_once dirname(__DIR__) . '/auth.php';

require_once dirname(__DIR__) . '/roles.php';

require_once dirname(__DIR__) . '/local_db.php';

require_once dirname(__DIR__) . '/lib/s3.php';

header('Content-Type: application/json');



$agent = require_login();

$perms = current_perms();

$admin = !empty($perms['isAdmin']);



$in       = json_decode(file_get_contents('php://input'), true) ?: [];

$folderId = $in['folder_id'] ?? '';

$origName = basename($in['name'] ?? '');



if (!$folderId) { echo json_encode(['ok'=>false,'error'=>'folder_id required']); exit; }

if (!$origName) { echo json_encode(['ok'=>false,'error'=>'name required']); exit; }



// Admins may upload anywhere. A non-admin may only upload into their own

// personal folder (self-serve "My Documents").

if (!$admin) {

    $db  = local_db();

    $chk = $db->prepare("SELECT visibility, owner_email FROM vault_folders WHERE id=?");

    $chk->execute([$folderId]);

    $fld = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$fld || $fld['visibility'] !== 'personal' || ($fld['owner_email'] ?? '') !== $agent['email']) {

        echo json_encode(['ok'=>false,'error'=>'access denied']); exit;

    }

}



$id = sprintf('%08x-%04x-%04x-%04x-%012x',

    mt_rand(), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,

    mt_rand(0,0x3fff)|0x8000, mt_rand()*mt_rand());

$storageKey = 'vault/' . $folderId . '/' . $id . '/' . $origName;



try {

    $uploadUrl = s3_presigned_put_url($storageKey);

} catch (\Exception $e) {

    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;

}



echo json_encode(['ok'=>true,'upload_url'=>$uploadUrl,'file_id'=>$id,'storage_key'=>$storageKey]);

