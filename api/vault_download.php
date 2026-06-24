<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/local_db.php';
require_once dirname(__DIR__) . '/lib/s3.php';
header('Content-Type: application/json');
$agent = require_login();

$fileId = $_GET['file_id'] ?? '';
if (!$fileId) { echo json_encode(['error'=>'file_id required']); exit; }

$db   = local_db();
$stmt = $db->prepare("SELECT vf.*, vfo.visibility, vfo.dept_slug
    FROM vault_files vf
    LEFT JOIN vault_folders vfo ON vfo.id = vf.folder_id
    WHERE vf.id=?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) { echo json_encode(['error'=>'not found']); exit; }

// Access check — same logic as vault_browse.
$perms = current_perms();
$admin = !empty($perms['isAdmin']);
$vis   = $file['visibility'] ?? 'public';
if ($vis !== 'public' && !$admin) {
    if ($vis === 'dept') {
        $s = $db->prepare("SELECT 1 FROM vault_user_depts WHERE email=? AND dept_slug=?");
        $s->execute([$agent['email'], $file['dept_slug'] ?? '']);
        if (!$s->fetchColumn()) { echo json_encode(['error'=>'access denied']); exit; }
    } else {
        echo json_encode(['error'=>'access denied']); exit;
    }
}

try {
    $url = s3_presigned_url($file['storage_key'], 3600);
    echo json_encode(['url' => $url]);
} catch (\Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
