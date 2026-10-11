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

$email = $agent['email'] ?? '';



$folderId = $_GET['folder_id'] ?? '';

if (!$folderId) { echo json_encode(['folders'=>[], 'files'=>[]]); exit; }



$db = local_db();



// Verify the user can see this folder.

$stmt = $db->prepare("SELECT * FROM vault_folders WHERE id=?");

$stmt->execute([$folderId]);

$folder = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$folder) { echo json_encode(['error'=>'not found']); exit; }



function user_can_see_folder(array $folder, bool $admin, string $email): bool {

    if (!vault_user_passes_folder_restriction($folder, $email)) return false;

    if ($folder['visibility'] === 'public') return true;

    if ($folder['visibility'] === 'dept') {

        if (vault_user_has_dept($email, $folder['dept_slug'] ?? '')) return true;

        // Admin bypass only applies to non-restricted departments — a
        // restricted one (e.g. Legal & Contracts) is only visible to
        // whoever holds an explicit grant, checked above.
        return $admin && !vault_dept_restricted($folder['dept_slug'] ?? '');

    }

    if ($admin) return true;

    if ($folder['visibility'] === 'personal') {

        return strtolower($folder['owner_email'] ?? '') === strtolower($email);

    }

    return false;

}



if (!user_can_see_folder($folder, $admin, $email)) {

    echo json_encode(['error'=>'access denied']); exit;

}



// Can the current user write (upload/delete) into this specific folder?

// Admins always can, EXCEPT a restricted department (only an explicit grant
// can write there — same rule as reading it); a non-admin can only in their
// own personal folder.

$canWrite = ($folder['visibility'] === 'dept' && vault_dept_restricted($folder['dept_slug'] ?? ''))
    ? vault_user_has_dept($email, $folder['dept_slug'] ?? '')
    : ($admin || ($folder['visibility'] === 'personal' && strtolower($folder['owner_email'] ?? '') === strtolower($email)));



// Sub-folders — inherit parent's visibility/dept for display; filter by access.

$sf = $db->prepare("SELECT * FROM vault_folders WHERE parent_id=? ORDER BY sort_ord, name");

$sf->execute([$folderId]);

$subFolders = array_values(array_filter(

    $sf->fetchAll(PDO::FETCH_ASSOC),

    fn($f) => user_can_see_folder($f, $admin, $email)

));



// Files in this folder.

$ff = $db->prepare("SELECT * FROM vault_files WHERE folder_id=? ORDER BY name");

$ff->execute([$folderId]);

$files = $ff->fetchAll(PDO::FETCH_ASSOC);

foreach ($files as &$f) {

    $f['size_fmt'] = s3_fmt_size((int)($f['size_bytes'] ?? 0));

}



echo json_encode(['folders' => $subFolders, 'files' => $files, 'can_write' => $canWrite, 'folder' => [
    'id' => $folder['id'], 'visibility' => $folder['visibility'], 'dept_slug' => $folder['dept_slug'] ?? '',
]]);

