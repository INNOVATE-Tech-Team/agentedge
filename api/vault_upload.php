<?php

require_once dirname(__DIR__) . '/db.php';

require_once dirname(__DIR__) . '/auth.php';

require_once dirname(__DIR__) . '/roles.php';

require_once dirname(__DIR__) . '/local_db.php';

require_once dirname(__DIR__) . '/lib/s3.php';

require_once dirname(__DIR__) . '/lib/vault.php';

header('Content-Type: application/json');

$agent = require_login();

$perms = current_perms();

$admin = !empty($perms['isAdmin']);



$folderId = $_POST['folder_id'] ?? '';

if (!$folderId) { echo json_encode(['ok'=>false,'error'=>'folder_id required']); exit; }



$db = local_db();



// Admins may upload anywhere, EXCEPT a restricted department (e.g. Legal &
// Contracts) — that one only accepts an explicit vault_user_depts grant, same
// rule as reading it. A non-admin may otherwise only upload into their own
// personal folder (self-serve "My Documents").

$chk = $db->prepare("SELECT visibility, owner_email, dept_slug, restricted_emails FROM vault_folders WHERE id=?");

$chk->execute([$folderId]);

$fld = $chk->fetch(PDO::FETCH_ASSOC);

if (!$fld) { echo json_encode(['ok'=>false,'error'=>'access denied']); exit; }

if (!vault_user_passes_folder_restriction($fld, $agent['email'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'access denied']); exit;
}

if ($fld['visibility'] === 'dept' && vault_dept_restricted($fld['dept_slug'] ?? '')) {

    if (!vault_user_has_dept($agent['email'], $fld['dept_slug'])) {

        echo json_encode(['ok'=>false,'error'=>'access denied']); exit;

    }

} elseif (!$admin) {

    if ($fld['visibility'] !== 'personal' || strtolower($fld['owner_email'] ?? '') !== strtolower($agent['email'] ?? '')) {

        echo json_encode(['ok'=>false,'error'=>'access denied']); exit;

    }

}



if (empty($_FILES['file'])) { echo json_encode(['ok'=>false,'error'=>'no file']); exit; }



$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'upload error '.$file['error']]); exit; }



$origName   = basename($file['name']);

$mime       = $file['type'] ?: s3_mime_from_name($origName);

$size       = (int)$file['size'];

$id         = sprintf('%08x-%04x-%04x-%04x-%012x',

    mt_rand(), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,

    mt_rand(0,0x3fff)|0x8000, mt_rand()*mt_rand());

$storageKey = 'vault/' . $folderId . '/' . $id . '/' . $origName;



try {

    s3_put_file($file['tmp_name'], $storageKey, $mime);

} catch (\Exception $e) {

    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;

}



$db->prepare("INSERT INTO vault_files (id,folder_id,name,mime_type,size_bytes,storage_key,uploaded_by,created_at)

              VALUES (?,?,?,?,?,?,?,datetime('now'))")

   ->execute([$id, $folderId, $origName, $mime, $size, $storageKey, $agent['email']]);



echo json_encode(['ok'=>true,'id'=>$id,'name'=>$origName]);

