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



$body   = json_decode(file_get_contents('php://input'), true) ?? [];

$fileId = $body['file_id'] ?? '';

if (!$fileId) { echo json_encode(['ok'=>false,'error'=>'file_id required']); exit; }



$db   = local_db();

$stmt = $db->prepare("SELECT vf.storage_key, vfo.visibility, vfo.owner_email, vfo.dept_slug, vfo.restricted_emails

    FROM vault_files vf LEFT JOIN vault_folders vfo ON vfo.id = vf.folder_id

    WHERE vf.id=?");

$stmt->execute([$fileId]);

$row  = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

if (!vault_user_passes_folder_restriction($row, $agent['email'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'access denied']); exit;
}



// Admins may delete anything, EXCEPT a restricted department (e.g. Legal &
// Contracts) — that one only accepts an explicit vault_user_depts grant, same
// rule as reading it. A non-admin may otherwise only delete their own file in
// their own personal folder.

if (($row['visibility'] ?? '') === 'dept' && vault_dept_restricted($row['dept_slug'] ?? '')) {

    if (!vault_user_has_dept($agent['email'], $row['dept_slug'])) {

        echo json_encode(['ok'=>false,'error'=>'access denied']); exit;

    }

} elseif (!$admin) {

    $isOwn = ($row['visibility'] ?? '') === 'personal' && strtolower($row['owner_email'] ?? '') === strtolower($agent['email'] ?? '');

    if (!$isOwn) { echo json_encode(['ok'=>false,'error'=>'access denied']); exit; }

}



try { s3_delete($row['storage_key']); } catch (\Exception $e) {}



$db->prepare("DELETE FROM vault_files WHERE id=?")->execute([$fileId]);



echo json_encode(['ok'=>true]);

