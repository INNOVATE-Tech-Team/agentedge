<?php
// Document library actions: create folder, upload file, delete folder/file, list.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$db = local_db();

// GET — folder tree or folder contents
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
    if (is_admin()) {
        $vis = '';
    } elseif (can_view_leader_docs()) {
        $vis = "AND visibility IN ('all','leaders')";
    } else {
        $vis = "AND visibility='all'";
    }

    if ($folderId === null) {
        // Top-level folders
        $folders = $db->query("SELECT * FROM doc_folders WHERE parent_id IS NULL $vis ORDER BY sort_ord,name")
                      ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'folders'=>$folders,'files'=>[],'breadcrumb'=>[]]);
    } else {
        // Sub-folders + files in this folder
        $subFolders = $db->prepare("SELECT * FROM doc_folders WHERE parent_id=? $vis ORDER BY sort_ord,name");
        $subFolders->execute([$folderId]);
        $files = $db->prepare("SELECT id,name,orig_name,mime_type,size_bytes,uploaded_by,created_at FROM doc_files WHERE folder_id=? ORDER BY name");
        $files->execute([$folderId]);
        // Breadcrumb
        $bc = []; $cur = $folderId;
        while ($cur) {
            $f = $db->prepare("SELECT id,name,parent_id FROM doc_folders WHERE id=?");
            $f->execute([$cur]); $row = $f->fetch(PDO::FETCH_ASSOC);
            if (!$row) break;
            array_unshift($bc, ['id'=>$row['id'],'name'=>$row['name']]);
            $cur = $row['parent_id'];
        }
        echo json_encode(['ok'=>true,'folders'=>$subFolders->fetchAll(PDO::FETCH_ASSOC),'files'=>$files->fetchAll(PDO::FETCH_ASSOC),'breadcrumb'=>$bc]);
    }
    exit;
}

// POST — admin only for mutations
if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'),true)['action'] ?? '');
$in     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $in['action'] ?? $action;

if ($action === 'create_folder') {
    $name     = trim($in['name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'name required']); exit; }
    $parentId = !empty($in['parent_id']) ? (int)$in['parent_id'] : null;
    $vis      = in_array($in['visibility']??'all',['all','admin','leaders']) ? $in['visibility'] : 'all';
    $db->prepare("INSERT INTO doc_folders (parent_id,name,visibility,created_by) VALUES (?,?,?,?)")
       ->execute([$parentId,$name,$vis,$me['email']]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    exit;
}

if ($action === 'delete_folder') {
    $id = (int)($in['id'] ?? 0);
    // Recursive delete
    function delete_folder_rec(PDO $db, int $id): void {
        $files = $db->prepare("SELECT storage_key FROM doc_files WHERE folder_id=?");
        $files->execute([$id]);
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $path = __DIR__ . '/../data/docs/' . $key;
            if (file_exists($path)) @unlink($path);
        }
        $db->prepare("DELETE FROM doc_files WHERE folder_id=?")->execute([$id]);
        $subs = $db->prepare("SELECT id FROM doc_folders WHERE parent_id=?");
        $subs->execute([$id]);
        foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $subId) delete_folder_rec($db, (int)$subId);
        $db->prepare("DELETE FROM doc_folders WHERE id=?")->execute([$id]);
    }
    delete_folder_rec($db, $id);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'delete_file') {
    $id = (int)($in['id'] ?? 0);
    $f = $db->prepare("SELECT storage_key FROM doc_files WHERE id=?");
    $f->execute([$id]);
    $key = $f->fetchColumn();
    if ($key) { $path = __DIR__ . '/../data/docs/' . $key; if (file_exists($path)) @unlink($path); }
    $db->prepare("DELETE FROM doc_files WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// File upload — multipart/form-data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error'=>'upload error']); exit; }
    if ($file['size'] > 50 * 1024 * 1024) { http_response_code(400); echo json_encode(['error'=>'max 50 MB']); exit; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key = uniqid('', true) . ($ext ? ".$ext" : '');
    $dest = __DIR__ . '/../data/docs/' . $key;
    if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
    $displayName = trim($_POST['display_name'] ?? '') ?: $file['name'];
    $db->prepare("INSERT INTO doc_files (folder_id,name,orig_name,mime_type,size_bytes,storage_key,uploaded_by) VALUES (?,?,?,?,?,?,?)")
       ->execute([$folderId, $displayName, $file['name'], $file['type'], $file['size'], $key, $me['email']]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
