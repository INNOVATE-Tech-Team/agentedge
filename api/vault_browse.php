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
$email = $agent['email'] ?? '';

$folderId = $_GET['folder_id'] ?? '';
if (!$folderId) { echo json_encode(['folders'=>[],'files']=[]); exit; }

$db = local_db();

// Verify the user can see this folder.
$folder = $db->prepare("SELECT * FROM vault_folders WHERE id=?")->execute([$folderId])
    ? $db->prepare("SELECT * FROM vault_folders WHERE id=?") : null;
$stmt = $db->prepare("SELECT * FROM vault_folders WHERE id=?");
$stmt->execute([$folderId]);
$folder = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$folder) { echo json_encode(['error'=>'not found']); exit; }

function user_can_see_folder(array $folder, bool $admin, string $email): bool {
    if ($folder['visibility'] === 'public') return true;
    if ($admin) return true;
    if ($folder['visibility'] === 'dept') {
        $db = local_db();
        $s  = $db->prepare("SELECT 1 FROM vault_user_depts WHERE email=? AND dept_slug=?");
        $s->execute([$email, $folder['dept_slug']]);
        return (bool)$s->fetchColumn();
    }
    return false;
}

if (!user_can_see_folder($folder, $admin, $email)) {
    echo json_encode(['error'=>'access denied']); exit;
}

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

echo json_encode(['folders' => $subFolders, 'files' => $files]);
