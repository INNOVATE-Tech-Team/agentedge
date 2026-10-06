<?php

require_once dirname(__DIR__) . '/db.php';

require_once dirname(__DIR__) . '/auth.php';

require_once dirname(__DIR__) . '/roles.php';

require_once dirname(__DIR__) . '/local_db.php';

require_once dirname(__DIR__) . '/lib/s3.php';

header('Content-Type: application/json');

$agent = require_login();

$perms = current_perms();

$admin = !empty($perms['isAdmin']);



$body   = json_decode(file_get_contents('php://input'), true) ?? [];

$fileId = $body['file_id'] ?? '';

if (!$fileId) { echo json_encode(['ok'=>false,'error'=>'file_id required']); exit; }



$db   = local_db();

$stmt = $db->prepare("SELECT vf.storage_key, vfo.visibility, vfo.owner_email

    FROM vault_files vf LEFT JOIN vault_folders vfo ON vfo.id = vf.folder_id

    WHERE vf.id=?");

$stmt->execute([$fileId]);

$row  = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }



// Admins may delete anything. A non-admin may only delete their own file in

// their own personal folder.

if (!$admin) {

    $isOwn = ($row['visibility'] ?? '') === 'personal' && ($row['owner_email'] ?? '') === $agent['email'];

    if (!$isOwn) { echo json_encode(['ok'=>false,'error'=>'access denied']); exit; }

}



try { s3_delete($row['storage_key']); } catch (\Exception $e) {}



$db->prepare("DELETE FROM vault_files WHERE id=?")->execute([$fileId]);



echo json_encode(['ok'=>true]);

