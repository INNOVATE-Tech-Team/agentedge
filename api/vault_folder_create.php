<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/local_db.php';
header('Content-Type: application/json');
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'admin only']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name     = trim($body['name'] ?? '');
$parentId = $body['parent_id'] ?? null;
$deptSlug = $body['dept_slug'] ?? '';
$vis      = $body['visibility'] ?? ($deptSlug ? 'dept' : 'public');

if (!$name) { echo json_encode(['ok'=>false,'error'=>'name required']); exit; }

// Inherit visibility/dept from parent folder if creating a sub-folder.
if ($parentId) {
    $db   = local_db();
    $stmt = $db->prepare("SELECT visibility, dept_slug FROM vault_folders WHERE id=?");
    $stmt->execute([$parentId]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($parent) { $vis = $parent['visibility']; $deptSlug = $parent['dept_slug'] ?? ''; }
}

$id = sprintf('%08x-%04x-%04x-%04x-%012x',
    mt_rand(), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,
    mt_rand(0,0x3fff)|0x8000, mt_rand()*mt_rand());

$db = local_db();
$db->prepare("INSERT INTO vault_folders (id,parent_id,name,visibility,dept_slug,created_by,created_at)
              VALUES (?,?,?,?,?,?,datetime('now'))")
   ->execute([$id, $parentId ?: null, $name, $vis, $deptSlug, $agent['email']]);

echo json_encode(['ok'=>true,'id'=>$id]);
